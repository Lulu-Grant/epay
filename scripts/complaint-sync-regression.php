#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/includes/lib/Complain/IComplain.php';
require $root.'/includes/lib/Complain/CommUtil.php';
require $root.'/includes/lib/Complain/AdminFetch.php';
require $root.'/includes/lib/Complain/AlipayRisk.php';
require $root.'/includes/lib/Complain/Wxpay.php';

function complaintAssert($actual, $expected, $label){
    if ($actual !== $expected) {
        fwrite(STDERR, $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}

use lib\Complain\CommUtil;
use lib\Complain\AdminFetch;

complaintAssert(CommUtil::supports(['plugin'=>'epayn','type'=>1], 0), true, 'supported epayn alipay');
complaintAssert(CommUtil::supports(['plugin'=>'epay','type'=>1], 0), false, 'unsupported old epay');
complaintAssert(CommUtil::supports(['plugin'=>'wxpayn','type'=>1], 0), false, 'wrong plugin type');
complaintAssert(CommUtil::supports(['plugin'=>'wxpayn','type'=>2], 1), false, 'wrong complaint source');
complaintAssert(CommUtil::supports(['plugin'=>'alipay','type'=>1], 1), true, 'risk source');
complaintAssert(AdminFetch::request(['channel'=>'901','subchannel'=>'0','num'=>'20','source'=>'1']),
    ['channel'=>901,'subchannel'=>0,'num'=>20,'source'=>1], 'valid fetch request');
foreach ([['channel'=>901,'num'=>1001],['channel'=>901,'num'=>9],['channel'=>901,'num'=>20,'source'=>2],
    ['channel'=>901,'num'=>20,'subchannel'=>['other']],['channel'=>'bad','num'=>20]] as $badRequest) {
    try { AdminFetch::request($badRequest); }
    catch (InvalidArgumentException $e) { continue; }
    fwrite(STDERR, "invalid complaint fetch request was accepted\n");
    exit(1);
}
$encoded = AdminFetch::encode(['code'=>-1,'msg'=>"upstream \"quoted\" \\ newline\n中文",'html'=>'<script>']);
complaintAssert(json_decode($encoded, true)['msg'], "upstream \"quoted\" \\ newline\n中文", 'structured JSON error');
complaintAssert(strpos($encoded, '<script>') === false, true, 'JSON HTML escaped');
complaintAssert(AdminFetch::hasUnresolvedConfig(['id'=>901,'name'=>'[display]', 'mchid'=>'[merchant_id]']), true, 'unresolved variable blocked');
complaintAssert(AdminFetch::hasUnresolvedConfig(['id'=>901,'mchid'=>'configured-id']), false, 'resolved config accepted');
$failure = CommUtil::syncFailure('QUERY_FAILED', 4, 2, 1, 0, 1);
complaintAssert($failure['partial'], true, 'previously saved records marked partial');
complaintAssert($failure['counts']['failed'], 1, 'failed item counted');

class ComplaintFakeDb {
    public $inserted = null;
    public $inserts = 0;
    public $failInsert = false;
    public $autoLookups = 0;
    public function find($table, $columns, $where, $join = null, $limit = null){
        if ($table === 'complain') {
            if (!$this->inserted) return false;
            return ['id'=>73, 'status'=>$this->inserted['status'], 'edittime'=>$this->inserted['edittime']];
        }
        if ($columns === 'trade_no,uid' && isset($where['api_trade_no'])) {
            return ['trade_no'=>'LOCAL-ORDER-901','uid'=>901];
        }
        if ($columns === 'buyer,realmoney,status,ip') $this->autoLookups++;
        return false;
    }
    public function insert($table, $row){
        $this->inserts++;
        if ($this->failInsert) return false;
        $this->inserted = $row;
        return 73;
    }
    public function update($table, $data, $where){ return 1; }
}

$conf = ['complain_range'=>0, 'complain_freeze_order'=>0, 'complain_auto_black'=>0,
    'complain_auto_refund'=>0, 'complain_auto_reply'=>0];

function complaintRunAdapter($class, $channel, $info){
    global $DB;
    $DB = new ComplaintFakeDb();
    $reflection = new ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('channel');
    $property->setAccessible(true);
    $property->setValue($instance, $channel);
    $method = $reflection->getMethod('updateInfo');
    $method->setAccessible(true);
    complaintAssert($method->invoke($instance, $info), 1, $class.' first insert');
    complaintAssert($DB->inserted['trade_no'], 'LOCAL-ORDER-901', $class.' local order mapping');
    complaintAssert($DB->inserted['uid'], 901, $class.' merchant mapping');
    complaintAssert($method->invoke($instance, $info), 0, $class.' repeated complaint unchanged');
    complaintAssert($DB->inserts, 1, $class.' no duplicate insert');

    $DB = new ComplaintFakeDb();
    $DB->failInsert = true;
    try { $method->invoke($instance, $info); }
    catch (RuntimeException $e) {
        complaintAssert($DB->autoLookups, 0, $class.' no automatic action after failed insert');
        return;
    }
    fwrite(STDERR, $class.' accepted failed database insert'.PHP_EOL);
    exit(1);
}

$riskInfo = [
    'id'=>'RISK-901','status'=>'FINISHED','complaint_trade_info_list'=>[['out_no'=>'UPSTREAM-ORDER','trade_no'=>'UPSTREAM-TRADE']],
    'complain_content'=>'test complaint','contact'=>'','gmt_complain'=>'2026-09-22 10:00:00','gmt_process'=>'2026-09-22 10:01:00'
];
$wxInfo = [
    'complaint_id'=>'WX-901','complaint_state'=>'CLOSED',
    'complaint_order_info'=>[['out_trade_no'=>'UPSTREAM-ORDER','transaction_id'=>'UPSTREAM-TRADE']],
    'complaint_time'=>'2026-09-22T10:00:00+08:00','problem_type'=>'OTHERS','payer_phone'=>null,
    'problem_description'=>'test','complaint_detail'=>'test complaint','complainted_mchid'=>'merchant-test'
];
complaintRunAdapter('lib\Complain\AlipayRisk', ['id'=>901,'type'=>1], $riskInfo);
complaintRunAdapter('lib\Complain\Wxpay', ['id'=>901,'type'=>2], $wxInfo);

function complaintPartialPage($class, $channel, $firstPage){
    global $DB;
    $DB = new ComplaintFakeDb();
    $adapter = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $channelProperty = new ReflectionProperty($class, 'channel');
    $channelProperty->setAccessible(true);
    $channelProperty->setValue($adapter, $channel);
    $serviceProperty = new ReflectionProperty($class, 'service');
    $serviceProperty->setAccessible(true);
    $serviceProperty->setValue($adapter, new class($firstPage) {
        private $firstPage;
        function __construct($firstPage){ $this->firstPage=$firstPage; }
        function riskbatchQuery($a, $b, $c, $page, $size){ return $page === 1 ? $this->firstPage : ['invalid'=>'shape']; }
        function batchQuery($a, $b, $page, $size){ return $page === 1 ? $this->firstPage : ['invalid'=>'shape']; }
    });
    $result = $adapter->refreshNewList(21);
    complaintAssert($result['code'], -1, $class.' malformed second page rejected');
    complaintAssert($result['error'], 'INVALID_RESPONSE', $class.' failure category');
    complaintAssert($result['partial'], true, $class.' partial flag');
    complaintAssert($result['counts']['inserted'], 1, $class.' prior insert count');
    complaintAssert($result['counts']['failed'], 1, $class.' failure count');
}
complaintPartialPage('lib\Complain\AlipayRisk', ['id'=>901,'type'=>1],
    ['total_size'=>21,'complaint_list'=>[$riskInfo]]);
complaintPartialPage('lib\Complain\Wxpay', ['id'=>901,'type'=>2],
    ['offset'=>0,'total_count'=>21,'data'=>[$wxInfo]]);

echo "complaint sync regression: ok\n";
