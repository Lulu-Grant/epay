<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('EPAY_LIST_TEST_ISOLATED') !== '1') throw new RuntimeException('isolated_test_required');
require dirname(__DIR__).'/config.php';
if ($dbconfig['host'] !== '127.0.0.1' || $dbconfig['dbname'] !== 'epay_acceptance' || $dbconfig['port'] < 33080
    || !preg_match('~^http://127\.0\.0\.1:[0-9]+/shopping\.php\?act=notify$~D', getenv('LIST_TEST_NOTIFY_URL') ?: '')) {
    throw new RuntimeException('isolated_database_required');
}
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) throw new RuntimeException('php84_required');
require dirname(__DIR__).'/includes/common.php';
use lib\ListReadCache;
use lib\ListQueryReader;
use lib\Payment;

function payAssert(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
function quantiles(array $samples): array {
    sort($samples); return ['samples'=>count($samples),'p50_ms'=>round($samples[(int)floor((count($samples)-1)*.5)],3),
        'p95_ms'=>round($samples[(int)floor((count($samples)-1)*.95)],3)];
}
function callbackFixture(): array {
    global $DB;
    $no='LPC'.bin2hex(random_bytes(10));
    $row=['trade_no'=>$no,'out_trade_no'=>$no,'uid'=>1000,'tid'=>0,'type'=>1,'channel'=>1,'subchannel'=>0,
        'money'=>'1.00','realmoney'=>'1.00','getmoney'=>'1.00','name'=>'Cache acceptance','param'=>'fixture',
        'notify_url'=>getenv('LIST_TEST_NOTIFY_URL'),'return_url'=>'http://127.0.0.1/','status'=>0,'addtime'=>'NOW()'];
    if ($DB->insert('order',$row) === false) throw new RuntimeException('fixture_insert');
    invalidateListReadCache('payment',1000);
    $row=$DB->find('order','*',['trade_no'=>$no]); $row['typeshowname']='Fixture';
    return $row;
}
function callbackRun(array $row): float {
    ListReadCache::reset(); // Each synthetic callback models a new request, including fault retries.
    $start=hrtime(true); Payment::processOrder(true,$row,'fixture-api',null); return (hrtime(true)-$start)/1e6;
}
$channel=$DB->find('channel','*',['id'=>1]);
if (($argv[1] ?? '') === '--mixed-worker') {
    $rate=(int)$argv[2]; $start=(float)$argv[3];
    if(!in_array($rate,[1,5],true)) throw new RuntimeException('invalid_worker_rate');
    for($i=0;$i<$rate*10;$i++) {
        $target=$start+$i/$rate;
        if($target>microtime(true)) usleep((int)(($target-microtime(true))*1000000));
        $row=callbackFixture(); callbackRun($row);
        if((int)$DB->findColumn('order','status',['trade_no'=>$row['trade_no']])!==1) throw new RuntimeException('worker_payment');
    }
    echo json_encode(['writes'=>$rate*10]); exit;
}
$control=getenv('EPAY_LIST_CACHE_CONFIG');
$original=file_get_contents($control);
$config=json_decode($original,true);
$report=['php'=>PHP_VERSION,'database'=>$DB->getColumn('SELECT VERSION()'),'callback'=>[],'mixed'=>[]];
$selectMode=static function(string $mode) use ($config,$control): void {
    foreach(glob($config['directory'].'/*/tags') as $dir) chmod($dir,0700);
    $options=$config;
    foreach(['counts','summaries','dictionaries','metadata'] as $kind) $options[$kind.'_enabled']=$mode!=='off';
    $options['metrics_sample_permille']=0;
    file_put_contents($control,json_encode($options)); ListReadCache::reset();
    if($mode==='fault') foreach(glob($config['directory'].'/*/tags') as $dir) chmod($dir,0500);
};
try {
    // Alternate modes within each round so growth, host scheduling and warmed DB pages
    // do not systematically benefit the first mode or penalize the last mode.
    $before=(float)$DB->findColumn('user','money',['uid'=>1000]);
    $times=['off'=>[],'on'=>[],'fault'=>[]]; $ids=[];
    for($i=0;$i<100;$i++) {
        $modes=['off','on','fault'];
        $modes=array_merge(array_slice($modes,$i%3),array_slice($modes,0,$i%3));
        foreach($modes as $mode) {
            $selectMode($mode);
            $row=callbackFixture(); $ids[]=$row['trade_no']; $times[$mode][]=callbackRun($row);
            $paid=$DB->find('order','*',['trade_no'=>$row['trade_no']]);
            if((int)$paid['status']!==1 || (int)$paid['notify']!==0) throw new RuntimeException('payment_notify_result');
            if($i%10===0) callbackRun($paid+['typeshowname'=>'Fixture']);
        }
    }
    $after=(float)$DB->findColumn('user','money',['uid'=>1000]);
    payAssert(abs($after-$before-300)<.001,'300 callbacks credit once with duplicate retries across off/on/fault');
    foreach($ids as $id) {
        $shadow=$DB->find('shop_orders','pay_status,order_status',['pay_trade_no'=>$id]);
        if(!$shadow || (int)$shadow['pay_status']!==1 || (int)$shadow['order_status']!==2) throw new RuntimeException('shadow_result');
    }
    foreach($times as $mode=>$samples) $report['callback'][$mode]=quantiles($samples);
    echo 'CALLBACK_TIMINGS '.json_encode($report['callback']).PHP_EOL;
    foreach (['off','on','fault'] as $mode) {
        $selectMode($mode);
        ListQueryReader::paymentList($DB,[],1000);
        $tagDirs=glob($config['directory'].'/*/tags');
        foreach([0,1,10] as $rate) {
            $started=microtime(true)+.3; $writes=0; $samples=[]; $workers=[];
            for($worker=0;$worker<($rate===10?2:($rate?1:0));$worker++) {
                $pipes=[];
                $process=proc_open([PHP_BINARY,__FILE__,'--mixed-worker',(string)($rate===10?5:1),(string)($started+$worker*.1)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['file',getenv('EPAY_LIST_TEST_REPORT').'.worker.log','a']],$pipes);
                if(!is_resource($process)) throw new RuntimeException('worker_start');
                fclose($pipes[0]); $workers[]=[$process,$pipes[1]];
            }
            $metricsBefore=ListReadCache::metrics();
            for($i=0;$i<100;$i++) {
                $target=$started+$i*.1;
                if($target>microtime(true)) usleep((int)(($target-microtime(true))*1000000));
                $start=hrtime(true); $list=ListQueryReader::paymentList($DB,[],1000); $samples[]=(hrtime(true)-$start)/1e6;
                if(!$list['rows'] || !$list['meta']['rows_live']) throw new RuntimeException('mixed_read');
            }
            foreach($workers as [$process,$pipe]) {
                $result=json_decode(stream_get_contents($pipe),true); fclose($pipe);
                if(proc_close($process)!==0 || !isset($result['writes'])) throw new RuntimeException('worker_result');
                $writes+=$result['writes'];
            }
            $metrics=[]; foreach(ListReadCache::metrics() as $name=>$value) $metrics[$name]=$value-($metricsBefore[$name]??0);
            $report['mixed'][]=['mode'=>$mode,'target_writes_per_second'=>$rate,'writes'=>$writes,'elapsed_seconds'=>round(microtime(true)-$started,3),
                'read'=>quantiles($samples),'metrics'=>$metrics];
            payAssert($writes===$rate*10,'mixed workload read and write count: '.$mode.' '.$rate.'/s');
        }
        foreach($tagDirs as $dir) chmod($dir,0700);
    }
    file_put_contents(getenv('EPAY_LIST_TEST_REPORT'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo 'CALLBACK_TIMINGS '.json_encode($report['callback']).PHP_EOL;
    foreach(['on','fault'] as $mode) {
        $allowed=max(5,$report['callback']['off']['p95_ms']*.1);
        payAssert($report['callback'][$mode]['p95_ms']-$report['callback']['off']['p95_ms']<=$allowed,'callback P95 overhead within max(10%,5ms): '.$mode);
    }
    file_put_contents(getenv('EPAY_LIST_TEST_REPORT'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo 'LIST_PAYMENT_CACHE_OK '.json_encode($report).PHP_EOL;
} finally {
    foreach(glob($config['directory'].'/*/tags') as $dir) chmod($dir,0700);
    file_put_contents($control,$original); ListReadCache::reset();
}
