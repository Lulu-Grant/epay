#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/health-test-bootstrap.php';
if(!health_test_bootstrap()){
    fwrite(STDERR, "Explicit isolated test database environment is required\n");
    exit(1);
}

$database = (string)$DB->getColumn('SELECT DATABASE()');
if(!preg_match('/(?:_local|_test)$/D', $database) || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', DBQZ)){
    fwrite(STDERR, "Refusing migration regression outside an isolated test schema\n");
    exit(1);
}

$lockName = 'epay_health_schema_migration_regression';
if(!$DB->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])){
    fwrite(STDERR, "Migration regression lock is busy\n");
    exit(1);
}

$tables = ['pre_health_snapshot','pre_health_report','pre_telegram_notify_queue'];
$backups = [
    'pre_health_snapshot'=>'pre_health_snapshot_migbak',
    'pre_health_report'=>'pre_health_report_migbak',
    'pre_telegram_notify_queue'=>'pre_telegram_notify_queue_migbak',
];
$hadAiTask = $DB->getColumn("SHOW TABLES LIKE 'pre_health_ai_task'") !== false;
if($hadAiTask){ $tables[]='pre_health_ai_task'; $backups['pre_health_ai_task']='pre_health_ai_task_migbak'; }
$hadHealthOrderIndex = $DB->getRow("SHOW INDEX FROM pre_order WHERE Key_name='idx_health_addtime'") !== false;
$configRows = [];
$renamed = false;
$failure = null;
$scenarioCount = 0;

try {
    foreach($backups as $backup){
        if($DB->getColumn("SHOW TABLES LIKE '{$backup}'") !== false) throw new RuntimeException('Stale migration backup table exists: '.$backup);
    }
    foreach($tables as $table){
        if($DB->getColumn("SHOW TABLES LIKE '{$table}'") === false) throw new RuntimeException('Required fixture table is missing: '.$table);
    }
    $configRows = $DB->getAll("SELECT k,v FROM pre_config WHERE k='addon_health_report' OR k LIKE 'health\\_%'");
    if($configRows === false) throw new RuntimeException('Cannot snapshot health configuration');

    $rename = [];
    foreach($backups as $table=>$backup) $rename[] = "`{$table}` TO `{$backup}`";
    migration_exec('RENAME TABLE '.implode(',', $rename));
    $renamed = true;
    migration_exec("DELETE FROM pre_config WHERE k='addon_health_report' OR k LIKE 'health\\_%'");
    foreach(array_keys($conf) as $key){
        if($key === 'addon_health_report' || strpos($key, 'health_') === 0) unset($conf[$key]);
    }

    $healthSql = file_get_contents(ROOT.'install/addon_health_report.sql');
    if($healthSql === false) throw new RuntimeException('Cannot read health schema');
    $healthStatements = [];
    foreach(explode(';', $healthSql) as $statement){
        $statement = trim($statement);
        if($statement !== '') $healthStatements[] = $statement;
    }
    if(count($healthStatements) !== 3) throw new RuntimeException('Unexpected health schema statement count');

    $scenarios = [
        ['fresh-install',0,0,false],
        ['interrupted-after-health-snapshot',1,0,false],
        ['interrupted-after-health-report',2,0,false],
        ['interrupted-after-health-ai-task',3,0,false],
    ];
    for($queuePrefix=1;$queuePrefix<=6;$queuePrefix++){
        $scenarios[] = ['interrupted-after-queue-ddl-'.$queuePrefix,3,$queuePrefix,false];
    }
    $scenarios[] = ['legacy-report-missing-ai-audit',3,6,true];

    foreach($scenarios as $index=>$scenario){
        list($name,$healthPrefix,$queuePrefix,$legacyReport) = $scenario;
        migration_reset_tables();
        migration_create_legacy_queue($queuePrefix);
        for($i=0;$i<$healthPrefix;$i++){
            $statement = $healthStatements[$i];
            if($legacyReport && $i === 1){
                foreach(['ai_attempted_at','ai_request_sha256','ai_response_sha256','ai_started_at','ai_completed_at','ai_retry_until','ai_next_retry_at','ai_calls','ai_source_rows','ai_sample_rows','ai_drilldown_rows','ai_payload_mode','ai_request_bytes','ai_response_bytes','ai_input_tokens','ai_output_tokens','ai_token_source','ai_pipeline_version'] as $column){
                    $statement = preg_replace('/^  `'.preg_quote($column, '/').'`[^\n]+\n/m', '', $statement);
                    if(strpos($statement, $column) !== false) throw new RuntimeException('Legacy report fixture still contains '.$column);
                }
            }
            migration_exec($statement);
        }
        migration_exec("INSERT INTO pre_telegram_notify_queue (id,uid,scene,param,status,retry_count,error_msg,addtime,sendtime) VALUES (4242,1001,'order','legacy-row',0,1,'legacy-error','2026-01-01 00:00:00',NULL)");

        if(!\lib\Health\Installer::install()) throw new RuntimeException($name.' first install failed');
        if(!\lib\Health\Installer::isInstalled()) throw new RuntimeException($name.' schema verification failed');
        migration_assert_legacy_row($name);
        migration_assert_defaults($name);
        if(!\lib\Health\Installer::install()) throw new RuntimeException($name.' repeat install failed');
        if(!\lib\Health\Installer::isInstalled()) throw new RuntimeException($name.' repeat schema verification failed');
        migration_assert_legacy_row($name.' repeat');
        $scenarioCount++;
    }
} catch(Throwable $e){
    $failure = $e;
} finally {
    try {
        if($renamed){
            migration_reset_tables();
            $restore = [];
            foreach($backups as $table=>$backup) $restore[] = "`{$backup}` TO `{$table}`";
            migration_exec('RENAME TABLE '.implode(',', $restore));
            migration_exec("DELETE FROM pre_config WHERE k='addon_health_report' OR k LIKE 'health\\_%'");
            foreach($configRows as $row){
                migration_exec('REPLACE INTO pre_config (k,v) VALUES (:k,:v)', [':k'=>$row['k'],':v'=>$row['v']]);
            }
        }
        if(!$hadHealthOrderIndex && $DB->getRow("SHOW INDEX FROM pre_order WHERE Key_name='idx_health_addtime'") !== false){
            migration_exec('ALTER TABLE pre_order DROP KEY idx_health_addtime');
        }
    } catch(Throwable $restoreError){
        $failure = new RuntimeException('Fixture restore failed: '.$restoreError->getMessage(), 0, $failure);
    }
    $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
}

