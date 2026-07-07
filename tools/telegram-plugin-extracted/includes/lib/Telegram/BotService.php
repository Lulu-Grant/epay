<?php
namespace lib\Telegram;

class BotService
{
    private $DB;
    private $botAPI;
    private $config;

    public function __construct($DB, $config = [])
    {
        $this->DB = $DB;
        $this->config = $config;
        
        $token = $config['telegram_bot_token'];
        $this->botAPI = new BotAPI($token);
    }

    public function getBotAPI()
    {
        return $this->botAPI;
    }

    public function isAdmin($chatId)
    {
        $adminChatId = $this->config['telegram_admin_chat_id'];
        return $adminChatId && $chatId == $adminChatId;
    }

    public function isMerchantBound($chatId)
    {
        return $this->DB->find('telegram_bind', '*', ['chat_id' => $chatId]);
    }

    public function getBoundMerchant($chatId)
    {
        return $this->DB->find('telegram_bind', '*', ['chat_id' => $chatId, 'status' => 1]);
    }

    public function bindMerchant($chatId, $uid, $key)
    {
        $user = $this->DB->find('user', '*', ['uid' => $uid]);
        if (!$user) {
            return ['success' => false, 'message' => '商户不存在'];
        }
        
        if ($user['key'] !== $key) {
            return ['success' => false, 'message' => '商户密钥错误'];
        }

        $existBind = $this->DB->find('telegram_bind', '*', ['uid' => $uid, 'status' => 1]);
        if ($existBind && $existBind['chat_id'] != $chatId) {
            return ['success' => false, 'message' => '该商户已被其他账号绑定，一个商户号只能绑定一个Telegram账号'];
        }

        $exist = $this->DB->find('telegram_bind', '*', ['chat_id' => $chatId]);
        if ($exist) {
            $this->DB->update('telegram_bind', [
                'uid' => $uid,
                'status' => 1,
                'bindtime' => date('Y-m-d H:i:s')
            ], ['chat_id' => $chatId]);
        } else {
            $this->DB->insert('telegram_bind', [
                'chat_id' => $chatId,
                'uid' => $uid,
                'status' => 1,
                'bindtime' => date('Y-m-d H:i:s'),
                'notify_order' => 1,
                'notify_settle' => 1,
                'notify_login' => 1,
                'notify_complain' => 1,
                'notify_mchrisk' => 1,
                'notify_balance' => 1
            ]);
        }

        return ['success' => true, 'message' => '绑定成功', 'user' => $user];
    }

    public function unbindMerchant($chatId)
    {
        $bind = $this->getBoundMerchant($chatId);
        if (!$bind) {
            return ['success' => false, 'message' => '您还未绑定商户'];
        }

        $this->DB->update('telegram_bind', ['status' => 0], ['chat_id' => $chatId]);
        return ['success' => true, 'message' => '解绑成功'];
    }

    public function updateNotifySettings($chatId, $orderNotify = null, $settleNotify = null, $loginNotify = null, $complainNotify = null, $mchriskNotify = null, $balanceNotify = null)
    {
        $bind = $this->getBoundMerchant($chatId);
        if (!$bind) {
            return ['success' => false, 'message' => '您还未绑定商户'];
        }

        $data = [];
        if ($orderNotify !== null) {
            $data['notify_order'] = $orderNotify ? 1 : 0;
        }
        if ($settleNotify !== null) {
            $data['notify_settle'] = $settleNotify ? 1 : 0;
        }
        if ($loginNotify !== null) {
            $data['notify_login'] = $loginNotify ? 1 : 0;
        }
        if ($complainNotify !== null) {
            $data['notify_complain'] = $complainNotify ? 1 : 0;
        }
        if ($mchriskNotify !== null) {
            $data['notify_mchrisk'] = $mchriskNotify ? 1 : 0;
        }
        if ($balanceNotify !== null) {
            $data['notify_balance'] = $balanceNotify ? 1 : 0;
        }

        if ($data) {
            $this->DB->update('telegram_bind', $data, ['chat_id' => $chatId]);
        }

        return ['success' => true, 'message' => '设置已更新'];
    }

