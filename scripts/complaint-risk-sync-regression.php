#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/lib/Complain/IComplain.php';
require_once __DIR__.'/../includes/lib/Complain/CommUtil.php';
require_once __DIR__.'/../includes/lib/Complain/SyncActionException.php';
require_once __DIR__.'/../includes/lib/Complain/SyncReport.php';
require_once __DIR__.'/../includes/lib/Complain/AlipayRisk.php';
require_once __DIR__.'/../includes/vendor/cccyun/alipay-sdk/src/Aop/AlipayResponseException.php';

function riskCheck($actual, $expected, string $name): void {
    if($actual !== $expected) throw new RuntimeException($name.' failed: '.var_export($actual, true));
}
function riskInfo(int $id, string $status = 'WAIT_PROCESS'): array {
    return ['id'=>'fixture-'.$id, 'status'=>$status,
        'complaint_trade_info_list'=>[['out_no'=>'ORDER-'.$id, 'trade_no'=>'UPSTREAM-'.$id]],
        'complain_content'=>'PRIVATE-COMPLAINT', 'contact'=>'PRIVATE-PHONE',
        'gmt_complain'=>'2026-09-30 00:00:00', 'gmt_process'=>'2026-09-30 00:00:00'];
}
class RiskMemoryDb {
    public array $rows = [];
    public bool $failInsert = false;
    public bool $failUpdate = false;
    public bool $failAutoHandle = false;
    public bool $unmatched = false;
    public int $autoHandles = 0;
    public function find($table, $columns, $where, $sort = null, $limit = null){
        if($table === 'complain') return $this->rows[$where['thirdid']] ?? false;
        if($table === 'order'){
            if($columns === 'buyer,realmoney,status,ip'){
                ++$this->autoHandles;
                if($this->failAutoHandle) throw new RuntimeException('PRIVATE-ACTION-ERROR');
            }
            if($this->unmatched) return false;
            return ['uid'=>901, 'trade_no'=>$where['trade_no'] ?? 'LOCAL-ORDER', 'buyer'=>'', 'realmoney'=>1, 'status'=>1, 'ip'=>''];
        }
        return false;
    }
    public function insert($table, $row){
        if($this->failInsert) return false;
        $row['id'] = count($this->rows) + 1;
        $this->rows[$row['thirdid']] = $row;
        return $row['id'];
    }
    public function update($table, $data, $where){
        if($this->failUpdate) return false;
        foreach($this->rows as &$row) if($row['id'] === $where['id']) $row = array_merge($row, $data);
        return 1;
    }
    public function getRow($sql, $params){
        return ['uid'=>901, 'trade_no'=>'fixture', 'title'=>'fixture', 'content'=>'fixture',
            'addtime'=>'2026-09-30 00:00:00', 'ordername'=>'fixture', 'money'=>1];
    }
}
class RiskFakeNotice {
    public static function send(...$args){ throw new RuntimeException('PRIVATE-NOTIFICATION-ERROR'); }
}
class_alias(RiskFakeNotice::class, 'lib\MsgNotice');
class RiskFakeService {
    public array $pages;
    public array $replyResults = [];
    public array $replies = [];
    public int $queries = 0;
    public function __construct(array $pages){ $this->pages = $pages; }
    public function riskbatchQuery($a, $b, $c, $page, $size){
        ++$this->queries;
        $value = $this->pages[$page - 1] ?? ['total_size'=>0];
        if($value instanceof Throwable) throw $value;
        return $value;
    }
    public function riskfeedbackSubmit($id, $code, $text, $images){
        $this->replies[] = $id;
        riskCheck([$code, $text, $images], ['ORTHER', 'fixture reply', null], 'existing action preserved');
        $value = $this->replyResults[$id] ?? true;
        if($value instanceof Throwable) throw $value;
        return $value;
    }
}
function riskAdapter(RiskFakeService $service): \lib\Complain\AlipayRisk {
    $r = new ReflectionClass(\lib\Complain\AlipayRisk::class);
    $adapter = $r->newInstanceWithoutConstructor();
    $r->getProperty('channel')->setValue($adapter, ['id'=>901, 'type'=>1]);
    $r->getProperty('service')->setValue($adapter, $service);
    return $adapter;
}

