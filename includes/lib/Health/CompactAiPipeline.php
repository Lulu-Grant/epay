<?php
namespace lib\Health;

class CompactAiPipeline
{
    const VERSION = 'compact-channel-v2';
    const MAX_DRILLDOWN_CHANNELS = 3;
    const MAX_DRILLDOWN_ROWS_PER_CHANNEL = 40;
    const MAX_DRILLDOWN_ROWS_PER_REQUEST = 20;
    const MAX_DRILLDOWN_PAYLOAD_BYTES = 98304;
    const MAX_SYNTHESIS_PAYLOAD_BYTES = 131072;
    const RETRY_SECONDS = 900;
    const RETRY_WINDOW_SECONDS = 21600;

    private $db;
    private $config;
    private $client;
    private $compactor;

    public function __construct($db, $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        $clientConfig = [
            'base_url'=>isset($config['health_ai_base_url']) ? $config['health_ai_base_url'] : '',
            'model'=>isset($config['health_ai_model']) ? $config['health_ai_model'] : '',
            'timeout'=>$this->settingInt('health_ai_timeout', 600, 60, 600),
            'first_byte_timeout'=>$this->settingInt('health_ai_first_byte_timeout', 300, 30, 600),
            'idle_timeout'=>$this->settingInt('health_ai_idle_timeout', 120, 30, 300),
            'max_tokens'=>$this->settingInt('health_ai_max_tokens', 2500, 300, 5000),
            'stream'=>true,
        ];
        if(isset($config['health_ai_transport']) && is_callable($config['health_ai_transport'])) $clientConfig['transport'] = $config['health_ai_transport'];
        $this->client = new AiClient($clientConfig);
        $this->compactor = new EvidenceCompactor();
    }

    public function run($reportId, $dataset)
    {
        $started = microtime(true);
        $calls = 0;
        $requestHashes = [];
        $responseHashes = [];
        $usage = $this->emptyUsage();
        $compacted = $this->compactor->compact($dataset);
        $channelResults = [];

        foreach($compacted['channels'] as $channelId=>$channel){
            $payload = array_merge([
                'pipeline_version'=>self::VERSION,
                'stage'=>'channel_compact',
            ], $channel['payload']);
            $result = $this->call($reportId, 'channel_compact', $channelId, 0, $payload, $this->compactPrompt(), 3000);
            if(empty($result['cached'])) $calls++;
            $this->collectResultStats($result, $requestHashes, $responseHashes, $usage);
            if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage, $compacted);
            $channelResults[$channelId] = $this->validateTaskResult($result, function($data)use($channel){
                return $this->validateChannelResult($data, $channel['visible_refs'], true);
            });
        }

