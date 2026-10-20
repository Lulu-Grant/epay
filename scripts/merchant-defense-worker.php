#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')!=='--run' || count($argv)!==2){fwrite(STDERR,"Usage: php scripts/merchant-defense-worker.php --run\n");exit(2);}
// Explicit operational worker. Local regression fixtures instantiate Reconciler without this bootstrap.
$nosession=true;
$_SERVER['HTTP_HOST']='localhost';
require dirname(__DIR__).'/includes/common.php';
try{
    if(!\lib\Defense\Runtime::installed())throw new \RuntimeException();
    $worker=new \lib\Defense\Reconciler(\lib\Defense\Runtime::store(),
        function($order)use($conf,$DB){
            if(!empty($order['subchannel']))throw new \lib\Defense\DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
            $merchant=$DB->find('user','channelinfo',['uid'=>$order['uid']]);
            $channel=\lib\Channel::get($order['channel'],$merchant?$merchant['channelinfo']:null);
            if(!$channel)throw new \lib\Defense\DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
            if(($order['defense_account_scope']??'')!==('alipay:'.(string)($channel['appid']??'')))throw new \lib\Defense\DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
            return new \lib\Defense\AlipayQrAdapter($channel,$conf,$order);
        },
        function($order,$upstream){processNotify($order,$upstream);}
    );
    echo json_encode($worker->run(),JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"merchant_defense_worker_failed\n");exit(1);}