$oldErrorLog = ini_get('error_log');
$testLog = tempnam(sys_get_temp_dir(), 'epay-complaint-');
ini_set('error_log', $testLog);
try {
    $conf = ['complain_range'=>0, 'complain_auto_reply'=>1, 'complain_auto_reply_con'=>'fixture reply',
        'complain_freeze_order'=>0, 'complain_auto_black'=>0, 'complain_auto_refund'=>0];
    $DB = new RiskMemoryDb();
    $svc = new RiskFakeService([['total_size'=>2, 'complaint_list'=>[riskInfo(1), riskInfo(2)]]]);
    $svc->replyResults['fixture-1'] = new \Alipay\Aop\AlipayResponseException([
        'code'=>'40004', 'sub_code'=>'isv.invalid-parameter', 'sub_msg'=>'PRIVATE-UPSTREAM-ERROR']);
    $adapter = riskAdapter($svc);
    $r = $adapter->refreshNewList(20);
    riskCheck([$r['code'], $r['sync_complete'], $r['warning']], [0, true, true], 'warning is not a sync failure');
    riskCheck([$r['counts']['inserted'], $r['counts']['auto_reply_failed'], count($DB->rows)], [2,1,2], 'later rows persist');
    riskCheck(str_contains(json_encode($r), 'PRIVATE-'), false, 'response does not expose upstream text');
    riskCheck($DB->autoHandles, 2, 'reply failure does not suppress configured order handling');
    $r = $adapter->refreshNewList(20);
    riskCheck([$r['counts']['unchanged'], $r['counts']['auto_reply_failed'], count($svc->replies)], [2,0,2], 'repeat fetch neither replies again nor retains old warning');

    $DB = new RiskMemoryDb();
    $svc = new RiskFakeService([['total_size'=>21, 'complaint_list'=>[riskInfo(3)]], new RuntimeException('PRIVATE-QUERY-ERROR')]);
    $svc->replyResults['fixture-3'] = false;
    $r = riskAdapter($svc)->refreshNewList(21);
    riskCheck([$r['code'], $r['error'], $r['partial'], $r['counts']['inserted'], $r['counts']['auto_reply_failed']],
        [-1, 'QUERY_FAILED', true, 1, 1], 'later query failure preserves earlier warning and saved count');

    foreach([new TypeError('PRIVATE-TYPE-ERROR'), new RuntimeException('PRIVATE-TIMEOUT'), false] as $failure){
        $DB = new RiskMemoryDb();
        $svc = new RiskFakeService([['total_size'=>1, 'complaint_list'=>[riskInfo(4)]]]);
        $svc->replyResults['fixture-4'] = $failure;
        $r = riskAdapter($svc)->refreshNewList(20);
        riskCheck([$r['code'], $r['counts']['auto_reply_failed'], count($svc->replies)], [0,1,1], 'uncertain reply reported without retry');
    }

    foreach([['total_size'=>0], ['total_size'=>'0','complaint_list'=>[]]] as $empty){
        $svc = new RiskFakeService([$empty]);
        $r = riskAdapter($svc)->refreshNewList(20);
        riskCheck([$r['code'], $r['counts']['fetched'], $svc->queries], [0,0,1], 'explicit empty response');
    }
    foreach([[], ['total_size'=>1], ['total_size'=>-1,'complaint_list'=>[]],
        ['total_size'=>1,'complaint_list'=>[]], ['total_size'=>0,'complaint_list'=>'bad'],
        ['total_size'=>0,'complaint_list'=>[riskInfo(5)]], ['total_size'=>1,'complaint_list'=>[['id'=>'bad']]]] as $bad){
        $DB = new RiskMemoryDb();
        $svc = new RiskFakeService([$bad]);
        $r = riskAdapter($svc)->refreshNewList(20);
        riskCheck([$r['error'], count($DB->rows), count($svc->replies)], ['INVALID_RESPONSE',0,0], 'malformed response cannot silently succeed or run actions');
    }

    $DB = new RiskMemoryDb(); $DB->failInsert = true;
    $svc = new RiskFakeService([['total_size'=>1,'complaint_list'=>[riskInfo(6)]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    riskCheck([$r['error'], count($svc->replies), $DB->autoHandles], ['SAVE_FAILED',0,0], 'failed insert has no actions');
    $DB = new RiskMemoryDb(); $DB->failAutoHandle = true;
    $svc = new RiskFakeService([['total_size'=>2,'complaint_list'=>[riskInfo(7,'FINISHED'),riskInfo(8,'FINISHED')]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    riskCheck([$r['error'], $r['counts']['inserted'], count($DB->rows)], ['ACTION_FAILED',1,1], 'order action failure remains blocking');

    $conf['complain_auto_reply'] = 0;
    $DB = new RiskMemoryDb();
    $svc = new RiskFakeService([['total_size'=>40,'complaint_list'=>array_map('riskInfo', range(1,20))],
        ['total_size'=>40,'complaint_list'=>array_map('riskInfo', range(21,40))]]);
    $r = riskAdapter($svc)->refreshNewList(21);
    riskCheck([$r['counts']['fetched'], count($DB->rows), count($svc->replies), $svc->queries], [21,21,0,2], 'requested count is a hard bound and disabled reply respected');
    $svc = new RiskFakeService([['total_size'=>1,'complaint_list'=>[riskInfo(1,'FINISHED')]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    riskCheck([$r['counts']['updated'], $DB->rows['fixture-1']['status']], [1,2], 'existing status updated');
    $DB->failUpdate = true;
    $svc = new RiskFakeService([['total_size'=>1,'complaint_list'=>[riskInfo(1)]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    riskCheck($r['error'], 'SAVE_FAILED', 'failed update is not success');
    $DB = new RiskMemoryDb(); $DB->unmatched = true;
    $svc = new RiskFakeService([['total_size'=>1,'complaint_list'=>[riskInfo(22)]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    riskCheck([$r['counts']['skipped_unmatched'], count($DB->rows)], [1,0], 'unmatched order scope unchanged');

    $DB = new RiskMemoryDb();
    $_GET['key'] = 'fixture-monitor';
    $svc = new RiskFakeService([['total_size'=>2,'complaint_list'=>[riskInfo(23),riskInfo(24)]]]);
    $r = riskAdapter($svc)->refreshNewList(20);
    unset($_GET['key']);
    riskCheck([$r['code'], $r['counts']['inserted'], $r['counts']['notification_failed']], [0,2,2], 'monitor notification failure cannot block synchronization');

    $log = file_get_contents($testLog);
    riskCheck(str_contains($log, 'PRIVATE-'), false, 'no exception messages, complaint text or phone in logs');
    riskCheck(str_contains($log, 'isv.invalid-parameter'), true, 'structured upstream sub-code preserved');
    riskCheck(str_contains($log, '"complaint_id":1'), true, 'failed automatic reply traceable to local record');
    echo "RiskGO sync regression: ok\n";
} finally {
    ini_set('error_log', $oldErrorLog);
    unlink($testLog);
}
