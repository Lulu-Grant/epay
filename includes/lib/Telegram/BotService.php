<?php
namespace lib\Telegram;

class BotService
{
    private $DB;
    private $config;
    private $botAPI;

    public function __construct($DB, $config = [])
    {
        $this->DB = $DB;
        $this->config = $config;
        $this->botAPI = new BotAPI(isset($config['telegram_bot_token']) ? $config['telegram_bot_token'] : '');
    }

    public function getBotAPI()
    {
        return $this->botAPI;
    }

    public function isAdmin($chatId, $fromId = null)
    {
        $adminChatId = isset($this->config['telegram_admin_chat_id']) ? trim((string)$this->config['telegram_admin_chat_id']) : '';
        if($adminChatId === '') return false;
        if($fromId !== null && $fromId !== ''){
            return (string)$chatId === $adminChatId && (string)$fromId === $adminChatId;
        }
        return (string)$chatId === $adminChatId;
    }

    public function getBoundMerchant($chatId)
    {
        return $this->DB->find('telegram_bind', '*', ['chat_id'=>(string)$chatId, 'status'=>1], null, 1);
    }

    public function getMerchantInfo($chatId)
    {
        $bind = $this->getBoundMerchant($chatId);
        if(!$bind) return null;

        $user = $this->DB->find('user', '*', ['uid'=>intval($bind['uid'])], null, 1);
        if(!$user) return null;

        return [
            'uid' => intval($user['uid']),
            'account' => isset($user['account']) ? $user['account'] : '',
            'username' => isset($user['username']) && $user['username'] !== '' ? $user['username'] : (isset($user['account']) ? $user['account'] : ''),
            'email' => isset($user['email']) ? $user['email'] : '',
            'phone' => isset($user['phone']) ? $user['phone'] : '',
            'money' => isset($user['money']) ? $user['money'] : '0.00',
            'status' => isset($user['status']) ? intval($user['status']) : 1,
            'pay' => isset($user['pay']) ? intval($user['pay']) : 1,
            'settle' => isset($user['settle']) ? intval($user['settle']) : 1,
            'bindtime' => $bind['bindtime'],
            'notify_order' => intval($bind['notify_order']),
            'notify_settle' => isset($bind['notify_settle']) ? intval($bind['notify_settle']) : 1,
            'notify_login' => isset($bind['notify_login']) ? intval($bind['notify_login']) : 1,
            'notify_complain' => isset($bind['notify_complain']) ? intval($bind['notify_complain']) : 1,
            'notify_mchrisk' => isset($bind['notify_mchrisk']) ? intval($bind['notify_mchrisk']) : 1,
            'notify_balance' => isset($bind['notify_balance']) ? intval($bind['notify_balance']) : 1,
        ];
    }

    public function createBindCode($uid, $ttl = 300)
    {
        $uid = intval($uid);
        $ttl = max(60, min(3600, intval($ttl)));
        $user = $this->DB->find('user', 'uid,status', ['uid'=>$uid], null, 1);
        if(!$user) return ['success'=>false, 'message'=>'商户不存在'];
        if(isset($user['status']) && intval($user['status']) === 0) return ['success'=>false, 'message'=>'商户已被禁用'];

        $this->expireBindCodes();
        $this->DB->exec("UPDATE pre_telegram_bind_code SET status=2 WHERE uid=:uid AND status=0", [':uid'=>$uid]);

        $code = $this->generateBindCode();
        for($i=0; $i<5; $i++){
            $exists = $this->DB->find('telegram_bind_code', 'id', ['code'=>$code], null, 1);
            if(!$exists) break;
            $code = $this->generateBindCode();
        }

        $expiretime = date('Y-m-d H:i:s', time() + $ttl);
        $result = $this->DB->insert('telegram_bind_code', [
            'uid' => $uid,
            'code' => $code,
            'status' => 0,
            'addtime' => 'NOW()',
            'expiretime' => $expiretime,
        ]);

        if($result === false) return ['success'=>false, 'message'=>'生成绑定码失败'];
        return ['success'=>true, 'code'=>$code, 'expiretime'=>$expiretime, 'ttl'=>$ttl];
    }

    public function getActiveBindCode($uid)
    {
        $this->expireBindCodes();
        return $this->DB->getRow(
            "SELECT * FROM pre_telegram_bind_code WHERE uid=:uid AND status=0 AND expiretime>=NOW() ORDER BY id DESC LIMIT 1",
            [':uid'=>intval($uid)]
        );
    }

