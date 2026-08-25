<?php
namespace lib\Health;

class ReportService
{
    private $db;
    private $config;

    public function __construct($db, $config = [])
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function generateDaily($date, $useAi = false, $allowTerminalRetry = false)
    {
        $this->assertDate($date);
        $payloadMode = $this->payloadMode();
        $lockName = $this->lockName('report_generate');
        if(!$this->db->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])) throw new \RuntimeException('Another health report is being generated');
        try {
            $existing = $this->db->find('health_report', '*', ['report_date'=>$date, 'report_type'=>'daily'], null, 1);
            if($existing && isset($existing['ai_payload_mode']) && in_array($existing['ai_payload_mode'], ['raw','compact'], true)){
                $payloadMode = $existing['ai_payload_mode'];
            }
            $pipelineVersion = $this->pipelineVersion($payloadMode);
            $restartTerminal = false;
            if($existing){
                $existingReport = $this->hydrateReport($existing);
                if(intval(isset($existing['telegram_status'])?$existing['telegram_status']:0) !== 0 || intval($existing['ai_status']) === 1) return $existingReport;
                if(intval($existing['ai_status']) === 5 && isset($existing['ai_pipeline_version']) && $existing['ai_pipeline_version'] === $pipelineVersion){
                    if(!$allowTerminalRetry) return $existingReport;
                    $restartTerminal = true;
                }
                if(intval($existing['ai_status']) === 4 && !empty($existing['ai_retry_until']) && strtotime($existing['ai_retry_until']) <= time()){
                    if($this->db->update('health_report',['ai_status'=>5,'ai_error'=>'AI retry window expired','ai_next_retry_at'=>null,'updated_at'=>'NOW()'],['id'=>intval($existing['id'])]) === false) throw new \RuntimeException('Health report retry expiry update failed: '.$this->db->error());
                    return $this->hydrateReport($this->db->find('health_report','*',['id'=>intval($existing['id'])],null,1));
                }
                if(intval($existing['ai_status']) === 4 && !empty($existing['ai_next_retry_at']) && strtotime($existing['ai_next_retry_at']) > time()) return $existingReport;
            }

            $dataset = (new RawDataService($this->db))->collect($date);
            $metrics = $dataset['metrics'];
            $rules = ['engine'=>'disabled','level'=>'unknown','summary'=>'本报告未运行本地规则诊断，结论由AI根据客观订单证据生成。','issues'=>[]];
            $aiEnabled = $useAi && !empty($this->config['health_ai_enabled']);
            $record = [
                'report_date'=>$date, 'report_type'=>'daily', 'period_start'=>$dataset['period_start'], 'period_end'=>$dataset['period_end'],
                'health_level'=>'unknown', 'metrics_json'=>$this->json($metrics), 'rules_json'=>$this->json($rules),
                'ai_status'=>$aiEnabled?4:3, 'ai_provider'=>isset($this->config['health_ai_provider'])?$this->config['health_ai_provider']:'openai_compatible',
                'ai_model'=>isset($this->config['health_ai_model'])?$this->config['health_ai_model']:null,
                'ai_error'=>$aiEnabled?'AI order evidence analysis is in progress':'AI analysis is disabled',
                'ai_attempted_at'=>$aiEnabled?'NOW()':null, 'ai_source_rows'=>count($dataset['orders']),
                'ai_sample_rows'=>0, 'ai_drilldown_rows'=>0, 'ai_payload_mode'=>$payloadMode,
                'ai_pipeline_version'=>$pipelineVersion, 'updated_at'=>'NOW()',
            ];
            if($existing){
                $id = intval($existing['id']);
                if($restartTerminal || empty($existing['ai_started_at'])) $record['ai_started_at'] = 'NOW()';
                if($restartTerminal || empty($existing['ai_retry_until'])) $record['ai_retry_until'] = date('Y-m-d H:i:s', time()+$this->retryWindowSeconds($payloadMode));
                if($restartTerminal){ $record['ai_completed_at']=null; $record['ai_next_retry_at']=null; }
                if($this->db->update('health_report',$record,['id'=>$id]) === false) throw new \RuntimeException('Health report reservation update failed: '.$this->db->error());
            }else{
                $record['ai_started_at'] = $aiEnabled?'NOW()':null;
                $record['ai_retry_until'] = $aiEnabled?date('Y-m-d H:i:s', time()+$this->retryWindowSeconds($payloadMode)):null;
                $record['created_at'] = 'NOW()';
                $id = $this->db->insert('health_report',$record);
                if($id === false) throw new \RuntimeException('Health report reservation failed: '.$this->db->error());
            }
            if(!$aiEnabled) return $this->hydrateReport($this->db->find('health_report','*',['id'=>intval($id)],null,1));

            try {
                $result = $this->createPipeline($payloadMode)->run($id,$dataset);
            } catch(\Throwable $e){
                error_log('Health AI pipeline error: '.$e->getMessage());
                $message = $e instanceof \UnexpectedValueException ? 'AI output validation failed: '.$e->getMessage() : 'AI raw-data analysis failed before completion';
                $result = ['ok'=>false,'terminal'=>false,'error'=>$message,'calls'=>0,'duration_ms'=>null,'request_sha256'=>null,'response_sha256'=>null];
            }
            $stored = $this->db->find('health_report','*',['id'=>intval($id)],null,1);
            $taskCalls = $this->db->getColumn('SELECT COUNT(*) FROM pre_health_ai_task WHERE report_id=:report_id',[':report_id'=>intval($id)]);
            if($taskCalls === false) throw new \RuntimeException('Health report AI call count failed: '.$this->db->error());
            $totalCalls = intval($taskCalls);
            if(!empty($result['ok'])){
                $aiData = $result['data'];
                $level = isset($aiData['health_level'])?$aiData['health_level']:'unknown';
                $rules['level'] = $level;
                $rules['summary'] = '本地规则诊断已关闭；本结论由AI分析客观订单证据生成。';
                $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : [];
                $final = [
                    'health_level'=>$level, 'rules_json'=>$this->json($rules), 'ai_status'=>1,
                    'ai_json'=>$this->json($aiData), 'ai_error'=>null, 'ai_duration_ms'=>$result['duration_ms'],
                    'ai_request_sha256'=>$result['request_sha256'], 'ai_response_sha256'=>$result['response_sha256'],
                    'ai_sample_rows'=>intval(isset($result['sample_rows'])?$result['sample_rows']:0),
                    'ai_drilldown_rows'=>intval(isset($result['drilldown_rows'])?$result['drilldown_rows']:0),
                    'ai_request_bytes'=>intval(isset($usage['request_bytes'])?$usage['request_bytes']:0),
                    'ai_response_bytes'=>intval(isset($usage['response_bytes'])?$usage['response_bytes']:0),
                    'ai_input_tokens'=>intval(isset($usage['input_tokens'])?$usage['input_tokens']:0),
                    'ai_output_tokens'=>intval(isset($usage['output_tokens'])?$usage['output_tokens']:0),
                    'ai_token_source'=>$this->tokenSource($usage),
                    'ai_calls'=>$totalCalls, 'ai_completed_at'=>'NOW()', 'ai_next_retry_at'=>null, 'updated_at'=>'NOW()',
                ];
            }else{
                $retryExpired = !empty($stored['ai_retry_until']) && strtotime($stored['ai_retry_until']) <= time();
                $terminal = !empty($result['terminal']) || $retryExpired;
                $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : [];
                $final = [
                    'ai_status'=>$terminal?5:4, 'ai_error'=>mb_substr((string)$result['error'],0,500,'UTF-8'),
                    'ai_duration_ms'=>$result['duration_ms'], 'ai_request_sha256'=>$result['request_sha256'],
                    'ai_response_sha256'=>$result['response_sha256'], 'ai_calls'=>$totalCalls,
                    'ai_sample_rows'=>intval(isset($result['sample_rows'])?$result['sample_rows']:0),
                    'ai_drilldown_rows'=>intval(isset($result['drilldown_rows'])?$result['drilldown_rows']:0),
                    'ai_request_bytes'=>intval(isset($usage['request_bytes'])?$usage['request_bytes']:0),
                    'ai_response_bytes'=>intval(isset($usage['response_bytes'])?$usage['response_bytes']:0),
                    'ai_input_tokens'=>intval(isset($usage['input_tokens'])?$usage['input_tokens']:0),
                    'ai_output_tokens'=>intval(isset($usage['output_tokens'])?$usage['output_tokens']:0),
                    'ai_token_source'=>$this->tokenSource($usage),
                    'ai_next_retry_at'=>$terminal?null:date('Y-m-d H:i:s', time()+$this->retrySeconds($payloadMode)), 'updated_at'=>'NOW()',
                ];
            }
            if($this->db->update('health_report',$final,['id'=>intval($id)]) === false) throw new \RuntimeException('Health report AI finalization failed: '.$this->db->error());
            return $this->hydrateReport($this->db->find('health_report','*',['id'=>intval($id)],null,1));
        } finally {
            $this->db->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
        }
    }

    public function previewDaily($date)
    {
        $this->assertDate($date);
        return $this->buildDailyEvaluation($date);
    }

    public function enqueueTelegram($report)
    {
        if(empty($this->config['health_report_enabled'])) return ['ok'=>false, 'message'=>'每日简报已停用，不能新加入发送队列'];
        $reportId = intval(isset($report['id']) ? $report['id'] : 0);
        if($reportId <= 0) return ['ok'=>false, 'message'=>'Report does not exist'];
        if(intval(isset($report['ai_status'])?$report['ai_status']:0) !== 1 || empty($report['ai'])) return ['ok'=>false, 'message'=>'AI原始数据分析尚未完成，暂不发送'];
        $destination = trim(isset($this->config['health_report_chat_id']) ? (string)$this->config['health_report_chat_id'] : '');
        if($destination === '') $destination = trim(isset($this->config['telegram_admin_chat_id']) ? (string)$this->config['telegram_admin_chat_id'] : '');
        if(!preg_match('/^-?[0-9]{5,20}$/D', $destination)) return ['ok'=>false, 'message'=>'Telegram recipient is not configured'];
        if(!$this->db->beginTransaction()) throw new \RuntimeException('Report queue transaction failed to start');
        try {
            $row = $this->db->getRow('SELECT * FROM pre_health_report WHERE id=:id FOR UPDATE', [':id'=>$reportId]);
            if(!$row){
                $this->db->rollBack();
                return ['ok'=>false, 'message'=>'Report does not exist'];
            }
            if(intval($row['telegram_status']) === 2){
                $this->commitQueueTransaction();
                return ['ok'=>true, 'message'=>'报告已送达', 'queue_id'=>$row['telegram_queue_id']];
            }
            if(intval($row['telegram_status']) === 1){
                $state = $this->reconcileTelegramState($row);
                $this->commitQueueTransaction();
                return $state;
            }
            if(intval($row['telegram_status']) === 3){
                $this->commitQueueTransaction();
                return ['ok'=>false, 'message'=>'上次发送失败或送达不确定，请先人工复核'];
            }

            $storedReport = $this->hydrateReport($row);
            $rendered = (new TelegramRenderer())->render($storedReport);
            $digest = hash('sha256', $rendered['html']."\n".$rendered['plain']);
            $dedupeKey = 'daily_health:'.$reportId.':'.$digest;
            $queueId = \lib\Telegram\QueueHelper::addToQueue('daily_health', 0, [
                'html'=>$rendered['html'],
                'plain'=>$rendered['plain'],
                'report_id'=>$reportId,
                'chat_id'=>$destination,
            ], true, $dedupeKey);
            if(!$queueId){
                $this->db->rollBack();
                return ['ok'=>false, 'message'=>'Telegram queue rejected the report'];
            }
            $queueRow = $this->db->getRow('SELECT status,sendtime FROM pre_telegram_notify_queue WHERE id=:id FOR UPDATE', [':id'=>intval($queueId)]);
            if(!$queueRow){
                $this->db->rollBack();
                return ['ok'=>false, 'message'=>'Telegram queue state could not be verified'];
            }
            $queueStatus = intval($queueRow['status']);
            if($queueStatus === 1){
                $updated = $this->db->update('health_report', [
                    'telegram_status'=>2,
                    'telegram_queue_id'=>intval($queueId),
                    'sent_at'=>$queueRow['sendtime'],
                ], ['id'=>$reportId]);
                if($updated === false) throw new \RuntimeException('Report sent status update failed: '.$this->db->error());
                $this->commitQueueTransaction();
                return ['ok'=>true, 'message'=>'报告已送达', 'queue_id'=>intval($queueId)];
            }
            if($queueStatus === 2){
                $updated = $this->db->update('health_report', ['telegram_status'=>3, 'telegram_queue_id'=>intval($queueId)], ['id'=>$reportId]);
                if($updated === false) throw new \RuntimeException('Report failure status update failed: '.$this->db->error());
                $this->commitQueueTransaction();
                return ['ok'=>false, 'message'=>'上次队列任务已终止，需要人工复核'];
            }
            if(!in_array($queueStatus, [0,3], true)){
                $this->db->rollBack();
                return ['ok'=>false, 'message'=>'Telegram queue is in an unsupported state'];
            }
            $updated = $this->db->update('health_report', ['telegram_status'=>1, 'telegram_queue_id'=>intval($queueId)], ['id'=>$reportId]);
            if($updated === false) throw new \RuntimeException('Report queue status update failed: '.$this->db->error());
            $this->commitQueueTransaction();
            return ['ok'=>true, 'message'=>'已加入发送队列（尚未送达）', 'queue_id'=>intval($queueId)];
        } catch(\Throwable $e){
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cleanReports($days)
    {
        $days = max(30, min(3650, intval($days)));
        return $this->db->exec("DELETE FROM pre_health_report WHERE telegram_status<>1 AND report_date<DATE_SUB(CURDATE(),INTERVAL {$days} DAY)");
    }

    private function aggregateSnapshots($start, $end)
    {
        $rows = $this->db->getAll(
            "SELECT period_start,period_end,metrics_json FROM pre_health_snapshot
             WHERE scope_type='platform' AND metrics_version=:version AND period_start>=:start AND period_end<=:end
             ORDER BY period_start ASC",
            [':version'=>MetricsService::VERSION, ':start'=>$start, ':end'=>$end]
        );
        if($rows === false) throw new \RuntimeException('Snapshot aggregation query failed: '.$this->db->error());
        if(!$rows) return null;
        $result = null;
        $coveredHours = [];
        foreach($rows as $row){
            $bundle = json_decode($row['metrics_json'], true);
            if(!$this->validSnapshotRow($row, $bundle, $start, $end) || isset($coveredHours[$row['period_start']])){
                $result = $result === null ? $this->emptyAggregate($start, $end) : $result;
                $result['data_complete'] = false;
                continue;
            }
            $coveredHours[$row['period_start']] = true;
            $result = $result === null ? $this->emptyAggregate($start, $end) : $result;
            $this->mergeMetric($result['platform'], $bundle['platform']);
            foreach(isset($bundle['channels']) ? $bundle['channels'] : [] as $id => $channel){
                if(!isset($result['channels'][$id])) $result['channels'][$id] = $this->emptyChannel($channel);
                $this->updateChannelMetadata($result['channels'][$id], $channel);
                $this->mergeMetric($result['channels'][$id], $channel);
                $this->mergeAmountBands($result['channels'][$id], $channel);
                $this->mergeStreak($result['channels'][$id], $channel);
            }
            if(empty($bundle['data_complete'])) $result['data_complete'] = false;
            $generatedAt = !empty($bundle['generated_at']) ? strtotime($bundle['generated_at']) : false;
            if($generatedAt !== false && (empty($result['snapshot_finalized_at']) || $generatedAt > strtotime($result['snapshot_finalized_at']))){
                $result['snapshot_finalized_at'] = date('Y-m-d H:i:s', $generatedAt);
            }
            $settledAt = strtotime($row['period_end']) + $this->settingInt('health_snapshot_settle_hours', 3) * 3600;
            if($generatedAt === false || $generatedAt < $settledAt) $result['data_complete'] = false;
        }
        if($result === null) return null;
        $expectedHours = max(1, (int)((strtotime($end) - strtotime($start)) / 3600));
        for($time = strtotime($start); $time < strtotime($end); $time += 3600){
            if(empty($coveredHours[date('Y-m-d H:i:s', $time)])){
                $result['data_complete'] = false;
                break;
            }
        }
        $result['snapshot_hours'] = count($coveredHours);
        $result['expected_snapshot_hours'] = $expectedHours;
        $this->finalizeMetric($result['platform']);
        foreach($result['channels'] as &$channel) $this->finalizeMetric($channel);
        unset($channel);
        return $result;
    }

    private function buildDailyEvaluation($date)
    {
        $dataset = (new RawDataService($this->db))->collect($date);
        return [
            'start'=>$dataset['period_start'], 'end'=>$dataset['period_end'], 'metrics'=>$dataset['metrics'],
            'baseline'=>$dataset['baseline'],
            'rules'=>['engine'=>'disabled','level'=>'unknown','summary'=>'本地规则分析已关闭；预览仅展示原始数据统计。','issues'=>[]],
        ];
    }

    private function emptyAggregate($start, $end)
    {
        return ['version'=>MetricsService::VERSION, 'period_start'=>$start, 'period_end'=>$end,
            'generated_at'=>date('Y-m-d H:i:s'), 'snapshot_finalized_at'=>null, 'source'=>'hourly_snapshots',
            'platform'=>[], 'channels'=>[], 'merchants'=>[], 'data_complete'=>true, 'merchant_data_complete'=>true];
    }

    private function emptyChannel($source)
    {
        return [
            'channel_id'=>intval(isset($source['channel_id']) ? $source['channel_id'] : 0),
            'channel_name'=>isset($source['channel_name']) ? $source['channel_name'] : '',
            'plugin'=>isset($source['plugin']) ? $source['plugin'] : '',
            'channel_status'=>intval(isset($source['channel_status']) ? $source['channel_status'] : 0),
            'daystatus'=>intval(isset($source['daystatus']) ? $source['daystatus'] : 0),
            'paymin'=>isset($source['paymin']) ? $source['paymin'] : null,
            'paymax'=>isset($source['paymax']) ? $source['paymax'] : null,
            'failure_streak'=>0,
            'failure_streak_max'=>0,
            'streak'=>['count'=>0, 'leading'=>0, 'trailing'=>0, 'max'=>0, 'all_unpaid'=>false],
            'amount_bands'=>[],
        ];
    }

    private function updateChannelMetadata(&$target, $source)
    {
        foreach(['channel_name','plugin','channel_status','daystatus','paymin','paymax'] as $field){
            if(array_key_exists($field, $source)) $target[$field] = $source[$field];
        }
    }

    private function mergeMetric(&$target, $source)
    {
        $sumFields = ['total_orders','paid_orders','unpaid_orders','refunded_orders','frozen_orders','preauth_orders',
            'total_money','paid_money','profit_money','notify_total','notify_success','notify_pending','notify_failed'];
        foreach($sumFields as $field){
            $target[$field] = (isset($target[$field]) ? $target[$field] : 0) + (isset($source[$field]) ? $source[$field] : 0);
        }
        if(!empty($source['last_success_time']) && (empty($target['last_success_time']) || $source['last_success_time'] > $target['last_success_time'])){
            $target['last_success_time'] = $source['last_success_time'];
        }
        if(!empty($source['notify_oldest_time']) && (empty($target['notify_oldest_time']) || $source['notify_oldest_time'] < $target['notify_oldest_time'])){
            $target['notify_oldest_time'] = $source['notify_oldest_time'];
        }
        if(isset($source['latency']) && intval($source['latency']['count']) > 0){
            if(!isset($target['_latency'])) $target['_latency'] = ['count'=>0, 'weighted'=>0];
            $count = intval($source['latency']['count']);
            $target['_latency']['count'] += $count;
            $target['_latency']['weighted'] += (float)$source['latency']['avg'] * $count;
        }
    }

    private function mergeStreak(&$target, $source)
    {
        $incoming = isset($source['streak']) && is_array($source['streak']) ? $source['streak'] : [
            'count'=>intval(isset($source['total_orders']) ? $source['total_orders'] : 0),
            'leading'=>intval(isset($source['failure_streak']) ? $source['failure_streak'] : 0),
            'trailing'=>intval(isset($source['failure_streak']) ? $source['failure_streak'] : 0),
            'max'=>intval(isset($source['failure_streak']) ? $source['failure_streak'] : 0),
            'all_unpaid'=>intval(isset($source['paid_orders']) ? $source['paid_orders'] : 0) === 0,
        ];
        if(intval($incoming['count']) === 0) return;
        $current = isset($target['streak']) && is_array($target['streak']) ? $target['streak'] : ['count'=>0,'leading'=>0,'trailing'=>0,'max'=>0,'all_unpaid'=>false];
        $cross = intval($current['trailing']) + intval($incoming['leading']);
        $combined = [
            'count'=>intval($current['count']) + intval($incoming['count']),
            'leading'=>(intval($current['count']) === 0 || !empty($current['all_unpaid'])) ? intval($current['count']) + intval($incoming['leading']) : intval($current['leading']),
            'trailing'=>!empty($incoming['all_unpaid']) ? intval($current['trailing']) + intval($incoming['count']) : intval($incoming['trailing']),
            'max'=>max(intval($current['max']), intval($incoming['max']), $cross),
            'all_unpaid'=>(intval($current['count']) === 0 || !empty($current['all_unpaid'])) && !empty($incoming['all_unpaid']),
        ];
        $target['streak'] = $combined;
        $target['failure_streak'] = $combined['trailing'];
        $target['failure_streak_max'] = $combined['max'];
    }

    private function mergeAmountBands(&$target, $source)
    {
        foreach(isset($source['amount_bands']) ? $source['amount_bands'] : [] as $name => $band){
            if(!isset($target['amount_bands'][$name])) $target['amount_bands'][$name] = ['total'=>0, 'paid'=>0];
            $target['amount_bands'][$name]['total'] += intval($band['total']);
            $target['amount_bands'][$name]['paid'] += intval($band['paid']);
        }
    }

    private function finalizeMetric(&$target)
    {
        $total = intval(isset($target['total_orders']) ? $target['total_orders'] : 0);
        $paid = intval(isset($target['paid_orders']) ? $target['paid_orders'] : 0);
        $target['success_rate'] = $total > 0 ? round($paid * 100 / $total, 2) : null;
        if(isset($target['_latency']) && $target['_latency']['count'] > 0){
            $count = $target['_latency']['count'];
            $target['latency'] = ['count'=>$count, 'avg'=>round($target['_latency']['weighted'] / $count, 2),
                'p50'=>null, 'p95'=>null, 'percentiles_available'=>false];
            unset($target['_latency']);
        }else $target['latency'] = ['count'=>0, 'avg'=>null, 'p50'=>null, 'p95'=>null, 'percentiles_available'=>false];
        foreach(isset($target['amount_bands']) ? $target['amount_bands'] : [] as &$band){
            $band['success_rate'] = $band['total'] > 0 ? round($band['paid'] * 100 / $band['total'], 2) : null;
        }
        unset($band);
    }

    private function validSnapshotRow($row, $bundle, $windowStart, $windowEnd)
    {
        if(!is_array($bundle) || !isset($bundle['platform'])) return false;
        foreach(['period_start','period_end'] as $field){
            if(empty($row[$field]) || empty($bundle[$field]) || $row[$field] !== $bundle[$field]) return false;
            $value = \DateTime::createFromFormat('Y-m-d H:i:s', $row[$field]);
            if(!$value || $value->format('Y-m-d H:i:s') !== $row[$field]) return false;
        }
        $start = strtotime($row['period_start']);
        $end = strtotime($row['period_end']);
        if($start === false || $end === false || $end - $start !== 3600) return false;
        if(date('i:s', $start) !== '00:00' || date('i:s', $end) !== '00:00') return false;
        return $start >= strtotime($windowStart) && $end <= strtotime($windowEnd);
    }

    private function refreshNotificationMetrics(&$metrics, $start, $end)
    {
        $fresh = (new MetricsService($this->db))->collectNotificationMetrics($start, $end);
        $fields = ['notify_total','notify_success','notify_pending','notify_failed','notify_oldest_time'];
        if(!isset($metrics['platform']) || !is_array($metrics['platform'])) $metrics['platform'] = [];
        foreach($fields as $field){
            $metrics['platform'][$field] = array_key_exists($field, $fresh['platform']) ? $fresh['platform'][$field] : ($field === 'notify_oldest_time' ? null : 0);
        }
        if(!isset($metrics['channels']) || !is_array($metrics['channels'])) $metrics['channels'] = [];
        foreach($metrics['channels'] as $id => &$channel){
            $source = isset($fresh['channels'][(string)$id]) ? $fresh['channels'][(string)$id] : [];
            foreach($fields as $field){
                $channel[$field] = array_key_exists($field, $source) ? $source[$field] : ($field === 'notify_oldest_time' ? null : 0);
            }
        }
        unset($channel);
        $metrics['notification_as_of'] = $fresh['as_of'];
    }

    private function settingInt($key, $default)
    {
        return isset($this->config[$key]) ? intval($this->config[$key]) : $default;
    }

    private function settingFloat($key, $default)
    {
        return isset($this->config[$key]) ? floatval($this->config[$key]) : $default;
    }

    private function classificationAiError($reason)
    {
        $messages = [
            'data_incomplete'=>'Current snapshot is incomplete; AI analysis was skipped',
            'baseline_missing'=>'Seven-day baseline is incomplete; AI analysis was skipped',
            'no_traffic'=>'No orders were observed; AI analysis was skipped',
            'sample_insufficient'=>'Order sample is below the external analysis threshold',
            'unknown'=>'Health classification is unknown; AI analysis was skipped',
        ];
        return isset($messages[$reason]) ? $messages[$reason] : $messages['unknown'];
    }

    private function json($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($json === false) throw new \RuntimeException('Report JSON encoding failed');
        return $json;
    }

    private function assertDate($date)
    {
        $value = \DateTime::createFromFormat('Y-m-d', $date);
        if(!$value || $value->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Invalid report date');
        if($date >= date('Y-m-d')) throw new \InvalidArgumentException('Only completed calendar days can be reported');
    }

    private function hydrateReport($row)
    {
        $metrics = json_decode(isset($row['metrics_json']) ? $row['metrics_json'] : '', true);
        $rules = json_decode(isset($row['rules_json']) ? $row['rules_json'] : '', true);
        $ai = !empty($row['ai_json']) ? json_decode($row['ai_json'], true) : null;
        if(!is_array($metrics) || !is_array($rules)) throw new \RuntimeException('Stored health report is invalid');
        return [
            'id'=>intval($row['id']),
            'report_date'=>$row['report_date'],
            'metrics'=>$metrics,
            'rules'=>$rules,
            'ai'=>is_array($ai) ? $ai : null,
            'ai_status'=>intval($row['ai_status']),
            'ai_error'=>$row['ai_error'],
            'ai_duration_ms'=>$row['ai_duration_ms'] === null ? null : intval($row['ai_duration_ms']),
            'ai_request_sha256'=>isset($row['ai_request_sha256']) ? $row['ai_request_sha256'] : null,
            'ai_response_sha256'=>isset($row['ai_response_sha256']) ? $row['ai_response_sha256'] : null,
            'ai_calls'=>intval(isset($row['ai_calls'])?$row['ai_calls']:0),
            'ai_source_rows'=>intval(isset($row['ai_source_rows'])?$row['ai_source_rows']:0),
            'ai_sample_rows'=>intval(isset($row['ai_sample_rows'])?$row['ai_sample_rows']:0),
            'ai_drilldown_rows'=>intval(isset($row['ai_drilldown_rows'])?$row['ai_drilldown_rows']:0),
            'ai_payload_mode'=>isset($row['ai_payload_mode'])?$row['ai_payload_mode']:'compact',
            'ai_request_bytes'=>intval(isset($row['ai_request_bytes'])?$row['ai_request_bytes']:0),
            'ai_response_bytes'=>intval(isset($row['ai_response_bytes'])?$row['ai_response_bytes']:0),
            'ai_input_tokens'=>intval(isset($row['ai_input_tokens'])?$row['ai_input_tokens']:0),
            'ai_output_tokens'=>intval(isset($row['ai_output_tokens'])?$row['ai_output_tokens']:0),
            'ai_token_source'=>isset($row['ai_token_source'])?$row['ai_token_source']:null,
            'ai_pipeline_version'=>isset($row['ai_pipeline_version'])?$row['ai_pipeline_version']:null,
            'ai_next_retry_at'=>isset($row['ai_next_retry_at'])?$row['ai_next_retry_at']:null,
        ];
    }

    private function lockName($suffix)
    {
        $prefix = defined('DBQZ') ? DBQZ : 'pre';
        return 'epay_'.substr(hash('sha256', $prefix.':health:'.$suffix), 0, 24);
    }

    private function payloadMode()
    {
        return isset($this->config['health_ai_payload_mode']) && $this->config['health_ai_payload_mode'] === 'raw' ? 'raw' : 'compact';
    }

    private function pipelineVersion($mode = null)
    {
        $mode = $mode === null ? $this->payloadMode() : $mode;
        return $mode === 'compact' ? CompactAiPipeline::VERSION : RawAiPipeline::VERSION;
    }

    private function createPipeline($mode = null)
    {
        $mode = $mode === null ? $this->payloadMode() : $mode;
        return $mode === 'compact'
            ? new CompactAiPipeline($this->db, $this->config)
            : new RawAiPipeline($this->db, $this->config);
    }

    private function retrySeconds($mode = null)
    {
        $mode = $mode === null ? $this->payloadMode() : $mode;
        return $mode === 'compact' ? CompactAiPipeline::RETRY_SECONDS : RawAiPipeline::RETRY_SECONDS;
    }

    private function retryWindowSeconds($mode = null)
    {
        $mode = $mode === null ? $this->payloadMode() : $mode;
        return $mode === 'compact' ? CompactAiPipeline::RETRY_WINDOW_SECONDS : RawAiPipeline::RETRY_WINDOW_SECONDS;
    }

    private function tokenSource($usage)
    {
        $provider = intval(isset($usage['provider_calls']) ? $usage['provider_calls'] : 0);
        $estimated = intval(isset($usage['estimated_calls']) ? $usage['estimated_calls'] : 0);
        if($provider > 0 && $estimated > 0) return 'mixed';
        if($provider > 0) return 'provider';
        return $estimated > 0 ? 'estimated' : null;
    }

    private function commitQueueTransaction()
    {
        if(!$this->db->commit()) throw new \RuntimeException('Report queue transaction commit failed');
    }

    private function reconcileTelegramState($reportRow)
    {
        $queueId = intval(isset($reportRow['telegram_queue_id']) ? $reportRow['telegram_queue_id'] : 0);
        if($queueId <= 0){
            $updated = $this->db->update('health_report', ['telegram_status'=>3], ['id'=>intval($reportRow['id'])]);
            if($updated === false) throw new \RuntimeException('Missing queue state reconciliation failed: '.$this->db->error());
            return ['ok'=>false, 'message'=>'Queued report has no queue record; manual review is required', 'queue_id'=>null];
        }
        $queue = $this->db->getRow('SELECT status,sendtime FROM pre_telegram_notify_queue WHERE id=:id FOR UPDATE', [':id'=>$queueId]);
        if(!$queue){
            $updated = $this->db->update('health_report', ['telegram_status'=>3], ['id'=>intval($reportRow['id'])]);
            if($updated === false) throw new \RuntimeException('Missing queue row reconciliation failed: '.$this->db->error());
            return ['ok'=>false, 'message'=>'Queue record is missing; manual review is required', 'queue_id'=>$queueId];
        }
        $queueStatus = intval($queue['status']);
        if($queueStatus === 1){
            $updated = $this->db->update('health_report', ['telegram_status'=>2, 'sent_at'=>$queue['sendtime']], ['id'=>intval($reportRow['id'])]);
            if($updated === false) throw new \RuntimeException('Sent queue reconciliation failed: '.$this->db->error());
            return ['ok'=>true, 'message'=>'Report was already sent', 'queue_id'=>$queueId];
        }
        if($queueStatus === 2){
            $updated = $this->db->update('health_report', ['telegram_status'=>3], ['id'=>intval($reportRow['id'])]);
            if($updated === false) throw new \RuntimeException('Failed queue reconciliation failed: '.$this->db->error());
            return ['ok'=>false, 'message'=>'Queue attempt is terminal; manual review is required', 'queue_id'=>$queueId];
        }
        if(in_array($queueStatus, [0,3], true)) return ['ok'=>true, 'message'=>'Report is already queued', 'queue_id'=>$queueId];
        $updated = $this->db->update('health_report', ['telegram_status'=>3], ['id'=>intval($reportRow['id'])]);
        if($updated === false) throw new \RuntimeException('Unsupported queue state reconciliation failed: '.$this->db->error());
        return ['ok'=>false, 'message'=>'Queue state is unsupported; manual review is required', 'queue_id'=>$queueId];
    }
}
