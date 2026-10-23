#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/fixtures/merchant-defense-db.php';
require dirname(__DIR__).'/plugins/weilaile/inc/WeilaileClient.php';
use lib\Defense\{Repository,PolicyService,AdapterFactory,WeilaileAdapter,QuotaService,SessionService,ComplaintService,Reconciler,LegacyOrderService,Runtime,DefenseException};
$checks=0;$database=null;$admin=null;
function holdCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function holdReject($reason,callable $fn,$label){try{$fn();}catch(DefenseException $e){holdCheck($e->reason===$reason,$label);return;}throw new RuntimeException('Expected rejection '.$label);}
try{
 [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);$now=time();$store=new Repository($DB,function()use(&$now){return $now;});defenseFixtureSeed($pdo,$now);
 defenseFixtureSql($pdo,file_get_contents(dirname(__DIR__).'/install/migrations/20261022_merchant_defense_legacy.sql'));
 $pdo->exec("UPDATE pay_channel SET plugin='weilaile',apptype=NULL,config='{\"appid\":\"9001\",\"appkey\":\"fixture-only-key\"}' WHERE id=100");
 $channel=['id'=>100,'plugin'=>'weilaile','type'=>1,'mode'=>0,'apptype'=>null,'appid'=>'9001','appkey'=>'fixture-only-key'];
 $conf=['localurl'=>'https://fixture.invalid/','merchant_defense_ip_mode'=>'direct','merchant_defense_net_verified'=>'1'];$policy=new PolicyService($store,$conf);
 holdCheck(PolicyService::channelMode($channel)==='local_hold','local capability identified');holdCheck(!PolicyService::channelCanClose($channel),'no invented close capability');
 $bad=$channel;$bad['subid']=1;holdCheck(!PolicyService::channelSupported($bad),'subchannel still unsupported');$bad=$channel;$bad['mode']=1;holdCheck(!PolicyService::channelSupported($bad),'direct settlement still unsupported');
 holdCheck($policy->blockers($store->merchant(1))===[],'empty merchant eligible in local mode');
 $scope=AdapterFactory::accountScope($channel);holdCheck($scope==='weilaile:9001','provider PID scope isolated');
 $client=new class('9001','fixture-only-key') extends \WeilaileClient {
  public $calls=0;public $last=[];public $response;
  protected function request(array $params){$this->calls++;$this->last=$params;return $this->response??['code'=>1,'trade_no'=>'UP-'.$params['out_trade_no'],'payurl'=>'https://fixture.invalid/pay/mock'];}
 };
 $adapter=new WeilaileAdapter($channel,$conf,$client);$quota=new QuotaService($store);$sessions=new SessionService($store);$order=defenseFixtureOrder($pdo,701);$ip='9.9.9.9';$cookie=str_repeat('a',64);$ua='Fixture Chrome Browser';
 $challenge=$sessions->issue($order,$ip,$cookie,$ua);$answer=0;while(!str_starts_with(hash('sha256',$challenge['challenge'].':'.$answer),'000'))$answer++;
 $sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],(string)$answer);$context=$sessions->context($order,$ip,$cookie,$ua);$context['account_scope']=$scope;
 $quota->reserve($order,$context);holdCheck($quota->attempt($order['trade_no']),'one upstream attempt claimed');holdCheck(!$quota->attempt($order['trade_no']),'duplicate attempt rejected');
 $result=$adapter->pay($order,'Synthetic item',$ip);$quota->ready($order['trade_no'],$result);
 holdCheck($client->calls===1&&$client->last['clientip']===$ip,'trusted browser IP forwarded after verification');
 holdCheck($client->last['out_trade_no']===$order['trade_no']&&$client->last['money']==='1.00','order and amount preserved');
 holdCheck(WeilaileAdapter::validResult($result,$order,$scope),'bound payment result reusable');
 $changed=$order;$changed['realmoney']='2.00';holdCheck(!WeilaileAdapter::validResult($result,$changed,$scope),'cached amount mismatch rejected');
 holdCheck(!WeilaileAdapter::validResult($result,$order,'weilaile:9002'),'cached PID mismatch rejected');
 $changed=$order;$changed['trade_no']='LP0000000000000000999';holdCheck(!WeilaileAdapter::validResult($result,$changed,$scope),'cached cross-order result rejected');
 $bad=$result;$bad['url']='javascript:alert(1)';holdCheck(!WeilaileAdapter::validResult($bad,$order,$scope),'cached unsafe URL rejected');
 $GLOBALS['conf']=$conf;$GLOBALS['channel']=$channel;$_SERVER=['REMOTE_ADDR'=>$ip,'HTTP_USER_AGENT'=>$ua];$_COOKIE=[Runtime::COOKIE=>$cookie];
 holdCheck(Runtime::beforePlugin('weilaile','alipay',$order['trade_no'])===$result,'actual shared loader reuses verified result');holdCheck($client->calls===1,'refresh does not call upstream twice');
 $_COOKIE=[];holdCheck(Runtime::beforePlugin('weilaile','alipay',$order['trade_no'])['reason']==='DEFENSE_VERIFY_FAILED','non-browser entry without cookie denied');$_COOKIE=[Runtime::COOKIE=>$cookie];
 holdCheck(Runtime::beforePlugin('weilaile','notify',$order['trade_no'])===null,'auth callback stays outside browser gate');
 holdReject('DEFENSE_LOCAL_HOLD',fn()=>$adapter->query($order),'no speculative query transport');holdReject('DEFENSE_LOCAL_HOLD',fn()=>$adapter->close($order),'no speculative close transport');
 foreach(range(702,705) as $number){$o=defenseFixtureOrder($pdo,$number);$quota->reserve($o,$context);$quota->attempt($o['trade_no']);}
 $sixth=defenseFixtureOrder($pdo,706);holdReject('DEFENSE_PENDING_LIMIT',fn()=>$quota->reserve($sixth,$context),'sixth link blocked before MAPI');
 $now+=86400;$worker=new Reconciler($store,fn()=>$adapter,fn()=>null);$stats=$worker->run();
 holdCheck($stats['held']===5&&$stats['closed']===0&&$stats['failed']===0,'worker reports held links without pretending closed');holdCheck($client->calls===1,'worker never calls payment creation');
 holdReject('DEFENSE_PENDING_LIMIT',fn()=>$quota->reserve($sixth,$context),'midnight cannot release issued links');
 holdCheck((int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1 AND ip=:ip',[':ip'=>$ip])['success_count']===0,'unpaid holds not counted successful');
 foreach(range(701,705) as $n){$trade='LP'.str_pad((string)$n,19,'0',STR_PAD_LEFT);$quota->paid($trade);holdCheck(!$quota->paid($trade),'duplicate payment counted once '.$n);}
 holdReject('DEFENSE_DAILY_LIMIT',fn()=>$quota->reserve($sixth,$context),'five authenticated payments stop sixth');
 $now-=86400;$complaint=['uid'=>1,'trade_no'=>$order['trade_no'],'source'=>1,'thirdid'=>'fixture-case','addtime'=>date('Y-m-d H:i:s',$now),'defense_received_at'=>date('Y-m-d H:i:s',$now)];
 $complaints=new ComplaintService($store);holdCheck($complaints->observe($complaint,$scope),'local-mode complaint ban recorded');holdCheck($quota->blocked(1,$ip)===$now+172800,'ban lasts 48 hours');holdCheck(!$complaints->observe($complaint,$scope),'complaint retry not extended');
 holdCheck(Runtime::beforePlugin('weilaile','alipay',$order['trade_no'])['reason']==='DEFENSE_IP_BLOCKED','complaint blocks reissued information');
 $pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=1');$legacy=new LegacyOrderService($store);$old=defenseFixtureOrder($pdo,707);$snapshot=$legacy->snapshot($old);
 holdReject('DEFENSE_CHANNEL_UNSUPPORTED',fn()=>$legacy->retire(1,$old['trade_no'],$snapshot,'fixture-admin',fn()=>null),'upstream retirement not available for local mode');
 $factoryCalls=0;$blocked=$legacy->retire(1,$old['trade_no'],$snapshot,'fixture-admin',function()use(&$factoryCalls){$factoryCalls++;throw new RuntimeException();},true);
 holdCheck($blocked['state']==='local_blocked'&&!$blocked['upstream_closed']&&!$blocked['quota_released'],'local block never masquerades as upstream close');holdCheck($factoryCalls===0,'local block never calls adapter');
 holdCheck($legacy->summary(1)['issued_unpaid']>=1,'unknown legacy links remain visible for review');
 holdReject('DEFENSE_ORDER_RETIRED',fn()=>Runtime::beforePlugin('weilaile','alipay',$old['trade_no']),'blocked old link cannot generate MAPI again with defense off');
 holdCheck(Runtime::beforePlugin('weilaile','notify',$old['trade_no'])===null,'late real callback still admitted');
 foreach(['qrcode'=>['qrcode','https://fixture.invalid/qr/mock'],'urlscheme'=>['scheme','alipays://platformapi/startapp?appId=20000067'],'html'=>['html','<form>synthetic gateway form</form>']] as $field=>$case){
  $client->response=['code'=>1,'trade_no'=>'UP-FIXTURE',$field=>$case[1]];$mapped=$adapter->pay($order,'Synthetic item',$ip);
  holdCheck($mapped['type']===$case[0],'preserve upstream payment format '.$field);
  holdCheck(WeilaileAdapter::validResult($mapped,$order,$scope),'bound replay validates '.$field);
 }
 $failed=defenseFixtureOrder($pdo,708,2);$quota->reserve($failed,$context);$quota->attempt($failed['trade_no']);$client->response=['code'=>-1,'msg'=>'synthetic rejection'];
 try{$adapter->pay($failed,'Synthetic item',$ip);throw new RuntimeException('Expected SDK rejection');}catch(\WeilaileException $e){holdCheck(true,'gateway rejection caught');}
 holdCheck($store->row('SELECT state FROM pre_merchant_defense_order WHERE trade_no=:t',[':t'=>$failed['trade_no']])['state']==='unknown','upstream rejection does not fabricate closed proof');
 holdCheck(!$quota->releaseUnattempted($failed['trade_no']),'attempted rejection cannot be released as unattempted');
 echo "Merchant defense local hold regression: $checks checks passed; synthetic loopback only.\n";
}finally{if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');}
