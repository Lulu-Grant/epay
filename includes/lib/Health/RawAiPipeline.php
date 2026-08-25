<?php
namespace lib\Health;

class RawAiPipeline
{
    const VERSION = 'raw-channel-v1';
    const MAX_ORDERS_PER_CHUNK = 200;
    const MAX_CHUNK_BYTES = 225280;
    const RETRY_SECONDS = 900;
    const RETRY_WINDOW_SECONDS = 21600;

    private $db;
    private $config;
    private $client;

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
    }

    public function run($reportId, $dataset)
    {
        $started = microtime(true);
        $calls = 0;
        $requestHashes = [];
        $responseHashes = [];
        $usage = ['request_bytes'=>0,'response_bytes'=>0,'input_tokens'=>0,'output_tokens'=>0,'provider_calls'=>0,'estimated_calls'=>0];
        $ordersByChannel = [];
        foreach($dataset['orders'] as $order) $ordersByChannel[(string)intval($order['channel'])][] = $order;
        $complaintsByChannel = [];
        foreach($dataset['complaints'] as $complaint) $complaintsByChannel[(string)intval(isset($complaint['channel'])?$complaint['channel']:0)][] = $complaint;

        $channelResults = [];
        foreach($ordersByChannel as $channelId=>$orders){
            $chunks = $this->chunkOrders($orders);
            $chunkResults = [];
            foreach($chunks as $index=>$chunk){
                $chunkComplaints = $this->complaintsForChunk(isset($complaintsByChannel[$channelId])?$complaintsByChannel[$channelId]:[], $chunk, $index);
                $payload = [
                    'pipeline_version'=>self::VERSION,
                    'stage'=>'channel_raw_orders',
                    'report_date'=>$dataset['report_date'],
                    'channel_id'=>intval($channelId),
                    'channel_metrics'=>isset($dataset['metrics']['channels'][$channelId])?$dataset['metrics']['channels'][$channelId]:[],
                    'seven_day_baseline'=>$this->channelBaseline($dataset['baseline'], $channelId),
                    'orders'=>$chunk,
                    'complaints'=>$chunkComplaints,
                ];
                $result = $this->call($reportId, 'channel_chunk', $channelId, $index, $payload, $this->chunkPrompt(), 3000);
                if(empty($result['cached'])) $calls++;
                $this->collectHashes($result, $requestHashes, $responseHashes, $usage);
                if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage);
                $chunkResults[] = $this->validateTaskResult($result,function($data)use($chunk,$chunkComplaints){
                    return $this->validateChannelResult($data,$chunk,$chunkComplaints);
                });
            }
            if(count($chunkResults) === 1){
                $channelResults[$channelId] = $chunkResults[0];
            }else{
                $payload = [
                    'pipeline_version'=>self::VERSION,
                    'stage'=>'channel_reduce',
                    'report_date'=>$dataset['report_date'],
                    'channel_id'=>intval($channelId),
                    'channel_metrics'=>isset($dataset['metrics']['channels'][$channelId])?$dataset['metrics']['channels'][$channelId]:[],
                    'chunk_analyses'=>$chunkResults,
                ];
                $result = $this->call($reportId, 'channel_reduce', $channelId, 0, $payload, $this->reducePrompt(), 3000);
                if(empty($result['cached'])) $calls++;
                $this->collectHashes($result, $requestHashes, $responseHashes, $usage);
                if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage);
                $channelComplaints = isset($complaintsByChannel[$channelId])?$complaintsByChannel[$channelId]:[];
                $channelResults[$channelId] = $this->validateTaskResult($result,function($data)use($orders,$channelComplaints){
                    return $this->validateChannelResult($data,$orders,$channelComplaints);
                });
            }
        }

        $globalPayload = [
            'pipeline_version'=>self::VERSION,
            'stage'=>'platform_synthesis',
            'report_date'=>$dataset['report_date'],
            'platform_metrics'=>$dataset['metrics']['platform'],
            'hourly_metrics'=>$dataset['metrics']['hourly'],
            'channel_metrics'=>$dataset['metrics']['channels'],
            'seven_day_baseline'=>$dataset['baseline'],
            'complaint_count'=>count($dataset['complaints']),
            'channel_analyses'=>$channelResults,
        ];
        $result = $this->call($reportId, 'platform_synthesis', '0', 0, $globalPayload, $this->synthesisPrompt(), 5000);
        if(empty($result['cached'])) $calls++;
        $this->collectHashes($result, $requestHashes, $responseHashes, $usage);
        if(empty($result['ok'])) return $this->pendingResult($result['error'], $started, $calls, $requestHashes, $responseHashes, $usage);
        $final = $this->validateTaskResult($result,function($data)use($dataset,$channelResults){
            return $this->validateFinalResult($data,$dataset,$channelResults);
        });
        $final['pipeline_version'] = self::VERSION;
        $final['channel_analyses'] = $channelResults;
        return [
            'ok'=>true,
            'terminal'=>true,
            'data'=>$final,
            'calls'=>$calls,
            'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            'request_sha256'=>$this->combinedHash($requestHashes),
            'response_sha256'=>$this->combinedHash($responseHashes),
            'usage'=>$usage,
            'sample_rows'=>count($dataset['orders']),
            'drilldown_rows'=>0,
        ];
    }

    private function call($reportId, $stage, $scopeId, $chunkNo, $payload, $prompt, $maxTokens)
    {
        $limit = $this->settingInt('health_ai_daily_limit', 100, 1, 100);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($payloadJson === false) return ['ok'=>false, 'error'=>'AI raw payload encoding failed'];
        $payloadHash = hash('sha256',$payloadJson."\0".$prompt."\0".(string)(isset($this->config['health_ai_model'])?$this->config['health_ai_model']:''));
        $completed = $this->db->find('health_ai_task','*',[
            'report_id'=>intval($reportId), 'stage'=>$stage, 'scope_id'=>(string)$scopeId,
            'chunk_no'=>intval($chunkNo), 'status'=>1, 'request_payload_sha256'=>$payloadHash,
        ],'id DESC',1);
        if($completed){
            $cachedData = json_decode((string)$completed['result_json'],true);
            if(is_array($cachedData)) return [
                'ok'=>true, 'cached'=>true, 'duration_ms'=>0,
                'request_sha256'=>isset($completed['request_sha256'])?$completed['request_sha256']:null,
                'response_sha256'=>isset($completed['response_sha256'])?$completed['response_sha256']:null,
                'request_bytes'=>intval(isset($completed['request_bytes'])?$completed['request_bytes']:0),
                'response_bytes'=>intval(isset($completed['response_bytes'])?$completed['response_bytes']:0),
                'input_tokens'=>intval(isset($completed['input_tokens'])?$completed['input_tokens']:0),
                'output_tokens'=>intval(isset($completed['output_tokens'])?$completed['output_tokens']:0),
                'token_source'=>isset($completed['token_source'])?$completed['token_source']:'estimated',
                'data'=>$cachedData, 'task_id'=>intval($completed['id']),
            ];
        }
        $budgetLock = 'epay_health_ai_daily_budget';
        if(!$this->db->getColumn('SELECT GET_LOCK(:name,5)', [':name'=>$budgetLock])) return ['ok'=>false, 'error'=>'AI budget reservation is busy'];
        try{
            $used = $this->db->getColumn("SELECT COUNT(*) FROM pre_health_ai_task WHERE created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)");
            if($used === false) throw new \RuntimeException('AI call budget query failed: '.$this->db->error());
            if(intval($used) >= $limit) return ['ok'=>false, 'error'=>'Daily AI call limit reached', 'budget_exhausted'=>true];
            $taskId = $this->db->insert('health_ai_task', [
                'report_id'=>intval($reportId), 'stage'=>$stage, 'scope_type'=>$stage==='platform_synthesis'?'platform':'channel',
                'scope_id'=>(string)$scopeId, 'chunk_no'=>intval($chunkNo), 'status'=>0,
                'source_rows'=>$this->payloadRowCount($payload), 'request_payload_sha256'=>$payloadHash,
                'payload_bytes'=>strlen($payloadJson),
                'created_at'=>'NOW()', 'updated_at'=>'NOW()',
            ]);
            if($taskId === false) throw new \RuntimeException('AI task reservation failed: '.$this->db->error());
        }finally{
            $this->db->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$budgetLock]);
        }
        $result = $this->client->completeJson($prompt, $payload, $maxTokens);
        $update = [
            'status'=>!empty($result['ok'])?1:2,
            'duration_ms'=>isset($result['duration_ms'])?intval($result['duration_ms']):null,
            'request_sha256'=>isset($result['request_sha256'])?$result['request_sha256']:null,
            'response_sha256'=>isset($result['response_sha256'])?$result['response_sha256']:null,
            'request_bytes'=>intval(isset($result['request_bytes'])?$result['request_bytes']:0),
            'response_bytes'=>intval(isset($result['response_bytes'])?$result['response_bytes']:0),
            'input_tokens'=>intval(isset($result['input_tokens'])?$result['input_tokens']:0),
            'output_tokens'=>intval(isset($result['output_tokens'])?$result['output_tokens']:0),
            'token_source'=>isset($result['token_source'])?$result['token_source']:'estimated',
            'result_json'=>!empty($result['ok'])?json_encode($result['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
            'error_message'=>empty($result['ok'])?mb_substr((string)$result['error'],0,500,'UTF-8'):null,
            'completed_at'=>'NOW()', 'updated_at'=>'NOW()',
        ];
        if($this->db->update('health_ai_task', $update, ['id'=>intval($taskId)]) === false) throw new \RuntimeException('AI task completion write failed: '.$this->db->error());
        $result['task_id'] = intval($taskId);
        return $result;
    }

    private function validateTaskResult($result, $validator)
    {
        try{
            return call_user_func($validator,$result['data']);
        }catch(\Throwable $e){
            if(!empty($result['task_id'])){
                $updated = $this->db->update('health_ai_task',[
                    'status'=>2, 'result_json'=>null,
                    'error_message'=>mb_substr('AI output validation failed: '.$e->getMessage(),0,500,'UTF-8'),
                    'completed_at'=>'NOW()', 'updated_at'=>'NOW()',
                ],['id'=>intval($result['task_id'])]);
                if($updated === false) throw new \RuntimeException('AI task validation failure write failed: '.$this->db->error(),0,$e);
            }
            throw $e;
        }
    }

    private function chunkOrders($orders)
    {
        $chunks = [];
        $current = [];
        foreach($orders as $order){
            $candidate = $current;
            $candidate[] = $order;
            $encoded = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if($current && (count($candidate) > self::MAX_ORDERS_PER_CHUNK || strlen($encoded) > self::MAX_CHUNK_BYTES)){
                $chunks[] = $current;
                $current = [$order];
            }else $current = $candidate;
        }
        if($current) $chunks[] = $current;
        return $chunks;
    }

    private function validateChannelResult($data, $orders, $complaints = [])
    {
        if(!is_array($data) || empty($data['summary']) || !isset($data['findings']) || !is_array($data['findings'])) throw new \UnexpectedValueException('AI channel result schema is incomplete');
        $allowed = [];
        foreach($orders as $order) if(!empty($order['_evidence_ref'])) $allowed[$order['_evidence_ref']] = true;
        foreach($complaints as $complaint) if(!empty($complaint['_evidence_ref'])) $allowed[$complaint['_evidence_ref']] = true;
        $findings = [];
        foreach(array_slice($data['findings'],0,12) as $finding){
            if(!is_array($finding) || empty($finding['title'])) continue;
            $refs = [];
            foreach(isset($finding['evidence_refs'])&&is_array($finding['evidence_refs'])?$finding['evidence_refs']:[] as $ref){
                if(!isset($allowed[$ref])) throw new \UnexpectedValueException('AI channel result contains an unknown evidence reference');
                $refs[] = $ref;
            }
            if(!$refs) throw new \UnexpectedValueException('AI channel finding is missing an evidence reference');
            $findings[] = [
                'severity'=>$this->level(isset($finding['severity'])?$finding['severity']:'info'),
                'title'=>$this->text($finding['title'],160),
                'evidence'=>$this->text(isset($finding['evidence'])?$finding['evidence']:'',500),
                'evidence_refs'=>array_values(array_unique($refs)),
                'likely_cause'=>$this->text(isset($finding['likely_cause'])?$finding['likely_cause']:'',500),
                'action'=>$this->text(isset($finding['action'])?$finding['action']:'',500),
            ];
        }
        return [
            'health_level'=>$this->level(isset($data['health_level'])?$data['health_level']:'unknown'),
            'summary'=>$this->text($data['summary'],1200),
            'findings'=>$findings,
        ];
    }

    private function validateFinalResult($data, $dataset, $channelResults = [])
    {
        if(!is_array($data) || empty($data['headline']) || empty($data['executive_summary']) || empty($data['telegram_brief'])) throw new \UnexpectedValueException('AI final result schema is incomplete');
        $allowed = [];
        foreach($channelResults as $channelResult){
            foreach(isset($channelResult['findings'])&&is_array($channelResult['findings'])?$channelResult['findings']:[] as $finding){
                foreach(isset($finding['evidence_refs'])&&is_array($finding['evidence_refs'])?$finding['evidence_refs']:[] as $ref) $allowed[$ref] = true;
            }
        }
        $findings = [];
        foreach(isset($data['findings'])&&is_array($data['findings'])?array_slice($data['findings'],0,12):[] as $finding){
            if(!is_array($finding) || empty($finding['title'])) continue;
            $refs = [];
            foreach(isset($finding['evidence_refs'])&&is_array($finding['evidence_refs'])?$finding['evidence_refs']:[] as $ref){
                if(!isset($allowed[$ref])) throw new \UnexpectedValueException('AI final result contains an unknown evidence reference');
                $refs[] = $ref;
            }
            if(!$refs) throw new \UnexpectedValueException('AI final finding is missing an evidence reference');
            $findings[] = [
                'severity'=>$this->level(isset($finding['severity'])?$finding['severity']:'info'),
                'scope'=>$this->text(isset($finding['scope'])?$finding['scope']:'platform',80),
                'title'=>$this->text($finding['title'],160),
                'evidence'=>$this->text(isset($finding['evidence'])?$finding['evidence']:'',700),
                'evidence_refs'=>array_values(array_unique($refs)),
                'likely_cause'=>$this->text(isset($finding['likely_cause'])?$finding['likely_cause']:'',700),
                'action'=>$this->text(isset($finding['action'])?$finding['action']:'',700),
                'confidence'=>$this->text(isset($finding['confidence'])?$finding['confidence']:'',30),
            ];
        }
        return [
            'headline'=>$this->text($data['headline'],180),
            'health_level'=>$this->level(isset($data['health_level'])?$data['health_level']:'unknown'),
            'executive_summary'=>$this->text($data['executive_summary'],1800),
            'key_metrics'=>isset($data['key_metrics'])&&is_array($data['key_metrics'])?array_slice($data['key_metrics'],0,12):[],
            'findings'=>$findings,
            'channel_overview'=>isset($data['channel_overview'])&&is_array($data['channel_overview'])?array_slice($data['channel_overview'],0,20):[],
            'telegram_brief'=>$this->text($data['telegram_brief'],6000),
            'source_row_count'=>count($dataset['orders']),
            'complaint_row_count'=>count($dataset['complaints']),
        ];
    }

    private function pendingResult($error, $started, $calls, $requestHashes, $responseHashes, $usage = [])
    {
        return [
            'ok'=>false, 'terminal'=>$error==='Daily AI call limit reached', 'error'=>$error, 'calls'=>$calls,
            'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            'request_sha256'=>$this->combinedHash($requestHashes), 'response_sha256'=>$this->combinedHash($responseHashes),
            'usage'=>$usage, 'sample_rows'=>0, 'drilldown_rows'=>0,
        ];
    }

    private function chunkPrompt()
    {
        return '你是支付平台只读数据分析师。输入是某支付通道当日逐笔原始订单、关联投诉和七日客观对照，不包含本地规则结论。独立识别转化、时段、金额、回调、退款、冻结、投诉和商户聚集异常。不得假定输入中不存在的日志或原因。只输出紧凑JSON，不要Markdown或额外文字。结构：health_level(healthy/attention/warning/critical/unknown)、summary、findings数组。summary不超过600字；findings最多5项，每项含severity、title、evidence、evidence_refs、likely_cause、action，后三个说明字段各不超过300字。evidence_refs只能使用订单或投诉中的_evidence_ref。';
    }

    private function reducePrompt()
    {
        return '你是支付平台通道分析汇总员。基于同一通道的多个原始订单批次分析和通道客观指标消除重复、处理矛盾并形成通道结论。只输出紧凑JSON，不要Markdown或额外文字。结构：health_level、summary、findings；summary不超过600字；findings最多5项，每项含severity、title、evidence、evidence_refs、likely_cause、action，说明字段各不超过300字。不得创造输入中没有的事实。';
    }

    private function synthesisPrompt()
    {
        return '你是支付平台每日健康简报主分析师。输入包含平台、小时、商户、通道客观指标，逐笔订单分析、投诉和七日对照。请自行判断总体健康状态、关键异常、可能原因和排查优先级，并用中文重写完整简报。不要声称已执行修复，不要建议自动切换通道。只输出紧凑JSON，不要Markdown或额外文字。结构：headline、health_level(healthy/attention/warning/critical/unknown)、executive_summary、key_metrics数组、findings数组、channel_overview数组、telegram_brief。findings最多10项，每项含severity、scope、title、evidence、evidence_refs、likely_cause、action、confidence，evidence_refs必须非空且只能复用通道分析中的 order:/complaint: 引用。telegram_brief必须是可直接发送的中文纯文本，清楚列出统计口径、总体结论、重点通道、回调/投诉情况和建议，控制在3500字内。';
    }

    private function channelBaseline($baseline, $channelId)
    {
        return array_values(array_filter($baseline,function($row)use($channelId){return (string)intval($row['channel'])===(string)intval($channelId);}));
    }

    private function complaintsForChunk($complaints, $orders, $chunkIndex)
    {
        $tradeNos = [];
        foreach($orders as $order) if(!empty($order['trade_no'])) $tradeNos[(string)$order['trade_no']] = true;
        return array_values(array_filter($complaints,function($complaint)use($tradeNos,$chunkIndex){
            $tradeNo = trim((string)(isset($complaint['trade_no'])?$complaint['trade_no']:''));
            return $tradeNo !== '' ? isset($tradeNos[$tradeNo]) : $chunkIndex === 0;
        }));
    }

    private function payloadRowCount($payload)
    {
        return count(isset($payload['orders'])&&is_array($payload['orders'])?$payload['orders']:[]);
    }

    private function collectHashes($result, &$request, &$response, &$usage = null)
    {
        if(!empty($result['request_sha256'])) $request[] = $result['request_sha256'];
        if(!empty($result['response_sha256'])) $response[] = $result['response_sha256'];
        if(is_array($usage)){
            foreach(['request_bytes','response_bytes','input_tokens','output_tokens'] as $key) $usage[$key] += intval(isset($result[$key])?$result[$key]:0);
            if(isset($result['token_source']) && $result['token_source']==='provider') $usage['provider_calls']++;
            else $usage['estimated_calls']++;
        }
    }

    private function combinedHash($values)
    {
        return $values ? hash('sha256', implode("\n",$values)) : null;
    }

    private function level($value)
    {
        $value = strtolower(trim((string)$value));
        return in_array($value,['healthy','attention','warning','critical','unknown','info'],true)?$value:'unknown';
    }

    private function text($value, $limit)
    {
        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',' ',$value);
        return mb_substr($value,0,$limit,'UTF-8');
    }

    private function settingInt($key, $default, $min, $max)
    {
        return max($min,min($max,isset($this->config[$key])?intval($this->config[$key]):$default));
    }
}
