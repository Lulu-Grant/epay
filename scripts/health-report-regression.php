#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root.'/includes/lib/Health/AdminSupport.php';
require $root.'/includes/lib/Health/RuleEngine.php';
require $root.'/includes/lib/Health/AiClient.php';
require $root.'/includes/lib/Health/Installer.php';
require $root.'/includes/lib/Health/MetricsService.php';
require $root.'/includes/lib/Health/RawDataService.php';
require $root.'/includes/lib/Health/RawAiPipeline.php';
require $root.'/includes/lib/Health/EvidenceCompactor.php';
require $root.'/includes/lib/Health/CompactAiPipeline.php';
require $root.'/includes/lib/Health/ReportService.php';
require $root.'/includes/lib/Health/ReportCommand.php';
require $root.'/includes/lib/Health/SnapshotCommand.php';
require $root.'/includes/lib/Health/TelegramRenderer.php';
require $root.'/includes/lib/Telegram/BotAPI.php';
require $root.'/includes/lib/Telegram/NotifyHelper.php';
require $root.'/includes/lib/Telegram/QueueHelper.php';

$failures = [];
$assert = function($condition, $label) use (&$failures){ if(!$condition) $failures[] = $label; };
$assertSame = function($expected, $actual, $label) use (&$failures){
    if($expected !== $actual) $failures[] = $label.' expected='.var_export($expected,true).' actual='.var_export($actual,true);
};

$assertSame(null, \lib\Health\RuleEngine::wilsonLower(0, 0), 'wilson empty sample');
$assert(\lib\Health\RuleEngine::wilsonLower(90, 100) > 80, 'wilson healthy sample');
$assert(\lib\Health\RuleEngine::wilsonLower(1, 100) < 10, 'wilson critical sample');

$deliveryOff = \lib\Health\AdminSupport::deliveryReadiness([]);
$assertSame(false, $deliveryOff['ok'], 'manual delivery is disabled without report and AI configuration');
$deliveryReady = \lib\Health\AdminSupport::deliveryReadiness([
    'health_report_enabled'=>1,'health_ai_enabled'=>1,'telegram_notice'=>1,
    'telegram_bot_token'=>'configured','health_report_chat_id'=>'123456',
]);
$assertSame(true, $deliveryReady['ok'], 'manual delivery accepts a complete configuration');
$assertSame('123456', $deliveryReady['destination'], 'manual delivery returns the configured destination');
$assertSame(false, \lib\Health\AdminSupport::aiEnabled([]), 'AI action is disabled by default');
$assertSame('已完成', \lib\Health\AdminSupport::aiFeedback(1, null)['label'], 'AI success feedback is explicit');
$assertSame('当前统计窗口快照不完整，AI 分析已跳过', \lib\Health\AdminSupport::aiFeedback(3, 'Current snapshot is incomplete; AI analysis was skipped')['reason'], 'current snapshot AI skip feedback is localized');
$assertSame('最近7日基线快照不完整，AI 分析已跳过', \lib\Health\AdminSupport::aiFeedback(3, 'Seven-day baseline is incomplete; AI analysis was skipped')['reason'], 'baseline AI skip feedback is localized');
$manualQueue = \lib\Health\ReportCommand::parse(['2026-07-18','--send']);
$assertSame(true, $manualQueue['use_ai'], 'manual CLI queueing always invokes AI');
$assertSame(true, \lib\Health\ReportCommand::parse(['2026-07-18','--ai','--force'])['force'], 'CLI force flag enables controlled terminal retry');
$assertSame(true, $manualQueue['send'], 'manual CLI queueing remains enabled');
$scheduledQueue = \lib\Health\ReportCommand::parse(['--send','--ai']);
$assertSame(true, $scheduledQueue['use_ai'], 'scheduled CLI AI requires explicit opt-in');
$invalidCli = false;
try { \lib\Health\ReportCommand::parse(['--send','--ai','--no-ai']); } catch(InvalidArgumentException $e){ $invalidCli = true; }
$assertSame(true, $invalidCli, 'conflicting CLI AI options are rejected');
foreach(['--send','--ai','--no-ai','--force'] as $duplicateOption){
    $duplicateRejected = false;
    try { \lib\Health\ReportCommand::parse([$duplicateOption,$duplicateOption]); } catch(InvalidArgumentException $e){ $duplicateRejected = true; }
    $assertSame(true, $duplicateRejected, 'duplicate '.$duplicateOption.' is rejected');
}
$snapshotOptions = \lib\Health\SnapshotCommand::parse(['2026-07-18 10:00:00','--force','--backfill=6'], 4, 12);
$assertSame(true, $snapshotOptions['force'], 'snapshot force option is explicit');
$assertSame(6, $snapshotOptions['backfill'], 'snapshot backfill option is parsed strictly');
$invalidSnapshot = false;
try { \lib\Health\SnapshotCommand::parse(['--forc'], 4, 12); } catch(InvalidArgumentException $e){ $invalidSnapshot = true; }
$assertSame(true, $invalidSnapshot, 'unknown snapshot options fail closed');
$invalidBackfill = false;
try { \lib\Health\SnapshotCommand::parse(['--backfill=0'], 4, 12); } catch(InvalidArgumentException $e){ $invalidBackfill = true; }
$assertSame(true, $invalidBackfill, 'invalid snapshot backfill fails closed');

$engine = new \lib\Health\RuleEngine(['min_sample'=>30,'attention_drop'=>5,'warning_drop'=>10,'failure_streak'=>10,'notify_critical_rate'=>50]);
$current = [
    'data_complete'=>true,
    'period_end'=>'2026-07-19 00:00:00',
    'platform'=>['total_orders'=>100,'paid_orders'=>50],
    'channels'=>[
        14=>['channel_status'=>1,'total_orders'=>60,'paid_orders'=>6,'failure_streak'=>12,'notify_total'=>6,'notify_failed'=>1],
    ],
];
$baseline = [
    'data_complete'=>true,
    'platform'=>['total_orders'=>100,'paid_orders'=>70],
    'channels'=>[14=>['total_orders'=>60,'paid_orders'=>30]],
];
$rules = $engine->evaluate($current, $baseline);
$codes = array_column($rules['issues'], 'code');
$assert(in_array('success_rate_drop', $codes, true), 'baseline drop detected');
$assert(in_array('failure_streak', $codes, true), 'failure streak detected');
$assert(in_array('notify_failed', $codes, true), 'notify failure detected');
$assert(in_array($rules['level'], ['warning','critical'], true), 'report level escalated');

