#!/usr/bin/env php
<?php
namespace lib {
    class Payment {
        public static $cache = [];
        public static $locks = 0;
        public static function lockPayData($trade, $callback){
            self::$locks++;
            return self::$cache[$trade] ??= $callback();
        }
    }
}

namespace {
    if(PHP_SAPI !== 'cli'){http_response_code(404);exit;}
    if(PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500){fwrite(STDERR, "PHP 8.4.x required\n");exit(1);}
    error_reporting(E_ALL);
    define('TRADE_NO', 'LP2026101209000012345');
    require dirname(__DIR__).'/plugins/weilaile/weilaile_plugin.php';

    class WeilaileFixtureClient extends WeilaileClient {
        public static $requests = [];
        public static $response = ['code'=>1, 'trade_no'=>'UPSTREAM001', 'payurl'=>'https://pay.example.test/cashier'];
        protected function request(array $params){self::$requests[] = $params;return self::$response;}
    }
    class WeilaileFixturePlugin extends weilaile_plugin {
        protected static function client(){global $channel;return new WeilaileFixtureClient($channel['appid'], $channel['appkey']);}
    }
    $processed = [];
    $credits = 0;
    $throwProcessing = false;
    function processNotify($order, $upstream){
        global $processed, $credits, $throwProcessing;
        if($throwProcessing)throw new RuntimeException('fixture process failure');
        if(!isset($processed[$order['trade_no']])){$processed[$order['trade_no']] = $upstream;$credits++;}
    }
    $events = [];
    function logPaymentCallbackEvent($event, $trade, $channel, $reason){global $events;$events[] = [$event,$reason];}
    function checkwechat(){return false;}
    function checkalipay(){return false;}
    function checkmobile(){return false;}
    $checks = 0;
    function same($expected, $actual, $label){global $checks;$checks++;if($expected !== $actual){fwrite(STDERR, "FAIL: $label\n");exit(1);}}
    function rejects($callback, $label){try{$callback();}catch(WeilaileException $e){same(true,true,$label);return;}same(true,false,$label);}

    // All credentials and orders below are local fixtures. No network or database connection is made.
    $key = 'local-fixture-key-never-use-in-production';
    $channel = ['id'=>9001, 'appid'=>'1001', 'appkey'=>$key];
    $order = ['trade_no'=>TRADE_NO, 'typename'=>'alipay', 'name'=>'测试 & 商品+号', 'realmoney'=>'10.00', 'status'=>0, 'api_trade_no'=>null];
    $siteurl = 'https://merchant.example.test/';
    $conf = ['localurl'=>'https://notify.example.test/'];
    $ordername = '';
    $clientip = '203.0.113.10';
    $client = new WeilaileFixtureClient('1001', $key);

    $fields = ['z'=>'0', 'name'=>'测试 & 商品+号', 'empty'=>'', 'missing'=>null, 'pid'=>'1001', 'sign'=>'ignored', 'sign_type'=>'MD5'];
    same(md5('name=测试 & 商品+号&pid=1001&z=0'.$key),$client->sign($fields),'ASCII sorting, unencoded Unicode, zero retained, excluded empty and sign fields');
    $fields['sign'] = $client->sign($fields);
    same(true,$client->verify($fields),'correct MD5');
    $fields['name'] .= 'tampered';
    same(false,$client->verify($fields),'tampered signature');
    same(false,$client->verify(['sign_type'=>'RSA','sign'=>str_repeat('a',32)]),'wrong signature algorithm');
    same(false,$client->verify(['sign_type'=>'MD5','sign'=>['bad']]),'array signature');
    rejects(fn()=>$client->sign(['param'=>['bad']]),'array parameter');
    rejects(fn()=>new WeilaileFixtureClient('bad','fixture'),'invalid PID');
    rejects(fn()=>new WeilaileFixtureClient('1001',''),'missing key');
    foreach(['10'=>1000,'10.0'=>1000,'10.00'=>1000,'0.01'=>1,'10.001'=>false,'1e1'=>false,'-10'=>false,'NaN'=>false] as $money=>$cents){same($cents,WeilaileClient::amountCents((string)$money),'exact decimal money '.$money);}

