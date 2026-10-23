#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/fixtures/merchant-defense-db.php';
use lib\Defense\{Repository,PolicyService,LegacyOrderService,QuotaService,SessionService,Runtime,DefenseException};
$checks=0;$database=null;$admin=null;
function cutoverCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function cutoverReject($reason,callable $fn,$label){try{$fn();}catch(DefenseException $e){cutoverCheck($e->reason===$reason,$label.' ('.$e->reason.')');return;}throw new RuntimeException('Expected rejection '.$label);}
try{
 [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);$now=time();$store=new Repository($DB,function()use(&$now){return $now;});defenseFixtureSeed($pdo,$now);
 defenseFixtureSql($pdo,file_get_contents(dirname(__DIR__).'/install/migrations/20261022_merchant_defense_legacy.sql'));
 $newOrder=function($n,$at)use($pdo,$store){$o=defenseFixtureOrder($pdo,$n);$pdo->prepare('UPDATE pay_order SET addtime=FROM_UNIXTIME(?) WHERE trade_no=?')->execute([$at,$o['trade_no']]);return $store->row('SELECT * FROM pre_order WHERE trade_no=:t',[':t'=>$o['trade_no']]);};
 $conf=['merchant_defense_ip_mode'=>'direct','merchant_defense_net_verified'=>'1'];$policy=new PolicyService($store,$conf);$legacy=new LegacyOrderService($store);$quota=new QuotaService($store);
 $pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=1');
 $old=$newOrder(801,$now-172801);$exposed=$newOrder(802,$now-10);$sameSecond=$newOrder(803,$now);
 $pdo->prepare('UPDATE pay_order SET payurl=? WHERE trade_no=?')->execute(['https://fixture.invalid/already-issued',$exposed['trade_no']]);
 foreach(range(804,808) as $n){$o=$newOrder($n,$now-60);$pdo->prepare('UPDATE pay_order SET status=1,date=?,endtime=FROM_UNIXTIME(?) WHERE trade_no=?')->execute([Repository::day($now),$now-5,$o['trade_no']]);}
 $before=$pdo->query('SELECT * FROM pay_order ORDER BY trade_no')->fetchAll(PDO::FETCH_ASSOC);
 cutoverCheck($legacy->summary(1)['issued_unpaid']===3&&$legacy->summary(1)['today_success']===5,'historical orders visible without invented payer identity');
 cutoverCheck($policy->blockers($store->merchant(1))===[],'old channel-only links, issued links and paid orders do not block');
 $m=$policy->set(1,1,1,'fixture-admin');cutoverCheck((int)$m['defense_enabled_at']===$now&&(int)$m['defense_revision']===2,'atomic activation persists boundary and revision');
 cutoverCheck($before===$pdo->query('SELECT * FROM pay_order ORDER BY trade_no')->fetchAll(PDO::FETCH_ASSOC),'activation never rewrites financial orders');
 cutoverCheck((int)$store->row('SELECT COUNT(*) n FROM pre_merchant_defense_legacy')['n']===0,'activation does not bulk retire or call upstream');
 cutoverCheck((bool)$store->row("SELECT id FROM pre_merchant_defense_audit WHERE action='activation_scope' AND reason='new_orders_only_legacy_callbacks_preserved'"),'scope change audited');
 $GLOBALS['conf']=$conf;$GLOBALS['channel']=['id'=>100,'plugin'=>'alipay','type'=>1,'mode'=>0,'apptype'=>'3'];
 $context=['ip'=>'9.9.9.9','revision'=>2,'account_scope'=>'alipay:fixture'];
 foreach([$old,$exposed,$sameSecond] as $i=>$o){
  cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>Runtime::initial($o['trade_no'],'alipay','8.8.8.8'),'old MAPI/web entry rejected '.$i);
  foreach(['submit','mapi','qrcode','submitwap'] as $method)cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>Runtime::beforePlugin('alipay',$method,$o['trade_no']),'old official route rejected '.$i.'/'.$method);
  foreach(['submit','mapi','alipay','qrcode'] as $method)cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>Runtime::beforePlugin('weilaile',$method,$o['trade_no']),'old gateway route rejected '.$i.'/'.$method);
  cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>(new SessionService($store))->issue($o,'9.9.9.9',str_repeat('a',64),'Fixture Chrome Browser'),'old browser challenge rejected '.$i);
  $forged=$o;$forged['addtime']=date('Y-m-d H:i:s',$now+3600);
  cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>$quota->reserve($forged,$context),'quota uses persisted boundary not supplied timestamp '.$i);
  cutoverCheck(Runtime::beforePlugin('alipay','notify',$o['trade_no'])===null&&Runtime::beforePlugin('weilaile','notify',$o['trade_no'])===null,'late callback bypasses issuance fence '.$i);
 }
 cutoverCheck(!$quota->paid($old['trade_no']),'legacy callback does not invent new quota ownership');
 cutoverCheck((int)$store->row('SELECT COUNT(*) n FROM pre_merchant_defense_quota')['n']===0,'legacy rejections and callbacks leave quotas untouched');
 $now+=2;$fresh=$newOrder(809,$now-1);$pendingContext=$context;$pendingContext['ip']='8.8.4.4';
 $quota->reserve($fresh,$pendingContext);$quota->attempt($fresh['trade_no']);$quota->ready($fresh['trade_no'],['type'=>'qrcode','page'=>'alipay_qrcode','url'=>'https://qr.alipay.com/fixture']);
 cutoverCheck((int)$quota->reserve($fresh,$pendingContext)['unsafe_exposure']===0,'new order after cutover is managed and reusable');
 foreach(range(810,814) as $n){$o=$newOrder($n,$now);$quota->reserve($o,$context);cutoverCheck($quota->paid($o['trade_no']),'new scope counts authenticated payment '.$n);cutoverCheck(!$quota->paid($o['trade_no']),'duplicate callback counted once '.$n);}
 $sixth=$newOrder(815,$now);cutoverReject('DEFENSE_DAILY_LIMIT',fn()=>$quota->reserve($sixth,$context),'five managed successes stop sixth despite legacy exclusion');
 $policy->set(1,0,2,'fixture-admin');$now+=10;$m=$policy->set(1,1,3,'fixture-admin');$pendingContext['revision']=4;
 cutoverCheck($quota->reserve($fresh,$pendingContext)['pay_result']!==null,'safe issued result survives re-enable without duplicate upstream creation');
 $context['revision']=4;cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>$quota->reserve($sixth,$context),'previously unreserved order now behind new boundary');
 $newSixth=$newOrder(816,$now+1);$now+=2;cutoverReject('DEFENSE_DAILY_LIMIT',fn()=>$quota->reserve($newSixth,$context),'off/on cannot reset success count');
 $policy->set(1,0,4,'fixture-admin');Runtime::beforePlugin('alipay','submit',$fresh['trade_no']);$now+=2;$policy->set(1,1,5,'fixture-admin');$pendingContext['revision']=6;
 cutoverReject('DEFENSE_LEGACY_ORDER',fn()=>$quota->reserve($fresh,$pendingContext),'off-mode uncontrolled exposure cannot masquerade as safe managed order');
 cutoverCheck($store->row('SELECT state FROM pre_merchant_defense_order WHERE trade_no=:t',[':t'=>$fresh['trade_no']])['state']==='unmanaged','unsafe held capacity is not erased');
 $r=$policy->readiness($store->merchant(1));cutoverCheck($r['scope']['mode']==='new_orders'&&!$r['scope']['legacy_blocks_activation'],'readiness explicitly separates history and blockers');
 $badConf=$conf;$badConf['merchant_defense_net_verified']='0';cutoverCheck(count((new PolicyService($store,$badConf))->blockers($store->merchant(1)))>0,'network prerequisite remains enforced');
 $pdo->exec('UPDATE pay_user SET defense_enabled_at=0 WHERE uid=1');$another=$newOrder(817,$now+1);cutoverReject('DEFENSE_TEMP_UNAVAILABLE',fn()=>$quota->reserve($another,['ip'=>'1.1.1.1','revision'=>6]),'invalid boundary fails closed');
 echo "Merchant defense activation cutover regression: $checks checks passed; synthetic loopback only.\n";
}finally{if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');}