    public function bindMerchantByCode($chatId, $code)
    {
        $this->expireBindCodes();
        $code = strtoupper(trim((string)$code));
        if($code === '') return ['success'=>false, 'message'=>'绑定码不能为空'];

        $bindCode = $this->DB->getRow(
            "SELECT * FROM pre_telegram_bind_code WHERE code=:code AND status=0 AND expiretime>=NOW() LIMIT 1",
            [':code'=>$code]
        );
        if(!$bindCode) return ['success'=>false, 'message'=>'绑定码不存在、已使用或已过期'];

        $uid = intval($bindCode['uid']);
        $user = $this->DB->find('user', '*', ['uid'=>$uid], null, 1);
        if(!$user) return ['success'=>false, 'message'=>'商户不存在'];
        if(isset($user['status']) && intval($user['status']) === 0) return ['success'=>false, 'message'=>'商户已被禁用'];

        $chatId = (string)$chatId;
        $data = [
            'uid' => $uid,
            'status' => 1,
            'bindtime' => 'NOW()',
            'notify_order' => 1,
            'notify_settle' => 1,
            'notify_login' => 1,
            'notify_complain' => 1,
            'notify_mchrisk' => 1,
            'notify_balance' => 1,
        ];

        $this->DB->beginTransaction();
        try{
            $this->DB->update('telegram_bind', ['status'=>0], ['uid'=>$uid]);
            $exist = $this->DB->find('telegram_bind', '*', ['chat_id'=>$chatId], null, 1);
            if($exist){
                $ok = $this->DB->update('telegram_bind', $data, ['chat_id'=>$chatId]);
            }else{
                $data['chat_id'] = $chatId;
                $ok = $this->DB->insert('telegram_bind', $data);
            }
            if($ok === false) throw new \Exception('绑定写入失败');

            $ok = $this->DB->update('telegram_bind_code', ['status'=>1, 'chat_id'=>$chatId, 'usetime'=>'NOW()'], ['id'=>intval($bindCode['id'])]);
            if($ok === false) throw new \Exception('绑定码状态更新失败');

            $this->DB->commit();
        }catch(\Exception $e){
            $this->DB->rollBack();
            return ['success'=>false, 'message'=>$e->getMessage()];
        }

        return ['success'=>true, 'message'=>'绑定成功', 'user'=>$user];
    }

    public function unbindMerchant($chatId)
    {
        $bind = $this->getBoundMerchant($chatId);
        if(!$bind) return ['success'=>false, 'message'=>'您还未绑定商户'];

        $result = $this->DB->update('telegram_bind', ['status'=>0], ['chat_id'=>(string)$chatId]);
        if($result === false) return ['success'=>false, 'message'=>'解绑失败'];
        return ['success'=>true, 'message'=>'解绑成功'];
    }

    public function updateNotifySetting($chatId, $type)
    {
        $allowed = ['order', 'settle', 'login', 'complain', 'mchrisk', 'balance'];
        if(!in_array($type, $allowed, true)) return ['success'=>false, 'message'=>'设置项不支持'];

        $bind = $this->getBoundMerchant($chatId);
        if(!$bind) return ['success'=>false, 'message'=>'您还未绑定商户'];

        $field = 'notify_'.$type;
        $newValue = empty($bind[$field]) ? 1 : 0;
        $result = $this->DB->update('telegram_bind', [$field=>$newValue], ['chat_id'=>(string)$chatId]);
        if($result === false) return ['success'=>false, 'message'=>'设置更新失败'];
        return ['success'=>true, 'message'=>'设置已更新', 'value'=>$newValue];
    }

    public function getAdminSettings($chatId)
    {
        $row = $this->DB->find('telegram_admin_settings', '*', ['chat_id'=>(string)$chatId], null, 1);
        if($row) return $row;
        return [
            'notify_order'=>1,
            'notify_settle'=>1,
            'notify_login'=>1,
            'notify_complain'=>1,
            'notify_mchrisk'=>1,
            'notify_balance'=>1,
        ];
    }

