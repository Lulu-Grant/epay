<?php
include("../includes/common.php");
if($islogin==1){}else exit('{"code":-3,"msg":"No Login"}');
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

if(empty($conf['addon_telegram']) || intval($conf['addon_telegram']) < 1100){
	\lib\Telegram\Installer::install();
	$conf=$CACHE->pre_fetch();
}

switch($act){
case 'testAdmin':
	if(empty($conf['telegram_bot_token']))exit('{"code":-1,"msg":"请先填写 Bot Token"}');
	if(empty($conf['telegram_admin_chat_id']))exit('{"code":-1,"msg":"请先填写管理员 Chat ID"}');
	$bot = new \lib\Telegram\BotAPI($conf['telegram_bot_token']);
	$msg = "<b>Telegram 通知测试</b>\n\n站点：".htmlspecialchars($conf['sitename'], ENT_QUOTES, 'UTF-8')."\n时间：".date('Y-m-d H:i:s');
	$result = $bot->sendMessage($conf['telegram_admin_chat_id'], $msg);
	if($result !== false){
		exit('{"code":0,"msg":"测试消息已发送"}');
	}
	$errmsg = $bot->getLastError();
	$CACHE->save('telegramerrmsg', ['errmsg'=>$errmsg, 'time'=>date('Y-m-d H:i:s')], 86400);
	exit(json_encode(['code'=>-1, 'msg'=>'发送失败：'.$errmsg], JSON_UNESCAPED_UNICODE));
break;

case 'saveBind':
	$uid = intval($_POST['uid']);
	$chat_id = trim($_POST['chat_id']);
	if($uid <= 0)exit('{"code":-1,"msg":"商户号不能为空"}');
	if($chat_id === '' || !preg_match('/^-?[0-9]{5,32}$/', $chat_id))exit('{"code":-1,"msg":"Chat ID 格式不正确"}');
	$user = $DB->find('user', 'uid', ['uid'=>$uid], null, 1);
	if(!$user)exit('{"code":-1,"msg":"商户不存在"}');

	$data = [
		'uid' => $uid,
		'status' => 1,
		'bindtime' => 'NOW()',
		'notify_order' => isset($_POST['notify_order']) ? intval($_POST['notify_order']) : 1,
		'notify_settle' => isset($_POST['notify_settle']) ? intval($_POST['notify_settle']) : 1,
		'notify_login' => isset($_POST['notify_login']) ? intval($_POST['notify_login']) : 1,
		'notify_complain' => isset($_POST['notify_complain']) ? intval($_POST['notify_complain']) : 1,
		'notify_balance' => isset($_POST['notify_balance']) ? intval($_POST['notify_balance']) : 1,
	];

	$DB->update('telegram_bind', ['status'=>0], ['uid'=>$uid]);
	$exist = $DB->find('telegram_bind', '*', ['chat_id'=>$chat_id], null, 1);
	if($exist){
		$result = $DB->update('telegram_bind', $data, ['chat_id'=>$chat_id]);
	}else{
		$data['chat_id'] = $chat_id;
		$result = $DB->insert('telegram_bind', $data);
	}
	if($result !== false)exit('{"code":0,"msg":"绑定保存成功"}');
	exit('{"code":-1,"msg":"绑定保存失败"}');
break;

case 'unbind':
	$id = intval($_POST['id']);
	if($id <= 0)exit('{"code":-1,"msg":"参数错误"}');
	$result = $DB->update('telegram_bind', ['status'=>0], ['id'=>$id]);
	if($result !== false)exit('{"code":0,"msg":"已解除绑定"}');
	exit('{"code":-1,"msg":"解除绑定失败"}');
break;

case 'deleteBind':
	$id = intval($_POST['id']);
	if($id <= 0)exit('{"code":-1,"msg":"参数错误"}');
	$result = $DB->delete('telegram_bind', ['id'=>$id]);
	if($result !== false)exit('{"code":0,"msg":"已删除"}');
	exit('{"code":-1,"msg":"删除失败"}');
break;

case 'processQueue':
	$result = \lib\Telegram\QueueHelper::processQueue(50);
	\lib\Telegram\QueueHelper::cleanOldNotifications(7);
	exit(json_encode(['code'=>0, 'msg'=>$result['message'], 'data'=>$result], JSON_UNESCAPED_UNICODE));
break;

case 'setCommands':
	if(empty($conf['telegram_bot_token']))exit('{"code":-1,"msg":"请先填写 Bot Token"}');
	$service = new \lib\Telegram\BotService($DB, $conf);
	$result = $service->setDefaultCommands();
	if($result !== false)exit('{"code":0,"msg":"Bot 命令列表已设置"}');
	$errmsg = $service->getBotAPI()->getLastError();
	$CACHE->save('telegramerrmsg', ['errmsg'=>$errmsg, 'time'=>date('Y-m-d H:i:s')], 86400);
	exit(json_encode(['code'=>-1, 'msg'=>'设置失败：'.$errmsg], JSON_UNESCAPED_UNICODE));
break;

case 'workerStatus':
	$status = $CACHE->read('telegram_worker_status');
	$status = $status ? @unserialize($status) : [];
	if(!is_array($status))$status = [];
	$status['last_update_id'] = (new \lib\Telegram\BotService($DB, $conf))->getLastUpdateId();
	exit(json_encode(['code'=>0, 'msg'=>'succ', 'data'=>$status], JSON_UNESCAPED_UNICODE));
break;

case 'clearError':
	$CACHE->delete('telegramerrmsg');
	exit('{"code":0,"msg":"已清理 Telegram 错误缓存"}');
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}
