<?php
// Runs only against a newly created, disposable database; never loads config.php.
use lib\Shop\OrderService;
use lib\Shop\TradeJoin;

require dirname(__DIR__, 2).'/includes/autoloader.php';
Autoloader::register();

function shopCheck($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "[OK] {$message}\n";
}
class ShopCheckDb extends \lib\PdoHelper {
    public array $reads = [];
    public bool $failList = false;
    public function getAll($_sql, $_array = null) {
        $this->reads[] = $_sql;
        if ($this->failList && str_starts_with($_sql, 'SELECT A.*,P.uid')) return false;
        return parent::getAll($_sql, $_array);
    }
}
$host = getenv('EPAY_DB_HOST') ?: '127.0.0.1';
$port = getenv('EPAY_DB_PORT') ?: '3306';
$user = getenv('EPAY_DB_USER') ?: 'root';
$password = getenv('EPAY_DB_PASSWORD') ?: '';
$name = 'epay_shop_check_'.bin2hex(random_bytes(6));
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
try {
    $pdo = new PDO($dsn.';dbname='.$name, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET sql_mode=''");
    foreach (['install.sql', 'addon_shop.sql'] as $file) {
        $pdo->exec(file_get_contents(dirname(__DIR__, 2).'/install/'.$file));
    }
    $insertPay = $pdo->prepare("INSERT INTO pre_order (trade_no,out_trade_no,uid,tid,name,money,type,status,addtime) VALUES (?,?,1000,0,'Original',10,1,?,NOW())");
    $insertShop = $pdo->prepare("INSERT INTO pre_shop_orders (shop_trade_no,pay_trade_no,out_trade_no,goods_id,goods_name,goods_price,quantity,money,pay_type,pay_status,order_status,query_token,status_times,addtime) VALUES (?,?,?,1,'Fixture',10,1,10,1,?,?,?,'{\"source\":\"merchant_order_shadow\"}',NOW())");
    $pdo->beginTransaction();
    for ($i=1; $i<=20000; $i++) {
        $trade = ($i % 2 ? 'LP' : '').sprintf('%019d', $i);
        $paid = $i % 2;
        $insertPay->execute([$trade, 'fixture-'.$i, $paid]);
        $insertShop->execute(['S'.sprintf('%021d', $i), $trade, 'fixture-'.$i, $paid, $paid ? 2 : 0, str_repeat('a',32)]);
    }
    $pdo->commit();
    $config = ['host'=>$host, 'port'=>$port, 'user'=>$user, 'pwd'=>$password, 'dbname'=>$name, 'dbqz'=>'pre'];
    foreach ([['utf8','utf8mb4'], ['utf8','utf8'], ['utf8mb4','utf8mb4']] as [$payCharset, $shopCharset]) {
        $pdo->exec("ALTER TABLE pre_order MODIFY trade_no varchar(32) CHARACTER SET {$payCharset} COLLATE {$payCharset}_general_ci NOT NULL");
        $pdo->exec("ALTER TABLE pre_shop_orders MODIFY pay_trade_no varchar(32) CHARACTER SET {$shopCharset} COLLATE {$shopCharset}_general_ci NOT NULL");
        $DB = new ShopCheckDb($config);
        $list = OrderService::adminList([],0,20);
        shopCheck($list['total']===20000 && count($list['rows'])===20 && intval($list['rows'][0]['merchant_uid'])===1000, "{$payCharset}/{$shopCharset}: page and merchant association");
        $sql = end($DB->reads);
        $explain = $DB->getAll('EXPLAIN '.$sql);
        $payment = array_values(array_filter($explain, fn($r)=>$r['table']==='P'))[0];
        shopCheck($payment['key']==='PRIMARY' && in_array($payment['type'],['eq_ref','ref','const']), 'payment primary key lookup');
        shopCheck(!str_contains(json_encode($explain),'join buffer'), 'no full buffered join');
        $durations=[];
        for($i=0;$i<20;$i++) { $start=microtime(true); OrderService::adminList([],0,20); $durations[]=microtime(true)-$start; }
        sort($durations);
        shopCheck($durations[18]<0.2, '20k rows list P95 < 200ms ('.round($durations[18]*1000,2).'ms)');
        $page2=OrderService::adminList([],20,20);
        shopCheck($page2['rows'][0]['id']!==$list['rows'][0]['id'], 'pagination advances');
        shopCheck(OrderService::adminList(['keyword'=>'1000'],0,20)['total']===20000, 'merchant search count');
        shopCheck(OrderService::adminList(['keyword'=>'LP'.sprintf('%019d',19999)],0,20)['total']===1, 'LP order search');
        shopCheck(OrderService::adminList(['keyword'=>'Fixture','pay_status'=>'1','order_status'=>'2'],0,20)['total']===10000, 'combined filters');
        shopCheck(OrderService::adminList(['keyword'=>'no-such-product'],0,20)['total']===0, 'empty search');
        shopCheck(intval(OrderService::adminGet(20000)['merchant_uid'])===1000, 'detail resolves merchant');
        $DB->failList=true;
        try { OrderService::adminList([],0,20); throw new LogicException('query failure was hidden'); }
        catch (LogicException $e) { throw $e; }
        catch (Exception $e) { shopCheck(str_contains($e->getMessage(),'查询失败'),'query failure is explicit'); }
        $DB->failList=false;
        $join=TradeJoin::condition('P','S','shop');
        $scan=$DB->getAll('EXPLAIN SELECT P.trade_no,S.id FROM pre_order P LEFT JOIN pre_shop_orders S ON '.$join.' WHERE P.addtime>=CURDATE() LIMIT 200');
        $shop=array_values(array_filter($scan,fn($r)=>$r['table']==='S'))[0];
        shopCheck($shop['key']==='uk_pay_trade_no','reconciliation retains shop unique index');
    }
    if (is_file(__DIR__.'/shop-reconcile-check.inc.php')) require __DIR__.'/shop-reconcile-check.inc.php';
    echo "SHOP_REPAIR_CHECK_OK\n";
} finally {
    $DB = null;
    $pdo = null;
    $admin->exec("DROP DATABASE `{$name}`");
}
