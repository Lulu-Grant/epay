#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/fixtures/merchant-defense-db.php';
use lib\Defense\{Repository,PolicyService,LegacyOrderService,AlipayQrAdapter,DefenseException,Runtime};
$checks=0;$database=null;$admin=null;
function takeoverCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function takeoverReject(callable $fn,$label){try{$fn();}catch(DefenseException $e){takeoverCheck(true,$label);return;}throw new RuntimeException('Expected refusal '.$label);}
try{
 [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);$now=strtotime('2026-10-22 12:00:00');$store=new Repository($DB,fn()=>$now);
 defenseFixtureSeed($pdo,$now);$legacy=new LegacyOrderService($store);
 takeoverCheck(!$legacy->installed(),'old schema compatibility');
 defenseFixtureSql($pdo,file_get_contents(dirname(__DIR__).'/install/migrations/20261022_merchant_defense_legacy.sql'));
 takeoverCheck($legacy->installed(),'migration marker');
 $channel=['id'=>100,'plugin'=>'alipay','type'=>1,'apptype'=>'2','mode'=>0];
 takeoverCheck(PolicyService::channelSupported($channel),'single H5 capability');
 foreach(['2,3','1','4','2,4'] as $types){$bad=$channel;$bad['apptype']=$types;takeoverCheck(!PolicyService::channelSupported($bad),'unsupported combination '.$types);}
 $bad=$channel;$bad['plugin']='weilaile';takeoverCheck(!PolicyService::channelSupported($bad),'no fabricated Weilaile close support');
 $conf=['payer_ip_version'=>'1','payer_ip_enabled'=>'1','payer_ip_esa_verified'=>'1','payer_ip_trust_mode'=>'origin_auth','payer_ip_header'=>'Ali-Real-Client-Ip','merchant_defense_ip_mode'=>'esa','merchant_defense_net_verified'=>'1'];
 takeoverCheck((new PolicyService($store,$conf))->networkReady(),'ESA defense uses verified collector');$badConf=$conf;$badConf['merchant_defense_net_verified']='0';takeoverCheck(!(new PolicyService($store,$badConf))->networkReady(),'capture alone cannot certify defense');
 $order=defenseFixtureOrder($pdo,501);$service=new class {
  public $args;public function wapPay($args){$this->args=$args;$args['product_code']='QUICK_WAP_WAY';return 'https://openapi.alipay.com/gateway.do?'.http_build_query(['method'=>'alipay.trade.wap.pay','sign_type'=>'RSA2','sign'=>'fixture-signature','biz_content'=>json_encode($args)]);}
 };
 $result=(new AlipayQrAdapter($channel,[],$order,$service))->pay($order,'Fixture','9.9.9.9');
 takeoverCheck($result['type']==='redirect'&&AlipayQrAdapter::validResult($result,$order),'H5 signed gateway result checked');
 takeoverCheck($service->args['timeout_express']==='30m','bounded upstream relative timeout');
 $badResult=$result;$badResult['url']=str_replace('openapi.alipay.com','evil.invalid',$result['url']);takeoverCheck(!AlipayQrAdapter::validResult($badResult,$order),'H5 gateway host pinned');
 $changed=$order;$changed['realmoney']='2.00';takeoverCheck(!AlipayQrAdapter::validResult($result,$changed),'H5 cached amount bound');
 $before=$pdo->query('SELECT * FROM pay_order')->fetchAll(PDO::FETCH_ASSOC);
 $plan=$legacy->page(1);takeoverCheck(count($plan['orders'])===1,'bounded plan lists candidate');
 takeoverCheck($before===$pdo->query('SELECT * FROM pay_order')->fetchAll(PDO::FETCH_ASSOC),'plan does not modify orders');
 takeoverCheck($legacy->summary(1)['issued_unpaid']===1,'candidate count');
 takeoverCheck($store->row('SELECT COUNT(*) n FROM pre_merchant_defense_legacy')['n']==0,'plan does not install a fence');
 $pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=1');
 $snapshot=$legacy->snapshot($order);$calls=0;
 $pdo->exec("UPDATE pay_channel SET config='{\"appid\":\"fixture-account-change\"}' WHERE id=100");
 takeoverReject(fn()=>$legacy->retire(1,$order['trade_no'],$snapshot,'fixture-admin',fn()=>null),'receiving account change invalidates preview');
 $pdo->exec("UPDATE pay_channel SET config='{}' WHERE id=100");
 takeoverReject(fn()=>$legacy->retire(1,$order['trade_no'],str_repeat('a',64),'fixture-admin',fn()=>null),'stale snapshot rejected');
 takeoverReject(fn()=>$legacy->retire(2,$order['trade_no'],$snapshot,'fixture-admin',fn()=>null),'cross merchant rejected');
 $adapter=new class {public function query($o){return ['trade_status'=>'WAIT_BUYER_PAY'];}public function close($o){return ['trade_status'=>'TRADE_CLOSED','_defense_closed_unpaid'=>true];}};
 $retired=$legacy->retire(1,$order['trade_no'],$snapshot,'fixture-admin',function($o)use($adapter,$DB,&$calls){takeoverCheck(!$DB->inTransaction(),'no network IO under transaction');$calls++;return $adapter;});
 takeoverCheck($retired['state']==='closed_unpaid','verified close retired');
 takeoverCheck($legacy->summary(1)['issued_unpaid']===0,'verified close removes only unpaid blocker');
 takeoverCheck($before===$pdo->query('SELECT * FROM pay_order')->fetchAll(PDO::FETCH_ASSOC),'retirement does not mutate financial order');
 $legacy->retire(1,$order['trade_no'],$snapshot,'fixture-admin',function()use(&$calls){$calls++;throw new RuntimeException();});takeoverCheck($calls===1,'repeat retirement does not repeat network close');
 $GLOBALS['conf']=$conf;$GLOBALS['channel']=$channel;
 takeoverReject(fn()=>Runtime::beforePlugin('alipay','submit',$order['trade_no']),'retired links stay blocked with defense off');
 takeoverCheck(Runtime::beforePlugin('alipay','notify',$order['trade_no'])===null,'paid callback not blocked');
 $unknown=defenseFixtureOrder($pdo,502);
 $notFound=new class {public function query($o){throw new RuntimeException('synthetic not found');}};
 $r=$legacy->retire(1,$unknown['trade_no'],$legacy->snapshot($unknown),'fixture-admin',fn()=>$notFound);
 takeoverCheck($r['state']==='review'&&$legacy->retired($unknown['trade_no']),'not-found keeps fence and requires review');
 takeoverCheck($legacy->summary(1)['issued_unpaid']===1,'not-found never clears legacy blocker');
 $ambiguous=defenseFixtureOrder($pdo,503);$closed=new class {public function query($o){return ['trade_status'=>'TRADE_CLOSED'];}};
 takeoverCheck($legacy->retire(1,$ambiguous['trade_no'],$legacy->snapshot($ambiguous),'fixture-admin',fn()=>$closed)['state']==='review','closed query without unpaid ack not enough');
 $paid=defenseFixtureOrder($pdo,504);$pdo->prepare('UPDATE pay_order SET status=1,date=? WHERE trade_no=?')->execute([Repository::day($now),$paid['trade_no']]);
 takeoverCheck($legacy->summary(1)['today_success']===1,'unknown-IP paid records remain blockers');
 $paid=$store->row('SELECT * FROM pre_order WHERE trade_no=:t',[':t'=>$paid['trade_no']]);
 takeoverReject(fn()=>$legacy->retire(1,$paid['trade_no'],$legacy->snapshot($paid),'fixture-admin',fn()=>$adapter),'paid order cannot be retired');
 // A real adapter validates amount/order/state before returning an unpaid-close proof.
 $fakeSdk=new class {public function query($a,$b){return ['out_trade_no'=>$b,'total_amount'=>'2.00','trade_status'=>'TRADE_CLOSED'];}public function close($a){return ['code'=>'10000','out_trade_no'=>$a['out_trade_no']];}};
 takeoverReject(fn()=>(new AlipayQrAdapter($channel,[],$order,$fakeSdk))->close($order),'close amount mismatch rejected');
 $pdo->exec('UPDATE pay_user SET defense_mode=1 WHERE uid=1');
 $pending=defenseFixtureOrder($pdo,505);$quota=new \lib\Defense\QuotaService($store);
 $quota->reserve($pending,['ip'=>'9.9.9.9','revision'=>1,'account_scope'=>'alipay:fixture']);$quota->attempt($pending['trade_no']);
 $pdo->prepare('UPDATE pay_merchant_defense_order SET created_at=? WHERE trade_no=?')->execute([$now-3600,$pending['trade_no']]);
 $stats=(new \lib\Defense\Reconciler($store,fn()=>$notFound,fn()=>null))->run();
 takeoverCheck($stats['failed']===1,'WAP not-found requires reconciliation');
 takeoverCheck($store->row('SELECT state FROM pre_merchant_defense_order WHERE trade_no=:t',[':t'=>$pending['trade_no']])['state']==='unknown','elapsed timeout does not release uncreated WAP link');
 takeoverCheck((int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1 AND ip=:ip',[':ip'=>'9.9.9.9'])['success_count']===0,'unknown WAP not counted successful');
 $raced=defenseFixtureOrder($pdo,506);$pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=1');
 $raceAdapter=new class($pdo,$raced['trade_no'],$now){
  private $pdo;private $trade;private $now;
  public function __construct($p,$t,$n){$this->pdo=$p;$this->trade=$t;$this->now=$n;}
  public function query($o){return ['trade_status'=>'WAIT_BUYER_PAY'];}
  public function close($o){$this->pdo->prepare('UPDATE pay_order SET status=1,date=? WHERE trade_no=?')->execute([Repository::day($this->now),$this->trade]);return ['trade_status'=>'TRADE_CLOSED','_defense_closed_unpaid'=>true];}
 };
 takeoverCheck($legacy->retire(1,$raced['trade_no'],$legacy->snapshot($raced),'fixture-admin',fn()=>$raceAdapter)['state']==='review','callback race invalidates closure snapshot');
 takeoverCheck($store->row('SELECT state FROM pre_merchant_defense_legacy WHERE trade_no=:t',[':t'=>$raced['trade_no']])['state']==='closing','callback race keeps unresolved fence');
 $accountRace=defenseFixtureOrder($pdo,507);
 $accountAdapter=new class($pdo){
  private $pdo;public function __construct($p){$this->pdo=$p;}
  public function query($o){return ['trade_status'=>'WAIT_BUYER_PAY'];}
  public function close($o){$this->pdo->exec("UPDATE pay_channel SET config='{\"appid\":\"changed-during-close\"}' WHERE id=100");return ['trade_status'=>'TRADE_CLOSED','_defense_closed_unpaid'=>true];}
 };
 takeoverCheck($legacy->retire(1,$accountRace['trade_no'],$legacy->snapshot($accountRace),'fixture-admin',fn()=>$accountAdapter)['state']==='review','account change during close cannot clear blocker');
 takeoverCheck(!AlipayQrAdapter::validResult($result,$order,'different-app'),'cached H5 URL cannot switch receiving app');
 [$fresh,$freshPdo,$freshAdmin]=defenseFixtureCreate(true);
 try{takeoverCheck($pdo->query('SHOW COLUMNS FROM pay_merchant_defense_legacy')->fetchAll(PDO::FETCH_ASSOC)===$freshPdo->query('SHOW COLUMNS FROM pay_merchant_defense_legacy')->fetchAll(PDO::FETCH_ASSOC),'fresh and migrated legacy schema identical');}
 finally{$freshAdmin->exec('DROP DATABASE `'.$fresh.'`');}
 echo "Merchant defense H5 / readiness / legacy regression: $checks checks passed; synthetic loopback only.\n";
}finally{if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');}
