#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);
$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/health-test-bootstrap.php';
$explicitTestBootstrap = health_test_bootstrap();
if(!$explicitTestBootstrap) require dirname(__DIR__).'/includes/common.php';

$database = isset($dbconfig['dbname']) ? (string)$dbconfig['dbname'] : '';
if(!preg_match('/(?:_local|_test)$/D', $database)){
    fwrite(STDERR, "health UI fixture refuses a database without a _local or _test suffix\n");
    exit(1);
}
if((!$explicitTestBootstrap && (!\lib\Telegram\Installer::install() || !\lib\Health\Installer::install())) || !\lib\Health\Installer::isInstalled()){
    fwrite(STDERR, "health UI fixture schema preparation failed\n");
    exit(1);
}

$dates = [
    'overview'=>date('Y-m-d', strtotime('-1 day')),
    'rule'=>date('Y-m-d', strtotime('-2 days')),
    'ai_degraded'=>date('Y-m-d', strtotime('-3 days')),
    'ai_budget'=>date('Y-m-d', strtotime('-4 days')),
    'ai_incomplete'=>date('Y-m-d', strtotime('-20 days')),
    'delivered'=>date('Y-m-d', strtotime('-13 days')),
    'failure'=>date('Y-m-d', strtotime('-14 days')),
    'uncertain'=>date('Y-m-d', strtotime('-15 days')),
    'missing_id'=>date('Y-m-d', strtotime('-16 days')),
    'missing_row'=>date('Y-m-d', strtotime('-17 days')),
    'unsupported'=>date('Y-m-d', strtotime('-18 days')),
    'review_healthy'=>'2002-01-08',
    'review_degraded'=>'2002-01-17',
    'review_incomplete'=>'2002-01-26',
    'review_baseline_missing'=>'2002-02-04',
    'review_sample_insufficient'=>'2002-02-13',
];
$failure = null;
try {
    if(!$DB->beginTransaction()) throw new RuntimeException('fixture transaction could not start');
    $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE scene='daily_health'");
    foreach($dates as $date) $DB->exec("DELETE FROM pre_health_report WHERE report_date=:date AND report_type='daily'", [':date'=>$date]);
    $snapshotStart=date('Y-m-d 00:00:00',strtotime('-11 days'));
    $snapshotEnd=date('Y-m-d 00:00:00');
    $DB->exec("DELETE FROM pre_health_snapshot WHERE metrics_version=:version AND period_start>=:start AND period_end<=:end", [
        ':version'=>\lib\Health\MetricsService::VERSION, ':start'=>$snapshotStart, ':end'=>$snapshotEnd,
    ]);
    for($time=strtotime($snapshotStart);$time<strtotime($snapshotEnd);$time+=3600){
        $start=date('Y-m-d H:i:s',$time);$end=date('Y-m-d H:i:s',$time+3600);
        $bundle=health_fixture_snapshot($start,$end);
        $written=$DB->insert('health_snapshot',[
            'snapshot_time'=>$end,'period_start'=>$start,'period_end'=>$end,'scope_type'=>'platform','scope_id'=>'0',
            'metrics_json'=>json_encode($bundle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'metrics_version'=>\lib\Health\MetricsService::VERSION,'created_at'=>'NOW()',
        ]);
        if($written===false) throw new RuntimeException('fixture snapshot write failed');
    }
    health_fixture_insert_report($DB,$dates['overview'],1,0,null,null);
    $deliveredId=health_fixture_queue($DB,'delivered',0);
    $DB->update('telegram_notify_queue',['status'=>1,'next_attempt'=>null,'sendtime'=>$dates['delivered'].' 09:06:00'],['id'=>$deliveredId]);
    health_fixture_insert_report($DB,$dates['delivered'],1,2,$deliveredId,$dates['delivered'].' 09:06:00');
    $conf['addon_health_report']=\lib\Health\Installer::VERSION;
    $conf['health_report_enabled']='0';
    $conf['telegram_notice']='1';
    $conf['telegram_bot_token']='fixture-token';
    $conf['telegram_admin_chat_id']='123456789';
    $failureId=health_fixture_queue($DB,'failure',2);
    health_fixture_insert_report($DB,$dates['failure'],2,1,$failureId,null);
    $deterministicBot=new HealthFixtureBot(false,'Telegram rejected the fixture message format');
    \lib\Telegram\QueueHelper::processQueue(1,$deterministicBot);
    health_fixture_assert_terminal($DB,$failureId,false);
    $uncertainId=health_fixture_queue($DB,'uncertain',0);
    health_fixture_insert_report($DB,$dates['uncertain'],2,1,$uncertainId,null);
    $uncertainBot=new HealthFixtureBot(true,'Delivery state unknown after fixture timeout');
    \lib\Telegram\QueueHelper::processQueue(1,$uncertainBot);
    health_fixture_assert_terminal($DB,$uncertainId,true);
    health_fixture_insert_report($DB,$dates['missing_id'],2,3,null,null);
    health_fixture_insert_report($DB,$dates['missing_row'],2,3,987654321,null);
    $unsupportedId=health_fixture_queue($DB,'unsupported',0);
    $DB->update('telegram_notify_queue',['status'=>9],['id'=>$unsupportedId]);
    health_fixture_insert_report($DB,$dates['unsupported'],2,3,$unsupportedId,null);
    if(!$DB->commit()) throw new RuntimeException('fixture transaction commit failed');
} catch(Throwable $e){
    $DB->rollBack();
    $failure=$e;
}
if($failure){fwrite(STDERR,'health UI fixture data failed: '.$failure->getMessage().PHP_EOL);exit(1);}

try {
    health_fixture_prepare_review_reports($DB, $conf, $dates);
} catch(Throwable $e){
    fwrite(STDERR,'health UI review fixture failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

$settings = [
    'health_snapshot_enabled'=>'1','health_report_enabled'=>'1','health_ai_enabled'=>'1',
    'health_ai_base_url'=>'https://api.example.invalid/v1','health_ai_model'=>'fixture-model',
    'health_ai_daily_limit'=>'1','health_ai_min_sample'=>'5','health_report_chat_id'=>'123456789',
    'health_min_sample'=>'30','health_attention_drop'=>'5','health_warning_drop'=>'10','health_failure_streak'=>'10',
    'health_stale_hours'=>'6','health_notify_critical_count'=>'20','health_notify_critical_rate'=>'20',
    'telegram_notice'=>'1','telegram_bot_token'=>'fixture-token','telegram_admin_chat_id'=>'123456789',
];
foreach($settings as $key=>$value) if(saveSetting($key,$value)===false){fwrite(STDERR,"health UI fixture setting preparation failed\n");exit(1);}
if(isset($CACHE) && $CACHE->clear() === false){fwrite(STDERR,"health UI fixture cache clear failed\n");exit(1);}
echo json_encode(['ok'=>true,'dates'=>$dates],JSON_UNESCAPED_SLASHES).PHP_EOL;

function health_fixture_prepare_review_reports($DB, $config, $dates)
{
    $start = '2002-01-01 00:00:00';
    $end = '2002-02-14 00:00:00';
    if(!$DB->beginTransaction()) throw new RuntimeException('review fixture transaction could not start');
    try {
        $DB->exec("DELETE FROM pre_health_snapshot WHERE metrics_version=:version AND period_start>=:start AND period_end<=:end", [
            ':version'=>\lib\Health\MetricsService::VERSION, ':start'=>$start, ':end'=>$end,
        ]);
		foreach(['review_healthy','review_degraded','review_incomplete','review_baseline_missing','review_sample_insufficient'] as $key){
			$DB->exec("DELETE FROM pre_health_report WHERE report_date=:date AND report_type='daily'", [':date'=>$dates[$key]]);
		}
		$DB->exec("DELETE FROM pre_order WHERE out_trade_no LIKE 'health-ui-notify-%'");
		health_fixture_seed_review_scenario($DB,$dates['review_healthy'],168,24,10,9,10,9);
		health_fixture_seed_review_scenario($DB,$dates['review_degraded'],168,24,10,9,10,6);
		health_fixture_seed_review_scenario($DB,$dates['review_incomplete'],168,18,10,9,10,9);
		health_fixture_insert_failed_notification_order($DB,$dates['review_incomplete']);
		health_fixture_seed_review_scenario($DB,$dates['review_baseline_missing'],167,24,10,9,10,9);
		health_fixture_seed_review_scenario($DB,$dates['review_sample_insufficient'],168,24,10,9,1,1,1);
        if(!$DB->commit()) throw new RuntimeException('review fixture transaction commit failed');
    } catch(Throwable $e){
        $DB->rollBack();
        throw $e;
    }

    $reviewConfig = $config;
    $reviewConfig['health_ai_enabled'] = '1';
    $reviewConfig['health_ai_min_sample'] = '5';
    $reviewConfig['health_min_sample'] = '30';
    $reviewConfig['health_attention_drop'] = '5';
    $reviewConfig['health_warning_drop'] = '10';
    $reviewConfig['health_failure_streak'] = '10';
    $reviewConfig['health_stale_hours'] = '6';
    $reviewConfig['health_notify_critical_count'] = '20';
    $reviewConfig['health_notify_critical_rate'] = '20';
    $service = new \lib\Health\ReportService($DB, $reviewConfig);
    $expectations = [
		'review_healthy'=>['healthy',[],false],
		'review_degraded'=>['warning',['success_rate_drop'],false],
		'review_incomplete'=>['critical',['data_incomplete','notify_failed'],true],
		'review_baseline_missing'=>['unknown',['baseline_missing'],true],
		'review_sample_insufficient'=>['warning',['sample_insufficient','frozen_orders'],true],
    ];
    foreach($expectations as $key=>$expected){
        $report = $service->generateDaily($dates[$key], $expected[2]);
        $codes = array_column($report['rules']['issues'], 'code');
		$missingCodes = array_diff($expected[1], $codes);
		if($report['rules']['level'] !== $expected[0] || $missingCodes){
            throw new RuntimeException('review report did not match '.$key);
        }
        if($expected[2] && intval($report['ai_status']) !== 3) throw new RuntimeException('review report AI skip was not persisted for '.$key);
    }
}

function health_fixture_seed_review_scenario($DB, $date, $baselineHours, $currentHours, $baselineTotal, $baselinePaid, $currentTotal, $currentPaid, $currentFrozen = 0)
{
    $dateStart = strtotime($date.' 00:00:00');
    $baselineStart = $dateStart - 7 * 86400;
    for($hour=0;$hour<intval($baselineHours);$hour++){
        health_fixture_insert_review_snapshot($DB,$baselineStart+$hour*3600,$baselineTotal,$baselinePaid);
	}
	for($hour=0;$hour<intval($currentHours);$hour++){
		health_fixture_insert_review_snapshot($DB,$dateStart+$hour*3600,$currentTotal,$currentPaid,$currentFrozen);
	}
}

function health_fixture_insert_review_snapshot($DB, $startTime, $total, $paid, $frozen = 0)
{
    $start = date('Y-m-d H:i:s',$startTime);
    $end = date('Y-m-d H:i:s',$startTime+3600);
    $bundle = [
        'version'=>\lib\Health\MetricsService::VERSION,'period_start'=>$start,'period_end'=>$end,
        'generated_at'=>date('Y-m-d H:i:s',$startTime+4*3600+10*60),
		'platform'=>[
			'total_orders'=>intval($total),'paid_orders'=>intval($paid),'unpaid_orders'=>max(0,intval($total)-intval($paid)),
			'refunded_orders'=>0,'frozen_orders'=>intval($frozen),'preauth_orders'=>0,'total_money'=>intval($total)*10,
			'paid_money'=>intval($paid)*10,'profit_money'=>0,'notify_total'=>0,'notify_success'=>0,'notify_pending'=>0,
			'notify_failed'=>0,'notify_oldest_time'=>null,'last_success_time'=>intval($paid)>0?$end:null,
			'latency'=>['count'=>intval($paid),'avg'=>5,'p50'=>5,'p95'=>5],
		],
        'channels'=>[],'merchants'=>[],'merchant_data_complete'=>true,'data_complete'=>true,
    ];
    $written=$DB->insert('health_snapshot',[
        'snapshot_time'=>$end,'period_start'=>$start,'period_end'=>$end,'scope_type'=>'platform','scope_id'=>'0',
        'metrics_json'=>json_encode($bundle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'metrics_version'=>\lib\Health\MetricsService::VERSION,'created_at'=>'NOW()',
    ]);
	if($written===false) throw new RuntimeException('review snapshot write failed');
}

function health_fixture_insert_failed_notification_order($DB, $date)
{
	$tradeNo = 'HFUI'.date('ymd', strtotime($date)).'000000001';
	$written = $DB->insert('order', [
		'trade_no'=>$tradeNo,
		'out_trade_no'=>'health-ui-notify-'.str_replace('-', '', $date).'-1',
		'uid'=>0,
		'tid'=>0,
		'type'=>0,
		'channel'=>0,
		'name'=>'health ui notification fixture',
		'money'=>'10.00',
		'realmoney'=>'10.00',
		'notify_url'=>'https://merchant.example.invalid/notify',
		'addtime'=>$date.' 12:00:00',
		'endtime'=>$date.' 12:01:00',
		'date'=>$date,
		'status'=>1,
		'notify'=>-1,
	]);
	if($written===false) throw new RuntimeException('review notification order write failed');
}

function health_fixture_snapshot($start,$end)
{
    return [
        'version'=>\lib\Health\MetricsService::VERSION,'period_start'=>$start,'period_end'=>$end,'generated_at'=>date('Y-m-d H:i:s'),
        'platform'=>['total_orders'=>10,'paid_orders'=>6,'unpaid_orders'=>4,'refunded_orders'=>0,'frozen_orders'=>1,'preauth_orders'=>0,
            'total_money'=>100,'paid_money'=>60,'profit_money'=>1,'notify_total'=>0,'notify_success'=>0,'notify_pending'=>0,'notify_failed'=>0,
            'notify_oldest_time'=>null,'last_success_time'=>$end,'latency'=>['count'=>6,'avg'=>5,'p50'=>5,'p95'=>5]],
        'channels'=>[14=>['channel_id'=>14,'channel_name'=>'优选 H5','channel_status'=>1,'total_orders'=>10,'paid_orders'=>6,'unpaid_orders'=>4,
            'refunded_orders'=>0,'frozen_orders'=>1,'preauth_orders'=>0,'total_money'=>100,'paid_money'=>60,'profit_money'=>1,
            'notify_total'=>0,'notify_success'=>0,'notify_pending'=>0,'notify_failed'=>0,'notify_oldest_time'=>null,'last_success_time'=>$end,
            'failure_streak'=>2,'failure_streak_max'=>3,'latency'=>['count'=>6,'avg'=>5,'p50'=>5,'p95'=>5],
            'amount_bands'=>['30_100'=>['total'=>10,'paid'=>6,'success_rate'=>60]]]],
        'merchants'=>[],'merchant_data_complete'=>true,'data_complete'=>true,
    ];
}

function health_fixture_insert_report($DB,$date,$aiStatus,$telegramStatus,$queueId,$sentAt)
{
    $metrics=health_fixture_overview_metrics($date);
    $rules=['level'=>'warning','summary'=>'存在告警，共发现2项规则命中。','issues'=>[
        ['code'=>'frozen_orders','scope'=>'platform','level'=>'warning','message'=>'平台存在2笔冻结订单','evidence'=>['frozen_orders'=>2,'paid_orders'=>7802]],
        ['code'=>'success_rate_drop','scope'=>'channel:14','level'=>'attention','message'=>'通道14（优选 H5）曾支付成功率较基线下降6.2个百分点','evidence'=>['current_rate'=>63,'baseline_rate'=>69.2,'sample'=>100]],
    ]];
    $ai=$aiStatus===1?['findings'=>[['rule_code'=>'frozen_orders','scope'=>'platform','title'=>'平台存在2笔冻结订单','evidence'=>'frozen_orders=2, paid_orders=7802','action'=>'核对冻结订单明细、冻结原因及相关通道状态。']]]:null;
    $id=$DB->insert('health_report',[
        'report_date'=>$date,'report_type'=>'daily','period_start'=>$metrics['period_start'],'period_end'=>$metrics['period_end'],'health_level'=>'warning',
        'metrics_json'=>json_encode($metrics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'rules_json'=>json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'ai_status'=>$aiStatus,'ai_provider'=>'openai_compatible','ai_model'=>'fixture-model','ai_duration_ms'=>$aiStatus===1?142:null,
        'ai_json'=>$ai===null?null:json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'ai_error'=>null,'ai_attempted_at'=>$aiStatus===1?$date.' 09:05:00':null,
        'telegram_status'=>$telegramStatus,'telegram_queue_id'=>$queueId,'created_at'=>'NOW()','updated_at'=>'NOW()','sent_at'=>$sentAt,
    ]);
    if($id===false) throw new RuntimeException('fixture report write failed');
    if($queueId) $DB->exec("UPDATE pre_telegram_notify_queue SET param=:param WHERE id=:id",[
        ':param'=>json_encode(['report_id'=>intval($id),'html'=>'fixture','plain'=>'fixture','chat_id'=>'123456789']),':id'=>$queueId,
    ]);
    return intval($id);
}

function health_fixture_queue($DB,$name,$retryCount)
{
    $id=$DB->insert('telegram_notify_queue',[
        'uid'=>0,'scene'=>'daily_health','param'=>'{}','status'=>0,'retry_count'=>$retryCount,'dedupe_key'=>'health_ui_fixture:'.$name,
        'claimtime'=>null,'sendstarttime'=>null,'next_attempt'=>'NOW()','addtime'=>'NOW()','sendtime'=>null,'error_msg'=>null,
    ]);
    if($id===false) throw new RuntimeException('fixture queue write failed');
    return intval($id);
}

function health_fixture_assert_terminal($DB,$queueId,$uncertain)
{
    $row=$DB->find('telegram_notify_queue','status,sendstarttime', ['id'=>$queueId], null, 1);
    if(!$row || intval($row['status'])!==2) throw new RuntimeException('fixture worker did not terminalize queue '.$queueId);
    if($uncertain && empty($row['sendstarttime'])) throw new RuntimeException('fixture uncertain delivery lost its start marker');
    if(!$uncertain && $row['sendstarttime']!==null) throw new RuntimeException('fixture deterministic failure retained a start marker');
}

class HealthFixtureBot
{
    private $uncertain;
    private $error;
    public function __construct($uncertain,$error){$this->uncertain=(bool)$uncertain;$this->error=(string)$error;}
    public function sendMessage($chatId,$message,$options=[]){return false;}
    public function isDeterministicFormatError(){return false;}
    public function isDeliveryUncertain(){return $this->uncertain;}
    public function getLastError(){return $this->error;}
}

function health_fixture_overview_metrics($date)
{
    return ['version'=>\lib\Health\MetricsService::VERSION,'period_start'=>$date.' 00:00:00','period_end'=>date('Y-m-d H:i:s',strtotime($date.' +1 day')),
        'snapshot_hours'=>24,'snapshot_finalized_at'=>date('Y-m-d H:i:s',strtotime($date.' +1 day +3 hours 10 minutes')),
        'notification_as_of'=>date('Y-m-d H:i:s',strtotime($date.' +1 day +9 hours 5 minutes')),'data_complete'=>true,
        'platform'=>['total_orders'=>12345,'paid_orders'=>7802,'refunded_orders'=>17,'frozen_orders'=>2,'preauth_orders'=>3,'success_rate'=>63.21,
            'notify_total'=>7600,'notify_success'=>7589,'notify_pending'=>8,'notify_failed'=>3,'notify_oldest_time'=>date('Y-m-d H:i:s',strtotime($date.' +1 day +8 hours 2 minutes'))],
        'channels'=>[14=>['channel_id'=>14,'channel_name'=>'优选 H5','channel_status'=>1,'total_orders'=>100,'paid_orders'=>63,'success_rate'=>63,
            'failure_streak'=>4,'failure_streak_max'=>7,'notify_pending'=>2,'notify_failed'=>1,'amount_bands'=>[
                '0_30'=>['total'=>20,'paid'=>14,'success_rate'=>70],'30_100'=>['total'=>80,'paid'=>49,'success_rate'=>61.25],
            ]]],
    ];
}