    public function getAdminSettings($chatId)
    {
        $settings = $this->DB->find('telegram_admin_settings', '*', ['chat_id' => $chatId]);
        if (!$settings) {
            return [
                'notify_order' => 1,
                'notify_settle' => 1,
                'notify_login' => 1,
                'notify_complain' => 1,
                'notify_mchrisk' => 1,
                'notify_balance' => 1
            ];
        }
        return $settings;
    }

    public function updateAdminSettings($chatId, $orderNotify = null, $settleNotify = null, $loginNotify = null, $complainNotify = null, $mchriskNotify = null, $balanceNotify = null)
    {
        $exist = $this->DB->find('telegram_admin_settings', '*', ['chat_id' => $chatId]);
        
        $data = [
            'updatetime' => date('Y-m-d H:i:s')
        ];
        if ($orderNotify !== null) {
            $data['notify_order'] = $orderNotify ? 1 : 0;
        }
        if ($settleNotify !== null) {
            $data['notify_settle'] = $settleNotify ? 1 : 0;
        }
        if ($loginNotify !== null) {
            $data['notify_login'] = $loginNotify ? 1 : 0;
        }
        if ($complainNotify !== null) {
            $data['notify_complain'] = $complainNotify ? 1 : 0;
        }
        if ($mchriskNotify !== null) {
            $data['notify_mchrisk'] = $mchriskNotify ? 1 : 0;
        }
        if ($balanceNotify !== null) {
            $data['notify_balance'] = $balanceNotify ? 1 : 0;
        }

        if ($exist) {
            $this->DB->update('telegram_admin_settings', $data, ['chat_id' => $chatId]);
        } else {
            $data['chat_id'] = $chatId;
            $data['notify_order'] = $orderNotify ?? 1;
            $data['notify_settle'] = $settleNotify ?? 1;
            $data['notify_login'] = $loginNotify ?? 1;
            $data['notify_complain'] = $complainNotify ?? 1;
            $data['notify_mchrisk'] = $mchriskNotify ?? 1;
            $data['notify_balance'] = $balanceNotify ?? 1;
            $this->DB->insert('telegram_admin_settings', $data);
        }

        return ['success' => true, 'message' => '设置已更新'];
    }

