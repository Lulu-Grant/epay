#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/merchant-defense-db.php';
use lib\Defense\{ClientIp,Repository,QuotaService,SessionService,ComplaintService,PolicyService,EntryService,DefenseException,AlipayQrAdapter,AdminController,Reconciler};
$checks=0;
function defenseSame($expected,$actual,$label){global $checks;$checks++;if($expected!==$actual)throw new RuntimeException('FAIL: '.$label);}
function defenseReject($reason,callable $callback,$label){try{$callback();}catch(DefenseException $e){defenseSame($reason,$e->reason,$label);return;}throw new RuntimeException('FAIL: expected rejection '.$label);}
function defenseAnswer($token){for($i=0;$i<100000000;$i++)if(str_starts_with(hash('sha256',$token.':'.$i),'000'))return (string)$i;throw new RuntimeException('Fixture work failure');}
$database=null;$admin=null;
try{
    [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);
    $now=strtotime('2026-10-21 12:00:00');
    $store=new Repository($DB,function()use(&$now){return $now;});
    defenseFixtureSeed($pdo,$now);
    $quota=new QuotaService($store);$sessions=new SessionService($store);$complaints=new ComplaintService($store);
    $conf=['merchant_defense_ip_mode'=>'direct','merchant_defense_net_verified'=>'1'];
    $policy=new PolicyService($store,$conf);

    defenseSame('192.0.2.1',ClientIp::normalize('::ffff:192.0.2.1'),'IPv4 mapped normalization');
    defenseSame('2001:db8::1',ClientIp::normalize('2001:0db8:0:0:0:0:0:1'),'IPv6 canonical form');
    defenseSame(true,ClientIp::contains('2001:db8::1','2001:db8::/32'),'IPv6 CIDR');
    defenseSame(false,ClientIp::contains('192.0.2.1','10.0.0.0/8'),'nonmatching CIDR');
    defenseSame('203.0.113.2',ClientIp::resolve(['REMOTE_ADDR'=>'203.0.113.2','HTTP_X_FORWARDED_FOR'=>'198.51.100.1'],['10.0.0.0/8']),'untrusted XFF ignored');
    defenseSame('198.51.100.1',ClientIp::resolve(['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.1, 10.0.0.2'],['10.0.0.0/8']),'trusted chain walks from peer');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>ClientIp::resolve(['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'bad'],['10.0.0.0/8']),'invalid trusted chain');

    $pdo->exec('UPDATE pay_user SET defense_mode=0');
    $merchant=$store->merchant(1);
    defenseSame([], $policy->blockers($merchant),'supported channel and empty merchant eligible');
    defenseSame(1,(int)$policy->set(1,1,1,'fixture-admin')['defense_mode'],'explicit target enables');
    defenseSame(2,(int)$store->merchant(1)['defense_revision'],'switch increments revision');
    defenseReject('DEFENSE_VERSION_CONFLICT',fn()=>$policy->set(1,0,1,'fixture-admin'),'stale version');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>AdminController::handle('setMerchantDefense',['uid'=>'1','target'=>'0','revision'=>'2'],[],true,'fixture-admin',$conf),'missing CSRF');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>AdminController::handle('getMerchantDefense',['uid'=>'1'],[],false,'fixture-admin',$conf),'non admin');
    defenseSame('local_hold',PolicyService::channelMode(['plugin'=>'weilaile','type'=>1,'apptype'=>'3','mode'=>0]),'gateway supports local hold without claiming closure');
    defenseSame(false,PolicyService::channelSupported(['plugin'=>'alipay','type'=>1,'apptype'=>'2,3','mode'=>0]),'mixed modes not advertised');

    $order=defenseFixtureOrder($pdo,1);$cookie=str_repeat('a',64);$ua='Fixture Chrome Browser';$ip='203.0.113.1';
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->issue($order,$ip,$cookie,'curl/8.0.0'),'known non browser denied');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->issue($order,$ip,$cookie,''),'missing browser denied');
    $entry=new EntryService($store);$access=$entry->issue($order,1,'198.51.100.1');
    defenseSame($order['trade_no'],$entry->verify($order['trade_no'],$access)['trade_no'],'entry bound to trade');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$entry->verify('LP0000000000000009999',$access),'entry cross order');
    $challenge=$sessions->issue($order,$ip,$cookie,$ua);$answer=defenseAnswer($challenge['challenge']);
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->consume($order,'203.0.113.2',$cookie,$ua,$challenge['challenge'],$answer),'challenge IP binding');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->consume($order,$ip,str_repeat('b',64),$ua,$challenge['challenge'],$answer),'challenge browser session binding');
    $changed=$order;$changed['money']='2.00';
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->consume($changed,$ip,$cookie,$ua,$challenge['challenge'],$answer),'challenge amount binding');
    defenseSame(true,$sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],$answer),'valid browser challenge');
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],$answer),'challenge one use');
    $context=$sessions->context($order,$ip,$cookie,$ua);
    $restarted=new SessionService(new Repository(defenseFixtureWrapper(defenseFixtureConnection($database)),function()use(&$now){return $now;}));
    defenseSame($ip,$restarted->context($order,$ip,$cookie,$ua)['ip'],'verified state survives process/database reconnection');
    defenseSame($ip,$context['ip'],'verified browser IP authoritative');
    defenseReject('DEFENSE_BROWSER_REQUIRED',fn()=>$sessions->context($order,$ip,$cookie,'Different Browser'),'UA changed needs verification');
    $quota->reserve($order,$context);
    $changed=$order;$changed['realmoney']='2.00';
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$quota->reserve($changed,$context),'reserved payment amount immutable');
    defenseSame(true,$quota->attempt($order['trade_no']),'upstream attempt claimed');
    defenseSame(false,$quota->attempt($order['trade_no']),'ambiguous upstream not retried');
    defenseSame(true,$quota->paid($order['trade_no']),'success counts once');
    defenseSame(false,$quota->paid($order['trade_no']),'duplicate success ignored');
    defenseReject('DEFENSE_ORDER_RETIRED',fn()=>$quota->ready($order['trade_no'],['type'=>'qrcode']),'late creation result cannot expose a retired qualification');
    foreach(range(2,5) as $number){$o=defenseFixtureOrder($pdo,$number);$quota->reserve($o,$context);$quota->paid($o['trade_no']);}
    $sixth=defenseFixtureOrder($pdo,6);
    defenseReject('DEFENSE_DAILY_LIMIT',fn()=>$quota->reserve($sixth,$context),'sixth success qualification blocked');
    $pdo->prepare('UPDATE pay_order SET status=2 WHERE trade_no=?')->execute([$order['trade_no']]);
    defenseReject('DEFENSE_DAILY_LIMIT',fn()=>$quota->reserve($sixth,$context),'refund does not restore daily slot');
    $other=defenseFixtureOrder($pdo,7,2);$pdo->exec('UPDATE pay_user SET defense_mode=1 WHERE uid=2');
    defenseSame('reserved',$quota->reserve($other,['ip'=>$ip,'revision'=>1])['state'],'merchant quotas isolated');

    defenseFixtureSeed($pdo,$now);$context=['ip'=>$ip,'revision'=>1];
    foreach(range(10,14) as $number){$o=defenseFixtureOrder($pdo,$number);$quota->reserve($o,$context);}
    $blocked=defenseFixtureOrder($pdo,15);
    defenseReject('DEFENSE_PENDING_LIMIT',fn()=>$quota->reserve($blocked,$context),'unpaid qualifications reserve capacity');
    defenseSame(true,$quota->closed('LP0000000000000000010'),'confirmed close releases');
    defenseSame('reserved',$quota->reserve($blocked,$context)['state'],'failure not success after proven close');
    $now+=86400;
    $extra=defenseFixtureOrder($pdo,16);
    defenseReject('DEFENSE_PENDING_LIMIT',fn()=>$quota->reserve($extra,$context),'midnight does not discard active slots');
    $quota->paid('LP0000000000000000011');
    defenseSame(1,(int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1 AND ip=:ip',[':ip'=>$ip])['success_count'],'old pending success counts confirmation day');
    $quota->closed('LP0000000000000000012');$quota->reserve($extra,$context);
    defenseSame(true,$quota->paid('LP0000000000000000010'),'late authenticated payment is recorded even after close');
    defenseReject('DEFENSE_TEMP_UNAVAILABLE',fn()=>$quota->reserve(defenseFixtureOrder($pdo,17),$context),'close invariant failure suspends new qualification');

    defenseFixtureSeed($pdo,$now);$order=defenseFixtureOrder($pdo,30);
    $challenge=$sessions->issue($order,$ip,$cookie,$ua);$sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],defenseAnswer($challenge['challenge']));
    $complaint=['thirdid'=>'case-1','trade_no'=>$order['trade_no'],'uid'=>1,'source'=>0,'addtime'=>date('Y-m-d H:i:s',$now),'defense_received_at'=>date('Y-m-d H:i:s',$now)];
    defenseSame(true,$complaints->observe($complaint,'alipay:fixture-app'),'new complaint bans');
    $deadline=$now+172800;defenseSame($deadline,$quota->blocked(1,$ip),'exact 48 hour deadline');
    $now+=3600;defenseSame(false,$complaints->observe($complaint,'alipay:fixture-app'),'same complaint does not extend');
    defenseSame($deadline,$quota->blocked(1,$ip),'deadline unchanged by repeat');
    defenseSame(0,$quota->blocked(2,$ip),'complaint ban merchant isolation');
    $second=$complaint;$second['thirdid']='case-2';$second['defense_received_at']=date('Y-m-d H:i:s',$now);
    defenseSame(true,$complaints->observe($second,'alipay:fixture-app'),'different complaint extends');
    $secondDeadline=$now+172800;defenseSame($secondDeadline,$quota->blocked(1,$ip),'latest live event deadline');
    $event=$store->row("SELECT event_key FROM pre_merchant_defense_complaint WHERE complaint_id='case-2'")['event_key'];
    $complaints->release(1,$event,'fixture-admin','Confirmed fixture correction');
    defenseSame(false,$complaints->observe($second,'alipay:fixture-app'),'released event not restored by replay');
    defenseSame($deadline,$quota->blocked(1,$ip),'other event survives individual release');
    $now=$deadline-1;defenseSame($deadline,$quota->blocked(1,$ip),'one second before expiry blocked');
    $now++;defenseSame(0,$quota->blocked(1,$ip),'exact expiry effective without cleanup');
    $unmatched=$complaint;$unmatched['trade_no']='LP0000000000000009999';
    defenseSame(false,$complaints->observe($unmatched,'alipay:fixture-app'),'missing trusted context not guessed');
    $wrong=$complaint;$wrong['uid']=2;defenseSame(false,$complaints->observe($wrong,'alipay:fixture-app'),'mismatched merchant not banned');

    defenseFixtureSeed($pdo,$now);$order=defenseFixtureOrder($pdo,40);
    $challenge=$sessions->issue($order,$ip,$cookie,$ua);$now+=300;
    defenseReject('DEFENSE_VERIFY_FAILED',fn()=>$sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],defenseAnswer($challenge['challenge'])),'challenge exact expiry');
    $fake=new class {
        public $status='WAIT_BUYER_PAY';public $money='1.00';public $closed=false;
        public function qrPay($params){return ['out_trade_no'=>$params['out_trade_no'],'qr_code'=>'https://qr.alipay.com/fixture'];}
        public function query($unused,$trade){return ['out_trade_no'=>$trade,'trade_no'=>'202610210000000000000000001','total_amount'=>$this->money,'trade_status'=>$this->status];}
        public function close($params){$this->closed=true;$this->status='TRADE_CLOSED';return ['code'=>'10000','out_trade_no'=>$params['out_trade_no']];}
    };
    $adapter=new AlipayQrAdapter(['plugin'=>'alipay','type'=>1,'apptype'=>'3','mode'=>0],[],$order,$fake);
    defenseSame('qrcode',$adapter->pay($order,'Fixture',$ip)['type'],'official QR adapter local fixture');
    defenseSame('WAIT_BUYER_PAY',$adapter->query($order)['trade_status'],'query waiting not success');
    defenseSame('TRADE_CLOSED',$adapter->close($order)['trade_status'],'close separately queried');
    $fake->money='9.00';defenseReject('DEFENSE_TEMP_UNAVAILABLE',fn()=>$adapter->query($order),'query amount mismatch');

    defenseFixtureSeed($pdo,$now);
    $pdo->exec("UPDATE pay_channel SET plugin='epay' WHERE id=100");
    $pdo->exec('UPDATE pay_user SET defense_mode=0');
    defenseReject('DEFENSE_CHANNEL_UNSUPPORTED',fn()=>$policy->set(1,1,1,'fixture-admin'),'unsupported route blocks activation');
    defenseSame(0,(int)$store->merchant(1)['defense_mode'],'failed activation remains off');
    $pdo->exec("UPDATE pay_channel SET plugin='alipay' WHERE id=100");
    defenseFixtureOrder($pdo,50);
    defenseSame(1,(int)$policy->set(1,1,1,'fixture-admin')['defense_mode'],'historical links do not block new-order activation');

    defenseFixtureSeed($pdo,$now);$order=defenseFixtureOrder($pdo,51);
    $pdo->exec('UPDATE pay_user SET defense_mode=0');
    $pdo->exec('UPDATE pay_order SET channel=0');
    $pdo->beginTransaction();
    try{
        $pdo->query('SELECT trade_no FROM pay_order')->fetchAll(); // Establish an older repeatable-read snapshot.
        $handoff=defenseFixtureConnection($database);
        $handoff->exec('UPDATE pay_order SET channel=100');
        defenseSame([],$policy->blockers($store->merchant(1)),'concurrent historical handoff does not block new-order scope');
    }finally{$pdo->rollBack();}

    defenseFixtureSeed($pdo,$now);
    $order=defenseFixtureOrder($pdo,60);$waiting=defenseFixtureOrder($pdo,61);$ambiguous=defenseFixtureOrder($pdo,62);
    $challenge=$sessions->issue($order,$ip,$cookie,$ua);$sessions->consume($order,$ip,$cookie,$ua,$challenge['challenge'],defenseAnswer($challenge['challenge']));
    $quota->reserve($order,['ip'=>$ip,'revision'=>1,'account_scope'=>'alipay:original-app']);$quota->attempt($order['trade_no']);
    $quota->reserve($waiting,['ip'=>$ip,'revision'=>1,'account_scope'=>'alipay:original-app']);$quota->attempt($waiting['trade_no']);
    $quota->reserve($ambiguous,['ip'=>$ip,'revision'=>1,'account_scope'=>'alipay:original-app']);$quota->attempt($ambiguous['trade_no']);
    $pdo->prepare('UPDATE pay_merchant_defense_order SET created_at=?')->execute([$now-901]);
    $pdo->prepare('INSERT INTO pay_complain (paytype,channel,uid,trade_no,thirdid,type,status,addtime,defense_received_at) VALUES (1,100,1,?,?,?,0,?,?)')->execute([$order['trade_no'],'worker-case','Synthetic',date('Y-m-d H:i:s',$now-20),date('Y-m-d H:i:s',$now-10)]);
    $pdo->exec("UPDATE pay_channel SET config='{\"appid\":\"rotated-app\"}' WHERE id=100");
    $financeFailure=true;$financeCalls=0;
    $worker=new Reconciler($store,
        function($order){return new class {
            public function query($order){return ['trade_status'=>str_ends_with($order['trade_no'],'60')?'TRADE_SUCCESS':(str_ends_with($order['trade_no'],'61')?'WAIT_BUYER_PAY':'TRADE_CLOSED'),'trade_no'=>'202610210000000000000000001'];}
            public function close($order){return ['trade_status'=>'TRADE_CLOSED','_defense_closed_unpaid'=>true];}
        };},
        function($order,$upstream)use($pdo,$quota,&$now,&$financeFailure,&$financeCalls){
            $financeCalls++;$quota->paid($order['trade_no']);
            if($financeFailure){$financeFailure=false;throw new RuntimeException('Synthetic post-confirmation interruption');}
            $pdo->prepare('UPDATE pay_order SET status=1,endtime=?,date=? WHERE trade_no=?')->execute([date('Y-m-d H:i:s',$now),Repository::day($now),$order['trade_no']]);
        });
    $result=$worker->run();
    defenseSame(2,$result['failed'],'financial interruption and ambiguous closure retained for review');
    defenseSame(1,$result['closed'],'worker only releases confirmed closed pending order');
    defenseSame(1,$result['complaints'],'persisted complaint reconciled despite earlier side-effect failure');
    defenseSame($now-10+172800,$quota->blocked(1,$ip),'queue delay does not restart 48 hours');
    $now+=60;$result=$worker->run();
    defenseSame(1,$result['paid'],'paid risk receipt still retries unfinished finance');
    defenseSame(2,$financeCalls,'financial completion retried');
    defenseSame(1,(int)$store->row('SELECT success_count FROM pre_merchant_defense_quota WHERE uid=1 AND ip=:ip',[':ip'=>$ip])['success_count'],'financial retry not double counted');
    defenseSame(0,$result['complaints'],'cursor does not replay existing complaint');
    defenseSame('unknown',$store->row('SELECT state FROM pre_merchant_defense_order WHERE trade_no=:trade',[':trade'=>$ambiguous['trade_no']])['state'],'closed without unpaid close proof does not free quota');
    $complaint=$store->row("SELECT * FROM pre_complain WHERE thirdid='worker-case'");
    defenseSame(false,\lib\Defense\Runtime::complaint($complaint,$store),'issuer configuration rotation does not duplicate complaint');

    defenseFixtureSeed($pdo,$now);$order=defenseFixtureOrder($pdo,70);
    $quota->reserve($order,['ip'=>$ip,'revision'=>1]);
    $pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=1');
    \lib\Defense\Runtime::beforePlugin('alipay','submit',$order['trade_no']);
    defenseSame(1,(int)$store->row('SELECT unsafe_exposure FROM pre_merchant_defense_order WHERE trade_no=:trade',[':trade'=>$order['trade_no']])['unsafe_exposure'],'off-mode payment exposure is tracked');
    defenseSame(1,(int)$policy->set(1,1,1,'fixture-admin')['defense_mode'],'off/on starts new-order scope without clearing existing holds');
    defenseReject('DEFENSE_LEGACY_ORDER',fn()=>$quota->reserve($order,['ip'=>$ip,'revision'=>2]),'off-mode exposed order cannot reenter managed issuance');

    defenseFixtureSeed($pdo,$now);$order=defenseFixtureOrder($pdo,75);
    $preauth=\lib\Defense\Runtime::beforePlugin('alipay','preauthnotify',$order['trade_no']);
    defenseSame('DEFENSE_CHANNEL_UNSUPPORTED',$preauth['reason'],'preauthorization notification cannot bypass qualification to create a charge');

    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
    defenseReject('DEFENSE_TEMP_UNAVAILABLE',fn()=>$store->row('SELECT * FROM pre_defense_nonexistent_fixture'),'silent database errors converted to safe failure');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $badProxy=new PolicyService($store,['merchant_defense_ip_mode'=>'trusted_proxy','merchant_defense_net_verified'=>'1','merchant_defense_trusted_proxies'=>'0.0.0.0/0']);
    defenseSame(false,(bool)$badProxy->networkReady(),'universal proxy trust rejected');
    [$freshDatabase,$freshPdo,$freshAdmin]=defenseFixtureCreate(true);
    try{
        $freshPdo->exec('INSERT INTO pay_user (uid) VALUES (3)');
        defenseSame(0,(int)$freshPdo->query('SELECT defense_mode FROM pay_user WHERE uid=3')->fetchColumn(),'fresh install defaults off');
        foreach(['context','entry','challenge','quota','order','complaint','audit'] as $table){
            $oldColumns=$pdo->query('SHOW COLUMNS FROM pay_merchant_defense_'.$table)->fetchAll(PDO::FETCH_ASSOC);
            $newColumns=$freshPdo->query('SHOW COLUMNS FROM pay_merchant_defense_'.$table)->fetchAll(PDO::FETCH_ASSOC);
            defenseSame($oldColumns,$newColumns,'fresh and upgraded schema match '.$table);
        }
    }finally{$freshAdmin->exec('DROP DATABASE `'.$freshDatabase.'`');}

    echo "Merchant defense PHP 8.4 / MariaDB regression: $checks checks passed; synthetic loopback database only.\n";
}finally{
    if($database && $admin)$admin->exec('DROP DATABASE `'.$database.'`');
}
