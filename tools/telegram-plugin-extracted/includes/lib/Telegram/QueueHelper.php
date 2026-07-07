<?php
namespace lib\Telegram;

class QueueHelper
{
    public static function addToQueue($scene, $uid, $param)
    {
        global $DB, $conf;
        
        $token = $conf['telegram_bot_token'];
        if (empty($token)) {
            return false;
        }
        
        try {
            $result = $DB->insert('telegram_notify_queue', [
                'uid' => $uid,
                'scene' => $scene,
                'param' => json_encode($param),
                'status' => 0,
                'retry_count' => 0
            ]);
            return $result !== false;
        } catch (\Exception $e) {
            error_log("Telegram Queue Error: " . $e->getMessage());
            return false;
        }
    }

    public static function processQueue($limit = 100)
    {
        global $DB, $conf;
        
        $token = $conf['telegram_bot_token'];
        if (empty($token)) {
            return ['success' => 0, 'failed' => 0, 'message' => 'Telegram bot token not configured'];
        }

        $successCount = 0;
        $failedCount = 0;

        try {
            $rs = $DB->query("SELECT * FROM pre_telegram_notify_queue WHERE status=0 ORDER BY addtime ASC LIMIT {$limit}");
            $notifications = [];
            while ($row = $rs->fetch()) {
                $notifications[] = $row;
            }

            if (empty($notifications)) {
                return ['success' => 0, 'failed' => 0, 'message' => 'No notifications to process'];
            }

            $botAPI = new BotAPI($token);

            foreach ($notifications as $notify) {
                try {
                    $result = self::sendNotification($notify, $botAPI);
                    
                    if ($result['success']) {
                        $DB->update('telegram_notify_queue', [
                            'status' => 1,
                            'sendtime' => date('Y-m-d H:i:s')
                        ], ['id' => $notify['id']]);
                        $successCount++;
                    } else {
                        self::handleFailedNotification($notify, $result['message']);
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    self::handleFailedNotification($notify, $e->getMessage());
                    $failedCount++;
                }
            }
        } catch (\Exception $e) {
            error_log("Telegram Queue Process Error: " . $e->getMessage());
            return ['success' => 0, 'failed' => 0, 'message' => 'Database error: ' . $e->getMessage()];
        }

        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'message' => "Processed: success={$successCount}, failed={$failedCount}"
        ];
    }

    private static function sendNotification($notify, $botAPI)
    {
        global $DB;
        
        $uid = $notify['uid'];
        $scene = $notify['scene'];
        $param = json_decode($notify['param'], true);

        if ($uid > 0) {
            $bindRs = $DB->query("SELECT * FROM pre_telegram_bind WHERE uid={$uid} AND status=1 LIMIT 1");
            $bind = $bindRs->fetch();

            if (!$bind) {
                return ['success' => false, 'message' => 'Merchant not bound to Telegram'];
            }

            $notifyField = 'notify_' . $scene;
            if (empty($bind[$notifyField])) {
                return ['success' => true, 'message' => 'Notification disabled for this scene'];
            }

            $message = NotifyHelper::getTelegramMessage($scene, $param);
            if (empty($message)) {
                return ['success' => false, 'message' => 'Failed to generate message'];
            }

            $result = $botAPI->sendMessage($bind['chat_id'], $message);
            return ['success' => $result !== false, 'message' => $result ? 'Sent successfully' : 'Failed to send'];
        } else {
            global $conf;
            $adminChatId = $conf['telegram_admin_chat_id'];
            
            if (empty($adminChatId)) {
                return ['success' => false, 'message' => 'Admin chat ID not configured'];
            }

            $message = NotifyHelper::getTelegramMessage($scene, $param);
            if (empty($message)) {
                return ['success' => false, 'message' => 'Failed to generate message'];
            }

            $result = $botAPI->sendMessage($adminChatId, $message);
            return ['success' => $result !== false, 'message' => $result ? 'Sent successfully' : 'Failed to send'];
        }
    }

    private static function handleFailedNotification($notify, $errorMsg)
    {
        global $DB;
        
        $newRetryCount = $notify['retry_count'] + 1;
        $maxRetries = 3;

        if ($newRetryCount >= $maxRetries) {
            $DB->update('telegram_notify_queue', [
                'status' => 2,
                'retry_count' => $newRetryCount,
                'error_msg' => substr($errorMsg, 0, 500)
            ], ['id' => $notify['id']]);
        } else {
            $DB->update('telegram_notify_queue', [
                'retry_count' => $newRetryCount,
                'error_msg' => substr($errorMsg, 0, 500)
            ], ['id' => $notify['id']]);
        }
    }

    public static function cleanOldNotifications($days = 7)
    {
        global $DB;
        
        try {
            $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE status=1 AND addtime < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        } catch (\Exception $e) {
            error_log("Telegram Queue Clean Error: " . $e->getMessage());
        }
    }
}
