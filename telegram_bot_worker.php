<?php
$nosession = true;
if(!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
require './includes/common.php';

if(function_exists('set_time_limit')) @set_time_limit(0);
if(function_exists('ignore_user_abort')) @ignore_user_abort(true);

$options = [];
if(PHP_SAPI === 'cli'){
    foreach(array_slice($argv, 1) as $arg){
        if($arg === '--once') $options['once'] = true;
        if($arg === '--process-history') $options['process_history'] = true;
        if(strpos($arg, '--limit=') === 0) $options['limit'] = intval(substr($arg, 8));
        if(strpos($arg, '--timeout=') === 0) $options['timeout'] = intval(substr($arg, 10));
    }
}else{
    @header('Content-Type: text/plain; charset=UTF-8');
    if(empty($conf['cronkey'])) exit("请先设置好监控密钥\n");
    if(!isset($_GET['key']) || $conf['cronkey'] != $_GET['key']) exit("监控密钥不正确\n");
    $options['once'] = true;
}

if(empty($conf['addon_telegram']) || intval($conf['addon_telegram']) < 1100){
    \lib\Telegram\Installer::install();
    $conf = $CACHE->pre_fetch();
}

if(empty($conf['telegram_notice'])){
    echo "Telegram notice disabled\n";
    exit(0);
}
if(empty($conf['telegram_bot_token'])){
    echo "Telegram bot token not configured\n";
    exit(1);
}

$lockFile = sys_get_temp_dir().'/epay_telegram_bot_worker.lock';
$lockFp = fopen($lockFile, 'c');
if(!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)){
    echo "Telegram bot worker already running\n";
    exit(0);
}

$service = new \lib\Telegram\BotService($DB, $conf);
$bot = $service->getBotAPI();
$handler = new \lib\Telegram\MessageHandler($service);
$limit = isset($options['limit']) ? max(1, min(100, intval($options['limit']))) : 100;
$timeout = isset($options['timeout']) ? max(0, min(30, intval($options['timeout']))) : 30;
$once = !empty($options['once']);
$errorCount = 0;

save_worker_status(['state'=>'starting', 'pid'=>getmypid(), 'last_error'=>null]);

$me = $bot->getMe();
if($me === false){
    save_worker_status(['state'=>'error', 'pid'=>getmypid(), 'last_error'=>$bot->getLastError()]);
    echo "Telegram getMe failed: ".$bot->getLastError()."\n";
    exit(1);
}

$bot->deleteWebhook();
$lastUpdateId = $service->getLastUpdateId();
if($lastUpdateId <= 0 && empty($options['process_history'])){
    $latestUpdates = $bot->getUpdates(-1, 1, 0);
    if($latestUpdates !== false && !empty($latestUpdates)){
        foreach($latestUpdates as $update){
            if(!isset($update['update_id'])) continue;
            $lastUpdateId = intval($update['update_id']);
            $DB->insert('telegram_update', [
                'update_id' => $lastUpdateId,
                'content' => json_encode($update, JSON_UNESCAPED_UNICODE),
                'addtime' => 'NOW()',
            ], true);
        }
        echo "historical updates skipped, start from update ".$lastUpdateId."\n";
    }
}
save_worker_status([
    'state' => 'running',
    'pid' => getmypid(),
    'bot_username' => isset($me['username']) ? $me['username'] : '',
    'last_update_id' => $lastUpdateId,
    'last_error' => null,
]);

echo "Telegram bot worker started\n";
echo "bot: ".(isset($me['username']) ? $me['username'] : 'unknown')."\n";
echo "last_update_id: ".$lastUpdateId."\n";

while(true){
    $updates = $bot->getUpdates($lastUpdateId + 1, $limit, $timeout);
    if($updates === false){
        $errorCount++;
        $error = $bot->getLastError();
        save_worker_status(['state'=>'running', 'pid'=>getmypid(), 'last_error'=>$error, 'error_count'=>$errorCount]);
        echo date('Y-m-d H:i:s')." Telegram API error: ".$error."\n";
        sleep($errorCount > 5 ? 10 : 3);
        if($once) break;
        continue;
    }

    $errorCount = 0;
    if($updates){
        foreach($updates as $update){
            if(!isset($update['update_id'])) continue;
            $updateId = intval($update['update_id']);
            $lastUpdateId = $updateId;
            $DB->insert('telegram_update', [
                'update_id' => $updateId,
                'content' => json_encode($update, JSON_UNESCAPED_UNICODE),
                'addtime' => 'NOW()',
            ], true);

            try{
                $handler->handleUpdate($update);
            }catch(\Exception $e){
                save_worker_status(['state'=>'running', 'pid'=>getmypid(), 'last_update_id'=>$lastUpdateId, 'last_error'=>$e->getMessage()]);
                echo date('Y-m-d H:i:s')." update ".$updateId." failed: ".$e->getMessage()."\n";
                continue;
            }
            save_worker_status(['state'=>'running', 'pid'=>getmypid(), 'last_update_id'=>$lastUpdateId, 'last_error'=>null]);
            echo date('Y-m-d H:i:s')." processed update ".$updateId."\n";
        }
    }else{
        save_worker_status([
            'state'=>'running',
            'pid'=>getmypid(),
            'last_update_id'=>$lastUpdateId,
            'last_error'=>null,
            'error_count'=>0,
        ]);
    }

    if($once) break;
    sleep(1);
}

save_worker_status(['state'=>'stopped', 'pid'=>getmypid(), 'last_update_id'=>$lastUpdateId]);
echo "Telegram bot worker stopped\n";

function save_worker_status($data)
{
    global $CACHE;
    if(!isset($CACHE)) return false;
    $old = $CACHE->read('telegram_worker_status');
    $status = [];
    if($old){
        $tmp = @unserialize($old);
        if(is_array($tmp)) $status = $tmp;
    }
    foreach($data as $key=>$value){
        $status[$key] = $value;
    }
    $status['time'] = date('Y-m-d H:i:s');
    return $CACHE->save('telegram_worker_status', $status, 86400);
}
