<?php
namespace lib\Telegram;

class QueueHelper
{
    public static function addToQueue($scene, $uid, $param)
    {
        global $DB, $conf;

        if(empty($conf['telegram_notice']) || empty($conf['telegram_bot_token'])) return false;
        if(!self::isSupportedScene($scene)) return false;

        if($uid > 0){
            $bind = $DB->find('telegram_bind', '*', ['uid'=>$uid, 'status'=>1], null, 1);
            if(!$bind) return false;
            $field = 'notify_'.$scene;
            if(isset($bind[$field]) && empty($bind[$field])) return false;
        }else{
            if(empty($conf['telegram_admin_chat_id'])) return false;
        }

        $message = NotifyHelper::getTelegramMessage($scene, $param);
        if(empty($message)) return false;

        $result = $DB->insert('telegram_notify_queue', [
            'uid' => intval($uid),
            'scene' => $scene,
            'param' => json_encode($param, JSON_UNESCAPED_UNICODE),
            'status' => 0,
            'retry_count' => 0,
            'addtime' => 'NOW()',
        ]);

        return $result !== false;
    }

    public static function processQueue($limit = 50)
    {
        global $DB, $conf, $CACHE;

        $limit = max(1, min(200, intval($limit)));
        if(empty($conf['telegram_bot_token'])){
            return ['success'=>0, 'failed'=>0, 'message'=>'Telegram bot token not configured'];
        }

        $rows = $DB->getAll("SELECT * FROM pre_telegram_notify_queue WHERE status=0 ORDER BY addtime ASC LIMIT {$limit}");
        if(!$rows){
            return ['success'=>0, 'failed'=>0, 'message'=>'No notifications to process'];
        }

        $bot = new BotAPI($conf['telegram_bot_token']);
        $success = 0;
        $failed = 0;
        foreach($rows as $row){
            $result = self::sendNotification($row, $bot);
            if($result['success']){
                $DB->update('telegram_notify_queue', ['status'=>1, 'sendtime'=>'NOW()', 'error_msg'=>null], ['id'=>$row['id']]);
                $success++;
            }else{
                self::markFailed($row, $result['message']);
                $failed++;
                if(!empty($result['message'])){
                    $CACHE->save('telegramerrmsg', ['errmsg'=>$result['message'], 'time'=>date('Y-m-d H:i:s')], 86400);
                }
            }
        }

        return ['success'=>$success, 'failed'=>$failed, 'message'=>"Processed: success={$success}, failed={$failed}"];
    }

    public static function cleanOldNotifications($days = 7)
    {
        global $DB;
        $days = max(1, intval($days));
        return $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE status=1 AND addtime < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    }

    private static function sendNotification($row, $bot)
    {
        global $DB, $conf;

        $uid = intval($row['uid']);
        $scene = $row['scene'];
        $param = json_decode($row['param'], true);
        if(!is_array($param)) return ['success'=>false, 'message'=>'Invalid notification payload'];

        if($uid > 0){
            $bind = $DB->find('telegram_bind', '*', ['uid'=>$uid, 'status'=>1], null, 1);
            if(!$bind) return ['success'=>true, 'message'=>'Merchant not bound'];
            $field = 'notify_'.$scene;
            if(isset($bind[$field]) && empty($bind[$field])) return ['success'=>true, 'message'=>'Notification disabled'];
            $chatId = $bind['chat_id'];
        }else{
            if(empty($conf['telegram_admin_chat_id'])) return ['success'=>false, 'message'=>'Admin chat ID not configured'];
            $chatId = $conf['telegram_admin_chat_id'];
        }

        $message = NotifyHelper::getTelegramMessage($scene, $param);
        if(empty($message)) return ['success'=>false, 'message'=>'Unsupported notification scene'];

        $send = $bot->sendMessage($chatId, $message);
        if($send === false){
            return ['success'=>false, 'message'=>$bot->getLastError()];
        }
        return ['success'=>true, 'message'=>'Sent'];
    }

    private static function markFailed($row, $message)
    {
        global $DB;

        $retry = intval($row['retry_count']) + 1;
        $status = $retry >= 3 ? 2 : 0;
        return $DB->update('telegram_notify_queue', [
            'status' => $status,
            'retry_count' => $retry,
            'error_msg' => mb_substr((string)$message, 0, 500),
        ], ['id'=>$row['id']]);
    }

    private static function isSupportedScene($scene)
    {
        return in_array($scene, ['regaudit', 'apply', 'domain', 'order', 'settle', 'login', 'complain', 'balance', 'group'], true);
    }
}