$incomplete = $engine->evaluate(['data_complete'=>false,'snapshot_hours'=>23,'platform'=>['total_orders'=>100,'paid_orders'=>100]], null);
$assertSame('unknown', $incomplete['level'], 'incomplete data cannot be healthy');
$assert(in_array('data_incomplete', array_column($incomplete['issues'], 'code'), true), 'incomplete data issue emitted');
$incompleteHard = $engine->evaluate(['data_complete'=>false,'snapshot_hours'=>23,'platform'=>[
    'total_orders'=>100,'paid_orders'=>100,'notify_total'=>100,'notify_failed'=>1,
],'channels'=>[]], ['data_complete'=>true,'platform'=>['total_orders'=>100,'paid_orders'=>100],'channels'=>[]]);
$assertSame('warning', $incompleteHard['level'], 'known notification failure remains visible with incomplete snapshots');
$assert(strpos($incompleteHard['summary'], '同时存在数据不足') !== false, 'compound summary retains the data-quality caveat');
$assert(in_array('data_incomplete', array_column($incompleteHard['issues'], 'code'), true), 'incomplete-data caveat remains visible with hard failure');
$assert(in_array('notify_failed', array_column($incompleteHard['issues'], 'code'), true), 'incomplete data does not suppress notification failure');
$incompleteFrozen = $engine->evaluate(['data_complete'=>false,'snapshot_hours'=>23,'platform'=>[
    'total_orders'=>100,'paid_orders'=>100,'frozen_orders'=>1,
],'channels'=>[]], ['data_complete'=>true,'platform'=>['total_orders'=>100,'paid_orders'=>100],'channels'=>[]]);
$assertSame('warning', $incompleteFrozen['level'], 'known frozen order remains visible with incomplete snapshots');
$assert(in_array('frozen_orders', array_column($incompleteFrozen['issues'], 'code'), true), 'incomplete data retains the frozen-order finding');

$noTraffic = $engine->evaluate(['data_complete'=>true,'platform'=>['total_orders'=>0,'paid_orders'=>0],'channels'=>[]], null);
$assertSame('unknown', $noTraffic['level'], 'zero traffic cannot be healthy');
$assert(in_array('no_traffic', array_column($noTraffic['issues'], 'code'), true), 'zero traffic issue emitted');
$lowSample = $engine->evaluate(['data_complete'=>true,'platform'=>['total_orders'=>5,'paid_orders'=>0],'channels'=>[]], null);
$assertSame('unknown', $lowSample['level'], 'low sample cannot be healthy');
$assert(in_array('sample_insufficient', array_column($lowSample['issues'], 'code'), true), 'low sample issue emitted');
$missingBaseline = $engine->evaluate(['data_complete'=>true,'platform'=>['total_orders'=>1000,'paid_orders'=>500],'channels'=>[]], null);
$assertSame('unknown', $missingBaseline['level'], 'missing seven-day baseline cannot be healthy');
$assert(in_array('baseline_missing', array_column($missingBaseline['issues'], 'code'), true), 'missing baseline issue emitted');
$assertSame('baseline_missing', \lib\Health\RuleEngine::classificationReason(['data_complete'=>true], $missingBaseline), 'baseline reason remains distinct');
$partialBaselineDrop = $engine->evaluate(
    ['data_complete'=>true,'platform'=>['total_orders'=>1000,'paid_orders'=>500],'channels'=>[]],
    ['data_complete'=>false,'platform'=>['total_orders'=>1000,'paid_orders'=>900],'channels'=>[]]
);
$assertSame('unknown', $partialBaselineDrop['level'], 'incomplete baseline dominates a computed conversion drop');
$assert(in_array('success_rate_drop', array_column($partialBaselineDrop['issues'], 'code'), true), 'baseline caveat retains the provisional conversion-drop finding');
$lowSampleHard = $engine->evaluate(['data_complete'=>true,'platform'=>['total_orders'=>5,'paid_orders'=>0,'notify_total'=>5,'notify_failed'=>1],'channels'=>[]], null);
$assertSame('warning', $lowSampleHard['level'], 'known notification failure remains visible with a low sample');
$assert(in_array('sample_insufficient', array_column($lowSampleHard['issues'], 'code'), true), 'low sample caveat retained with hard failure');
$assert(in_array('notify_failed', array_column($lowSampleHard['issues'], 'code'), true), 'low sample does not suppress hard notification failure');
$criticalNotify = (new \lib\Health\RuleEngine(['notify_critical_count'=>20,'notify_critical_rate'=>20]))->evaluate([
    'data_complete'=>true,'platform'=>['total_orders'=>1000,'paid_orders'=>1000,'notify_total'=>1000,'notify_failed'=>200],'channels'=>[],
], ['data_complete'=>true,'platform'=>['total_orders'=>1000,'paid_orders'=>1000],'channels'=>[]]);
$assertSame('critical', $criticalNotify['level'], 'large final notification failure is critical');

$allRefunded = $engine->evaluate(['data_complete'=>true,'platform'=>[
    'total_orders'=>100,'paid_orders'=>100,'refunded_orders'=>100,'frozen_orders'=>0,
],'channels'=>[]], ['data_complete'=>true,'platform'=>['total_orders'=>100,'paid_orders'=>100],'channels'=>[]]);
$assertSame('warning', $allRefunded['level'], 'all-refunded traffic cannot be classified healthy');
$assert(in_array('high_refund_rate', array_column($allRefunded['issues'], 'code'), true), 'high refund rate detected');
$frozen = $engine->evaluate(['data_complete'=>true,'platform'=>[
    'total_orders'=>5,'paid_orders'=>1,'refunded_orders'=>0,'frozen_orders'=>1,
],'channels'=>[]], ['data_complete'=>true,'platform'=>['total_orders'=>100,'paid_orders'=>20],'channels'=>[]]);
$assertSame('warning', $frozen['level'], 'known frozen order remains visible with a low sample');
$assert(in_array('frozen_orders', array_column($frozen['issues'], 'code'), true), 'frozen order detected');

$pending = $engine->evaluate([
    'data_complete'=>true,
    'period_end'=>date('Y-m-d H:i:s'),
    'platform'=>['total_orders'=>100,'paid_orders'=>100,'notify_total'=>100,'notify_pending'=>10,'notify_oldest_time'=>date('Y-m-d H:i:s', time()-7200)],
    'channels'=>[],
], ['data_complete'=>true,'platform'=>['total_orders'=>100,'paid_orders'=>100],'channels'=>[]]);
$assert(in_array('notify_pending', array_column($pending['issues'], 'code'), true), 'notify backlog detected');
$assertSame('warning', $pending['level'], 'notify backlog escalates level');