        $drilldownChannels = $this->selectDrilldownChannels($channelResults, $compacted);
        $drilldownRows = 0;
        foreach($drilldownChannels as $channelId){
            $channel = $compacted['channels'][$channelId];
            $resolved = $this->compactor->resolveDrilldown(
                $channel,
                $channelResults[$channelId]['drilldown_requests'],
                self::MAX_DRILLDOWN_ROWS_PER_REQUEST,
                self::MAX_DRILLDOWN_ROWS_PER_CHANNEL
            );
            if($resolved['source_rows'] <= 0) continue;
            $payload = [
                'pipeline_version'=>self::VERSION,
                'stage'=>'channel_drilldown',
                'report_date'=>$dataset['report_date'],
                'channel_id'=>intval($channelId),
                'channel_metrics'=>$channel['payload']['channel_metrics'],
                'previous_analysis'=>$channelResults[$channelId],
                'requested_evidence'=>$channelResults[$channelId]['drilldown_requests'],
                'orders'=>$resolved['orders'],
                'complaints'=>$resolved['complaints'],
            ];
            $this->assertPayloadBytes($payload, self::MAX_DRILLDOWN_PAYLOAD_BYTES, 'AI drilldown payload exceeded size limit');
            $allowed = $channel['visible_refs'];
            foreach($resolved['orders'] as $row) $allowed[$row['_evidence_ref']] = true;
            foreach($resolved['complaints'] as $row) $allowed[$row['_evidence_ref']] = true;
            $result = $this->call($reportId, 'channel_drilldown', $channelId, 0, $payload, $this->drilldownPrompt(), 3000);
            if(empty($result['cached'])) $calls++;
            $this->collectResultStats($result, $requestHashes, $responseHashes, $usage);
            if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage, $compacted, $drilldownRows);
            $channelResults[$channelId] = $this->validateTaskResult($result, function($data)use($allowed){
                return $this->validateChannelResult($data, $allowed, false);
            });
            $drilldownRows += intval($resolved['source_rows']);
        }

        $globalPayload = [
            'pipeline_version'=>self::VERSION,
            'stage'=>'platform_synthesis',
            'report_date'=>$dataset['report_date'],
            'platform_metrics'=>$dataset['metrics']['platform'],
            'hourly_metrics'=>$dataset['metrics']['hourly'],
            'channel_metrics'=>$dataset['metrics']['channels'],
            'merchant_metrics'=>$this->topPlatformMerchants(isset($dataset['metrics']['merchants']) ? $dataset['metrics']['merchants'] : []),
            'seven_day_baseline'=>$dataset['baseline'],
            'complaint_count'=>count($dataset['complaints']),
            'channel_analyses'=>$channelResults,
        ];
        $this->assertPayloadBytes($globalPayload, self::MAX_SYNTHESIS_PAYLOAD_BYTES, 'AI synthesis payload exceeded size limit');
        $result = $this->call($reportId, 'platform_synthesis', '0', 0, $globalPayload, $this->synthesisPrompt(), 5000);
        if(empty($result['cached'])) $calls++;
        $this->collectResultStats($result, $requestHashes, $responseHashes, $usage);
        if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage, $compacted, $drilldownRows);
        $final = $this->validateTaskResult($result, function($data)use($dataset, $channelResults){
            return $this->validateFinalResult($data, $dataset, $channelResults);
        });
        $final['pipeline_version'] = self::VERSION;
        $final['channel_analyses'] = $channelResults;
        $final['cited_evidence'] = $this->compactor->citedCatalog($compacted, $channelResults, $final);
        $final['evidence_stats'] = [
            'source_rows'=>intval($compacted['source_rows']),
            'sample_rows'=>intval($compacted['sample_rows']),
            'drilldown_rows'=>$drilldownRows,
            'compact_payload_bytes'=>$this->compactPayloadBytes($compacted),
        ];

        return [
            'ok'=>true,
            'terminal'=>true,
            'data'=>$final,
            'calls'=>$calls,
            'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            'request_sha256'=>$this->combinedHash($requestHashes),
            'response_sha256'=>$this->combinedHash($responseHashes),
            'usage'=>$usage,
            'sample_rows'=>intval($compacted['sample_rows']),
            'drilldown_rows'=>$drilldownRows,
        ];
    }

    private function call($reportId, $stage, $scopeId, $chunkNo, $payload, $prompt, $maxTokens)
    {
        $limit = $this->settingInt('health_ai_daily_limit', 100, 1, 100);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($payloadJson === false) return ['ok'=>false, 'error'=>'AI compact payload encoding failed'];
        $payloadHash = hash('sha256', $payloadJson."\0".$prompt."\0".(string)(isset($this->config['health_ai_model']) ? $this->config['health_ai_model'] : ''));
        $completed = $this->db->find('health_ai_task', '*', [
            'report_id'=>intval($reportId), 'stage'=>$stage, 'scope_id'=>(string)$scopeId,
            'chunk_no'=>intval($chunkNo), 'status'=>1, 'request_payload_sha256'=>$payloadHash,
        ], 'id DESC', 1);
        if($completed){
            $cachedData = json_decode((string)$completed['result_json'], true);
            if(is_array($cachedData)) return [
                'ok'=>true, 'cached'=>true, 'duration_ms'=>0,
                'request_sha256'=>isset($completed['request_sha256']) ? $completed['request_sha256'] : null,
                'response_sha256'=>isset($completed['response_sha256']) ? $completed['response_sha256'] : null,
                'request_bytes'=>intval(isset($completed['request_bytes']) ? $completed['request_bytes'] : 0),
                'response_bytes'=>intval(isset($completed['response_bytes']) ? $completed['response_bytes'] : 0),
                'input_tokens'=>intval(isset($completed['input_tokens']) ? $completed['input_tokens'] : 0),
                'output_tokens'=>intval(isset($completed['output_tokens']) ? $completed['output_tokens'] : 0),
                'token_source'=>isset($completed['token_source']) ? $completed['token_source'] : 'estimated',
                'data'=>$cachedData, 'task_id'=>intval($completed['id']),
            ];
        }

        $budgetLock = 'epay_health_ai_daily_budget';
        if(!$this->db->getColumn('SELECT GET_LOCK(:name,5)', [':name'=>$budgetLock])) return ['ok'=>false, 'error'=>'AI budget reservation is busy'];
        try {
            $used = $this->db->getColumn("SELECT COUNT(*) FROM pre_health_ai_task WHERE created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)");
            if($used === false) throw new \RuntimeException('AI call budget query failed: '.$this->db->error());
            if(intval($used) >= $limit) return ['ok'=>false, 'error'=>'Daily AI call limit reached', 'budget_exhausted'=>true];
            $taskId = $this->db->insert('health_ai_task', [
                'report_id'=>intval($reportId), 'stage'=>$stage, 'scope_type'=>$stage === 'platform_synthesis' ? 'platform' : 'channel',
                'scope_id'=>(string)$scopeId, 'chunk_no'=>intval($chunkNo), 'status'=>0,
                'source_rows'=>$this->payloadRowCount($payload), 'request_payload_sha256'=>$payloadHash,
                'payload_bytes'=>strlen($payloadJson), 'created_at'=>'NOW()', 'updated_at'=>'NOW()',
            ]);
            if($taskId === false) throw new \RuntimeException('AI task reservation failed: '.$this->db->error());
        } finally {
            $this->db->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$budgetLock]);
        }

        $result = $this->client->completeJson($prompt, $payload, $maxTokens);
        $update = [
            'status'=>!empty($result['ok']) ? 1 : 2,
            'duration_ms'=>isset($result['duration_ms']) ? intval($result['duration_ms']) : null,
            'request_sha256'=>isset($result['request_sha256']) ? $result['request_sha256'] : null,
            'response_sha256'=>isset($result['response_sha256']) ? $result['response_sha256'] : null,
            'request_bytes'=>intval(isset($result['request_bytes']) ? $result['request_bytes'] : 0),
            'response_bytes'=>intval(isset($result['response_bytes']) ? $result['response_bytes'] : 0),
            'input_tokens'=>intval(isset($result['input_tokens']) ? $result['input_tokens'] : 0),
            'output_tokens'=>intval(isset($result['output_tokens']) ? $result['output_tokens'] : 0),
            'token_source'=>isset($result['token_source']) ? $result['token_source'] : 'estimated',
            'result_json'=>!empty($result['ok']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error_message'=>empty($result['ok']) ? mb_substr((string)$result['error'], 0, 500, 'UTF-8') : null,
            'completed_at'=>'NOW()', 'updated_at'=>'NOW()',
        ];
        if($this->db->update('health_ai_task', $update, ['id'=>intval($taskId)]) === false) throw new \RuntimeException('AI task completion write failed: '.$this->db->error());
        $result['task_id'] = intval($taskId);
        return $result;
    }

    private function validateTaskResult($result, $validator)
    {
        try {
            return call_user_func($validator, $result['data']);
        } catch(\Throwable $e){
            if(!empty($result['task_id'])){
                $updated = $this->db->update('health_ai_task', [
                    'status'=>2, 'result_json'=>null,
                    'error_message'=>mb_substr('AI output validation failed: '.$e->getMessage(), 0, 500, 'UTF-8'),
                    'completed_at'=>'NOW()', 'updated_at'=>'NOW()',
                ], ['id'=>intval($result['task_id'])]);
                if($updated === false) throw new \RuntimeException('AI task validation failure write failed: '.$this->db->error(), 0, $e);
            }
            throw $e;
        }
    }

    private function validateChannelResult($data, $allowed, $allowDrilldown)
    {
        if(!is_array($data) || empty($data['summary']) || !isset($data['findings']) || !is_array($data['findings'])){
            throw new \UnexpectedValueException('AI channel result schema is incomplete');
        }
        $findings = [];
        foreach(array_slice($data['findings'], 0, 12) as $finding){
            if(!is_array($finding) || empty($finding['title'])) continue;
            $refs = $this->validatedRefs(isset($finding['evidence_refs']) ? $finding['evidence_refs'] : [], $allowed, 'AI channel result');
            if(!$refs) throw new \UnexpectedValueException('AI channel finding is missing an evidence reference');
            $findings[] = [
                'severity'=>$this->level(isset($finding['severity']) ? $finding['severity'] : 'info'),
                'title'=>$this->text($finding['title'], 160),
                'evidence'=>$this->text(isset($finding['evidence']) ? $finding['evidence'] : '', 500),
                'evidence_refs'=>$refs,
                'likely_cause'=>$this->text(isset($finding['likely_cause']) ? $finding['likely_cause'] : '', 500),
                'action'=>$this->text(isset($finding['action']) ? $finding['action'] : '', 500),
            ];
        }
        $requests = [];
        if($allowDrilldown){
            $requested = isset($data['drilldown_requests']) && is_array($data['drilldown_requests']) ? $data['drilldown_requests'] : [];
            if(count($requested) > 2) throw new \UnexpectedValueException('AI drilldown request count exceeded the supported limit');
            foreach($requested as $request){
                if(!is_array($request) || empty($request['evidence_ref'])) continue;
                $ref = (string)$request['evidence_ref'];
                if(!isset($allowed[$ref])) throw new \UnexpectedValueException('AI drilldown requested an unknown evidence reference');
                $requests[] = ['evidence_ref'=>$ref, 'reason'=>$this->text(isset($request['reason']) ? $request['reason'] : '', 240)];
            }
        }
        return [
            'health_level'=>$this->level(isset($data['health_level']) ? $data['health_level'] : 'unknown'),
            'summary'=>$this->text($data['summary'], 1200),
            'findings'=>$findings,
            'drilldown_requests'=>$requests,
        ];
    }

    private function validateFinalResult($data, $dataset, $channelResults)
    {
        if(!is_array($data) || empty($data['headline']) || empty($data['executive_summary']) || empty($data['telegram_brief'])){
            throw new \UnexpectedValueException('AI final result schema is incomplete');
        }
        $allowed = [];
        foreach($channelResults as $channelResult){
            foreach($channelResult['findings'] as $finding){
                foreach($finding['evidence_refs'] as $ref) $allowed[$ref] = true;
            }
        }
        $findings = [];
        foreach(array_slice(isset($data['findings']) && is_array($data['findings']) ? $data['findings'] : [], 0, 12) as $finding){
            if(!is_array($finding) || empty($finding['title'])) continue;
            $refs = $this->validatedRefs(isset($finding['evidence_refs']) ? $finding['evidence_refs'] : [], $allowed, 'AI final result');
            if(!$refs) throw new \UnexpectedValueException('AI final finding is missing an evidence reference');
            $findings[] = [
                'severity'=>$this->level(isset($finding['severity']) ? $finding['severity'] : 'info'),
                'scope'=>$this->text(isset($finding['scope']) ? $finding['scope'] : 'platform', 80),
                'title'=>$this->text($finding['title'], 160),
                'evidence'=>$this->text(isset($finding['evidence']) ? $finding['evidence'] : '', 700),
                'evidence_refs'=>$refs,
                'likely_cause'=>$this->text(isset($finding['likely_cause']) ? $finding['likely_cause'] : '', 700),
                'action'=>$this->text(isset($finding['action']) ? $finding['action'] : '', 700),
                'confidence'=>$this->text(isset($finding['confidence']) ? $finding['confidence'] : '', 30),
            ];
        }
        return [
            'headline'=>$this->text($data['headline'], 180),
            'health_level'=>$this->level(isset($data['health_level']) ? $data['health_level'] : 'unknown'),
            'executive_summary'=>$this->text($data['executive_summary'], 1800),
            'key_metrics'=>isset($data['key_metrics']) && is_array($data['key_metrics']) ? array_slice($data['key_metrics'], 0, 12) : [],
            'findings'=>$findings,
            'channel_overview'=>isset($data['channel_overview']) && is_array($data['channel_overview']) ? array_slice($data['channel_overview'], 0, 20) : [],
            'telegram_brief'=>$this->text($data['telegram_brief'], 6000),
            'source_row_count'=>count($dataset['orders']),
            'complaint_row_count'=>count($dataset['complaints']),
        ];
    }

    private function validatedRefs($values, $allowed, $label)
    {
        $refs = [];
        foreach(array_slice(is_array($values) ? $values : [], 0, 10) as $ref){
            $ref = (string)$ref;
            if(!isset($allowed[$ref])) throw new \UnexpectedValueException($label.' contains an unknown evidence reference');
            $refs[] = $ref;
        }
        return array_values(array_unique($refs));
    }

    private function selectDrilldownChannels($results, $compacted)
    {
        $candidates = [];
        $rank = ['critical'=>0, 'warning'=>1, 'attention'=>2, 'unknown'=>3, 'healthy'=>4, 'info'=>5];
        foreach($results as $channelId=>$result){
            if(empty($result['drilldown_requests'])) continue;
            $level = isset($rank[$result['health_level']]) ? $rank[$result['health_level']] : 3;
            $total = intval(isset($compacted['channels'][$channelId]['payload']['channel_metrics']['total_orders'])
                ? $compacted['channels'][$channelId]['payload']['channel_metrics']['total_orders'] : 0);
            $candidates[] = ['id'=>(string)$channelId, 'rank'=>$level, 'total'=>$total];
        }
        usort($candidates, function($a, $b){
            if($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
            if($a['total'] !== $b['total']) return $b['total'] <=> $a['total'];
            return intval($a['id']) <=> intval($b['id']);
        });
        return array_column(array_slice($candidates, 0, self::MAX_DRILLDOWN_CHANNELS), 'id');
    }

    private function topPlatformMerchants($merchants)
    {
        $rows = array_values(is_array($merchants) ? $merchants : []);
        usort($rows, function($a, $b){
            $at = intval(isset($a['total_orders']) ? $a['total_orders'] : 0);
            $bt = intval(isset($b['total_orders']) ? $b['total_orders'] : 0);
            if($at !== $bt) return $bt <=> $at;
            return intval(isset($a['uid']) ? $a['uid'] : 0) <=> intval(isset($b['uid']) ? $b['uid'] : 0);
        });
        return array_slice($rows, 0, 20);
    }

    private function compactPrompt()
    {
        return '你是支付平台只读数据分析师。输入是某通道的确定性客观聚合、脱敏样本、投诉摘要和七日对照；聚合层没有提供任何健康判断。请独立识别转化、时段、金额、回调、退款、冻结、投诉和商户聚集异常。不得假定不存在的日志或原因。需要更多逐笔证据时可请求最多2个已出现的证据编号。只输出紧凑JSON，不要Markdown。结构：health_level(healthy/attention/warning/critical/unknown)、summary、findings、drilldown_requests。findings最多5项，每项含severity、title、evidence、evidence_refs、likely_cause、action；evidence_refs只能使用输入中的证据编号。drilldown_requests每项含evidence_ref、reason。';
    }

    private function drilldownPrompt()
    {
        return '你是支付平台只读数据分析师。输入包含通道初次分析及按已校验证据请求返回的少量脱敏逐笔订单。请核实、修正并形成最终通道结论，不得继续请求数据，不得创造事实。只输出紧凑JSON，不要Markdown。结构：health_level、summary、findings；findings最多5项，每项含severity、title、evidence、evidence_refs、likely_cause、action，证据编号只能来自输入。';
    }

    private function synthesisPrompt()
    {
        return '你是支付平台每日健康简报主分析师。输入包含平台、小时、商户、通道客观指标，通道AI分析、投诉计数和七日对照。请自行判断总体健康状态、关键异常、可能原因和排查优先级，并用中文重写完整简报。不要声称已执行修复，不要建议自动切换通道。只输出紧凑JSON，不要Markdown。结构：headline、health_level(healthy/attention/warning/critical/unknown)、executive_summary、key_metrics、findings、channel_overview、telegram_brief。findings最多10项，每项含severity、scope、title、evidence、evidence_refs、likely_cause、action、confidence；evidence_refs必须非空且只能复用通道分析证据。telegram_brief为可直接发送的中文纯文本，列出统计口径、总体结论、重点通道、回调/投诉情况和建议，控制在3500字内。';
    }

    private function payloadRowCount($payload)
    {
        if(isset($payload['orders']) && is_array($payload['orders'])) return count($payload['orders']);
        if(isset($payload['normal_samples']) && is_array($payload['normal_samples'])) return count($payload['normal_samples']);
        return 0;
    }

    private function collectResultStats($result, &$requestHashes, &$responseHashes, &$usage)
    {
        if(!empty($result['request_sha256'])) $requestHashes[] = $result['request_sha256'];
        if(!empty($result['response_sha256'])) $responseHashes[] = $result['response_sha256'];
        foreach(['request_bytes','response_bytes','input_tokens','output_tokens'] as $key){
            $usage[$key] += intval(isset($result[$key]) ? $result[$key] : 0);
        }
        if(isset($result['token_source']) && $result['token_source'] === 'provider') $usage['provider_calls']++;
        else $usage['estimated_calls']++;
    }

    private function pendingResult($error, $started, $calls, $requestHashes, $responseHashes, $usage, $compacted, $drilldownRows = 0)
    {
        return [
            'ok'=>false, 'terminal'=>$error === 'Daily AI call limit reached', 'error'=>$error, 'calls'=>$calls,
            'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            'request_sha256'=>$this->combinedHash($requestHashes),
            'response_sha256'=>$this->combinedHash($responseHashes),
            'usage'=>$usage, 'sample_rows'=>intval($compacted['sample_rows']), 'drilldown_rows'=>intval($drilldownRows),
        ];
    }

    private function emptyUsage()
    {
        return ['request_bytes'=>0, 'response_bytes'=>0, 'input_tokens'=>0, 'output_tokens'=>0, 'provider_calls'=>0, 'estimated_calls'=>0];
    }

    private function compactPayloadBytes($compacted)
    {
        $total = 0;
        foreach($compacted['channels'] as $channel) $total += intval($channel['payload_bytes']);
        return $total;
    }

    private function assertPayloadBytes($payload, $max, $message)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($json === false || strlen($json) > $max) throw new \RuntimeException($message);
    }

    private function combinedHash($values)
    {
        return $values ? hash('sha256', implode("\n", $values)) : null;
    }

    private function level($value)
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['healthy','attention','warning','critical','unknown','info'], true) ? $value : 'unknown';
    }

    private function text($value, $limit)
    {
        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value);
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function settingInt($key, $default, $min, $max)
    {
        return max($min, min($max, isset($this->config[$key]) ? intval($this->config[$key]) : $default));
    }
}
