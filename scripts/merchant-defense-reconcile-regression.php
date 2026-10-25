#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/fixtures/merchant-defense-db.php';
require dirname(__DIR__).'/includes/vendor/autoload.php';
use lib\Defense\{Repository,QuotaService,Reconciler,AlipayQrAdapter,DefenseException};
use Alipay\Aop\AlipayResponseException;
$checks=0;$database=null;$admin=null;
function reconcileCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function reconcileReject($reason,callable $fn,$label){try{$fn();}catch(DefenseException $e){reconcileCheck($e->reason===$reason,$label);return;}throw new RuntimeException('Expected refusal '.$label);}
try{
 [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);$now=time();$store=new Repository($DB,function()use(&$now){return $now;});defenseFixtureSeed($pdo,$now);$quota=new QuotaService($store);
 $make=function($n,$age,$checkedAgo=null,$ip=null)use($pdo,$store,$quota,&$now){
  $o=defenseFixtureOrder($pdo,$n);$quota->reserve($o,['ip'=>$ip??('203.0.113.'.($n%200+1)),'revision'=>1,'account_scope'=>'alipay:fixture']);$quota->attempt($o['trade_no']);
  $quota->ready($o['trade_no'],['type'=>'qrcode','page'=>'alipay_qrcode','url'=>'https://qr.alipay.com/fixture']);
  $pdo->prepare('UPDATE pay_merchant_defense_order SET created_at=?,checked_at=? WHERE trade_no=?')->execute([$now-$age,$checkedAgo===null?null:$now-$checkedAgo,$o['trade_no']]);return $o;
 };
 $channel=['id'=>100,'plugin'=>'alipay','type'=>1,'mode'=>0,'apptype'=>'2'];$o=defenseFixtureOrder($pdo,901);
 $sdk=new class {
  public $code='40004';public $sub='ACQ.TRADE_NOT_EXIST';
  public function query($a,$b){throw new AlipayResponseException(['code'=>$this->code,'sub_code'=>$this->sub,'sub_msg'=>'fixture-private-response-must-not-be-logged']);}
 };
 $adapter=new AlipayQrAdapter($channel,[],$o,$sdk);$r=$adapter->query($o);
 reconcileCheck($r===['out_trade_no'=>$o['trade_no'],'trade_status'=>'NOT_CREATED'],'real SDK not-found exception becomes a bounded pending result');
 foreach([['20000','ACQ.TRADE_NOT_EXIST'],['40004','ACQ.INVALID_PARAMETER']] as $case){$sdk->code=$case[0];$sdk->sub=$case[1];try{$adapter->query($o);throw new RuntimeException('masked real error');}catch(AlipayResponseException $e){reconcileCheck($e->getErrCode()===$case[1],'only exact known response is pending');}}
 $sdk->code='40004';$sdk->sub='ACQ.TRADE_NOT_EXIST';
 $queries=0;$closes=0;$confirms=0;
 $pending=new class($queries,$closes){
  private $q;private $c;public function __construct(&$q,&$c){$this->q=&$q;$this->c=&$c;}
  public function query($o){$this->q++;return ['trade_status'=>'NOT_CREATED'];}
  public function close($o){$this->c++;throw new RuntimeException('not-found must never close');}
 };
 $worker=new Reconciler($store,fn()=>$pending,function()use(&$confirms){$confirms++;});
 $o=$make(902,7200);$before=$store->row('SELECT * FROM pre_merchant_defense_order WHERE trade_no=:t',[':t'=>$o['trade_no']]);
 $r=$worker->run();reconcileCheck($r['waiting']===1&&$r['failed']===0&&$r['closed']===0,'pending query is not a system failure');
 $after=$store->row('SELECT * FROM pre_merchant_defense_order WHERE trade_no=:t',[':t'=>$o['trade_no']]);
 reconcileCheck($after['state']==='ready'&&$after['attempted_at']===$before['attempted_at']&&$after['pay_result']===$before['pay_result']&&$after['success_at']===null,'unknown upstream result preserves payment capability, hold and attempt ownership');
 reconcileCheck($closes===0&&$confirms===0,'not-found does not close or confirm');
 reconcileCheck($worker->run()['checked']===0,'same-minute invocation cannot repeat query');
 $now+=1799;reconcileCheck($worker->run()['checked']===0,'two-hour-old link waits thirty minutes');$now++;
 reconcileCheck($worker->run()['waiting']===1&&$queries===2,'due boundary resumes read query');
 reconcileCheck((int)$store->row("SELECT COUNT(*) n FROM pre_merchant_defense_audit WHERE action='reconcile_waiting'")['n']===1,'pending audit deduplicates across retry cycles');
 $pdo->prepare('UPDATE pay_order SET status=1,endtime=FROM_UNIXTIME(?),date=? WHERE trade_no=?')->execute([$now-1,Repository::day($now),$o['trade_no']]);
 $r=$worker->run();reconcileCheck($r['paid']===1&&$queries===2,'trusted persisted success bypasses schedule and upstream');
 reconcileCheck($worker->run()['checked']===0,'accounted successful order leaves queue');

 defenseFixtureSeed($pdo,$now);$queries=0;$closes=0;
 foreach([[120,60],[1200,300],[7200,1800],[172800,21600]] as $i=>$case){$make(910+$i,$case[0],$case[1]);$make(920+$i,$case[0],$case[1]-1);}
 $r=$worker->run();reconcileCheck($r['waiting']===4&&$queries===4,'SQL applies four age-dependent due intervals');
 $now++;$r=$worker->run();reconcileCheck($r['waiting']===4&&$queries===8,'one-second-before intervals become due without early repeats');

 defenseFixtureSeed($pdo,$now);$queries=0;$closes=0;
 $unattempted=defenseFixtureOrder($pdo,930);$quota->reserve($unattempted,['ip'=>'9.9.9.9','revision'=>1]);
 reconcileCheck($worker->run()['checked']===0,'unattempted qualification is not sent upstream');
 $now+=899;reconcileCheck($worker->run()['checked']===0,'unattempted hold retained before deadline');$now++;
 $r=$worker->run();reconcileCheck($r['closed']===1&&$queries===0&&$closes===0,'only never-attempted qualification can be locally released after deadline');
 $localPaid=defenseFixtureOrder($pdo,931);$quota->reserve($localPaid,['ip'=>'9.9.9.9','revision'=>1]);
 $pdo->prepare('UPDATE pay_order SET status=1,endtime=FROM_UNIXTIME(?),date=? WHERE trade_no=?')->execute([$now,Repository::day($now),$localPaid['trade_no']]);
 $now+=901;$r=$worker->run();reconcileCheck($r['paid']===1&&$r['closed']===0,'persisted payment takes precedence over unattempted timeout');

 defenseFixtureSeed($pdo,$now);$queries=0;$closes=0;
 foreach(range(940,944) as $n)$make($n,172800,null,'8.8.8.8');
 $r=$worker->run();reconcileCheck($r['waiting']===5&&$r['failed']===0,'old unresolved orders remain pending');$now+=172800;$worker->run();
 $sixth=defenseFixtureOrder($pdo,945);reconcileReject('DEFENSE_PENDING_LIMIT',fn()=>$quota->reserve($sixth,['ip'=>'8.8.8.8','revision'=>1,'account_scope'=>'alipay:fixture']),'retry backoff and new day cannot grant a sixth capability');
 reconcileCheck((int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1 AND ip=:ip',[':ip'=>'8.8.8.8'])['success_count']===0,'waiting never masquerades as a successful payment');

 defenseFixtureSeed($pdo,$now);$make(950,7200);$make(951,7200);$make(952,7200);$make(953,7200);$make(954,7200);
 $badWorker=new Reconciler($store,function($o){return new class {
  public function query($o){
   if(str_ends_with($o['trade_no'],'950'))throw new AlipayResponseException(['code'=>'20000','sub_code'=>'ACQ.SYSTEM_ERROR','sub_msg'=>'fixture-private-response-must-not-be-logged']);
   if(str_ends_with($o['trade_no'],'951'))return ['trade_status'=>'WAIT_BUYER_PAY'];
   if(str_ends_with($o['trade_no'],'952'))return ['trade_status'=>'TRADE_CLOSED'];
   if(str_ends_with($o['trade_no'],'953'))throw new AlipayResponseException(['code'=>'bad|code','sub_code'=>'secret=value','sub_msg'=>'fixture-private-response-must-not-be-logged']);
   return ['trade_status'=>'TRADE_SUCCESS','trade_no'=>'202610250000000000000001'];
  }
  public function close($o){throw new AlipayResponseException(['code'=>'40004','sub_code'=>'ACQ.INVALID_PARAMETER','sub_msg'=>'fixture-private-response-must-not-be-logged']);}
 };},function(){throw new RuntimeException('fixture-private-response-must-not-be-logged');});
 $r=$badWorker->run();reconcileCheck($r['failed']===4&&$r['review']===1&&$r['waiting']===0,'query, close and financial errors stay distinct from closure review');
 $reasons=array_column($store->rows("SELECT reason FROM pre_merchant_defense_audit WHERE action='reconcile_failure'"),'reason');
 foreach(['alipay_query_failed|20000|ACQ.SYSTEM_ERROR','alipay_close_failed|40004|ACQ.INVALID_PARAMETER','alipay_query_failed|UNKNOWN|UNKNOWN','reconcile_confirm_error'] as $reason)reconcileCheck(in_array($reason,$reasons,true),'safe failure stage retained '.$reason);
 reconcileCheck(!str_contains(json_encode($reasons),'fixture-private')&&!str_contains(json_encode($reasons),'secret='),'raw response, sign-like fields and unsafe codes are never recorded');
 reconcileCheck($badWorker->run()['checked']===0,'true failures also honor polling backoff');

 defenseFixtureSeed($pdo,$now);$risk=$make(960,172800);$quota->paid($risk['trade_no']);$confirmCalls=0;$queryCalls=0;
 $riskWorker=new Reconciler($store,function()use(&$queryCalls){return new class($queryCalls){private $n;public function __construct(&$n){$this->n=&$n;}public function query($o){$this->n++;return ['trade_status'=>'TRADE_SUCCESS','trade_no'=>'202610250000000000000001'];}};},
  function($o)use(&$confirmCalls,$pdo,&$now){$confirmCalls++;if($confirmCalls===1)throw new RuntimeException('synthetic interrupted finance');$pdo->prepare('UPDATE pay_order SET status=1,endtime=FROM_UNIXTIME(?),date=? WHERE trade_no=?')->execute([$now,Repository::day($now),$o['trade_no']]);});
 reconcileCheck($riskWorker->run()['failed']===1,'known paid financial interruption retained');$now+=59;reconcileCheck($riskWorker->run()['checked']===0,'financial retry still bounded by one minute');$now++;
 reconcileCheck($riskWorker->run()['paid']===1&&$confirmCalls===2&&$queryCalls===2,'known paid old order retries in a minute rather than six hours');
 reconcileCheck((int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1')['success_count']===1,'financial retry does not double account');
 echo "Merchant defense reconciliation regression: $checks checks passed; synthetic loopback only.\n";
}finally{if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');}