    public function getMerchantInfo($chatId)
    {
        $bind = $this->getBoundMerchant($chatId);
        if (!$bind) {
            return null;
        }

        $user = $this->DB->find('user', '*', ['uid' => $bind['uid']]);
        if (!$user) {
            return null;
        }

        return [
            'uid' => $user['uid'],
            'username' => $user['username'] ?: $user['account'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'money' => $user['money'],
            'bindtime' => $bind['bindtime'],
            'notify_order' => $bind['notify_order'],
            'notify_settle' => $bind['notify_settle'] ?? 1,
            'notify_login' => $bind['notify_login'] ?? 1,
            'notify_complain' => $bind['notify_complain'] ?? 1,
            'notify_mchrisk' => $bind['notify_mchrisk'] ?? 1,
            'notify_balance' => $bind['notify_balance'] ?? 1
        ];
    }

    public function getMerchantOrderStats($uid, $dateStart, $dateEnd)
    {
        $stats = $this->DB->getRow(
            "SELECT COUNT(*) as total_orders, 
                    COUNT(IF(status=1, 1, NULL)) as success_orders,
                    SUM(money) as total_money,
                    SUM(IF(status=1, realmoney, 0)) as success_money
             FROM pre_order 
             WHERE uid=:uid AND date>=:date_start AND date<=:date_end",
            [':uid' => $uid, ':date_start' => $dateStart, ':date_end' => $dateEnd]
        );

        return $stats ?: ['total_orders'=>0, 'success_orders'=>0, 'total_money'=>0, 'success_money'=>0];
    }

    public function getAdminOrderStats($dateStart, $dateEnd)
    {
        $stats = $this->DB->getRow(
            "SELECT COUNT(*) as total_orders, 
                    COUNT(IF(status=1, 1, NULL)) as success_orders,
                    SUM(money) as total_money,
                    SUM(IF(status=1, realmoney, 0)) as success_money,
                    SUM(IF(status=1, profitmoney, 0)) as profit_money
             FROM pre_order 
             WHERE date>=:date_start AND date<=:date_end",
            [':date_start' => $dateStart, ':date_end' => $dateEnd]
        );

        return $stats ?: ['total_orders'=>0, 'success_orders'=>0, 'total_money'=>0, 'success_money'=>0, 'profit_money'=>0];
    }

    public function getChannelStats($dateStart, $dateEnd)
    {
        $channels = $this->DB->getAll(
            "SELECT c.id, c.name, 
                    COUNT(o.trade_no) as total_orders,
                    COUNT(IF(o.status=1, 1, NULL)) as success_orders,
                    SUM(o.money) as total_money,
                    SUM(IF(o.status=1, o.realmoney, 0)) as success_money
             FROM pre_channel c
             LEFT JOIN pre_order o ON c.id = o.channel AND o.date>=:date_start AND o.date<=:date_end
             WHERE c.status=1
             GROUP BY c.id
             ORDER BY success_money DESC",
            [':date_start' => $dateStart, ':date_end' => $dateEnd]
        );

        $result = [];
        if($channels){
            foreach ($channels as $channel) {
                $successRate = $channel['total_orders'] > 0 
                    ? round($channel['success_orders'] / $channel['total_orders'] * 100, 2) 
                    : 0;
                $result[] = [
                    'name' => $channel['name'],
                    'money' => round($channel['success_money'], 2),
                    'success_rate' => $successRate
                ];
            }
        }

        return $result;
    }

    public function getOrderInfo($tradeNo, $uid = null)
    {
        $where = ['trade_no' => $tradeNo];
        if ($uid !== null) {
            $where['uid'] = $uid;
        }
        
        $order = $this->DB->find('order', '*', $where);
        if (!$order) {
            return null;
        }

        $type = $this->DB->find('type', 'showname', ['id' => $order['type']]);
        $channel = $this->DB->find('channel', 'name', ['id' => $order['channel']]);

        $statusText = ['未支付', '已支付', '已退款', '已冻结', '预授权'];
        
        return [
            'trade_no' => $order['trade_no'],
            'out_trade_no' => $order['out_trade_no'],
            'type' => $type ? $type['showname'] : '未知',
            'channel' => $channel ? $channel['name'] : '未知',
            'name' => $order['name'],
            'money' => $order['money'],
            'realmoney' => $order['realmoney'],
            'status' => $order['status'],
            'status_text' => $statusText[$order['status']] ?? '未知',
            'addtime' => $order['addtime'],
            'endtime' => $order['endtime'],
            'buyer' => $order['buyer'],
            'ip' => $order['ip'],
            'domain' => $order['domain']
        ];
    }

    public function searchOrders($uid, $keyword, $limit = 10)
    {
        $where = "uid=:uid AND (trade_no LIKE :keyword OR out_trade_no LIKE :keyword OR name LIKE :keyword)";
        $orders = $this->DB->findAll('order', '*', 
            [$where, ':uid' => $uid, ':keyword' => "%{$keyword}%"], 
            'addtime DESC', $limit);

        $result = [];
        $statusText = ['未支付', '已支付', '已退款', '已冻结', '预授权'];
        
        foreach ($orders as $order) {
            $result[] = [
                'trade_no' => $order['trade_no'],
                'out_trade_no' => $order['out_trade_no'],
                'name' => $order['name'],
                'money' => $order['money'],
                'status_text' => $statusText[$order['status']] ?? '未知',
                'addtime' => $order['addtime']
            ];
        }

        return $result;
    }

    public function sendOrderNotification($uid, $orderData, $type = 'success')
    {
        $bind = $this->DB->find('telegram_bind', '*', ['uid' => $uid, 'status' => 1]);
        if (!$bind) {
            return false;
        }

        if ($type === 'success' && !$bind['notify_order']) {
            return false;
        }

        $message = "✅ <b>支付成功</b>\n\n";
        $message .= "订单号：{$orderData['trade_no']}\n";
        $message .= "商户订单：{$orderData['out_trade_no']}\n";
        $message .= "商品名称：{$orderData['name']}\n";
        $message .= "订单金额：¥{$orderData['money']}\n";
        
        if ($type === 'success') {
            $message .= "实付金额：¥{$orderData['realmoney']}\n";
        }
        
        $message .= "支付时间：{$orderData['endtime']}\n";

        return $this->botAPI->sendMessage($bind['chat_id'], $message);
    }

    public function sendAdminNotification($message)
    {
        $adminChatId = $this->config['telegram_admin_chat_id'];
        if (!$adminChatId) {
            return false;
        }

        return $this->botAPI->sendMessage($adminChatId, $message);
    }
}