    same(['alipay'],weilaile_plugin::$info['types'],'only documented payment code');
    same('/pay/alipay/'.TRADE_NO.'/',weilaile_plugin::submit()['url'],'submit uses internal cashier');
    $result = WeilaileFixturePlugin::mapi();
    same('jump',$result['type'],'MAPI payurl');
    $sent = WeilaileFixtureClient::$requests[0];
    same('alipay',$sent['type'],'required type sent');
    same('1001',$sent['pid'],'configured PID');
    same('10.00',$sent['money'],'two decimal money');
    same(TRADE_NO,$sent['out_trade_no'],'LP trade preserved');
    same($clientip,$sent['clientip'],'actual payer IP');
    same('pc',$sent['device'],'desktop device');
    same('https://notify.example.test/pay/notify/'.TRADE_NO.'/',$sent['notify_url'],'callback routing');
    same('https://merchant.example.test/pay/return/'.TRADE_NO.'/',$sent['return_url'],'return routing');
    same(true,$client->verify($sent),'request signed');
    same($result,WeilaileFixturePlugin::mapi(),'same payment information on retry');
    same(1,count(WeilaileFixtureClient::$requests),'duplicate call reuses locked payment data');
    foreach([['mobile','', 'mobile'], ['mobile','wechat','wechat'], ['mobile','alipay','alipay'], ['jump','', 'jump']] as [$device,$mdevice,$expectedDevice]){
        \lib\Payment::$cache=[];
        WeilaileFixturePlugin::mapi();
        same($expectedDevice,end(WeilaileFixtureClient::$requests)['device'],'MAPI device preserved '.$expectedDevice);
    }
    unset($device,$mdevice);
    foreach(['qrcode'=>'https://qr.example.test/code','urlscheme'=>'alipays://platformapi/startapp?appId=20000067','html'=>'<form method="post"></form>'] as $field=>$value){
        \lib\Payment::$cache = [];
        WeilaileFixtureClient::$response = ['code'=>1,'trade_no'=>'UPSTREAM001',$field=>$value];
        $r = WeilaileFixturePlugin::mapi();
        same(['qrcode'=>'qrcode','urlscheme'=>'scheme','html'=>'html'][$field],$r['type'],'payment response '.$field);
        same($value,$r['data']??$r['url'],'response preserved '.$field);
    }
    $params = $sent;unset($params['pid'],$params['sign'],$params['sign_type']);
    foreach(['payurl'=>'javascript:alert(1)','qrcode'=>"https://qr.example.test/code'bad",'urlscheme'=>'javascript:alert(1)'] as $field=>$value){WeilaileFixtureClient::$response=['code'=>1,'trade_no'=>'UPSTREAM001',$field=>$value];rejects(fn()=>$client->pay($params),'reject unsafe '.$field);}
    WeilaileFixtureClient::$response = ['code'=>1,'payurl'=>'https://pay.example.test/'];
    rejects(fn()=>$client->pay($params),'missing upstream trade');
    WeilaileFixtureClient::$response = ['code'=>1,'trade_no'=>'UPSTREAM001'];
    rejects(fn()=>$client->pay($params),'missing payment information');
    WeilaileFixtureClient::$response = ['code'=>-1,'msg'=>'sensitive upstream details'];
    \lib\Payment::$cache = [];
    same(false,str_contains(WeilaileFixturePlugin::mapi()['msg'],'sensitive'),'gateway error body not exposed');
    $bad = $params;$bad['type']='wxpay';rejects(fn()=>$client->pay($bad),'unsupported payment code');
    $bad = $params;$bad['clientip']='invalid';rejects(fn()=>$client->pay($bad),'invalid IP');
    $order['typename']='wxpay';same('error',WeilaileFixturePlugin::mapi()['type'],'plugin rejects other payment methods');$order['typename']='alipay';

    $base = ['pid'=>'1001','trade_no'=>'UPSTREAM001','out_trade_no'=>TRADE_NO,'type'=>'alipay','name'=>$order['name'],'money'=>'10','trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];
    $signed = function($p)use($client){$p['sign']=$client->sign($p);return $p;};
    $_SERVER['REQUEST_METHOD']='GET';
    // Buffer output so the plugin can set real callback response headers throughout this fixture.
    ob_start();
    $_GET = $signed($base);same('success',WeilaileFixturePlugin::notify()['data'],'valid signed payment');
    same(1,$credits,'first paid processing');
    same('success',WeilaileFixturePlugin::notify()['data'],'valid duplicate acknowledged');same(1,$credits,'fixture idempotent processor not credited twice');
    foreach(['pid'=>'1002','out_trade_no'=>'OTHERORDER','money'=>'10.01','trade_status'=>'WAIT_BUYER_PAY','type'=>'wxpay','trade_no'=>'bad trade'] as $field=>$value){$p=$base;$p[$field]=$value;$_GET=$signed($p);same('fail',WeilaileFixturePlugin::notify()['data'],'signed mismatch '.$field);}
    $p=$base;$p['money']='10.001';$_GET=$signed($p);same('fail',WeilaileFixturePlugin::notify()['data'],'excess precision rejected');
    $_GET=$signed($base);$_GET['money']='100';same('fail',WeilaileFixturePlugin::notify()['data'],'tampered callback rejected');
    $_GET=$signed($base);$_GET['param']=['nested'];same('fail',WeilaileFixturePlugin::notify()['data'],'nested callback parameter rejected');
    $order['api_trade_no']='OTHERUPSTREAM';$_GET=$signed($base);same('fail',WeilaileFixturePlugin::notify()['data'],'existing upstream trade must match');$order['api_trade_no']=null;
    $_SERVER['REQUEST_METHOD']='POST';$_GET=$signed($base);same('fail',WeilaileFixturePlugin::notify()['data'],'GET protocol enforced');$_SERVER['REQUEST_METHOD']='GET';
    $throwProcessing=true;$_GET=$signed($base);same('fail',WeilaileFixturePlugin::notify()['data'],'processing exception cannot acknowledge');same('process',end($events)[1],'safe processing stage log');$throwProcessing=false;
    same(['type'=>'page','page'=>'return'],WeilaileFixturePlugin::return(),'browser return waits on database');same(1,$credits,'browser return does not credit');
    foreach($events as $event){same(false,str_contains(json_encode($event),$key),'no key in rejection log');}
    ob_end_clean();
    echo "Weilaile PHP 8.4 regression: $checks checks passed; no network, production key or database used.\n";
}
