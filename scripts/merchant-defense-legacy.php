#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Default is a bounded read-only plan. Retiring ONE old link requires its reviewed snapshot.
$options=getopt('',['uid:','after:','limit:','retire:','fingerprint:','confirm-retire','actor:','block-local']);
$uid=filter_var($options['uid']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if($uid===false||(array_key_exists('block-local',$options)&&!isset($options['retire']))){fwrite(STDERR,"Usage: php scripts/merchant-defense-legacy.php --uid=UID [--after=TRADE --limit=50]\nRetire one reviewed order: --retire=TRADE --fingerprint=HASH --confirm-retire --actor=NAME [--block-local]\n");exit(2);}
$nosession=true;$_SERVER['HTTP_HOST']='localhost';
ob_start();
try{
    require dirname(__DIR__).'/includes/common.php';ob_end_clean();
    $store=\lib\Defense\Runtime::store();$service=new \lib\Defense\LegacyOrderService($store);
    $store->query('SET SESSION innodb_lock_wait_timeout=2');$store->query('SET SESSION lock_wait_timeout=2');
    if(isset($options['retire'])){
        if(!array_key_exists('confirm-retire',$options)||!is_string($options['actor']??null)||trim($options['actor'])==='')throw new \lib\Defense\DefenseException('DEFENSE_VERIFY_FAILED','必须明确确认逐单阻断或关单并填写操作者');
        $result=$service->retire($uid,$options['retire'],$options['fingerprint']??null,$options['actor'],function($order)use($DB,$conf){
            $info=$DB->findColumn('user','channelinfo',['uid'=>$order['uid']]);$channel=\lib\Channel::get($order['channel'],$info);
            return new \lib\Defense\AlipayQrAdapter($channel??[],$conf,$order);
        },array_key_exists('block-local',$options));
    }else{$result=$service->page($uid,$options['after']??'',(int)($options['limit']??50));}
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    if(isset($result['state'])&&$result['state']==='review')exit(3);
}catch(Throwable $e){while(ob_get_level())ob_end_clean();echo json_encode(['ok'=>false,'reason'=>$e instanceof \lib\Defense\DefenseException?$e->reason:'DEFENSE_TEMP_UNAVAILABLE']).PHP_EOL;exit(1);}