    public function updateAdminSetting($chatId, $type)
    {
        $allowed = ['order', 'settle', 'login', 'complain', 'mchrisk', 'balance'];
        if(!in_array($type, $allowed, true)) return ['success'=>false, 'message'=>'设置项不支持'];

        $chatId = (string)$chatId;
        $settings = $this->getAdminSettings($chatId);
        $field = 'notify_'.$type;
        $newValue = empty($settings[$field]) ? 1 : 0;
        $exist = $this->DB->find('telegram_admin_settings', 'id', ['chat_id'=>$chatId], null, 1);
        if($exist){
            $result = $this->DB->update('telegram_admin_settings', [$field=>$newValue, 'updatetime'=>'NOW()'], ['chat_id'=>$chatId]);
        }else{
            $data = [
                'chat_id' => $chatId,
                'notify_order' => 1,
                'notify_settle' => 1,
                'notify_login' => 1,
                'notify_complain' => 1,
                'notify_mchrisk' => 1,
                'notify_balance' => 1,
                'updatetime' => 'NOW()',
            ];
            $data[$field] = $newValue;
            $result = $this->DB->insert('telegram_admin_settings', $data);
        }
        if($result === false) return ['success'=>false, 'message'=>'设置更新失败'];
        return ['success'=>true, 'message'=>'设置已更新', 'value'=>$newValue];
    }

    public function getMerchantOrderStats($uid, $dateStart, $dateEnd)
    {
        $row = $this->DB->getRow(
            "SELECT COUNT(*) total_orders,
                    COUNT(IF(status=1, 1, NULL)) success_orders,
                    ROUND(COALESCE(SUM(money),0),2) total_money,
                    ROUND(COALESCE(SUM(IF(status=1, realmoney, 0)),0),2) success_money
             FROM pre_order
             WHERE uid=:uid AND date>=:date_start AND date<=:date_end",
            [':uid'=>intval($uid), ':date_start'=>$dateStart, ':date_end'=>$dateEnd]
        );
        return $this->normalizeStats($row, false);
    }

    public function getAdminOrderStats($dateStart, $dateEnd)
    {
        $row = $this->DB->getRow(
            "SELECT COUNT(*) total_orders,
                    COUNT(IF(status=1, 1, NULL)) success_orders,
                    ROUND(COALESCE(SUM(money),0),2) total_money,
                    ROUND(COALESCE(SUM(IF(status=1, realmoney, 0)),0),2) success_money,
                    ROUND(COALESCE(SUM(IF(status=1, profitmoney, 0)),0),2) profit_money
             FROM pre_order
             WHERE date>=:date_start AND date<=:date_end",
            [':date_start'=>$dateStart, ':date_end'=>$dateEnd]
        );
        return $this->normalizeStats($row, true);
    }

    public function getChannelStats($dateStart, $dateEnd, $limit = 12)
    {
        $limit = max(1, min(50, intval($limit)));
        $rows = $this->DB->getAll(
            "SELECT c.id,c.name,
                    COUNT(o.trade_no) total_orders,
                    COUNT(IF(o.status=1, 1, NULL)) success_orders,
                    ROUND(COALESCE(SUM(IF(o.status=1, o.realmoney, 0)),0),2) success_money
             FROM pre_channel c
             LEFT JOIN pre_order o ON c.id=o.channel AND o.date>=:date_start AND o.date<=:date_end
             WHERE c.status=1
             GROUP BY c.id,c.name
             ORDER BY success_money DESC,total_orders DESC
             LIMIT {$limit}",
            [':date_start'=>$dateStart, ':date_end'=>$dateEnd]
        );
        return $rows ?: [];
    }

    public function getTypeStats($dateStart, $dateEnd, $limit = 12)
    {
        $limit = max(1, min(50, intval($limit)));
        $rows = $this->DB->getAll(
            "SELECT t.id,t.showname,
                    COUNT(o.trade_no) total_orders,
                    COUNT(IF(o.status=1, 1, NULL)) success_orders,
                    ROUND(COALESCE(SUM(IF(o.status=1, o.realmoney, 0)),0),2) success_money
             FROM pre_type t
             LEFT JOIN pre_order o ON t.id=o.type AND o.date>=:date_start AND o.date<=:date_end
             WHERE t.status=1
             GROUP BY t.id,t.showname
             ORDER BY success_money DESC,total_orders DESC
             LIMIT {$limit}",
            [':date_start'=>$dateStart, ':date_end'=>$dateEnd]
        );
        return $rows ?: [];
    }

