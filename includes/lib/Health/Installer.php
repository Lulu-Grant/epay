<?php
namespace lib\Health;

class Installer
{
    const VERSION = '1207';

    public static function install()
    {
        global $DB, $CACHE, $conf;

        $previousVersion = intval(isset($conf['addon_health_report']) ? $conf['addon_health_report'] : 0);

        $file = ROOT.'install/addon_health_report.sql';
        if(!is_file($file)) return false;
        $lockName = 'epay_'.substr(hash('sha256', (defined('DBQZ') ? DBQZ : 'pre').':health_install'), 0, 24);
        if(!$DB->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])) return false;

        try {
            if($DB->getColumn("SHOW TABLES LIKE '".DBQZ."_telegram_notify_queue'") === false){
                throw new \RuntimeException('Telegram notification queue must be installed first');
            }
            if($DB->exec('SET SESSION lock_wait_timeout=5') === false) throw new \RuntimeException('Metadata lock timeout setup failed');
            if($DB->exec('SET SESSION innodb_lock_wait_timeout=5') === false) throw new \RuntimeException('InnoDB lock timeout setup failed');
            $sql = file_get_contents($file);
            foreach(explode(';', $sql) as $statement){
                $statement = trim($statement);
                if($statement === '') continue;
                if($DB->exec($statement) === false) throw new \RuntimeException($DB->error());
            }
            self::ensureQueueSchema();
            self::ensureHealthSchema();
            self::ensureAiTaskSchema();
            self::ensureOrderIndex();
            if(!self::schemaReady()) throw new \RuntimeException('Health report schema verification failed');

            $defaults = [
                'health_snapshot_enabled' => '0',
                'health_report_enabled' => '0',
                'health_ai_enabled' => '0',
                'health_ai_provider' => 'openai_compatible',
                'health_ai_base_url' => '',
                'health_ai_model' => '',
                'health_ai_timeout' => '600',
                'health_ai_first_byte_timeout' => '300',
                'health_ai_idle_timeout' => '120',
                'health_ai_max_tokens' => '3000',
                'health_ai_daily_limit' => '100',
                'health_ai_payload_mode' => 'compact',
                'health_ai_min_sample' => '10',
                'health_report_time' => '09:05',
                'health_report_chat_id' => '',
                'health_snapshot_days' => '90',
                'health_report_days' => '365',
                'health_snapshot_reconcile_hours' => '6',
                'health_snapshot_settle_hours' => '3',
                'health_min_sample' => '30',
                'health_attention_drop' => '5',
                'health_warning_drop' => '10',
                'health_stale_hours' => '6',
                'health_failure_streak' => '10',
                'health_notify_critical_count' => '20',
                'health_notify_critical_rate' => '20',
            ];
            foreach($defaults as $key => $value){
                if(!isset($conf[$key]) && saveSetting($key, $value) === false) throw new \RuntimeException('Default setting write failed');
            }
            if($previousVersion < 1205 && intval(isset($conf['health_ai_daily_limit'])?$conf['health_ai_daily_limit']:0) <= 5){
                if(saveSetting('health_ai_daily_limit','100') === false) throw new \RuntimeException('AI daily limit migration failed');
            }
            if($previousVersion < 1206){
                if(intval(isset($conf['health_ai_timeout'])?$conf['health_ai_timeout']:0) <= 60
                    && saveSetting('health_ai_timeout','600') === false) throw new \RuntimeException('AI absolute timeout migration failed');
                if(!isset($conf['health_ai_first_byte_timeout'])
                    && saveSetting('health_ai_first_byte_timeout','300') === false) throw new \RuntimeException('AI first-byte timeout migration failed');
                if(!isset($conf['health_ai_idle_timeout'])
                    && saveSetting('health_ai_idle_timeout','120') === false) throw new \RuntimeException('AI idle timeout migration failed');
            }
            if(saveSetting('addon_health_report', self::VERSION) === false) throw new \RuntimeException('Addon version write failed');
            if(isset($CACHE)) $CACHE->clear();
            return true;
        } catch(\Throwable $e){
            error_log('Health report install failed: '.$e->getMessage());
            return false;
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
        }
    }

    public static function isInstalled()
    {
        return self::schemaReady();
    }

    private static function ensureQueueSchema()
    {
        global $DB;
        $columns = [
            'dedupe_key'=>"varchar(100) DEFAULT NULL",
            'claimtime'=>"datetime DEFAULT NULL",
            'sendstarttime'=>"datetime DEFAULT NULL",
            'next_attempt'=>"datetime DEFAULT NULL",
        ];
        foreach($columns as $name => $definition){
            if($DB->getColumn("SHOW COLUMNS FROM pre_telegram_notify_queue LIKE '{$name}'") === false){
                if($DB->exec("ALTER TABLE pre_telegram_notify_queue ADD COLUMN `{$name}` {$definition}") === false) throw new \RuntimeException($DB->error());
            }
        }
        if($DB->getRow("SHOW INDEX FROM pre_telegram_notify_queue WHERE Key_name='uk_dedupe_key'") === false){
            if($DB->exec("ALTER TABLE pre_telegram_notify_queue ADD UNIQUE KEY `uk_dedupe_key` (`dedupe_key`)") === false) throw new \RuntimeException($DB->error());
        }
        if($DB->getRow("SHOW INDEX FROM pre_telegram_notify_queue WHERE Key_name='idx_queue_ready'") === false){
            if($DB->exec("ALTER TABLE pre_telegram_notify_queue ADD KEY `idx_queue_ready` (`status`,`next_attempt`,`addtime`)") === false) throw new \RuntimeException($DB->error());
        }
    }

    private static function ensureHealthSchema()
    {
        global $DB;
        $columns = [
            'ai_attempted_at'=>"datetime DEFAULT NULL AFTER `ai_error`",
            'ai_request_sha256'=>"char(64) DEFAULT NULL AFTER `ai_attempted_at`",
            'ai_response_sha256'=>"char(64) DEFAULT NULL AFTER `ai_request_sha256`",
            'ai_started_at'=>"datetime DEFAULT NULL AFTER `ai_response_sha256`",
            'ai_completed_at'=>"datetime DEFAULT NULL AFTER `ai_started_at`",
            'ai_retry_until'=>"datetime DEFAULT NULL AFTER `ai_completed_at`",
            'ai_next_retry_at'=>"datetime DEFAULT NULL AFTER `ai_retry_until`",
            'ai_calls'=>"int(11) NOT NULL DEFAULT '0' AFTER `ai_next_retry_at`",
            'ai_source_rows'=>"int(11) NOT NULL DEFAULT '0' AFTER `ai_calls`",
            'ai_sample_rows'=>"int(11) NOT NULL DEFAULT '0' AFTER `ai_source_rows`",
            'ai_drilldown_rows'=>"int(11) NOT NULL DEFAULT '0' AFTER `ai_sample_rows`",
            'ai_payload_mode'=>"varchar(16) NOT NULL DEFAULT 'compact' AFTER `ai_drilldown_rows`",
            'ai_request_bytes'=>"bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `ai_payload_mode`",
            'ai_response_bytes'=>"bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `ai_request_bytes`",
            'ai_input_tokens'=>"bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `ai_response_bytes`",
            'ai_output_tokens'=>"bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `ai_input_tokens`",
            'ai_token_source'=>"varchar(16) DEFAULT NULL AFTER `ai_output_tokens`",
            'ai_pipeline_version'=>"varchar(32) DEFAULT NULL AFTER `ai_token_source`",
        ];
        foreach($columns as $name=>$definition){
            if($DB->getColumn("SHOW COLUMNS FROM pre_health_report LIKE '{$name}'") === false){
                if($DB->exec("ALTER TABLE pre_health_report ADD COLUMN `{$name}` {$definition}") === false){
                    throw new \RuntimeException($DB->error());
                }
            }
        }
    }

    private static function ensureOrderIndex()
    {
        global $DB;
        if($DB->getRow("SHOW INDEX FROM pre_order WHERE Key_name='idx_health_addtime'") === false){
            if($DB->exec('ALTER TABLE pre_order ADD KEY `idx_health_addtime` (`addtime`,`channel`,`status`), ALGORITHM=INPLACE, LOCK=NONE') === false){
                throw new \RuntimeException('Health order index creation failed: '.$DB->error());
            }
        }
    }

    private static function ensureAiTaskSchema()
    {
        global $DB;
        $columns = [
            'payload_bytes'=>"int(11) unsigned NOT NULL DEFAULT '0' AFTER `source_rows`",
            'request_bytes'=>"int(11) unsigned NOT NULL DEFAULT '0' AFTER `response_sha256`",
            'response_bytes'=>"int(11) unsigned NOT NULL DEFAULT '0' AFTER `request_bytes`",
            'input_tokens'=>"int(11) unsigned NOT NULL DEFAULT '0' AFTER `response_bytes`",
            'output_tokens'=>"int(11) unsigned NOT NULL DEFAULT '0' AFTER `input_tokens`",
            'token_source'=>"varchar(16) DEFAULT NULL AFTER `output_tokens`",
        ];
        foreach($columns as $name=>$definition){
            if($DB->getColumn("SHOW COLUMNS FROM pre_health_ai_task LIKE '{$name}'") === false){
                if($DB->exec("ALTER TABLE pre_health_ai_task ADD COLUMN `{$name}` {$definition}") === false){
                    throw new \RuntimeException($DB->error());
                }
            }
        }
    }

    private static function schemaReady()
    {
        global $DB;
        foreach(['health_report','health_snapshot','health_ai_task','telegram_notify_queue'] as $table){
            if($DB->getColumn("SHOW TABLES LIKE '".DBQZ."_{$table}'") === false) return false;
        }
        $columns = [
            'health_snapshot'=>[
                'id'=>['bigint(20) unsigned','NO',null,'auto_increment'], 'snapshot_time'=>['datetime','NO',null,''],
                'period_start'=>['datetime','NO',null,''], 'period_end'=>['datetime','NO',null,''],
                'scope_type'=>['varchar(20)','NO',null,''], 'scope_id'=>['varchar(32)','NO','0',''],
                'metrics_json'=>['longtext','NO',null,''], 'metrics_version'=>['varchar(20)','NO',null,''],
                'created_at'=>['datetime','NO','current_timestamp()',''],
            ],
            'health_report'=>[
                'id'=>['bigint(20) unsigned','NO',null,'auto_increment'], 'report_date'=>['date','NO',null,''],
                'report_type'=>['varchar(20)','NO','daily',''], 'period_start'=>['datetime','NO',null,''],
                'period_end'=>['datetime','NO',null,''], 'health_level'=>['varchar(20)','NO',null,''],
                'metrics_json'=>['longtext','NO',null,''], 'rules_json'=>['longtext','NO',null,''],
                'ai_status'=>['tinyint(1)','NO','0',''], 'ai_provider'=>['varchar(40)','YES',null,''],
                'ai_model'=>['varchar(100)','YES',null,''], 'ai_duration_ms'=>['int(11)','YES',null,''],
                'ai_json'=>['longtext','YES',null,''], 'ai_error'=>['varchar(500)','YES',null,''],
                'ai_attempted_at'=>['datetime','YES',null,''],
                'ai_request_sha256'=>['char(64)','YES',null,''], 'ai_response_sha256'=>['char(64)','YES',null,''],
                'ai_started_at'=>['datetime','YES',null,''], 'ai_completed_at'=>['datetime','YES',null,''],
                'ai_retry_until'=>['datetime','YES',null,''], 'ai_next_retry_at'=>['datetime','YES',null,''],
                'ai_calls'=>['int(11)','NO','0',''], 'ai_source_rows'=>['int(11)','NO','0',''],
                'ai_sample_rows'=>['int(11)','NO','0',''], 'ai_drilldown_rows'=>['int(11)','NO','0',''],
                'ai_payload_mode'=>['varchar(16)','NO','compact',''],
                'ai_request_bytes'=>['bigint(20) unsigned','NO','0',''], 'ai_response_bytes'=>['bigint(20) unsigned','NO','0',''],
                'ai_input_tokens'=>['bigint(20) unsigned','NO','0',''], 'ai_output_tokens'=>['bigint(20) unsigned','NO','0',''],
                'ai_token_source'=>['varchar(16)','YES',null,''],
                'ai_pipeline_version'=>['varchar(32)','YES',null,''],
                'telegram_status'=>['tinyint(1)','NO','0',''], 'telegram_queue_id'=>['bigint(20) unsigned','YES',null,''],
                'created_at'=>['datetime','NO','current_timestamp()',''], 'updated_at'=>['datetime','NO','current_timestamp()',''], 'sent_at'=>['datetime','YES',null,''],
            ],
            'health_ai_task'=>[
                'id'=>['bigint(20) unsigned','NO',null,'auto_increment'], 'report_id'=>['bigint(20) unsigned','NO',null,''],
                'stage'=>['varchar(40)','NO',null,''], 'scope_type'=>['varchar(20)','NO',null,''],
                'scope_id'=>['varchar(32)','NO','0',''], 'chunk_no'=>['int(11)','NO','0',''],
                'status'=>['tinyint(1)','NO','0',''], 'source_rows'=>['int(11)','NO','0',''],
                'payload_bytes'=>['int(11) unsigned','NO','0',''],
                'request_payload_sha256'=>['char(64)','NO',null,''], 'request_sha256'=>['char(64)','YES',null,''],
                'response_sha256'=>['char(64)','YES',null,''], 'duration_ms'=>['int(11)','YES',null,''],
                'request_bytes'=>['int(11) unsigned','NO','0',''], 'response_bytes'=>['int(11) unsigned','NO','0',''],
                'input_tokens'=>['int(11) unsigned','NO','0',''], 'output_tokens'=>['int(11) unsigned','NO','0',''],
                'token_source'=>['varchar(16)','YES',null,''],
                'result_json'=>['longtext','YES',null,''], 'error_message'=>['varchar(500)','YES',null,''],
                'created_at'=>['datetime','NO','current_timestamp()',''], 'updated_at'=>['datetime','NO','current_timestamp()',''],
                'completed_at'=>['datetime','YES',null,''],
            ],
            'telegram_notify_queue'=>[
                'dedupe_key'=>['varchar(100)','YES',null,''], 'claimtime'=>['datetime','YES',null,''],
                'sendstarttime'=>['datetime','YES',null,''], 'next_attempt'=>['datetime','YES',null,''],
            ],
        ];
        foreach($columns as $table => $required){
            $actualRows = $DB->getAll("SHOW FULL COLUMNS FROM pre_{$table}");
            if($actualRows === false) return false;
            $actual = [];
            foreach($actualRows as $row) $actual[$row['Field']] = self::normalizeColumnDefinition([
                strtolower($row['Type']), strtoupper($row['Null']),
                $row['Default'] === null ? null : strtolower((string)$row['Default']), strtolower((string)$row['Extra']),
            ]);
            foreach($required as $column => $definition){
                if(!isset($actual[$column]) || $actual[$column] !== self::normalizeColumnDefinition($definition)) return false;
            }
        }
        $indexes = [
            'health_snapshot'=>[
                'uk_snapshot_scope'=>[0,['snapshot_time','scope_type','scope_id','metrics_version']],
                'idx_scope_time'=>[1,['scope_type','scope_id','snapshot_time']], 'idx_period'=>[1,['period_start','period_end']],
            ],
            'health_report'=>[
                'uk_report_date_type'=>[0,['report_date','report_type']], 'idx_level_date'=>[1,['health_level','report_date']],
                'idx_telegram_status'=>[1,['telegram_status','created_at']],
            ],
            'health_ai_task'=>[
                'idx_report_stage'=>[1,['report_id','stage','scope_id','chunk_no']],
                'idx_daily_budget'=>[1,['created_at','status']],
            ],
            'telegram_notify_queue'=>[
                'uk_dedupe_key'=>[0,['dedupe_key']], 'idx_queue_ready'=>[1,['status','next_attempt','addtime']],
            ],
        ];
        foreach($indexes as $table => $required){
            $actualRows = $DB->getAll("SHOW INDEX FROM pre_{$table}");
            if($actualRows === false) return false;
            $actual = [];
            foreach($actualRows as $row){
                $name = $row['Key_name'];
                $actual[$name]['non_unique'] = intval($row['Non_unique']);
                $actual[$name]['columns'][intval($row['Seq_in_index'])] = $row['Column_name'];
            }
            foreach($required as $name => $definition){
                if(!isset($actual[$name])) return false;
                ksort($actual[$name]['columns']);
                if($actual[$name]['non_unique'] !== $definition[0] || array_values($actual[$name]['columns']) !== $definition[1]) return false;
            }
        }
        $orderIndexRows = $DB->getAll("SHOW INDEX FROM pre_order WHERE Key_name='idx_health_addtime'");
        if($orderIndexRows === false || !$orderIndexRows) return false;
        usort($orderIndexRows,function($a,$b){return intval($a['Seq_in_index'])<=>intval($b['Seq_in_index']);});
        if(array_column($orderIndexRows,'Column_name') !== ['addtime','channel','status']) return false;
        return true;
    }

    private static function normalizeColumnDefinition($definition)
    {
        $type = preg_replace('/\s+/', ' ', strtolower(trim((string)$definition[0])));
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
        $type = preg_replace('/^integer\b/', 'int', $type);
        $default = $definition[2];
        if(is_string($default)){
            $default = strtolower(trim($default));
            if($default === 'current_timestamp()') $default = 'current_timestamp';
        }
        return [$type, strtoupper((string)$definition[1]), $default, strtolower(trim((string)$definition[3]))];
    }
}