class HealthSnapshotFakeDb {
    public $rows = [];
    public function getAll($sql, $bind){ return $this->rows; }
}
$snapshotDb = new HealthSnapshotFakeDb();
$snapshotBundle = function($start, $end){
    return json_encode([
        'platform'=>['total_orders'=>8,'paid_orders'=>0,'unpaid_orders'=>8],
        'channels'=>[14=>[
            'channel_id'=>14,'channel_status'=>1,'total_orders'=>8,'paid_orders'=>0,'unpaid_orders'=>8,
            'failure_streak'=>8,'failure_streak_max'=>8,
            'streak'=>['count'=>8,'leading'=>8,'trailing'=>8,'max'=>8,'all_unpaid'=>true],
        ]],
        'merchants'=>[], 'data_complete'=>true, 'period_start'=>$start, 'period_end'=>$end,
        'generated_at'=>date('Y-m-d H:i:s', strtotime($end) + 4 * 3600),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$snapshotDb->rows = [
    ['period_start'=>'2026-07-18 00:00:00','period_end'=>'2026-07-18 01:00:00','metrics_json'=>$snapshotBundle('2026-07-18 00:00:00','2026-07-18 01:00:00')],
    ['period_start'=>'2026-07-18 01:00:00','period_end'=>'2026-07-18 02:00:00','metrics_json'=>$snapshotBundle('2026-07-18 01:00:00','2026-07-18 02:00:00')],
];
$reportService = new \lib\Health\ReportService($snapshotDb, []);
$aggregateMethod = new ReflectionMethod($reportService, 'aggregateSnapshots');
$aggregated = $aggregateMethod->invoke($reportService, '2026-07-18 00:00:00', '2026-07-18 02:00:00');
$assertSame(16, $aggregated['channels'][14]['failure_streak'], 'cross-hour trailing streak merged');
$assertSame(16, $aggregated['channels'][14]['failure_streak_max'], 'cross-hour maximum streak merged');
$assertSame(true, $aggregated['data_complete'], 'contiguous snapshot window complete');
$incompleteAggregate = $aggregateMethod->invoke($reportService, '2026-07-18 00:00:00', '2026-07-18 03:00:00');
$assertSame(false, $incompleteAggregate['data_complete'], 'missing snapshot hour detected');
$snapshotDb->rows[] = $snapshotDb->rows[1];
$duplicateAggregate = $aggregateMethod->invoke($reportService, '2026-07-18 00:00:00', '2026-07-18 02:00:00');
$assertSame(false, $duplicateAggregate['data_complete'], 'duplicate snapshot period is rejected');
$assertSame(16, $duplicateAggregate['platform']['total_orders'], 'duplicate snapshot is not double counted');
$snapshotDb->rows = [[
    'period_start'=>'2026-07-18 00:30:00','period_end'=>'2026-07-18 01:30:00',
    'metrics_json'=>$snapshotBundle('2026-07-18 00:30:00','2026-07-18 01:30:00'),
]];
$unalignedAggregate = $aggregateMethod->invoke($reportService, '2026-07-18 00:00:00', '2026-07-18 02:00:00');
$assertSame(false, $unalignedAggregate['data_complete'], 'unaligned snapshot period is rejected');
$assertSame(0, intval(isset($unalignedAggregate['platform']['total_orders']) ? $unalignedAggregate['platform']['total_orders'] : 0), 'invalid snapshot metrics are not merged');

class HealthRawDataFakeDb {
    public $sql=[];
    public function getColumn($sql,$bind=[]){
        $this->sql[]=$sql;
        if(strpos($sql,'COUNT(*) FROM pre_order')!==false) return 2;
        return false;
    }
    public function getAll($sql,$bind=[]){
        $this->sql[]=$sql;
        if(strpos($sql,'FROM pre_order o')!==false) return [
            ['trade_no'=>'2001010800000000001','out_trade_no'=>'paid','uid'=>1001,'tid'=>0,'type'=>1,'channel'=>14,'name'=>'paid','money'=>'10.00','realmoney'=>'10.00','profitmoney'=>'0.10','notify_url'=>'https://merchant.invalid/notify?token=secret&keep=1','return_url'=>'https://merchant.invalid/return#secret','param'=>'{"token":"secret","memo":"ok"}','addtime'=>'2001-01-08 01:00:00','endtime'=>'2001-01-08 01:00:03','date'=>'2001-01-08','status'=>1,'notify'=>0,'_channel_name'=>'fixture','_channel_plugin'=>'alipay','_channel_status'=>1,'_channel_daystatus'=>0,'_channel_paymin'=>'0.01','_channel_paymax'=>'1000','_merchant_group'=>8],
            ['trade_no'=>'2001010800000000002','out_trade_no'=>'unpaid','uid'=>1001,'tid'=>0,'type'=>1,'channel'=>14,'name'=>'unpaid','money'=>'10.00','realmoney'=>null,'profitmoney'=>null,'notify_url'=>'https://merchant.invalid/notify','return_url'=>'https://merchant.invalid/return','param'=>null,'addtime'=>'2001-01-08 02:00:00','endtime'=>null,'date'=>null,'status'=>0,'notify'=>0,'_channel_name'=>'fixture','_channel_plugin'=>'alipay','_channel_status'=>1,'_channel_daystatus'=>0,'_channel_paymin'=>'0.01','_channel_paymax'=>'1000','_merchant_group'=>8],
        ];
        if(strpos($sql,'FROM pre_channel')!==false) return [['id'=>14,'name'=>'fixture','plugin'=>'alipay','status'=>1,'daystatus'=>0,'paymin'=>'0.01','paymax'=>'1000']];
        return [];
    }
    public function error(){ return 'fake'; }
}
$rawDb = new HealthRawDataFakeDb();
$rawDataset = (new \lib\Health\RawDataService($rawDb))->collect('2001-01-08');
$assertSame(2,$rawDataset['metrics']['platform']['total_orders'],'raw report includes date-null unpaid order');
$assertSame(1,$rawDataset['metrics']['platform']['paid_orders'],'raw report paid count is exact');
$assertSame(50.0,$rawDataset['metrics']['platform']['success_rate'],'raw report success rate uses all addtime rows');
$assert(strpos($rawDataset['orders'][0]['param'],'secret')===false && strpos($rawDataset['orders'][0]['param'],'[REDACTED]')!==false,'raw payload removes token-shaped values');
$assertSame('https://merchant.invalid/notify',$rawDataset['orders'][0]['notify_url'],'raw payload removes callback URL query parameters');
$assertSame('https://merchant.invalid/return',$rawDataset['orders'][0]['return_url'],'raw payload removes callback URL fragments');
$assert(strpos(implode(' ',$rawDb->sql),'date>=')===false,'raw report queries do not filter by mutable date field');

class HealthRawCapacityFakeDb extends HealthRawDataFakeDb {
    public function getColumn($sql,$bind=[]){ return strpos($sql,'COUNT(*) FROM pre_order')!==false ? 10001 : false; }
}
$capacityRejected=false;
try { (new \lib\Health\RawDataService(new HealthRawCapacityFakeDb()))->collect('2001-01-08'); }
catch(RuntimeException $e){ $capacityRejected=strpos($e->getMessage(),'supported daily capacity')!==false; }
$assertSame(true,$capacityRejected,'raw report fails closed above the supported daily order capacity');

$payloadModeMethod = new ReflectionMethod(\lib\Health\ReportService::class, 'payloadMode');
$payloadModeMethod->setAccessible(true);
$assertSame('compact', $payloadModeMethod->invoke(new \lib\Health\ReportService(new stdClass(), [])), 'compact evidence is the safe default payload mode');
$assertSame('raw', $payloadModeMethod->invoke(new \lib\Health\ReportService(new stdClass(), ['health_ai_payload_mode'=>'raw'])), 'raw payload mode remains available only when explicitly selected');

class HealthTerminalRetryFakeDb {
    public $rawCollectionReached=false;
    private $row;
    public function __construct(){
        $this->row=['id'=>77,'report_date'=>'2001-01-08','report_type'=>'daily','metrics_json'=>'{"platform":[]}','rules_json'=>'{"level":"unknown","issues":[]}',
            'ai_json'=>null,'ai_status'=>5,'ai_error'=>'terminal','ai_duration_ms'=>null,'ai_request_sha256'=>null,'ai_response_sha256'=>null,
            'ai_calls'=>100,'ai_source_rows'=>2,'ai_payload_mode'=>'raw','ai_pipeline_version'=>\lib\Health\RawAiPipeline::VERSION,'ai_next_retry_at'=>null,'telegram_status'=>0];
    }
    public function getColumn($sql,$bind=[]){
        if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false)return 1;
        if(strpos($sql,'COUNT(*) FROM pre_order')!==false){$this->rawCollectionReached=true;throw new RuntimeException('controlled collection marker');}
        return false;
    }
    public function find(){return $this->row;}
}
$terminalDb=new HealthTerminalRetryFakeDb();
$terminalService=new \lib\Health\ReportService($terminalDb,['health_ai_enabled'=>1]);
$terminalReport=$terminalService->generateDaily('2001-01-08',true,false);
$assertSame(5,$terminalReport['ai_status'],'terminal report remains immutable without an explicit retry');
$terminalRetryReached=false;
try{$terminalService->generateDaily('2001-01-08',true,true);}catch(RuntimeException $e){$terminalRetryReached=$terminalDb->rawCollectionReached;}
$assertSame(true,$terminalRetryReached,'explicit retry reopens an unsent terminal report');

class HealthTransactionFakeDb {
    public $commitResult = false;
    public function exec(){ return true; }
    public function beginTransaction(){ return true; }
    public function commit(){ return $this->commitResult; }
    public function rollBack(){ return true; }
}
class HealthTransactionMetricsService extends \lib\Health\MetricsService {
    public function collect($start, $end){ return ['period_start'=>$start,'period_end'=>$end,'platform'=>[],'channels'=>[]]; }
}
$transactionMetrics = new HealthTransactionMetricsService(new HealthTransactionFakeDb());
$transactionFailed = false;
try { $transactionMetrics->saveHourlySnapshots('2026-07-19 10:00:00', 1); } catch(RuntimeException $e){ $transactionFailed = strpos($e->getMessage(), 'commit failed') !== false; }
$assertSame(true, $transactionFailed, 'snapshot commit failure is not reported as success');
$assertSame(12, \lib\Health\MetricsService::MAX_SAFE_BACKFILL_HOURS, 'snapshot backfill has retention safety margin');

$metricsService = new \lib\Health\MetricsService(new stdClass());
$streakMethod = new ReflectionMethod($metricsService, 'streakSummaries');
$streaks = $streakMethod->invoke($metricsService, [
    ['channel'=>14,'status'=>0], ['channel'=>14,'status'=>2], ['channel'=>14,'status'=>0],
    ['channel'=>14,'status'=>3], ['channel'=>14,'status'=>0], ['channel'=>14,'status'=>4], ['channel'=>14,'status'=>0],
]);
$assertSame(1, $streaks['14']['trailing'], 'preauthorization breaks the unpaid streak without becoming a payment success');
$assertSame(1, $streaks['14']['max'], 'all non-unpaid states break consecutive unpaid runs');

class HealthNotificationMetricFakeDb {
    public $sql = [];
    public function getRow($sql){ $this->sql[]=$sql; return ['notify_total'=>2,'notify_success'=>1,'notify_pending'=>1,'notify_failed'=>0,'notify_oldest_time'=>'2026-07-18 01:00:00']; }
    public function getAll($sql){ $this->sql[]=$sql; return [['channel_id'=>14,'notify_total'=>2,'notify_success'=>1,'notify_pending'=>1,'notify_failed'=>0,'notify_oldest_time'=>'2026-07-18 01:00:00']]; }
}
$notificationDb = new HealthNotificationMetricFakeDb();
$notificationMetrics = (new \lib\Health\MetricsService($notificationDb))->collectNotificationMetrics('2026-07-18 00:00:00','2026-07-19 00:00:00');
$assertSame(2, $notificationMetrics['platform']['notify_total'], 'notification denominator is collected');
$assert(strpos(implode(' ', $notificationDb->sql), "tid=0") !== false && strpos(implode(' ', $notificationDb->sql), "TRIM(notify_url)<>''") !== false, 'notification metrics only include merchant callback eligible orders');

$report = [
    'report_date'=>'2026-07-18',
    'metrics'=>[
        'data_complete'=>false,
        'notification_as_of'=>'2026-07-19 09:05:00',
        'platform'=>['total_orders'=>100,'paid_orders'=>50,'refunded_orders'=>3,'frozen_orders'=>1,'preauth_orders'=>2,'success_rate'=>50,'notify_total'=>50,'notify_success'=>48,'notify_pending'=>1,'notify_failed'=>1,'notify_oldest_time'=>'2026-07-19 08:05:00'],
    ],
	'rules'=>['level'=>'warning','issues'=>array_merge([
		['level'=>'warning','code'=>'notify_failed','scope'=>'platform','message'=>'通道14 <异常> & 需复核'],
		['level'=>'attention','code'=>'data_incomplete','scope'=>'platform','message'=>'快照不完整'],
	], array_map(function($n){ return ['level'=>'attention','code'=>'fixture_'.$n,'scope'=>'platform','message'=>'规则'.$n.'需要复核']; }, range(3,10)))],
    'ai'=>['headline'=>'伪造9999笔订单','summary'=>'伪造0%成功率','findings'=>[[
        'rule_code'=>'notify_failed','scope'=>'platform','title'=>'本地规则事实','evidence'=>'notify_failed=1','action'=>'查看服务日志🚨',
    ]]],
];
$rendered = (new \lib\Health\TelegramRenderer())->render($report);
$assert(mb_strlen($rendered['html'],'UTF-8') <= 3500, 'telegram html length bounded');
$assert(strpos($rendered['html'], '&lt;异常&gt; &amp;') !== false, 'telegram html escaped');
$assert(strpos($rendered['plain'], '<异常> &') !== false, 'plain fallback retained');
$assert(strpos($rendered['plain'], '共10项') !== false && strpos($rendered['plain'], '展开前8项') !== false, 'telegram rule truncation is explicit');
$assert(strpos($rendered['plain'], '总体状态：告警（数据不足）') !== false, 'telegram preserves known severity with a data-quality caveat');
$assert(strpos($rendered['plain'], '[告警] 通道14') !== false, 'telegram issue lines expose severity');
$assert(strpos($rendered['plain'], '曾支付成功率：50.00%') !== false, 'telegram uses explicit ever-paid KPI label');
$assert(strpos($rendered['plain'], '退款3，冻结1，预授权2') !== false, 'telegram exposes reversal outcomes');
$assert(strpos($rendered['plain'], '符合条件50笔') !== false && strpos($rendered['plain'], '最早等待60分钟') !== false, 'telegram exposes eligible callback denominator and oldest wait');
$assert(strpos($rendered['plain'], 'Telegram仅展示摘要') !== false, 'telegram global summary disclosure appears before truncation');
$assert(strpos($rendered['plain'], '伪造9999') === false && strpos($rendered['plain'], '伪造0%') === false, 'untrusted AI headline and summary are not rendered');
$assert(strpos($rendered['plain'], '本地规则事实') !== false && strpos($rendered['plain'], '查看服务日志') !== false, 'grounded AI advice is rendered');
$assert(strpos($rendered['plain'], '🚨') === false, 'astral unicode removed before queue storage');
$longReport = $report;
$longReport['ai']['findings'][0]['action'] = str_repeat("'&<>", 500);
$longRendered = (new \lib\Health\TelegramRenderer())->render($longReport);
$assert(mb_strlen($longRendered['html'],'UTF-8') <= 3500, 'escaped telegram html length bounded');
$assert(mb_strlen($longRendered['plain'],'UTF-8') <= 3500, 'telegram plain length bounded');

$conf = ['sitename'=>'测试站点'];
$message = \lib\Telegram\NotifyHelper::getTelegramMessage('daily_health', ['html'=>$rendered['html']]);
$assertSame($rendered['html'], $message, 'daily health scene accepted');
$assertSame(false, \lib\Telegram\NotifyHelper::getTelegramMessage('daily_health', ['html'=>str_repeat('A',3501)]), 'oversized message rejected');

class HealthQueueFakeDb {
    public $data;
    public function insert($table, $data){ $this->data = $data; return 42; }
}
$DB = new HealthQueueFakeDb();
$conf = ['telegram_notice'=>1,'telegram_bot_token'=>'test-token','telegram_admin_chat_id'=>'123456'];
$assertSame(true, \lib\Telegram\QueueHelper::addToQueue('daily_health', 0, ['html'=>'ok','plain'=>'ok']), 'legacy queue return remains boolean');
$assertSame(42, \lib\Telegram\QueueHelper::addToQueue('daily_health', 0, ['html'=>'ok','plain'=>'ok','meta'=>'🚀'], true), 'queue id can be requested');
$assert(strpos($DB->data['param'], '🚀') === false && json_decode($DB->data['param'], true)['meta'] === '🚀', 'queue JSON remains ASCII-safe for MySQL utf8');

class HealthStaleQueueFakeDb {
    public $sql = [];
    public $readSql = [];
    public $reads = 0;
    public function getAll($sql){
        $this->reads++;
        $this->readSql[] = $sql;
        if(strpos($sql, 'claimtime<') !== false) return [
            ['id'=>1,'scene'=>'daily_health','sendstarttime'=>null,'param'=>'{"report_id":11}'],
            ['id'=>2,'scene'=>'daily_health','sendstarttime'=>'2026-07-19 10:00:00','param'=>'{"report_id":12}'],
        ];
        return [];
    }
    public function getRow($sql){ return ['v'=>'1']; }
    public function getColumn($sql, $bind=[]){ return 1; }
    public function exec($sql){ $this->sql[] = $sql; return 1; }
    public function find(){ return ['telegram_status'=>1,'telegram_queue_id'=>2]; }
    public function update(){ return true; }
    public function error(){ return 'fake'; }
}
class HealthQueueFakeCache { public function save(){ return true; } }
$DB = new HealthStaleQueueFakeDb();
$CACHE = new HealthQueueFakeCache();
$conf = ['telegram_bot_token'=>'test-token','health_report_enabled'=>1,'addon_health_report'=>\lib\Health\Installer::VERSION];
$queueResult = \lib\Telegram\QueueHelper::processQueue(10);
$assert(strpos($DB->sql[0], 'SET status=0') !== false, 'claimed-before-send row is safely requeued');
$assert(strpos($DB->sql[1], 'SET status=2') !== false, 'delivery-started stale row becomes terminal unknown');
$assertSame('No notifications to process', $queueResult['message'], 'stale recovery does not contact Telegram');
$assert(strpos($DB->readSql[2], "scene='daily_health' AND status=0") !== false, 'daily health receives a dedicated queue processing quota');
$assert(strpos($DB->readSql[3], "scene<>'daily_health'") !== false, 'legacy notifications retain the remaining queue quota');

class HealthDisabledQueueFakeDb {
    public $reads = [];
    public function getAll($sql){ $this->reads[] = $sql; return []; }
    public function getColumn($sql, $bind=[]){ return 1; }
}
$DB = new HealthDisabledQueueFakeDb();
$conf = ['telegram_bot_token'=>'test-token','health_report_enabled'=>0,'addon_health_report'=>\lib\Health\Installer::VERSION];
$disabledQueueResult = \lib\Telegram\QueueHelper::processQueue(10);
$assertSame(4, count($DB->reads), 'disabled scheduling still recovers, reconciles, and reads existing health work');
$assert(strpos($DB->reads[0], 'claimtime<') !== false, 'disabled scheduling still recovers interrupted health work');
$assert(strpos($DB->reads[1], 'pre_health_report') !== false, 'disabled scheduling still reconciles report projections');
$assert(strpos($DB->reads[2], "scene='daily_health' AND status=0") !== false, 'disabled scheduling does not suppress queued health work');
$assert(strpos($DB->reads[3], "scene<>'daily_health'") !== false, 'legacy notifications retain queue capacity');
$assertSame('No notifications to process', $disabledQueueResult['message'], 'empty queue remains idle after scheduling is disabled');

class HealthDisableBeforeTokenFakeDb {
    public $cancelled = false;
    public $projected = false;
    public function getAll($sql){
        if(strpos($sql, "scene='daily_health' AND status IN (0,3)") !== false && !$this->cancelled){
            return [['id'=>91,'scene'=>'daily_health','status'=>0,'param'=>'not-json']];
        }
        return [];
    }
    public function getRow($sql){ return ['v'=>'0']; }
    public function getColumn($sql, $bind=[]){ return 1; }
    public function exec($sql){ if(strpos($sql, 'id=91') !== false){ $this->cancelled=true; return 1; } return 0; }
    public function update($table, $data, $where){ if($table==='health_report' && isset($where['telegram_queue_id']) && intval($where['telegram_queue_id'])===91){ $this->projected=true; return 1; } return 0; }
    public function error(){ return 'fake'; }
}
$DB = new HealthDisableBeforeTokenFakeDb();
$conf = ['telegram_bot_token'=>'','health_report_enabled'=>0,'addon_health_report'=>\lib\Health\Installer::VERSION];
$disabledWithoutToken = \lib\Telegram\QueueHelper::processQueue(10);
$assertSame(false, $DB->cancelled, 'disabling future reports never terminalizes queued rows');
$assertSame(false, $DB->projected, 'disabling future reports never projects a false delivery failure');
$assertSame('Telegram bot token not configured', $disabledWithoutToken['message'], 'missing bot token leaves queued work pending');

class HealthQueueReadFailureFakeDb {
    public $sql=[];
    public function getRow($sql){ return ['v'=>'1']; }
    public function getAll($sql){ $this->sql[]=$sql; return false; }
    public function getColumn($sql, $bind=[]){ return 1; }
}
$DB = new HealthQueueReadFailureFakeDb();
$conf = ['telegram_bot_token'=>'test-token','health_report_enabled'=>1,'addon_health_report'=>\lib\Health\Installer::VERSION];
$queueReadFailure = \lib\Telegram\QueueHelper::processQueue(10);
$assertSame(1, $queueReadFailure['failed'], 'queue database read failure is surfaced');
$assert(strpos($queueReadFailure['message'], 'read failed') !== false, 'queue database read failure is not reported as empty');

class HealthQueueIsolationFakeDb {
    public $sql=[];
    public function getRow($sql){ return ['v'=>'1']; }
    public function getColumn($sql, $bind=[]){ return 1; }
    public function getAll($sql){
        $this->sql[]=$sql;
        if(strpos($sql, 'claimtime<')!==false) return false;
        return [];
    }
}
$DB = new HealthQueueIsolationFakeDb();
$conf = ['telegram_bot_token'=>'test-token','health_report_enabled'=>1,'addon_health_report'=>\lib\Health\Installer::VERSION];
$isolated = \lib\Telegram\QueueHelper::processQueue(10);
$assertSame(1, $isolated['failed'], 'health queue failure is surfaced while legacy processing continues');
$assert(strpos(implode(' ', $DB->sql), "scene<>'daily_health'")!==false, 'legacy queue is still read after optional health queue failure');

class HealthQueueLockFakeDb {
    public $reads=0;
    public function getColumn($sql, $bind=[]){ return strpos($sql,'GET_LOCK')!==false ? 0 : 1; }
    public function getAll($sql){ $this->reads++; return []; }
}
$DB = new HealthQueueLockFakeDb();
$conf = ['telegram_bot_token'=>'test-token'];
$locked = \lib\Telegram\QueueHelper::processQueue(10);
$assertSame(0, $DB->reads, 'second queue worker exits before reading queue rows');
$assert(strpos($locked['message'], 'already running')!==false, 'overlapping queue worker is reported');

class HealthQueueCleanupFakeDb {
    public $sql=[];
    public function exec($sql){ $this->sql[]=$sql; return 1; }
}
$DB = new HealthQueueCleanupFakeDb();
$conf = [];
$assertSame(1, \lib\Telegram\QueueHelper::cleanOldNotifications(7), 'queue cleanup succeeds');
$assert(strpos(implode(' ', $DB->sql), 'status=2')!==false && strpos(implode(' ', $DB->sql), "scene='daily_health'")===false, 'terminal cleanup covers legacy and health notification failures');

$bot = new \lib\Telegram\BotAPI('test-token');
$deliveryMethod = new ReflectionMethod($bot, 'curlFailureCouldHaveDelivered');
$assertSame(false, $deliveryMethod->invoke($bot, true, 7, 0), 'connect failure is safe to retry');
$assertSame(false, $deliveryMethod->invoke($bot, true, 28, 0, 0.0), 'connect-stage timeout is safe to retry');
$assertSame(true, $deliveryMethod->invoke($bot, true, 28, 0, 0.5), 'post-connect timeout is delivery uncertain');
$assertSame(true, $deliveryMethod->invoke($bot, true, 56, 0), 'receive failure is delivery uncertain');
$assertSame(true, $deliveryMethod->invoke($bot, true, 7, 100), 'submitted request is delivery uncertain');
$httpDeliveryMethod = new ReflectionMethod($bot, 'httpFailureCouldHaveDelivered');
$assertSame(true, $httpDeliveryMethod->invoke($bot, true, 500), 'structured POST 5xx is delivery uncertain');
$assertSame(false, $httpDeliveryMethod->invoke($bot, true, 400), 'POST 4xx is deterministic');
$assertSame(false, $httpDeliveryMethod->invoke($bot, false, 500), 'read-only GET 5xx cannot duplicate a send');
$policyMethod = new ReflectionMethod($bot, 'transportPolicy');
$directPolicy = $policyMethod->invoke($bot, 'sendMessage', [], []);
$assertSame(true, $directPolicy['ssl_verifypeer'], 'Telegram transport always verifies TLS certificates');
$assertSame(2, $directPolicy['ssl_verifyhost'], 'Telegram transport always verifies hostnames');
$assertSame(0, $directPolicy['maxredirs'], 'Telegram transport rejects redirects');
$assertSame(false, $directPolicy['followlocation'], 'Telegram transport never follows redirects');
$assertSame([], $directPolicy['proxy'], 'Telegram transport ignores ambient proxies by default');
$proxyPolicy = $policyMethod->invoke($bot, 'sendMessage', [], [
    'telegram_proxy'=>1,'telegram_proxy_server'=>'127.0.0.1','telegram_proxy_port'=>1080,
    'telegram_proxy_type'=>'sock5h','telegram_proxy_user'=>'fixture-user','telegram_proxy_pwd'=>'fixture-password',
]);
$assertSame('127.0.0.1', $proxyPolicy['proxy']['server'], 'Telegram dedicated proxy is selected explicitly');
$assertSame(1080, $proxyPolicy['proxy']['port'], 'Telegram dedicated proxy port is preserved');
$assertSame('fixture-user', $proxyPolicy['proxy']['user'], 'Telegram dedicated proxy credentials are scoped to the explicit proxy');
$classifyMethod = new ReflectionMethod($bot, 'classifyTransportResult');
$telegramOk = $classifyMethod->invoke($bot, true, '{"ok":true,"result":{"message_id":1}}', 200, 0, '', 120, 0.1, '');
$assertSame(true, $telegramOk['ok'], 'Telegram valid JSON success is accepted');
$assertSame(1, $telegramOk['result']['message_id'], 'Telegram success result is preserved');
$telegramFormat = $classifyMethod->invoke($bot, true, '{"ok":false,"description":"Bad Request: can\'t parse entities"}', 400, 0, '', 120, 0.1, '');
$assertSame(false, $telegramFormat['ok'], 'Telegram HTTP 400 is rejected');
$assertSame(true, $bot->isDeterministicFormatError(), 'Telegram entity parse failure is classified deterministically');
$assertSame(false, $bot->isDeliveryUncertain(), 'Telegram HTTP 400 is safe to retry after correction');
$classifyMethod->invoke($bot, true, '{"ok":false,"description":"upstream unavailable"}', 500, 0, '', 120, 0.1, '');
$assertSame(true, $bot->isDeliveryUncertain(), 'Telegram POST 5xx is delivery uncertain');
$classifyMethod->invoke($bot, true, 'not-json', 200, 0, '', 120, 0.1, '');
$assertSame(true, $bot->isDeliveryUncertain(), 'Telegram invalid POST success body is delivery uncertain');
$classifyMethod->invoke($bot, true, '', 0, 7, 'connection refused', 0, 0.0, '');
$assertSame(false, $bot->isDeliveryUncertain(), 'Telegram connect failure is classified as not delivered');
$classifyMethod->invoke($bot, true, '', 0, 28, 'timeout', 120, 0.2, 'Telegram专用代理');
$assertSame(true, $bot->isDeliveryUncertain(), 'Telegram post-connect timeout is classified as uncertain');
$assert(strpos($bot->getLastError(), 'Telegram专用代理') !== false, 'Telegram transport error identifies the selected proxy without exposing credentials');

$tmp = tempnam(sys_get_temp_dir(), 'epay-ai-env-');
$apiKeyName = 'HEALTH_AI_'.'API_KEY';
$envFixture = [
    '# comment',
    $apiKeyName."='secret-value'",
    'HEALTH_AI_ALLOWED_HOSTS'.'=api.example.com,api2.example.com',
    'HEALTH_AI_ALLOWED_MODELS'.'=model-a',
    'invalid line',
];
file_put_contents($tmp, implode(PHP_EOL, $envFixture).PHP_EOL);
$env = \lib\Health\AiClient::loadSecretEnvironment($tmp);
@unlink($tmp);
$assertSame('secret-value', $env['HEALTH_AI_API_KEY'], 'secret env quoted value');
$assertSame('api.example.com,api2.example.com', $env['HEALTH_AI_ALLOWED_HOSTS'], 'secret env allowlist');
$assertSame('model-a', $env['HEALTH_AI_ALLOWED_MODELS'], 'secret env model allowlist');

$lowSampleEnv = tempnam(sys_get_temp_dir(), 'epay-ai-low-');
$lowSampleFixture = [
    $apiKeyName.'=test-key',
    'HEALTH_AI_ALLOWED_HOSTS'.'=should-not-resolve.invalid',
    'HEALTH_AI_ALLOWED_MODELS'.'=model-a',
];
file_put_contents($lowSampleEnv, implode(PHP_EOL, $lowSampleFixture).PHP_EOL);
putenv('HEALTH_AI_ENV_FILE='.$lowSampleEnv);
$lowSampleClient = new \lib\Health\AiClient(['base_url'=>'https://should-not-resolve.invalid/v1','model'=>'model-a','min_sample'=>10]);
$lowSampleResult = $lowSampleClient->analyze(['platform'=>['total_orders'=>1,'paid_orders'=>1],'channels'=>[],'merchants'=>[]], ['level'=>'healthy','issues'=>[]]);
putenv('HEALTH_AI_ENV_FILE');
@unlink($lowSampleEnv);
$assertSame(false, $lowSampleResult['ok'], 'low sample AI request rejected');
$assertSame('Local order sample is below the configured AI threshold', $lowSampleResult['error'], 'low sample rejected before DNS or HTTP');

$transportEnv = tempnam(sys_get_temp_dir(), 'epay-ai-transport-');
$transportFixture = [
    $apiKeyName.'=test-key',
    'HEALTH_AI_ALLOWED_HOSTS'.'=ai-test.example',
    'HEALTH_AI_ALLOWED_MODELS'.'=model-a',
    'HEALTH_AI_PINNED_IPV4'.'=ai-test.example=1.1.1.1',
];
file_put_contents($transportEnv, implode(PHP_EOL, $transportFixture).PHP_EOL);
putenv('HEALTH_AI_ENV_FILE='.$transportEnv);
$transportMetrics = ['platform'=>['total_orders'=>100], 'channels'=>[], 'merchants'=>[]];
$transportRules = ['level'=>'warning', 'issues'=>[fetch_ai_transport_issue()]];
$validContent = json_encode(['findings'=>[
    ['rule_code'=>'notify_failed','scope'=>'platform'],
    ['rule_code'=>'notify_failed','scope'=>'platform'],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$validOuter = json_encode(['choices'=>[['message'=>['content'=>$validContent]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$transportCases = [
    'success'=>[['ok'=>true,'http_code'=>200,'response'=>$validOuter,'error'=>''], true, null],
    'http-error'=>[['ok'=>true,'http_code'=>503,'response'=>'unavailable','error'=>''], false, 'AI HTTP 503'],
    'timeout'=>[['ok'=>false,'http_code'=>0,'response'=>'','error'=>'operation timed out'], false, 'AI transport failed: operation timed out'],
    'overflow'=>[['ok'=>true,'http_code'=>200,'response'=>str_repeat('x', \lib\Health\AiClient::MAX_RESPONSE_BYTES + 1),'error'=>''], false, 'AI response exceeded size limit'],
    'outer-json'=>[['ok'=>true,'http_code'=>200,'response'=>'{broken','error'=>''], false, 'AI response is not valid JSON'],
    'missing-content'=>[['ok'=>true,'http_code'=>200,'response'=>'{"choices":[]}','error'=>''], false, 'AI response content is missing'],
    'token-limit'=>[['ok'=>true,'http_code'=>200,'response'=>json_encode(['choices'=>[['finish_reason'=>'length','message'=>['content'=>'{"partial":']]]]),'error'=>''], false, 'AI response was truncated at token limit'],
    'inner-json'=>[['ok'=>true,'http_code'=>200,'response'=>json_encode(['choices'=>[['message'=>['content'=>'{broken']]]]),'error'=>''], false, 'AI content is not valid JSON'],
    'ungrounded'=>[['ok'=>true,'http_code'=>200,'response'=>json_encode(['choices'=>[['message'=>['content'=>json_encode(['findings'=>[['rule_code'=>'invented','scope'=>'platform']]])]]]]),'error'=>''], false, 'AI returned no advice grounded in a local rule'],
];
foreach($transportCases as $name=>$case){
    $capturedRequest = null;
    $client = new \lib\Health\AiClient([
        'base_url'=>'https://ai-test.example/v1', 'model'=>'model-a', 'min_sample'=>10,
        'transport'=>function($request) use (&$capturedRequest, $case){
            $capturedRequest = $request;
            return $case[0];
        },
    ]);
    $result = $client->analyze($transportMetrics, $transportRules);
    $assertSame($case[1], $result['ok'], 'AI transport '.$name.' success state');
    if($case[2] !== null) $assertSame($case[2], $result['error'], 'AI transport '.$name.' error classification');
    $assert(isset($capturedRequest['body']) && isset($capturedRequest['endpoint']) && $capturedRequest['endpoint'] === 'https://ai-test.example/v1/chat/completions', 'AI transport '.$name.' exercised the complete request builder');
    $assert(preg_match('/^[a-f0-9]{64}$/D', (string)$result['request_sha256']) === 1, 'AI transport '.$name.' records request digest');
    $assert(preg_match('/^[a-f0-9]{64}$/D', (string)$result['response_sha256']) === 1, 'AI transport '.$name.' records response digest');
    if($name === 'success') $assertSame(1, count($result['data']['findings']), 'AI transport removes duplicate findings');
}

$streamCapturedRequest = null;
$streamEvents = [
    ['choices'=>[['delta'=>['content'=>'{"ok":'], 'finish_reason'=>null]]],
    ['choices'=>[['delta'=>['content'=>'true}'], 'finish_reason'=>'stop']]],
];
$streamResponse = '';
foreach($streamEvents as $event) $streamResponse .= 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
$streamResponse .= 'data: '.json_encode(['choices'=>[],'usage'=>['prompt_tokens'=>17,'completion_tokens'=>4]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
$streamResponse .= "data: [DONE]\n\n";
$streamClient = new \lib\Health\AiClient([
    'base_url'=>'https://ai-test.example/v1', 'model'=>'model-a', 'stream'=>true,
    'timeout'=>600, 'first_byte_timeout'=>300, 'idle_timeout'=>120,
    'transport'=>function($request) use (&$streamCapturedRequest, $streamResponse){
        $streamCapturedRequest = $request;
        return ['ok'=>true,'http_code'=>200,'response'=>$streamResponse,'error'=>'','first_byte_ms'=>25];
    },
]);
$streamResult = $streamClient->completeJson('Return JSON', ['probe'=>true], 300);
$assertSame(true, $streamResult['ok'], 'streaming completeJson succeeds');
$assertSame(true, $streamResult['data']['ok'], 'streaming JSON deltas are reconstructed');
$assertSame(2, $streamResult['stream_events'], 'streaming event count recorded');
$assertSame(25, $streamResult['first_byte_ms'], 'streaming first-byte timing recorded');
$streamRequestBody = json_decode($streamCapturedRequest['body'], true);
$assertSame(true, $streamCapturedRequest['stream'], 'streaming transport flag enabled');
$assertSame(true, $streamRequestBody['stream'], 'streaming API request flag enabled');
$assertSame(true, $streamRequestBody['stream_options']['include_usage'], 'streaming token usage was requested');
$assertSame(17, $streamResult['input_tokens'], 'provider input token usage recorded');
$assertSame(4, $streamResult['output_tokens'], 'provider output token usage recorded');
$assertSame('provider', $streamResult['token_source'], 'provider token source recorded');

$lengthStream = 'data: '.json_encode(['choices'=>[['delta'=>['content'=>'{"partial":'], 'finish_reason'=>'length']]])."\n\n".
    "data: [DONE]\n\n";
$lengthClient = new \lib\Health\AiClient([
    'base_url'=>'https://ai-test.example/v1', 'model'=>'model-a', 'stream'=>true,
    'transport'=>function() use ($lengthStream){ return ['ok'=>true,'http_code'=>200,'response'=>$lengthStream,'error'=>'']; },
]);
$lengthResult = $lengthClient->completeJson('Return JSON', ['probe'=>true], 300);
$assertSame(false, $lengthResult['ok'], 'length-finished stream rejected');
$assertSame('AI response was truncated at token limit', $lengthResult['error'], 'length-finished stream classified');

$incompleteStream = 'data: '.json_encode(['choices'=>[['delta'=>['content'=>'{"partial":'], 'finish_reason'=>null]]])."\n\n";
$incompleteClient = new \lib\Health\AiClient([
    'base_url'=>'https://ai-test.example/v1', 'model'=>'model-a', 'stream'=>true,
    'transport'=>function() use ($incompleteStream){ return ['ok'=>true,'http_code'=>200,'response'=>$incompleteStream,'error'=>'']; },
]);
$incompleteResult = $incompleteClient->completeJson('Return JSON', ['probe'=>true], 300);
$assertSame(false, $incompleteResult['ok'], 'unfinished stream rejected');
$assertSame('AI event stream ended before completion', $incompleteResult['error'], 'unfinished stream classified');

$fallbackOuter = json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'{"fallback":true}']]]]);
$fallbackClient = new \lib\Health\AiClient([
    'base_url'=>'https://ai-test.example/v1', 'model'=>'model-a', 'stream'=>true,
    'transport'=>function() use ($fallbackOuter){ return ['ok'=>true,'http_code'=>200,'response'=>$fallbackOuter,'error'=>'']; },
]);
$fallbackResult = $fallbackClient->completeJson('Return JSON', ['probe'=>true], 300);
$assertSame(true, $fallbackResult['ok'], 'non-stream provider fallback accepted');
$assertSame(true, $fallbackResult['data']['fallback'], 'non-stream provider fallback decoded');

putenv('HEALTH_AI_ENV_FILE');
@unlink($transportEnv);

$aiClient = new \lib\Health\AiClient();
$publicIpMethod = new ReflectionMethod($aiClient, 'isGloballyRoutableIpv4');
foreach(['100.64.0.1','100.100.100.200','198.18.0.1','224.0.0.1','203.0.113.1'] as $blockedIp){
    $assertSame(false, $publicIpMethod->invoke($aiClient, $blockedIp), 'special IPv4 rejected '.$blockedIp);
}
$assertSame(true, $publicIpMethod->invoke($aiClient, '1.1.1.1'), 'public IPv4 accepted');
$payloadMethod = new ReflectionMethod($aiClient, 'buildUserPayload');
$minimalPayload = $payloadMethod->invoke($aiClient, ['level'=>'warning','issues'=>[['code'=>'notify_failed','scope'=>'platform']]]);
$assertSame(['rule_result'], array_keys($minimalPayload), 'AI payload only contains local rule results');
$assert(strpos(json_encode($minimalPayload), 'metrics') === false, 'AI payload excludes complete metrics');
$sanitizeMethod = new ReflectionMethod($aiClient, 'sanitizeRules');
$sanitized = $sanitizeMethod->invoke($aiClient, ['level'=>'warning','issues'=>[[
    'level'=>'warning','code'=>'notify_failed','scope'=>'channel:14','message'=>'敏感通道名称','evidence'=>['notify_failed'=>1],
]]], ['channel:14'=>true]);
$sanitizedJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(strpos($sanitizedJson, '敏感通道名称')===false && strpos($sanitizedJson, 'notify_failed=1')===false, 'AI payload excludes local messages and evidence');
$assertSame('C14', $sanitized['issues'][0]['scope'], 'AI payload anonymizes channel scope');

$validateAi = new ReflectionMethod($aiClient, 'validateResult');
$allowedAi = ['notify_failed|platform'=>['level'=>'warning','code'=>'notify_failed','scope'=>'platform','message'=>'平台存在最终通知失败','evidence'=>['notify_failed'=>1]]];
$fabricatedRejected = false;
try { $validateAi->invoke($aiClient, ['headline'=>'9999 orders','summary'=>'0%','findings'=>[['title'=>'fake','evidence'=>'9999','action'=>'change channel']]], $allowedAi); }
catch(UnexpectedValueException $e){ $fabricatedRejected = true; }
$assertSame(true, $fabricatedRejected, 'free-form AI facts are rejected');
$groundedAi = $validateAi->invoke($aiClient, ['findings'=>[['rule_code'=>'notify_failed','scope'=>'platform','action'=>'执行 DELETE 并切换通道']]], $allowedAi);
$assertSame('平台存在最终通知失败', $groundedAi['findings'][0]['title'], 'AI title comes from local rule');
$assertSame('notify_failed=1', $groundedAi['findings'][0]['evidence'], 'AI evidence comes from local rule');
$assertSame('核对商户通知队列、响应码与重试日志，并保持回调处理幂等。', $groundedAi['findings'][0]['action'], 'AI free-form action is replaced by local playbook');

class HealthReportTransactionFakeDb {
    public function beginTransaction(){ return true; }
    public function getRow(){ return ['id'=>9,'telegram_status'=>2,'telegram_queue_id'=>77]; }
    public function commit(){ return false; }
    public function rollBack(){ return true; }
}
$reportCommitFailed = false;
try { (new \lib\Health\ReportService(new HealthReportTransactionFakeDb(), ['health_report_enabled'=>1,'telegram_admin_chat_id'=>'123456']))->enqueueTelegram(['id'=>9,'ai_status'=>1,'ai'=>['telegram_brief'=>'ok']]); }
catch(RuntimeException $e){ $reportCommitFailed = strpos($e->getMessage(), 'commit failed') !== false; }
$assertSame(true, $reportCommitFailed, 'report queue commit failure is not reported as success');

$normalizeColumn = new ReflectionMethod(\lib\Health\Installer::class, 'normalizeColumnDefinition');
$assertSame(['int','YES',null,''], $normalizeColumn->invoke(null, ['int(11)','YES',null,'']), 'integer display width is normalized');
$assertSame(['int unsigned','NO','0',''], $normalizeColumn->invoke(null, ['integer(10) unsigned','NO','0','']), 'integer alias and unsigned type are normalized');
$assertSame(['datetime','NO','current_timestamp',''], $normalizeColumn->invoke(null, ['datetime','NO','current_timestamp()','']), 'current timestamp default is normalized');

if($failures){
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}
echo "health report regression: ok\n";

function fetch_ai_transport_issue()
{
    return [
        'level'=>'warning', 'code'=>'notify_failed', 'scope'=>'platform',
        'message'=>'平台存在最终通知失败', 'evidence'=>['notify_failed'=>1],
    ];
}
