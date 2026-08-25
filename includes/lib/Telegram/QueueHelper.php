<?php
namespace lib\Telegram;

class QueueHelper
{
    public static function addToQueue($scene, $uid, $param, $returnId = false, $dedupeKey = null)
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
            $overrideChat = $scene === 'daily_health' && is_array($param) && isset($param['chat_id']) && preg_match('/^-?[0-9]{5,20}$/', (string)$param['chat_id']);
            if(empty($conf['telegram_admin_chat_id']) && !$overrideChat) return false;
        }

        $message = NotifyHelper::getTelegramMessage($scene, $param);
        if(empty($message)) return false;
        $encodedParam = json_encode($param);
        if($encodedParam === false) return false;

        $dedupeKey = $dedupeKey === null ? null : trim((string)$dedupeKey);
        if($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 100 || !preg_match('/^[A-Za-z0-9:._-]+$/D', $dedupeKey))) return false;
        $result = $DB->insert('telegram_notify_queue', [
            'uid' => intval($uid),
            'scene' => $scene,
            'param' => $encodedParam,
            'status' => 0,
            'retry_count' => 0,
            'dedupe_key' => $dedupeKey,
            'claimtime' => null,
            'sendstarttime' => null,
            'next_attempt' => 'NOW()',
            'addtime' => 'NOW()',
        ]);

        if($result === false && $dedupeKey !== null){
            $existing = $DB->find('telegram_notify_queue', 'id', ['dedupe_key'=>$dedupeKey], null, 1);
            if($existing) $result = intval($existing['id']);
        }
        if($result === false) return false;
        return $returnId ? intval($result) : true;
    }

    public static function processQueue($limit = 50, $testBot = null)
    {
        global $DB;
        $limit = max(1, min(200, intval($limit)));
        if($testBot !== null){
            $database = (string)$DB->getColumn('SELECT DATABASE()');
            $requiredMethods = ['sendMessage','isDeterministicFormatError','isDeliveryUncertain','getLastError'];
            foreach($requiredMethods as $method){
                if(!is_object($testBot) || !method_exists($testBot, $method)){
                    return ['success'=>0, 'failed'=>1, 'message'=>'Invalid Telegram test transport'];
                }
            }
            if(PHP_SAPI !== 'cli' || !preg_match('/(?:_local|_test)$/D', $database)){
                return ['success'=>0, 'failed'=>1, 'message'=>'Telegram test transport is restricted to CLI test databases'];
            }
        }
        $prefix = defined('DBQZ') ? DBQZ : 'pre';
        $lockName = 'epay_'.substr(hash('sha256', $prefix.':telegram_notify_worker'), 0, 24);
        if(!$DB->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])){
            return ['success'=>0, 'failed'=>0, 'message'=>'Telegram queue worker is already running'];
        }
        try {
            return self::processQueueLocked($limit, $testBot);
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
        }
    }

    private static function processQueueLocked($limit, $testBot = null)
    {
        global $DB, $conf, $CACHE;

        $healthInstalled = !empty($conf['addon_health_report']) && intval($conf['addon_health_report']) >= intval(\lib\Health\Installer::VERSION);
        $healthState = self::prepareHealthQueue($healthInstalled);
        $healthEnabled = !empty($healthState['ok']) && !empty($healthState['enabled']);
        $healthError = empty($healthState['ok']) ? $healthState['message'] : null;
        if(empty($conf['telegram_bot_token'])){
            $message = $healthError ? $healthError.'; Telegram bot token not configured' : 'Telegram bot token not configured';
            return ['success'=>0, 'failed'=>$healthError ? 1 : 0, 'message'=>$message];
        }

        $rows = [];
        if($healthInstalled && $healthEnabled){
            $healthLimit = min(10, $limit > 1 ? $limit - 1 : 1);
            $healthRows = $DB->getAll("SELECT * FROM pre_telegram_notify_queue WHERE scene='daily_health' AND status=0 AND (next_attempt IS NULL OR next_attempt<=NOW()) ORDER BY addtime ASC LIMIT {$healthLimit}");
            if($healthRows === false) $healthError = 'Telegram health queue read failed';
            else $rows = $healthRows ?: [];
        }
        $legacyLimit = $limit - count($rows);
        if($legacyLimit > 0){
            $legacyRows = $DB->getAll("SELECT * FROM pre_telegram_notify_queue WHERE scene<>'daily_health' AND status=0 ORDER BY addtime ASC LIMIT {$legacyLimit}");
            if($legacyRows === false) return ['success'=>0, 'failed'=>1, 'message'=>'Telegram legacy queue read failed'.($healthError ? '; '.$healthError : '')];
            if($legacyRows) $rows = array_merge($rows, $legacyRows);
        }
        if(!$rows){
            return ['success'=>0, 'failed'=>$healthError ? 1 : 0, 'message'=>$healthError ?: 'No notifications to process'];
        }

        $bot = $testBot === null ? new BotAPI($conf['telegram_bot_token']) : $testBot;
        $success = 0;
        $failed = $healthError ? 1 : 0;
        foreach($rows as $row){
            $id = intval($row['id']);
            if($row['scene'] !== 'daily_health'){
                $result = self::sendNotification($row, $bot, false);
                if($result['success']){
                    $updated = $DB->update('telegram_notify_queue', ['status'=>1, 'sendtime'=>'NOW()', 'error_msg'=>null], ['id'=>$id, 'status'=>0]);
                    if($updated === false){
                        error_log('Telegram legacy queue success update failed: '.$DB->error());
                        $failed++;
                    }else $success++;
                }else{
                    if(self::markLegacyFailed($row, $result['message']) === false) error_log('Telegram legacy queue failure update failed: '.$DB->error());
                    $failed++;
                    if(!empty($result['message'])) $CACHE->save('telegramerrmsg', ['errmsg'=>$result['message'], 'time'=>date('Y-m-d H:i:s')], 86400);
                }
                continue;
            }
            $claimed = $DB->exec("UPDATE pre_telegram_notify_queue SET status=3,claimtime=NOW(),sendstarttime=NULL WHERE id={$id} AND status=0 AND (next_attempt IS NULL OR next_attempt<=NOW())");
            if($claimed !== 1) continue;
            $row['status'] = 3;
            $result = self::sendNotification($row, $bot, true);
            if($result['success']){
                $updated = $DB->exec("UPDATE pre_telegram_notify_queue SET status=1,sendtime=NOW(),error_msg=NULL,claimtime=NULL,next_attempt=NULL WHERE id={$id} AND status=3");
                if($updated === 1){
                    if(self::updateHealthReportStatus($row, 2)) $success++;
                    else{
                        error_log('Telegram health success projection failed for queue '.$id);
                        $failed++;
                    }
                }else{
                    error_log('Telegram health queue success update failed for queue '.$id.': '.$DB->error());
                    $failed++;
                }
            }else{
                $failureState = self::markFailed($row, $result['message'], !empty($result['terminal']));
                if(!$failureState['updated']) error_log('Telegram health queue failure update failed for queue '.$id.': '.$DB->error());
                elseif($failureState['terminal'] && !self::updateHealthReportStatus($row, 3)) error_log('Telegram health failure projection failed for queue '.$id);
                $failed++;
                if(!empty($result['message'])){
                    $CACHE->save('telegramerrmsg', ['errmsg'=>$result['message'], 'time'=>date('Y-m-d H:i:s')], 86400);
                }
            }
        }

        $message = "Processed: success={$success}, failed={$failed}";
        if($healthError) $message .= '; health queue isolated: '.$healthError;
        return ['success'=>$success, 'failed'=>$failed, 'message'=>$message];
    }

    public static function cleanOldNotifications($days = 7)
    {
        global $DB;
        $days = max(1, intval($days));
        if(!self::reconcileHealthReports()) return false;
        $success = $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE status=1 AND addtime < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        if($success === false) return false;
        if($DB->exec("DELETE FROM pre_telegram_notify_queue WHERE status=2 AND addtime < DATE_SUB(NOW(), INTERVAL 30 DAY)") === false) return false;
        return $success;
    }

    public static function reconcileHealthReports($limit = 200)
    {
        global $DB, $conf;
        if(empty($conf['addon_health_report']) || intval($conf['addon_health_report']) < intval(\lib\Health\Installer::VERSION)) return true;
        $limit = max(1, min(1000, intval($limit)));
        $rows = $DB->getAll(
            "SELECT r.id report_id,r.telegram_queue_id,q.status queue_status,q.sendtime
             FROM pre_health_report r LEFT JOIN pre_telegram_notify_queue q ON q.id=r.telegram_queue_id
             WHERE r.telegram_status=1 ORDER BY r.updated_at ASC LIMIT {$limit}"
        );
        if($rows === false) return false;
        foreach($rows as $row){
            $queueId = intval($row['telegram_queue_id']);
            $queueStatus = $row['queue_status'] === null ? null : intval($row['queue_status']);
            if($queueStatus === 1){
                $data = ['telegram_status'=>2, 'sent_at'=>$row['sendtime']];
            }elseif($queueStatus === 2 || $queueStatus === null || $queueId <= 0){
                $data = ['telegram_status'=>3];
            }else continue;
            if($DB->update('health_report', $data, ['id'=>intval($row['report_id']), 'telegram_status'=>1]) === false) return false;
        }
        return true;
    }

    private static function sendNotification($row, $bot, $healthDelivery)
    {
        global $DB, $conf;

        $uid = intval($row['uid']);
        $scene = $row['scene'];
        $param = json_decode($row['param'], true);
        if(!is_array($param)) return ['success'=>false, 'terminal'=>true, 'message'=>'Invalid notification payload'];
        if($uid > 0){
            $bind = $DB->find('telegram_bind', '*', ['uid'=>$uid, 'status'=>1], null, 1);
            if(!$bind) return ['success'=>true, 'message'=>'Merchant not bound'];
            $field = 'notify_'.$scene;
            if(isset($bind[$field]) && empty($bind[$field])) return ['success'=>true, 'message'=>'Notification disabled'];
            $chatId = $bind['chat_id'];
        }else{
            if($healthDelivery){
                if(!isset($param['chat_id']) || !preg_match('/^-?[0-9]{5,20}$/D', (string)$param['chat_id'])){
                    return ['success'=>false, 'terminal'=>true, 'message'=>'Health report recipient is not frozen in the queue payload'];
                }
                $chatId = (string)$param['chat_id'];
            }else{
                $chatId = isset($conf['telegram_admin_chat_id']) ? trim((string)$conf['telegram_admin_chat_id']) : '';
                if(!preg_match('/^-?[0-9]{5,20}$/D', $chatId)) return ['success'=>false, 'terminal'=>true, 'message'=>'Admin chat ID not configured'];
            }
        }

        $message = NotifyHelper::getTelegramMessage($scene, $param);
        if(empty($message)) return ['success'=>false, 'terminal'=>true, 'message'=>'Unsupported notification scene'];

        if($healthDelivery){
            $id = intval($row['id']);
            $deliveryStarted = $DB->exec("UPDATE pre_telegram_notify_queue SET sendstarttime=NOW() WHERE id={$id} AND scene='daily_health' AND status=3 AND sendstarttime IS NULL");
            if($deliveryStarted !== 1) return ['success'=>false, 'terminal'=>true, 'message'=>'Unable to record delivery start'];
        }

        $send = $bot->sendMessage($chatId, $message);
        if($send === false && $healthDelivery && $bot->isDeterministicFormatError() && !empty($param['plain']) && is_string($param['plain'])){
            $plain = mb_substr(trim($param['plain']), 0, 3500, 'UTF-8');
            if($plain !== '') $send = $bot->sendMessage($chatId, $plain, ['parse_mode'=>null]);
        }
        if($send === false){
            return ['success'=>false, 'terminal'=>$healthDelivery && $bot->isDeliveryUncertain(), 'message'=>$bot->getLastError()];
        }
        return ['success'=>true, 'message'=>'Sent'];
    }

    private static function markFailed($row, $message, $forceTerminal = false)
    {
        global $DB;

        $retry = intval($row['retry_count']) + 1;
        $status = $forceTerminal || $retry >= 3 ? 2 : 0;
        $delay = min(900, 60 * (1 << max(0, $retry - 1)));
        $id = intval($row['id']);
        $error = mb_substr((string)$message, 0, 500);
        $next = $status === 0 ? date('Y-m-d H:i:s', time() + $delay) : null;
        $data = [
            'status'=>$status,
            'retry_count'=>$retry,
            'error_msg'=>$error,
            'claimtime'=>null,
            'next_attempt'=>$next,
        ];
        if($status === 0 || !$forceTerminal) $data['sendstarttime'] = null;
        $updated = $DB->update('telegram_notify_queue', $data, ['id'=>$id, 'status'=>3]);
        return ['updated'=>$updated !== false, 'terminal'=>$status === 2];
    }

    private static function markLegacyFailed($row, $message)
    {
        global $DB;
        $retry = intval($row['retry_count']) + 1;
        $status = $retry >= 3 ? 2 : 0;
        return $DB->update('telegram_notify_queue', [
            'status'=>$status,
            'retry_count'=>$retry,
            'error_msg'=>mb_substr((string)$message, 0, 500, 'UTF-8'),
        ], ['id'=>intval($row['id']), 'status'=>0]);
    }

    private static function isSupportedScene($scene)
    {
        return in_array($scene, ['regaudit', 'apply', 'domain', 'order', 'settle', 'login', 'complain', 'balance', 'group', 'daily_health'], true);
    }

    private static function prepareHealthQueue($healthInstalled)
    {
        global $DB;
        if(!$healthInstalled) return ['ok'=>true, 'enabled'=>false, 'message'=>'Health component is not installed'];

        $staleRows = $DB->getAll("SELECT * FROM pre_telegram_notify_queue WHERE scene='daily_health' AND status=3 AND claimtime<DATE_SUB(NOW(),INTERVAL 10 MINUTE) ORDER BY claimtime ASC LIMIT 100");
        if($staleRows === false) return ['ok'=>false, 'enabled'=>false, 'message'=>'Telegram queue stale-state read failed'];
        foreach($staleRows as $staleRow){
            $staleId = intval($staleRow['id']);
            if(empty($staleRow['sendstarttime'])){
                if($DB->exec("UPDATE pre_telegram_notify_queue SET status=0,error_msg='Worker interrupted before delivery started',claimtime=NULL,next_attempt=NOW() WHERE id={$staleId} AND scene='daily_health' AND status=3 AND sendstarttime IS NULL") === false){
                    return ['ok'=>false, 'enabled'=>false, 'message'=>'Telegram queue stale-state update failed'];
                }
            }else{
                $staleUpdated = $DB->exec("UPDATE pre_telegram_notify_queue SET status=2,error_msg='Delivery state unknown after worker interruption',claimtime=NULL WHERE id={$staleId} AND scene='daily_health' AND status=3 AND sendstarttime IS NOT NULL");
                if($staleUpdated === false) return ['ok'=>false, 'enabled'=>false, 'message'=>'Telegram queue stale-state update failed'];
                if($staleUpdated === 1 && !self::updateHealthReportStatus($staleRow, 3)){
                    return ['ok'=>false, 'enabled'=>false, 'message'=>'Telegram stale delivery projection failed'];
                }
            }
        }
        if(!self::reconcileHealthReports()) return ['ok'=>false, 'enabled'=>false, 'message'=>'Telegram report projection reconciliation failed'];
        return ['ok'=>true, 'enabled'=>true, 'message'=>'Health queue prepared'];
    }

    private static function updateHealthReportStatus($row, $status)
    {
        global $DB;
        if($row['scene'] !== 'daily_health') return true;
        $param = json_decode($row['param'], true);
        $reportId = is_array($param) && isset($param['report_id']) ? intval($param['report_id']) : 0;
        if($reportId <= 0) return false;
        $data = ['telegram_status'=>$status];
        if($status === 2) $data['sent_at'] = 'NOW()';
        if($status === 2){
            $updated = $DB->update('health_report', $data, ['id'=>$reportId, 'telegram_queue_id'=>intval($row['id']), 'telegram_status'=>1]);
            if($updated === false){ error_log('Health report delivery projection failed: '.$DB->error()); return false; }
        }else{
            $current = $DB->find('health_report', 'telegram_status,telegram_queue_id', ['id'=>$reportId], null, 1);
            if(!$current){ error_log('Health report delivery projection row is missing: '.$reportId); return false; }
            if(intval($current['telegram_status']) !== 2 && intval($current['telegram_queue_id']) === intval($row['id'])){
                $updated = $DB->update('health_report', $data, ['id'=>$reportId]);
                if($updated === false){ error_log('Health report delivery projection failed: '.$DB->error()); return false; }
            }
        }
        return true;
    }
}
