<?php
require_once dirname(__DIR__,2).'/includes/functions.php';
$siteurl='https://example.test/';
$pdo->exec('DELETE FROM pre_shop_orders');
$pdo->exec('DELETE FROM pre_order');
$pdo->exec("INSERT INTO pre_user (uid,gid,`key`,money,status,pay) VALUES (1000,0,'fixture',0,1,1)");
$pdo->exec('UPDATE pre_shop_goods SET status=0');
$pdo->exec('UPDATE pre_shop_goods SET status=1,stock=100 WHERE id=1');
$DB=new ShopCheckDb($config);
\lib\Shop\ConfigService::save(['shop_status'=>'1','shop_flow_mode'=>'shadow','shop_excluded_uids'=>'1001']);

function fixturePayment($n,$status=0,$uid=1000,$tid=0,$old=false) {
    global $pdo;
    $trade='LP'.sprintf('%019d',80000+$n);
    $stmt=$pdo->prepare("INSERT INTO pre_order (trade_no,out_trade_no,uid,tid,name,money,type,status,addtime,endtime) VALUES (?,?,?,?,'Fixture original',10,1,?,?,?)");
    $stmt->execute([$trade,'reconcile-'.$n,$uid,$tid,$status,$old?'2020-01-01 00:00:00':date('Y-m-d H:i:s'),$status===1?date('Y-m-d H:i:s'):null]);
    return $trade;
}
function shopRow($trade) { global $DB; return $DB->getRow('SELECT * FROM pre_shop_orders WHERE pay_trade_no=:trade', [':trade'=>$trade]); }
$unpaid=fixturePayment(1);
$paid=fixturePayment(2,1);
$excluded=fixturePayment(3,1,1001);
$special=[]; foreach([2,3,4,7] as $i=>$state) $special[]=fixturePayment(10+$i,$state);
$nonOrdinary=fixturePayment(20,1,1000,1);
$oldOrder=fixturePayment(21,1,1000,0,true);
$dry=\lib\Shop\OrderService::reconcilePending(200,true);
shopCheck($dry['created']===2 && $dry['protected']===4 && intval($pdo->query('SELECT COUNT(*) FROM pre_shop_orders')->fetchColumn())===0,'dry-run selects eligible gaps without writes');
$run=\lib\Shop\OrderService::reconcilePending();
shopCheck($run['failed']===0 && $run['created']===2,'paid and unpaid gaps repaired');
shopCheck(intval(shopRow($unpaid)['pay_status'])===0 && intval(shopRow($unpaid)['order_status'])===0,'unpaid stays pending');
shopCheck(intval(shopRow($paid)['pay_status'])===1 && intval(shopRow($paid)['order_status'])===2,'paid is shipped');
foreach(array_merge($special,[$excluded,$nonOrdinary,$oldOrder]) as $trade) shopCheck(!shopRow($trade),'protected/excluded/historical/nonordinary order untouched');
$before=shopRow($paid);
$stock=$pdo->query('SELECT stock FROM pre_shop_goods WHERE id=1')->fetchColumn();
\lib\Shop\OrderService::reconcilePaymentOrder($paid);
\lib\Shop\OrderService::reconcilePaymentOrder($paid);
shopCheck(shopRow($paid)===$before && $pdo->query('SELECT stock FROM pre_shop_goods WHERE id=1')->fetchColumn()===$stock,'replay preserves snapshot, times and stock');
$pdo->exec("UPDATE pre_shop_orders SET order_status=4,pay_type=0 WHERE pay_trade_no=".$pdo->quote($paid));
\lib\Shop\OrderService::reconcilePaymentOrder($paid);
shopCheck(intval(shopRow($paid)['order_status'])===4 && intval(shopRow($paid)['pay_type'])===1,'route repaired without lowering fulfillment');
$pdo->exec("UPDATE pre_shop_orders SET deleted=1,pay_type=0 WHERE pay_trade_no=".$pdo->quote($paid));
shopCheck(\lib\Shop\OrderService::reconcilePaymentOrder($paid)===false && intval(shopRow($paid)['pay_type'])===0,'deleted projection untouched');
$stale=fixturePayment(30,1);
\lib\Shop\OrderService::recordPaymentOrder($stale);
$stalePayment=$DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade',[':trade'=>$stale]);
$pdo->exec('UPDATE pre_order SET status=2 WHERE trade_no='.$pdo->quote($stale));
shopCheck(\lib\Shop\OrderService::markPaidFromPaymentOrder($stalePayment)===false && intval(shopRow($stale)['pay_status'])===0,'stale paid snapshot cannot override a refund');

