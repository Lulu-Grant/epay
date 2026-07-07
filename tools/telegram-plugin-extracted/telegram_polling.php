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

$token = $conf['telegram_bot_token'];

if (empty($token)) {
    echo "Bot token not configured\n";
    exit(1);
}

$botService = new \lib\Telegram\BotService($DB, [
    'telegram_bot_token' => $token,
    'telegram_admin_chat_id' => $conf['telegram_admin_chat_id']
]);

$botAPI = $botService->getBotAPI();
$handler = new \lib\Telegram\MessageHandler($botService);

$lastUpdateId = 0;
try {
    $result = $DB->query("SELECT MAX(update_id) FROM pre_telegram_update");
    if ($result) {
        $row = $result->fetch();
        $lastUpdateId = intval($row[0]);
    }
} catch (Exception $e) {
}

echo "========================================\n";
echo "Telegram Bot Polling Mode\n";
echo "========================================\n";
echo "Bot Token: " . substr($token, 0, 8) . "...\n";
echo "Last Update ID: {$lastUpdateId}\n";
echo "Starting polling loop...\n";
echo "========================================\n";

$errorCount = 0;

while (true) {
    try {
        $updates = $botAPI->getUpdates($lastUpdateId + 1, 100, 30);
        
        if ($updates) {
            foreach ($updates as $update) {
                $updateId = $update['update_id'];
                $lastUpdateId = $updateId;
                
                try {
                    $DB->insert('telegram_update', ['update_id' => $updateId, 'content' => json_encode($update), 'addtime' => date('Y-m-d H:i:s')], true);
                } catch (Exception $e) {
                }
                
                $handler->handleUpdate($update);
                
                echo date('Y-m-d H:i:s') . " - Processed update: {$updateId}\n";
            }
            $errorCount = 0;
        }
        
        sleep(1);
        
    } catch (Exception $e) {
        $errorCount++;
        $errorMsg = $e->getMessage();
        echo date('Y-m-d H:i:s') . " - Error: {$errorMsg}\n";
        
        if ($errorCount > 5) {
            echo "Too many errors, sleeping for 10 seconds...\n";
            sleep(10);
            $errorCount = 0;
        } else {
            sleep(5);
        }
    }
}
