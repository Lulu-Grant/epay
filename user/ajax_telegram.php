<?php
include("../includes/common.php");
if($islogin2==1){}else exit('{"code":-3,"msg":"No Login"}');
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

if(empty($conf['addon_telegram']) || intval($conf['addon_telegram']) < 1100){
	\lib\Telegram\Installer::install();
	$conf=$CACHE->pre_fetch();
}

$service = new \lib\Telegram\BotService($DB, $conf);

switch($act){
case 'createBindCode':
	$result = $service->createBindCode($uid, 300);
	if($result['success']){
		exit(json_encode(['code'=>0, 'msg'=>'绑定码已生成', 'data'=>$result], JSON_UNESCAPED_UNICODE));
	}
	exit(json_encode(['code'=>-1, 'msg'=>$result['message']], JSON_UNESCAPED_UNICODE));
break;

case 'unbind':
	$bind = $DB->find('telegram_bind', '*', ['uid'=>$uid, 'status'=>1], null, 1);
	if(!$bind)exit('{"code":-1,"msg":"当前未绑定 Telegram"}');
	$result = $DB->update('telegram_bind', ['status'=>0], ['id'=>intval($bind['id'])]);
	if($result !== false)exit('{"code":0,"msg":"已解绑 Telegram"}');
	exit('{"code":-1,"msg":"解绑失败"}');
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}