if($failure){
    fwrite(STDERR, 'health schema migration regression failed: '.$failure->getMessage().PHP_EOL);
    exit(1);
}
echo json_encode(['ok'=>true,'scenarios'=>$scenarioCount,'repeat_install'=>true,'legacy_row_preserved'=>true,'fixture_restored'=>true], JSON_UNESCAPED_SLASHES).PHP_EOL;

function migration_exec($sql, $params = [])
{
    global $DB;
    $result = $DB->exec($sql, $params);
    if($result === false) throw new RuntimeException($DB->error() ?: 'SQL execution failed');
    return $result;
}

function migration_reset_tables()
{
    migration_exec('DROP TABLE IF EXISTS pre_health_snapshot,pre_health_report,pre_health_ai_task,pre_telegram_notify_queue');
}

function migration_create_legacy_queue($prefix)
{
    migration_exec("CREATE TABLE pre_telegram_notify_queue (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      uid int(11) NOT NULL DEFAULT '0', scene varchar(50) NOT NULL, param text NOT NULL,
      status tinyint(1) NOT NULL DEFAULT '0', retry_count tinyint(1) NOT NULL DEFAULT '0',
      error_msg varchar(500) DEFAULT NULL, addtime datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      sendtime datetime DEFAULT NULL, PRIMARY KEY (id), KEY idx_uid_status (uid,status),
      KEY idx_status_addtime (status,addtime)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $operations = [
        "ALTER TABLE pre_telegram_notify_queue ADD COLUMN dedupe_key varchar(100) DEFAULT NULL",
        "ALTER TABLE pre_telegram_notify_queue ADD COLUMN claimtime datetime DEFAULT NULL",
        "ALTER TABLE pre_telegram_notify_queue ADD COLUMN sendstarttime datetime DEFAULT NULL",
        "ALTER TABLE pre_telegram_notify_queue ADD COLUMN next_attempt datetime DEFAULT NULL",
        "ALTER TABLE pre_telegram_notify_queue ADD UNIQUE KEY uk_dedupe_key (dedupe_key)",
        "ALTER TABLE pre_telegram_notify_queue ADD KEY idx_queue_ready (status,next_attempt,addtime)",
    ];
    for($i=0;$i<$prefix;$i++) migration_exec($operations[$i]);
}

function migration_assert_legacy_row($scenario)
{
    global $DB;
    $row = $DB->getRow('SELECT * FROM pre_telegram_notify_queue WHERE id=4242');
    if(!$row || intval($row['uid']) !== 1001 || $row['scene'] !== 'order' || $row['param'] !== 'legacy-row' || intval($row['retry_count']) !== 1 || $row['error_msg'] !== 'legacy-error'){
        throw new RuntimeException($scenario.' did not preserve the legacy queue row');
    }
}

function migration_assert_defaults($scenario)
{
    global $DB;
    $expected = [
        'addon_health_report'=>\lib\Health\Installer::VERSION,
        'health_snapshot_enabled'=>'0', 'health_report_enabled'=>'0', 'health_ai_enabled'=>'0',
        'health_report_time'=>'09:05', 'health_snapshot_reconcile_hours'=>'6',
        'health_ai_daily_limit'=>'100',
        'health_ai_payload_mode'=>'compact',
        'health_snapshot_settle_hours'=>'3', 'health_notify_critical_count'=>'20',
        'health_notify_critical_rate'=>'20',
    ];
    foreach($expected as $key=>$value){
        $actual = $DB->getColumn('SELECT v FROM pre_config WHERE k=:k', [':k'=>$key]);
        if((string)$actual !== $value) throw new RuntimeException($scenario.' default mismatch for '.$key);
    }
}