// A failure after creating a projection must roll it back, then continue the batch.
$fail=fixturePayment(31,1);
$good=fixturePayment(32,1);
$pdo->exec("CREATE TRIGGER fail_projection BEFORE UPDATE ON pre_shop_orders FOR EACH ROW BEGIN IF NEW.out_trade_no='reconcile-31' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture injected failure'; END IF; END");
$run=\lib\Shop\OrderService::reconcilePending();
shopCheck($run['failed']===1 && !shopRow($fail) && intval(shopRow($good)['pay_status'])===1,'per-order rollback and batch isolation');
$pdo->exec('DROP TRIGGER fail_projection');
\lib\Shop\OrderService::reconcilePending();
shopCheck(intval(shopRow($fail)['pay_status'])===1,'failed gap can be retried');
shopCheck((float)$pdo->query('SELECT money FROM pre_user WHERE uid=1000')->fetchColumn()===0.0 && (int)$pdo->query('SELECT COUNT(*) FROM pre_record')->fetchColumn()===0,'worker never credits merchant or records income');

// Exercise the actual CLI with disposable config, and identical DB identity in two releases.
$roots=[];
$cliRun=function($path,$args=[]) {
    $process=proc_open(array_merge([PHP_BINARY,$path.'/scripts/shop-shadow-reconcile.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    $json=json_decode($out,true);
    if(!is_array($json))throw new RuntimeException('CLI did not return JSON: '.$err);
    return [$code,$json];
};
try {
    foreach(['a','b'] as $label) {
        $path=sys_get_temp_dir().'/'.$name.'-'.$label;
        mkdir($path,0700);mkdir($path.'/scripts',0700);
        $roots[]=$path;
        symlink(dirname(__DIR__,2).'/includes',$path.'/includes');
        copy(dirname(__DIR__,2).'/scripts/shop-shadow-reconcile.php',$path.'/scripts/shop-shadow-reconcile.php');
        file_put_contents($path.'/config.php','<?php $dbconfig='.var_export($config,true).';');chmod($path.'/config.php',0600);
    }
    $pdo->exec("UPDATE pre_shop_orders SET pay_type=0 WHERE pay_trade_no=".$pdo->quote($unpaid));
    $snapshot=shopRow($unpaid);
    [$rc,$result]=$cliRun($roots[0],['--dry-run','--limit=20']);
    shopCheck($rc===0 && $result['dry_run']===true && $result['synced']===1 && shopRow($unpaid)===$snapshot,'actual CLI dry-run is read-only');
    $identity=hash('sha256',$config['host'].':'.$config['port'].'/'.$config['dbname'].'/'.$config['dbqz']);
    $lockPath=sys_get_temp_dir().'/epay-shop-shadow-'.substr($identity,0,24).'.lock';
    $handle=fopen($lockPath,'c');flock($handle,LOCK_EX);
    [$rc,$result]=$cliRun($roots[1],['--limit=20']);
    shopCheck($rc===0 && !empty($result['locked']),'same site lock across release directories');
    flock($handle,LOCK_UN);fclose($handle);
    [$rc,$result]=$cliRun($roots[1],['--limit=1']);
    shopCheck($rc===0 && $result['scanned']===1,'actual CLI bounded repair');
    // A locked original row causes a bounded failure, without partial writes.
    $blocked=fixturePayment(40,1);
    $pdo->beginTransaction();$pdo->query('SELECT trade_no FROM pre_order WHERE trade_no='.$pdo->quote($blocked).' FOR UPDATE')->fetchAll();
    $start=microtime(true);[$rc,$result]=$cliRun($roots[0],['--limit=1']);$seconds=microtime(true)-$start;
    $pdo->rollBack();
    shopCheck($rc===1 && $result['failed']===1 && $seconds<8 && !shopRow($blocked),'lock contention bounded and no partial projection');
    [$rc,$result]=$cliRun($roots[0],['--limit=1']);
    shopCheck($rc===0 && intval(shopRow($blocked)['pay_status'])===1,'retry after concurrent lock released');
    [$rc,$result]=$cliRun($roots[0],['--invalid']);shopCheck($rc===1,'invalid CLI option rejected');
    $badConfig=$config;$badConfig['pwd']='invalid-fixture-password';
    file_put_contents($roots[1].'/config.php','<?php $dbconfig='.var_export($badConfig,true).';');
    [$rc,$result]=$cliRun($roots[1],['--dry-run']);
    shopCheck($rc===1 && $result['reason']==='reconciliation_failed','connection failure returns safe JSON and nonzero exit');
    unlink($lockPath);
} finally {
    foreach($roots as $path) {
        unlink($path.'/includes');unlink($path.'/config.php');unlink($path.'/scripts/shop-shadow-reconcile.php');rmdir($path.'/scripts');rmdir($path);
    }
}
