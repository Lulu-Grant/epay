<?php
$nosession = true;
if(!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
require './includes/common.php';

if(function_exists('set_time_limit')) @set_time_limit(0);
if(function_exists('ignore_user_abort')) @ignore_user_abort(true);

if(PHP_SAPI !== 'cli'){
	@header('Content-Type: text/plain; charset=UTF-8');
	$cronKey = isset($conf['cronkey']) ? (string)$conf['cronkey'] : '';
	if($cronKey === '') exit("请先设置好监控密钥");
	$requestKey = '';
	$authorization = isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])
		? trim($_SERVER['HTTP_AUTHORIZATION']) : '';
	if(preg_match('/^Bearer[ ]+([^[:space:]]+)$/D', $authorization, $matches)){
		$requestKey = $matches[1];
	}elseif(isset($_GET['key']) && is_string($_GET['key'])){
		$requestKey = $_GET['key'];
	}
	if($requestKey === '' || !hash_equals($cronKey, $requestKey)) exit("监控密钥不正确");
}

if(empty($conf['telegram_notice'])){
	exit("Telegram notice disabled\n");
}

if(empty($conf['telegram_bot_token'])){
	exit("Telegram bot token not configured\n");
}

$result = \lib\Telegram\QueueHelper::processQueue(100);
$cleaned = \lib\Telegram\QueueHelper::cleanOldNotifications(7);
if($cleaned === false && intval($result['failed']) === 0){
	$result['failed'] = 1;
	$result['message'] .= '; cleanup failed';
}

echo "Telegram notify queue\n";
echo "time: ".date('Y-m-d H:i:s')."\n";
echo "success: ".$result['success']."\n";
echo "failed: ".$result['failed']."\n";
echo "message: ".$result['message']."\n";
if(PHP_SAPI === 'cli' && intval($result['failed']) > 0) exit(1);