    public function getOrderInfo($tradeNo, $uid = null)
    {
        $tradeNo = trim((string)$tradeNo);
        if($tradeNo === '') return null;

        $params = [':keyword'=>$tradeNo];
        $where = "(o.trade_no=:keyword OR o.out_trade_no=:keyword OR o.api_trade_no=:keyword)";
        if($uid !== null){
            $where .= " AND o.uid=:uid";
            $params[':uid'] = intval($uid);
        }
        $row = $this->DB->getRow(
            "SELECT o.*,t.showname type_name,c.name channel_name
             FROM pre_order o
             LEFT JOIN pre_type t ON o.type=t.id
             LEFT JOIN pre_channel c ON o.channel=c.id
             WHERE {$where}
             ORDER BY o.addtime DESC
             LIMIT 1",
            $params
        );
        return $row ?: null;
    }

    public function searchOrders($keyword, $uid = null, $limit = 5)
    {
        $keyword = trim((string)$keyword);
        if($keyword === '') return [];
        $limit = max(1, min(10, intval($limit)));
        $params = [':keyword'=>'%'.$keyword.'%'];
        $where = "(o.trade_no LIKE :keyword OR o.out_trade_no LIKE :keyword OR o.name LIKE :keyword)";
        if($uid !== null){
            $where .= " AND o.uid=:uid";
            $params[':uid'] = intval($uid);
        }
        $rows = $this->DB->getAll(
            "SELECT o.trade_no,o.out_trade_no,o.uid,o.name,o.money,o.realmoney,o.status,o.addtime,o.endtime
             FROM pre_order o
             WHERE {$where}
             ORDER BY o.addtime DESC
             LIMIT {$limit}",
            $params
        );
        return $rows ?: [];
    }

    public function getQueueStats()
    {
        $stats = [0=>0, 1=>0, 2=>0];
        $rows = $this->DB->getAll("SELECT status,COUNT(*) total FROM pre_telegram_notify_queue GROUP BY status");
        if($rows){
            foreach($rows as $row){
                $stats[intval($row['status'])] = intval($row['total']);
            }
        }
        $lastError = $this->DB->getRow("SELECT id,scene,error_msg,addtime FROM pre_telegram_notify_queue WHERE status=2 ORDER BY id DESC LIMIT 1");
        return ['pending'=>$stats[0], 'sent'=>$stats[1], 'failed'=>$stats[2], 'last_error'=>$lastError ?: null];
    }

    public function setDefaultCommands()
    {
        $commands = [
            ['command'=>'start', 'description'=>'显示菜单'],
            ['command'=>'help', 'description'=>'查看帮助'],
            ['command'=>'bind', 'description'=>'绑定商户'],
            ['command'=>'unbind', 'description'=>'解绑商户'],
            ['command'=>'info', 'description'=>'商户信息'],
            ['command'=>'today', 'description'=>'今日统计'],
            ['command'=>'yesterday', 'description'=>'昨日统计'],
            ['command'=>'week', 'description'=>'近7日统计'],
            ['command'=>'month', 'description'=>'本月统计'],
            ['command'=>'order', 'description'=>'查询订单'],
            ['command'=>'settings', 'description'=>'通知设置'],
        ];
        return $this->botAPI->setMyCommands($commands);
    }

    public function expireBindCodes()
    {
        return $this->DB->exec("UPDATE pre_telegram_bind_code SET status=2 WHERE status=0 AND expiretime<NOW()");
    }

    public function getLastUpdateId()
    {
        return intval($this->DB->getColumn("SELECT COALESCE(MAX(update_id),0) FROM pre_telegram_update"));
    }

    private function normalizeStats($row, $withProfit)
    {
        if(!$row) $row = [];
        $stats = [
            'total_orders' => intval(isset($row['total_orders']) ? $row['total_orders'] : 0),
            'success_orders' => intval(isset($row['success_orders']) ? $row['success_orders'] : 0),
            'total_money' => round(floatval(isset($row['total_money']) ? $row['total_money'] : 0), 2),
            'success_money' => round(floatval(isset($row['success_money']) ? $row['success_money'] : 0), 2),
        ];
        $stats['success_rate'] = $stats['total_orders'] > 0 ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) : 0;
        if($withProfit){
            $stats['profit_money'] = round(floatval(isset($row['profit_money']) ? $row['profit_money'] : 0), 2);
        }
        return $stats;
    }

    private function generateBindCode()
    {
        if(function_exists('random_bytes')){
            return strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        }
        return strtoupper(substr(md5(uniqid('', true).mt_rand()), 0, 10));
    }
}
