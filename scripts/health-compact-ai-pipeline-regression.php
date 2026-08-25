#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/includes/lib/Health/AiClient.php';
require $root.'/includes/lib/Health/EvidenceCompactor.php';
require $root.'/includes/lib/Health/CompactAiPipeline.php';

class HealthCompactAiFakeDb
{
    public $tasks = [];
    public $used = 0;
    public function getColumn($sql, $bind = [])
    {
        if(strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) return 1;
        return $this->used + count($this->tasks);
    }
    public function insert($table, $data)
    {
        $id = count($this->tasks) + 1;
        $data['id'] = $id;
        $this->tasks[$id] = $data;
        return $id;
    }
    public function update($table, $data, $where)
    {
        $id = intval($where['id']);
        $this->tasks[$id] = array_merge($this->tasks[$id], $data);
        return 1;
    }
    public function find($table, $columns, $where, $order = null, $limit = null)
    {
        foreach(array_reverse($this->tasks, true) as $task){
            $match = true;
            foreach($where as $key=>$value){
                if(!array_key_exists($key, $task) || (string)$task[$key] !== (string)$value){ $match = false; break; }
            }
            if($match) return $task;
        }
        return null;
    }
    public function error(){ return 'fake'; }
}

$failures = [];
$assert = function($condition, $message)use(&$failures){ if(!$condition) $failures[] = $message; };
$env = tempnam(sys_get_temp_dir(), 'epay-compact-ai-');
file_put_contents($env, "HEALTH_AI_API_KEY=test\nHEALTH_AI_ALLOWED_HOSTS=ai-test.example\nHEALTH_AI_ALLOWED_MODELS=model-a\nHEALTH_AI_PINNED_IPV4=ai-test.example=1.1.1.1\n");
putenv('HEALTH_AI_ENV_FILE='.$env);

$orders = [];
for($i=0;$i<500;$i++){
    $status = $i % 10 === 0 ? 2 : ($i % 3 === 0 ? 1 : 0);
    $notify = $i % 37 === 0 ? -1 : 0;
    $tradeNo = sprintf('20260725%011d', $i);
    $orders[] = [
        'trade_no'=>$tradeNo, 'out_trade_no'=>'merchant-'.$i, 'api_trade_no'=>'api-'.$i,
        'uid'=>1001 + ($i % 7), 'tid'=>0, 'type'=>1, 'channel'=>14,
        'name'=>$i % 4 === 0 ? '金币充值套餐' : '会员订阅服务',
        'money'=>number_format(10 + ($i % 1300), 2, '.', ''),
        'realmoney'=>$status === 0 ? null : number_format(10 + ($i % 1300), 2, '.', ''),
        'refundmoney'=>$status === 2 ? '1.00' : null, 'profitmoney'=>'0.10',
        'notify_url'=>'https://merchant.invalid/callback?token=must-not-leak-'.$i,
        'return_url'=>'https://merchant.invalid/return#secret-'.$i,
        'param'=>'{"token":"must-not-leak","memo":"'.str_repeat('x', 120).'"}',
        'ext'=>str_repeat('sensitive-extension-', 20),
        'addtime'=>'2026-07-25 '.sprintf('%02d', $i % 24).':00:00',
        'endtime'=>$status === 0 ? null : '2026-07-25 '.sprintf('%02d', $i % 24).':10:00',
        'ip'=>'198.51.100.'.($i % 20), 'status'=>$status, 'notify'=>$notify,
        '_merchant_group'=>8, '_evidence_ref'=>'order:'.$tradeNo,
    ];
}
$complaints = [[
    'id'=>9, 'trade_no'=>$orders[0]['trade_no'], 'uid'=>1001, 'channel'=>14,
    'type'=>'交易投诉', 'title'=>'未收到商品', 'content'=>'private detail', 'status'=>0,
    'addtime'=>'2026-07-25 12:00:00', '_evidence_ref'=>'complaint:9',
]];
$platformMetric = [
    'total_orders'=>500, 'paid_orders'=>217, 'unpaid_orders'=>283, 'refunded_orders'=>50,
    'frozen_orders'=>0, 'preauth_orders'=>0, 'total_money'=>0, 'paid_money'=>0,
    'notify_total'=>217, 'notify_success'=>203, 'notify_pending'=>0, 'notify_failed'=>14,
    'complaints'=>1, 'success_rate'=>43.4, 'latency'=>['count'=>217,'avg'=>600,'p50'=>600,'p95'=>600],
];
$dataset = [
    'report_date'=>'2026-07-25', 'period_start'=>'2026-07-25 00:00:00', 'period_end'=>'2026-07-26 00:00:00',
    'orders'=>$orders, 'complaints'=>$complaints, 'baseline'=>[],
    'metrics'=>[
        'platform'=>$platformMetric,
        'hourly'=>[],
        'merchants'=>['1001'=>array_merge($platformMetric, ['uid'=>1001])],
        'channels'=>['14'=>array_merge($platformMetric, ['channel_id'=>14,'channel_name'=>'fixture','plugin'=>'alipay'])],
    ],
];

