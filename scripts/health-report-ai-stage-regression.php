#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$expectFailure = false;
foreach(array_slice($argv, 1) as $argument){
    if($argument === '--expect-ai-failure' && !$expectFailure) $expectFailure = true;
    else { fwrite(STDERR, "Unsupported argument\n"); exit(1); }
}

$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/health-test-bootstrap.php';
if(!health_test_bootstrap()) require dirname(__DIR__).'/includes/common.php';

$database = (string)$DB->getColumn('SELECT DATABASE()');
if(!preg_match('/(?:_local|_test)$/D', $database)){
    fwrite(STDERR, "Refusing to run against a non-test database\n");
    exit(1);
}
$baseUrl = trim((string)getenv('HEALTH_AI_BASE_URL'));
$model = trim((string)getenv('HEALTH_AI_MODEL'));
if($baseUrl === '' || $model === ''){
    fwrite(STDERR, "HEALTH_AI_BASE_URL and HEALTH_AI_MODEL are required\n");
    exit(1);
}

$date = '2001-01-01';
$start = $date.' 00:00:00';
$end = '2001-01-02 00:00:00';
$baselineStart = date('Y-m-d H:i:s', strtotime($start.' -7 days'));
$failure = null;
$fixtureTradeNo = '2001010100000000001';
try {
    $DB->exec("DELETE FROM pre_health_report WHERE report_date=:date AND report_type='daily'", [':date'=>$date]);
    $DB->exec("DELETE FROM pre_health_snapshot WHERE metrics_version=:version AND period_start>=:start AND period_end<=:end",
        [':version'=>\lib\Health\MetricsService::VERSION, ':start'=>$baselineStart, ':end'=>$end]);
    $DB->exec("DELETE FROM pre_order WHERE trade_no=:trade_no", [':trade_no'=>$fixtureTradeNo]);
    $fixtureOrder = $DB->insert('order', [
        'trade_no'=>$fixtureTradeNo,'out_trade_no'=>'health-ai-stage-fixture','uid'=>1000,'tid'=>0,'type'=>1,'channel'=>14,
        'name'=>'health-stage-fixture','money'=>'1.00','realmoney'=>'1.00','getmoney'=>'1.00','profitmoney'=>'0.00',
        'notify_url'=>'https://merchant.invalid/notify','return_url'=>'https://merchant.invalid/return',
        'addtime'=>'2001-01-01 23:00:00','endtime'=>'2001-01-01 23:01:00','date'=>'2001-01-01',
        'status'=>1,'notify'=>-1,
    ]);
    if($fixtureOrder === false) throw new RuntimeException('Synthetic callback order write failed');
    for($hour = 0; $hour < 192; $hour++){
        $periodStart = date('Y-m-d H:i:s', strtotime($baselineStart) + $hour * 3600);
        $periodEnd = date('Y-m-d H:i:s', strtotime($periodStart) + 3600);
        $notifyFailed = $periodStart === '2001-01-01 23:00:00' ? 1 : 0;
        $bundle = [
            'version'=>\lib\Health\MetricsService::VERSION,
            'period_start'=>$periodStart,
            'period_end'=>$periodEnd,
            'generated_at'=>date('Y-m-d H:i:s'),
            'platform'=>[
                'total_orders'=>10,'paid_orders'=>6,'unpaid_orders'=>4,'refunded_orders'=>0,'frozen_orders'=>0,'preauth_orders'=>0,
                'total_money'=>100,'paid_money'=>60,'profit_money'=>1,'notify_total'=>6,'notify_success'=>6 - $notifyFailed,
                'notify_pending'=>0,'notify_failed'=>$notifyFailed,'last_success_time'=>$periodEnd,
                'latency'=>['count'=>6,'avg'=>5,'p50'=>5,'p95'=>5],
            ],
            'channels'=>[],
            'merchants'=>[],
            'merchant_data_complete'=>true,
            'data_complete'=>true,
        ];
        $written = $DB->exec("REPLACE INTO pre_health_snapshot
            (snapshot_time,period_start,period_end,scope_type,scope_id,metrics_json,metrics_version,created_at)
            VALUES(:snapshot,:start,:end,'platform','0',:metrics,:version,NOW())", [
                ':snapshot'=>$periodEnd, ':start'=>$periodStart, ':end'=>$periodEnd,
                ':metrics'=>json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':version'=>\lib\Health\MetricsService::VERSION,
            ]);
        if($written === false) throw new RuntimeException('Synthetic snapshot write failed');
    }

    $localConfig = $conf;
    $localConfig['health_ai_enabled'] = '1';
    $localConfig['health_ai_base_url'] = $baseUrl;
    $localConfig['health_ai_model'] = $model;
    $localConfig['health_ai_daily_limit'] = '10';
    $localConfig['health_ai_min_sample'] = '10';
    $service = new \lib\Health\ReportService($DB, $localConfig);
    $ruleOnly = $service->generateDaily($date, false);
    if(empty($ruleOnly['metrics']['data_complete']) || intval($ruleOnly['ai_status']) !== 0) throw new RuntimeException('Rule-only stage was not created');
    $ruleOnlyAgain = $service->generateDaily($date, false);
    if(intval($ruleOnlyAgain['id']) !== intval($ruleOnly['id']) || intval($ruleOnlyAgain['ai_status']) !== 0 || !empty($ruleOnlyAgain['ai'])){
        throw new RuntimeException('Rule-only regeneration unexpectedly invoked AI');
    }
    $enhanced = $service->generateDaily($date, true);
    if(intval($enhanced['id']) !== intval($ruleOnly['id'])) throw new RuntimeException('AI stage created a second report');
    $expectedStatus = $expectFailure ? 2 : 1;
    if(intval($enhanced['ai_status']) !== $expectedStatus || (!$expectFailure && empty($enhanced['ai']['findings']))){
        throw new RuntimeException('AI stage produced an unexpected result (status='.intval($enhanced['ai_status']).', error='.(isset($enhanced['ai_error'])?$enhanced['ai_error']:'none').')');
    }
    $storedAudit = $DB->getRow('SELECT ai_request_sha256,ai_response_sha256 FROM pre_health_report WHERE id=:id', [':id'=>intval($enhanced['id'])]);
    if(!$storedAudit || !preg_match('/^[a-f0-9]{64}$/D', (string)$storedAudit['ai_request_sha256'])
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$storedAudit['ai_response_sha256'])){
        throw new RuntimeException('AI request and response digests were not persisted');
    }
    $again = $service->generateDaily($date, true);
    if(intval($again['id']) !== intval($enhanced['id']) || intval($again['ai_status']) !== $expectedStatus) throw new RuntimeException('Completed AI stage was not immutable');
    if($expectFailure){
        if(!preg_match('/^AI HTTP [45][0-9]{2}$/D', (string)$enhanced['ai_error'])) throw new RuntimeException('AI authentication failure was not classified as an HTTP error');
        echo json_encode([
            'ok'=>true, 'report_id_stable'=>true, 'ai_status'=>2, 'retry_suppressed'=>true,
            'error_class'=>'provider_http_error', 'request_sha256'=>$storedAudit['ai_request_sha256'],
            'response_sha256'=>$storedAudit['ai_response_sha256'],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    }else{
        echo json_encode(['ok'=>true, 'report_id_stable'=>true, 'ai_status'=>1,
            'finding_count'=>count($enhanced['ai']['findings'])], JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
} catch(Throwable $e){
    $failure = $e;
} finally {
    $DB->exec("DELETE FROM pre_health_report WHERE report_date=:date AND report_type='daily'", [':date'=>$date]);
    $DB->exec("DELETE FROM pre_health_snapshot WHERE metrics_version=:version AND period_start>=:start AND period_end<=:end",
        [':version'=>\lib\Health\MetricsService::VERSION, ':start'=>$baselineStart, ':end'=>$end]);
    $DB->exec("DELETE FROM pre_order WHERE trade_no=:trade_no", [':trade_no'=>$fixtureTradeNo]);
}
if($failure){
    fwrite(STDERR, 'health report staged AI regression failed: '.$failure->getMessage().PHP_EOL);
    exit(1);
}
