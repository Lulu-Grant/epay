<?php
$nosession = true;
if(!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
require './includes/common.php';

if(function_exists('set_time_limit')) @set_time_limit(0);
if(function_exists('ignore_user_abort')) @ignore_user_abort(true);

if(PHP_SAPI !== 'cli'){
	@header('Content-Type: text/plain; charset=UTF-8');
	if(empty($conf['cronkey']))exit("请先设置好监控密钥");
	if($conf['cronkey']!=$_GET['key'])exit("监控密钥不正确");
}

if(empty($conf['telegram_notice'])){
	exit("Telegram notice disabled\n");
}

if(empty($conf['telegram_bot_token'])){
	exit("Telegram bot token not configured\n");
}

$result = \lib\Telegram\QueueHelper::processQueue(100);
\lib\Telegram\QueueHelper::cleanOldNotifications(7);

echo "Telegram notify queue\n";
echo "time: ".date('Y-m-d H:i:s')."\n";
echo "success: ".$result['success']."\n";
echo "failed: ".$result['failed']."\n";
echo "message: ".$result['message']."\n";