$compactor = new \lib\Health\EvidenceCompactor();
$first = $compactor->compact($dataset);
$second = $compactor->compact($dataset);
$firstJson = json_encode($first['channels']['14']['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$secondJson = json_encode($second['channels']['14']['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(hash('sha256', $firstJson) === hash('sha256', $secondJson), 'compact evidence output is not deterministic');
$assert(strlen($firstJson) <= \lib\Health\EvidenceCompactor::MAX_CHANNEL_PAYLOAD_BYTES, 'compact channel payload exceeded 64 KiB');
$rawBytes = strlen(json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$assert($rawBytes > 0 && (1 - strlen($firstJson) / $rawBytes) >= 0.65, 'compact payload did not reduce raw bytes by at least 65%');
$serialized = $firstJson;
$assert(strpos($serialized, '198.51.100.') === false, 'raw IP leaked into compact payload');
$assert(strpos($serialized, 'must-not-leak') === false, 'secret or URL parameter leaked into compact payload');
$assert(strpos($serialized, 'merchant.invalid') === false, 'callback URL leaked into compact payload');
$assert(($first['channels']['14']['payload']['channel_metrics']['total_orders']??null) === 500, 'channel totals changed during compaction');
$hourTotal = 0;
foreach($first['channels']['14']['payload']['hourly'] as $bucket) $hourTotal += intval($bucket['total_orders']);
$amountTotal = 0;
foreach($first['channels']['14']['payload']['amount_bands'] as $bucket) $amountTotal += intval($bucket['total_orders']);
$assert($hourTotal === 500, 'hourly compact totals do not cover every order');
$assert($amountTotal === 500, 'amount-band compact totals do not cover every order');
$assert(count($first['channels']['14']['payload']['normal_samples']) <= 80, 'normal sample cap was not enforced');
$anomalies = [];
foreach($first['channels']['14']['payload']['anomaly_groups'] as $group) $anomalies[$group['kind']] = $group;
$assert(($anomalies['refunded']['total_orders']??0) === 50, 'refund anomaly coverage is incomplete');
$assert(($anomalies['callback_failed']['total_orders']??0) > 0, 'callback failure anomaly coverage is incomplete');
$assert(($first['channels']['14']['payload']['complaint_summary']['count']??0) === 1, 'complaint coverage is incomplete');
$bounded = $compactor->resolveDrilldown($first['channels']['14'], [
    ['evidence_ref'=>'agg:channel:14:hour:00'],
    ['evidence_ref'=>'agg:channel:14:amount:0-30'],
], 20, 40);
$assert($bounded['source_rows'] <= 40, 'compactor drilldown exceeded the 40-row channel bound');

$stages = [];
$transport = function($request)use(&$stages){
    $outer = json_decode($request['body'], true);
    $payload = json_decode($outer['messages'][1]['content'], true);
    $stage = $payload['stage'];
    $stages[] = $stage;
    if($stage === 'channel_compact'){
        $ref = 'agg:channel:14:anomaly:callback_failed';
        $content = [
            'health_level'=>'warning', 'summary'=>'回调失败需要核对',
            'findings'=>[['severity'=>'warning','title'=>'回调失败','evidence'=>'存在失败订单','evidence_refs'=>[$ref],'likely_cause'=>'待核对','action'=>'检查回调日志']],
            'drilldown_requests'=>[['evidence_ref'=>$ref,'reason'=>'核对失败样本']],
        ];
    }elseif($stage === 'channel_drilldown'){
        $ref = $payload['orders'][0]['_evidence_ref'];
        $content = [
            'health_level'=>'warning', 'summary'=>'逐笔证据确认回调失败',
            'findings'=>[['severity'=>'warning','title'=>'回调失败','evidence'=>'逐笔订单确认','evidence_refs'=>[$ref],'likely_cause'=>'待核对','action'=>'检查商户响应']],
        ];
    }else{
        $finding = $payload['channel_analyses']['14']['findings'][0];
        $content = [
            'headline'=>'支付健康日报', 'health_level'=>'warning', 'executive_summary'=>'存在需要处理的回调失败。',
            'key_metrics'=>[], 'findings'=>[['severity'=>'warning','scope'=>'channel:14','title'=>'回调失败','evidence'=>'逐笔证据确认','evidence_refs'=>$finding['evidence_refs'],'likely_cause'=>'待核对','action'=>'检查商户响应','confidence'=>'high']],
            'channel_overview'=>[], 'telegram_brief'=>"总体结论：需关注\n重点通道：14\n回调与投诉：存在失败回调\n建议：检查商户响应",
        ];
    }
    return [
        'ok'=>true, 'http_code'=>200,
        'response'=>json_encode(['choices'=>[['message'=>['content'=>json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]], 'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'error'=>'',
    ];
};

$db = new HealthCompactAiFakeDb();
$pipeline = new \lib\Health\CompactAiPipeline($db, [
    'health_ai_base_url'=>'https://ai-test.example/v1', 'health_ai_model'=>'model-a',
    'health_ai_daily_limit'=>100, 'health_ai_transport'=>$transport,
]);
$result = $pipeline->run(31, $dataset);
$assert(!empty($result['ok']), 'compact AI pipeline did not complete');
$assert($stages === ['channel_compact','channel_drilldown','platform_synthesis'], 'compact AI stage order is incorrect');
$assert(count($db->tasks) === 3, 'compact pipeline did not use one channel call, one drilldown and one synthesis');
$assert(intval($result['drilldown_rows']) > 0 && intval($result['drilldown_rows']) <= 40, 'drilldown row bound was not enforced');
$assert(($result['usage']['input_tokens']??0) === 300 && ($result['usage']['output_tokens']??0) === 60, 'provider token usage was not aggregated');
$finalRef = $result['data']['findings'][0]['evidence_refs'][0] ?? '';
$assert(strpos($finalRef, 'order:') === 0, 'final evidence did not preserve drilled order reference');
$assert(isset($result['data']['cited_evidence'][$finalRef]), 'cited evidence snapshot was not stored');
$cached = $pipeline->run(31, $dataset);
$assert(!empty($cached['ok']) && intval($cached['calls']) === 0 && count($db->tasks) === 3, 'compact AI cache was not reused');

$unknownRejected = false;
try {
    $method = new ReflectionMethod($pipeline, 'validateChannelResult');
    $method->invoke($pipeline, [
        'health_level'=>'warning','summary'=>'x',
        'findings'=>[['title'=>'x','evidence_refs'=>['order:not-visible']]],
        'drilldown_requests'=>[],
    ], ['order:visible'=>true], true);
} catch(UnexpectedValueException $e){ $unknownRejected = true; }
$assert($unknownRejected, 'unknown compact evidence reference was not rejected');
$tooManyRejected = false;
try {
    $method->invoke($pipeline, [
        'health_level'=>'warning','summary'=>'x','findings'=>[],
        'drilldown_requests'=>[
            ['evidence_ref'=>'order:visible'], ['evidence_ref'=>'order:visible'], ['evidence_ref'=>'order:visible'],
        ],
    ], ['order:visible'=>true], true);
} catch(UnexpectedValueException $e){ $tooManyRejected = true; }
$assert($tooManyRejected, 'excess drilldown requests were not rejected');
$selector = new ReflectionMethod($pipeline, 'selectDrilldownChannels');
$selected = $selector->invoke($pipeline, [
    '1'=>['health_level'=>'attention','drilldown_requests'=>[['evidence_ref'=>'x']]],
    '2'=>['health_level'=>'critical','drilldown_requests'=>[['evidence_ref'=>'x']]],
    '3'=>['health_level'=>'warning','drilldown_requests'=>[['evidence_ref'=>'x']]],
    '4'=>['health_level'=>'critical','drilldown_requests'=>[['evidence_ref'=>'x']]],
], ['channels'=>[
    '1'=>['payload'=>['channel_metrics'=>['total_orders'=>100]]],
    '2'=>['payload'=>['channel_metrics'=>['total_orders'=>10]]],
    '3'=>['payload'=>['channel_metrics'=>['total_orders'=>100]]],
    '4'=>['payload'=>['channel_metrics'=>['total_orders'=>20]]],
]]);
$assert($selected === ['4','2','3'], 'drilldown channel ranking or three-channel cap is incorrect');

putenv('HEALTH_AI_ENV_FILE');
@unlink($env);
if($failures){
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}
echo json_encode([
    'ok'=>true, 'raw_bytes'=>$rawBytes, 'compact_bytes'=>strlen($firstJson),
    'reduction_percent'=>round((1 - strlen($firstJson) / $rawBytes) * 100, 2),
    'calls'=>count($db->tasks), 'drilldown_rows'=>$result['drilldown_rows'],
], JSON_UNESCAPED_SLASHES).PHP_EOL;
