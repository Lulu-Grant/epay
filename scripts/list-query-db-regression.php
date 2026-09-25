<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('EPAY_LIST_TEST_ISOLATED') !== '1') throw new RuntimeException('isolated_test_required');
require dirname(__DIR__).'/config.php';
if (($dbconfig['host'] ?? '') !== '127.0.0.1' || ($dbconfig['dbname'] ?? '') !== 'epay_list_acceptance'
    || (int)($dbconfig['port'] ?? 0) < 33080) throw new RuntimeException('isolated_database_required');
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) throw new RuntimeException('php84_required');
require dirname(__DIR__).'/includes/autoloader.php';
Autoloader::register();
require dirname(__DIR__).'/includes/functions.php';
date_default_timezone_set('Asia/Shanghai');

use lib\ListReadCache;
use lib\ListCacheInvalidator;
use lib\ListQueryReader;
use lib\Shop\OrderService;
use lib\Shop\GoodsService;

class ListTestDb extends \lib\PdoHelper
{
    public array $queries = [];
    public function query($_sql, $_array = null) { $this->queries[] = $_sql; return parent::query($_sql, $_array); }
}
function verify(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
function mustExec($db, string $sql, array $bind = []): void
{
    if ($db->exec($sql, $bind) === false) throw new RuntimeException('fixture_sql_failed: '.$db->error());
}
function cacheControl(bool $enabled, string $epoch = '1'): void
{
    $config = ['directory'=>getenv('EPAY_LIST_TEST_CACHE'), 'schema_epoch'=>$epoch,
        'counts_enabled'=>$enabled, 'summaries_enabled'=>$enabled, 'dictionaries_enabled'=>$enabled,
        'metadata_enabled'=>$enabled, 'admin_enabled'=>true, 'merchants_enabled'=>true];
    file_put_contents(getenv('EPAY_LIST_CACHE_CONFIG'), json_encode($config));
    ListReadCache::reset();
}
function benchmark(callable $call, int $samples = 100): array
{
    $call();
    $times = [];
    for ($i=0; $i<$samples; $i++) { $start = hrtime(true); $call(); $times[] = (hrtime(true)-$start)/1e6; }
    sort($times);
    return ['samples'=>$samples, 'p50_ms'=>round($times[(int)floor(($samples-1)*0.5)],3),
        'p95_ms'=>round($times[(int)floor(($samples-1)*0.95)],3)];
}

$DB = new ListTestDb($dbconfig, true);
$siteurl = 'http://127.0.0.1/';
$conf = ['user_refund'=>1];
mustExec($DB, "INSERT INTO pre_user (uid,gid,`key`,money,email,status,pay,mode) VALUES (1000,0,'fixture-only-a',1000000,'a@example.test',1,1,0),(1001,0,'fixture-only-b',1000000,'b@example.test',1,1,0)");
mustExec($DB, "INSERT INTO pre_channel (id,mode,type,plugin,name,rate,status,config) VALUES (1,0,1,'alipay','Fixture',100,1,'{}')");
mustExec($DB, "INSERT INTO pre_order (trade_no,out_trade_no,api_trade_no,uid,tid,type,channel,name,money,realmoney,getmoney,profitmoney,status,notify,addtime,date)
    SELECT IF(seq%2=0,CONCAT('LP',LPAD(seq,19,'0')),LPAD(seq,19,'0')),CONCAT('fixture-',seq),CONCAT('api-',seq),
    IF(seq%3=0,1001,1000),0,1,1,CONCAT('Fixture ',seq%10),10,10,9,1,seq%5,0,DATE_SUB(NOW(),INTERVAL (seq%30) DAY),CURDATE()
    FROM seq_1_to_100000");
mustExec($DB, "INSERT INTO pre_shop_orders (id,shop_trade_no,pay_trade_no,out_trade_no,goods_id,goods_name,goods_price,quantity,money,pay_type,pay_status,order_status,buyer_contact,query_token,status_times,deleted,addtime,updatetime)
    SELECT seq,CONCAT('S',LPAD(seq,21,'0')),IF(seq%2=0,CONCAT('LP',LPAD(seq,19,'0')),LPAD(seq,19,'0')),CONCAT('fixture-',seq),1,CONCAT('Fixture ',seq%10),10,1,10,1,IF(seq%5=1,1,0),IF(seq%5=1,2,0),CONCAT('contact-',seq),MD5(CONCAT('fixture-',seq)),'{}',IF(seq%100=0,1,0),NOW(),NOW() FROM seq_1_to_100000");
mustExec($DB, "INSERT INTO pre_record (uid,action,money,oldmoney,newmoney,type,trade_no,date) VALUES (1000,1,1,0,1,'Fixture','LP0000000000000000002',NOW()),(1001,1,1,0,1,'Fixture','LP0000000000000000006',NOW())");
mustExec($DB, "UPDATE pre_config SET v='0' WHERE k='captcha_open'");
mustExec($DB, "UPDATE pre_cache SET v='' WHERE k='config'");

$report = ['php'=>PHP_VERSION, 'database'=>$DB->getColumn('SELECT VERSION()'), 'payment_rows'=>100000, 'shop_rows'=>100000];
cacheControl(false);
$off = ListQueryReader::paymentList($DB, ['offset'=>0, 'limit'=>30]);
verify($off['total'] === 100000 && count($off['rows']) === 30, 'uncached payment result');
$report['payment_uncached'] = benchmark(fn()=>ListQueryReader::paymentList($DB, ['offset'=>0, 'limit'=>30]));
cacheControl(true);
$DB->queries = [];
$report['payment_cached'] = benchmark(fn()=>ListQueryReader::paymentList($DB, ['offset'=>0, 'limit'=>30]));
$aggregates = count(array_filter($DB->queries, fn($sql)=>str_contains($sql, 'COUNT(*)')));
verify($aggregates <= 10, 'at least 90 percent fewer count queries');
$report['payment_cached']['count_queries'] = $aggregates;
verify(count(array_filter($DB->queries, fn($sql)=>str_contains($sql, 'FROM pre_type'))) === 1, 'display types reused');
verify(count(array_filter($DB->queries, fn($sql)=>str_contains($sql, 'SELECT A.*,B.plugin'))) === 101, 'rows always queried live');
$on = ListQueryReader::paymentList($DB, ['offset'=>0, 'limit'=>30]);
verify($off['rows'] === $on['rows'], 'cached mode preserves rows and money types');
$merchantA = ListQueryReader::paymentList($DB, ['uid'=>1001, 'offset'=>0, 'limit'=>20], 1000);
$merchantB = ListQueryReader::paymentList($DB, ['uid'=>1000, 'offset'=>0, 'limit'=>20], 1001);
verify(array_unique(array_column($merchantA['rows'], 'uid')) === ['1000'] || array_unique(array_column($merchantA['rows'], 'uid')) === [1000], 'merchant cannot override scope');
verify($merchantA['total'] + $merchantB['total'] === 100000, 'merchant counts isolated');
$adminSummary = ListQueryReader::paymentSummary($DB, []);
$userSummary = ListQueryReader::paymentSummary($DB, [], 1000);
verify(isset($adminSummary['data']['profit_money']) && !isset($userSummary['data']['profit_money']) && isset($userSummary['data']['get_money']), 'admin profit never leaks into merchant summary');
ListReadCache::forSite()->invalidate(['payment.global']);
$DB->queries=[];
$report['summary_cached']=benchmark(fn()=>ListQueryReader::paymentSummary($DB,[]));
$summaryLoads=count(array_filter($DB->queries,fn($sql)=>str_contains($sql,'SUM(')));
verify($summaryLoads===1,'100 summary calls reuse one SQL aggregate');
$report['summary_cached']['aggregate_queries']=$summaryLoads;

foreach ([0,5000,50000] as $offset) {
    $legacySql = 'SELECT A.*,B.plugin FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id ORDER BY A.trade_no DESC LIMIT '.$offset.',30';
    $expected = $DB->getAll($legacySql);
    $actual = ListQueryReader::paymentList($DB, ['offset'=>$offset,'limit'=>30]);
    foreach ($actual['rows'] as &$row) { unset($row['typename'], $row['typeshowname']); } unset($row);
    verify($expected === $actual['rows'], 'payment deep page preserves full order at offset '.$offset);
    $report['deep_'.$offset] = benchmark(fn()=>ListQueryReader::paymentList($DB, ['offset'=>$offset,'limit'=>30]));
    $report['deep_legacy_rows_'.$offset] = benchmark(fn()=>$DB->getAll($legacySql));
}

foreach ([['utf8','utf8'],['utf8','utf8mb4'],['utf8mb4','utf8']] as $n=>[$payCharset,$shopCharset]) {
    cacheControl(false);
    mustExec($DB, 'ALTER TABLE pre_order MODIFY trade_no varchar(32) CHARACTER SET '.$payCharset.' COLLATE '.$payCharset.'_general_ci NOT NULL');
    mustExec($DB, 'ALTER TABLE pre_shop_orders MODIFY pay_trade_no varchar(32) CHARACTER SET '.$shopCharset.' COLLATE '.$shopCharset.'_general_ci NOT NULL');
    cacheControl(true, 'charset-'.$n);
    $DB = new ListTestDb($dbconfig, true);
    $exact = OrderService::adminList(['search_field'=>'pay_trade_no','keyword'=>'LP0000000000000000002'],0,20);
    verify($exact['total'] === 1 && $exact['rows'][0]['pay_trade_no'] === 'LP0000000000000000002', 'exact mixed charset search '.$n);
    $legacy = OrderService::adminList(['keyword'=>'LP0000000000000000002'],0,20);
    verify($legacy['total'] === 1, 'legacy keyword compatibility '.$n);
    $uidList = OrderService::adminList(['search_field'=>'merchant_uid','keyword'=>'1001'],0,20);
    verify(count(array_filter($uidList['rows'],fn($row)=>(int)$row['merchant_uid'] !== 1001)) === 0, 'reverse UID join '.$n);
    $join = \lib\Shop\TradeJoin::condition('P','A');
    $plan = $DB->getAll('EXPLAIN SELECT A.id,P.uid FROM pre_shop_orders A LEFT JOIN pre_order P ON '.$join.' WHERE A.deleted=0 ORDER BY A.id DESC LIMIT 20');
    $paymentPlan = array_values(array_filter($plan,fn($row)=>$row['table'] === 'P'))[0];
    verify(in_array($paymentPlan['type'],['eq_ref','const'],true) && $paymentPlan['key'] === 'PRIMARY', 'indexed payment join '.$n);
    $exactPlan=$DB->getAll("EXPLAIN SELECT A.id FROM pre_shop_orders A WHERE A.deleted=0 AND A.pay_trade_no='LP0000000000000000002'");
    verify(in_array($exactPlan[0]['type'],['const','ref'],true) && $exactPlan[0]['key']!==null,'exact shop search uses unique index '.$n);
    $report['exact_plan_'.$n]=array_map(fn($row)=>array_intersect_key($row,array_flip(['table','type','key','rows','Extra'])),$exactPlan);
    if($n===0) foreach([0,5000,50000] as $offset) {
        $legacySql='SELECT A.*,P.uid merchant_uid FROM pre_shop_orders A LEFT JOIN pre_order P ON '.$join.' WHERE A.deleted=0 ORDER BY A.id DESC LIMIT '.$offset.',20';
        $expected=$DB->getAll($legacySql);
        $actual=OrderService::adminList([], $offset,20)['rows'];
        foreach($actual as &$row) unset($row['record_source_text']); unset($row);
        verify($expected===$actual,'shop deep page preserves order at '.$offset);
        $report['shop_deep_'.$offset]=benchmark(fn()=>OrderService::adminList([],$offset,20));
        $report['shop_deep_legacy_rows_'.$offset]=benchmark(fn()=>$DB->getAll($legacySql));
    }
    $report['charset_'.$n] = benchmark(fn()=>OrderService::adminList([],0,20));
    verify($report['charset_'.$n]['p95_ms'] < 200, 'shop warm SQL service P95 below 200 ms '.$n);
    $DB = new ListTestDb($dbconfig, true);
    OrderService::adminList([],0,20);
    verify(count(array_filter($DB->queries,fn($sql)=>str_contains($sql,'SHOW FULL') || str_contains($sql,'information_schema'))) === 0, 'metadata reused across database objects '.$n);
}

$goods = GoodsService::adminList([],0,20);
$newGoods = GoodsService::create(['name'=>'Fixture added', 'price'=>'1.00', 'stock'=>10, 'sort'=>0, 'status'=>1]);
verify(GoodsService::adminList([],0,20)['total'] === $goods['total']+1, 'goods creation invalidates count');
GoodsService::softDelete($newGoods);
verify(GoodsService::adminList([],0,20)['total'] === $goods['total'], 'goods deletion invalidates count');
$beforeShop = OrderService::adminList([],0,20)['total'];
OrderService::softDelete(2);
verify(OrderService::adminList([],0,20)['total'] === $beforeShop-1, 'shop soft delete invalidates count');
ListQueryReader::paymentList($DB, ['dstatus'=>1], 1000);
$beforePaid = ListQueryReader::paymentList($DB, ['dstatus'=>1], 1000)['total'];
$freeze = \lib\Order::freeze('0000000000000000001');
verify($freeze['code'] === 0, 'real freeze business path succeeds');
verify(ListQueryReader::paymentList($DB, ['dstatus'=>1], 1000)['total'] === $beforePaid-1, 'freeze invalidates merchant paid count');
verify(\lib\Order::unfreeze('0000000000000000001')['code'] === 0, 'real unfreeze succeeds');
verify(ListQueryReader::paymentList($DB, ['dstatus'=>1], 1000)['total'] === $beforePaid, 'unfreeze refreshes merchant count');
$refund = \lib\Order::refund('fixture-refund', '0000000000000000001', 10);
verify($refund['code'] === 0 && ListQueryReader::paymentList($DB, ['dstatus'=>1], 1000)['total'] === $beforePaid-1, 'refund invalidates paid count');
$ledgerBefore = ListQueryReader::ledgerList($DB, [], 1000)['total'];
changeUserMoney(1000, 1, true, 'Fixture');
verify(ListQueryReader::ledgerList($DB, [], 1000)['total'] === $ledgerBefore+1, 'committed money change invalidates ledger');

// A rollback must not publish invalidation; another connection still sees the committed snapshot.
$shopBefore = OrderService::adminList([],0,20);
$DB->beginTransaction();
mustExec($DB, 'UPDATE pre_shop_orders SET deleted=1 WHERE id=3');
ListCacheInvalidator::changed('shop');
$DB->rollBack();
$shopAfter = OrderService::adminList([],0,20);
verify($shopBefore['total'] === $shopAfter['total'] && $shopAfter['meta']['total_cached'], 'transaction rollback does not publish invalidation');

// Simulate an external write that did not invalidate: rows stay live; one fresh count repairs the tail.
ListQueryReader::ledgerList($DB, [], 1001);
mustExec($DB, "DELETE FROM pre_record WHERE uid=1001");
$tail = ListQueryReader::ledgerList($DB, ['offset'=>20,'limit'=>20], 1001);
verify($tail['total'] === 0 && $tail['rows'] === [] && $tail['meta']['offset'] === 0 && !$DB->db->inTransaction(), 'empty tail corrected once in a read-only snapshot');
$fresh = ListQueryReader::paymentList($DB, ['fresh'=>1,'limit'=>30]);
verify(!$fresh['meta']['total_cached'], 'fresh query bypasses aggregate');

file_put_contents(getenv('EPAY_LIST_TEST_REPORT'), json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'LIST_QUERY_DB_OK '.json_encode($report, JSON_UNESCAPED_SLASHES).PHP_EOL;
