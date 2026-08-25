#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

require dirname(__DIR__).'/includes/lib/Health/AiClient.php';

$baseUrl = trim((string)getenv('HEALTH_AI_BASE_URL'));
$model = trim((string)getenv('HEALTH_AI_MODEL'));
if($baseUrl === '' || $model === ''){
    fwrite(STDERR, "HEALTH_AI_BASE_URL and HEALTH_AI_MODEL are required\n");
    exit(1);
}

$metrics = [
    'period_start'=>'2026-07-18 00:00:00',
    'period_end'=>'2026-07-19 00:00:00',
    'data_complete'=>true,
    'platform'=>[
        'total_orders'=>100,
        'paid_orders'=>60,
        'unpaid_orders'=>40,
        'refunded_orders'=>0,
        'frozen_orders'=>0,
        'preauth_orders'=>0,
        'notify_total'=>60,
        'notify_success'=>59,
        'notify_pending'=>0,
        'notify_failed'=>1,
        'success_rate'=>60.0,
    ],
    'channels'=>[],
    'merchants'=>[],
];
$rules = [
    'level'=>'warning',
    'issues'=>[fetch_smoke_issue()],
];

$client = new \lib\Health\AiClient([
    'base_url'=>$baseUrl,
    'model'=>$model,
    'timeout'=>30,
    'max_tokens'=>600,
    'min_sample'=>10,
]);
$result = $client->analyze($metrics, $rules);
if(empty($result['ok'])){
    fwrite(STDERR, 'AI smoke failed: '.(isset($result['error']) ? $result['error'] : 'unknown error').PHP_EOL);
    exit(1);
}
$findings = isset($result['data']['findings']) && is_array($result['data']['findings']) ? $result['data']['findings'] : [];
if(count($findings) !== 1 || $findings[0]['rule_code'] !== 'notify_failed' || $findings[0]['scope'] !== 'platform'){
    fwrite(STDERR, "AI smoke returned an ungrounded selection\n");
    exit(1);
}
if($findings[0]['action'] !== '核对商户通知队列、响应码与重试日志，并保持回调处理幂等。'){
    fwrite(STDERR, "AI smoke did not use the local playbook\n");
    exit(1);
}

echo json_encode(['ok'=>true, 'duration_ms'=>$result['duration_ms'], 'finding_count'=>count($findings),
    'rule_code'=>$findings[0]['rule_code'], 'scope'=>$findings[0]['scope'], 'local_playbook'=>true,
    'request_sha256'=>$result['request_sha256'], 'response_sha256'=>$result['response_sha256']], JSON_UNESCAPED_SLASHES).PHP_EOL;

function fetch_smoke_issue()
{
    return [
        'level'=>'warning',
        'code'=>'notify_failed',
        'scope'=>'platform',
        'message'=>'平台存在最终通知失败',
        'evidence'=>['notify_total'=>60,'notify_failed'=>1,'notify_failed_rate'=>1.67],
    ];
}
