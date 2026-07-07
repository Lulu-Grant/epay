<?php
define('IN_CRONLITE', true);
define('SYSTEM_ROOT', dirname(__FILE__).'/includes/');
define('ROOT', dirname(__FILE__).'/');

set_time_limit(0);

require ROOT.'config.php';
require SYSTEM_ROOT.'autoloader.php';
Autoloader::register();

$DB = new \lib\PdoHelper($dbconfig);
$CACHE = new \lib\Cache();
$conf = $CACHE->pre_fetch();

require SYSTEM_ROOT.'functions.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Telegram 通知队列处理 ===\n";
echo "开始时间: ".date('Y-m-d H:i:s')."\n\n";

if (empty($conf['telegram_bot_token'])) {
    echo "错误: Telegram bot token 未配置\n";
    exit(1);
}

try {
    $result = \lib\Telegram\QueueHelper::processQueue(100);
    
    echo "处理结果:\n";
    echo "  成功: {$result['success']}\n";
    echo "  失败: {$result['failed']}\n";
    echo "  消息: {$result['message']}\n";
    
    if ($result['success'] > 0 || $result['failed'] > 0) {
        echo "\n清理旧通知...\n";
        \lib\Telegram\QueueHelper::cleanOldNotifications(7);
        echo "已清理7天前的已发送通知\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n结束时间: ".date('Y-m-d H:i:s')."\n";
echo "处理完成!\n";
