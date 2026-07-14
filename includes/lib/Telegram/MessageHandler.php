<?php
namespace lib\Telegram;

class MessageHandler
{
    private $botService;
    private $botAPI;
    private $currentFromId;

    public function __construct($botService)
    {
        $this->botService = $botService;
        $this->botAPI = $botService->getBotAPI();
    }

    public function handleUpdate($update)
    {
        $this->currentFromId = null;
        if(isset($update['message'])){
            return $this->handleMessage($update['message']);
        }
        if(isset($update['callback_query'])){
            return $this->handleCallback($update['callback_query']);
        }
        return false;
    }

    private function handleMessage($message)
    {
        $chatId = isset($message['chat']['id']) ? $message['chat']['id'] : null;
        $this->currentFromId = isset($message['from']['id']) ? $message['from']['id'] : null;
        $text = trim(isset($message['text']) ? $message['text'] : '');
        if($chatId === null || $text === '') return false;

        if(substr($text, 0, 1) === '/'){
            return $this->handleCommand($chatId, $text);
        }

        $session = $this->getSession($chatId);
        if($session && isset($session['action'])){
            if($session['action'] === 'order_search'){
                $this->clearSession($chatId);
                return $this->showOrder($chatId, $text, false);
            }
            if($session['action'] === 'admin_order_search'){
                $this->clearSession($chatId);
                return $this->showOrder($chatId, $text, true);
            }
            if($session['action'] === 'bind_code'){
                $this->clearSession($chatId);
                return $this->bindByCode($chatId, $text);
            }
        }

        switch($text){
            case '商户信息':
                return $this->showMerchantInfo($chatId);
            case '今日统计':
                return $this->showStats($chatId, 'today', false);
            case '昨日统计':
                return $this->showStats($chatId, 'yesterday', false);
            case '近7日统计':
                return $this->showStats($chatId, 'week', false);
            case '本月统计':
                return $this->showStats($chatId, 'month', false);
            case '订单查询':
                return $this->promptOrderSearch($chatId, false);
            case '通知设置':
                return $this->showSettings($chatId, false);
            case '解绑商户':
                return $this->confirmUnbind($chatId);
            case '绑定商户':
                $this->setSession($chatId, ['action'=>'bind_code']);
                return $this->send($chatId, "请输入商户后台生成的一次性绑定码。\n\n格式：<code>/bind 绑定码</code>");
            case '管理员菜单':
                return $this->showAdminMenu($chatId);
            case '全站今日':
                return $this->showStats($chatId, 'today', true);
            case '全站昨日':
                return $this->showStats($chatId, 'yesterday', true);
            case '全站近7日':
                return $this->showStats($chatId, 'week', true);
            case '全站本月':
                return $this->showStats($chatId, 'month', true);
            case '通道统计':
                return $this->showChannelStats($chatId);
            case '查询订单':
                return $this->promptOrderSearch($chatId, true);
            case '队列状态':
                return $this->showQueueStats($chatId);
            case '返回主菜单':
                return $this->showMainMenu($chatId);
            default:
                return $this->showMainMenu($chatId);
        }
    }

    private function handleCommand($chatId, $text)
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        $command = strtolower($parts[0]);
        if(strpos($command, '@') !== false){
            $command = substr($command, 0, strpos($command, '@'));
        }
        $args = isset($parts[1]) ? trim($parts[1]) : '';

        switch($command){
            case '/start':
                return $this->showMainMenu($chatId);
            case '/help':
                return $this->showHelp($chatId);
            case '/bind':
                if($args === ''){
                    $this->setSession($chatId, ['action'=>'bind_code']);
                    return $this->send($chatId, "请输入商户后台生成的一次性绑定码。\n\n格式：<code>/bind 绑定码</code>");
                }
                return $this->bindByCode($chatId, $args);
            case '/unbind':
                return $this->confirmUnbind($chatId);
            case '/info':
                return $this->showMerchantInfo($chatId);
            case '/today':
                return $this->showStats($chatId, 'today', false);
            case '/yesterday':
                return $this->showStats($chatId, 'yesterday', false);
            case '/week':
                return $this->showStats($chatId, 'week', false);
            case '/month':
                return $this->showStats($chatId, 'month', false);
            case '/order':
                if($args === '') return $this->promptOrderSearch($chatId, false);
                return $this->showOrder($chatId, $args, false);
            case '/settings':
                return $this->showSettings($chatId, false);
            case '/admin':
                return $this->showAdminMenu($chatId);
            case '/admin_today':
                return $this->showStats($chatId, 'today', true);
            case '/admin_yesterday':
                return $this->showStats($chatId, 'yesterday', true);
            case '/admin_week':
                return $this->showStats($chatId, 'week', true);
            case '/admin_month':
                return $this->showStats($chatId, 'month', true);
            case '/channel':
                return $this->showChannelStats($chatId);
            case '/admin_order':
                if($args === '') return $this->promptOrderSearch($chatId, true);
                return $this->showOrder($chatId, $args, true);
            case '/queue':
                return $this->showQueueStats($chatId);
            default:
                return $this->send($chatId, '未知命令，请使用 /help 查看帮助。');
        }
    }

    private function handleCallback($callback)
    {
        $chatId = isset($callback['message']['chat']['id']) ? $callback['message']['chat']['id'] : null;
        $this->currentFromId = isset($callback['from']['id']) ? $callback['from']['id'] : null;
        $data = isset($callback['data']) ? $callback['data'] : '';
        if($chatId === null || $data === '') return false;
        if(isset($callback['id'])) $this->botAPI->answerCallbackQuery($callback['id']);

        switch($data){
            case 'menu':
                return $this->showMainMenu($chatId);
            case 'admin':
                return $this->showAdminMenu($chatId);
            case 'bind':
                $this->setSession($chatId, ['action'=>'bind_code']);
                return $this->send($chatId, "请输入商户后台生成的一次性绑定码。\n\n格式：<code>/bind 绑定码</code>");
            case 'confirm_unbind':
                return $this->processUnbind($chatId);
            case 'cancel':
                $this->clearSession($chatId);
                return $this->showMainMenu($chatId);
            case 'info':
                return $this->showMerchantInfo($chatId);
            case 'today':
                return $this->showStats($chatId, 'today', false);
            case 'yesterday':
                return $this->showStats($chatId, 'yesterday', false);
            case 'week':
                return $this->showStats($chatId, 'week', false);
            case 'month':
                return $this->showStats($chatId, 'month', false);
            case 'order':
                return $this->promptOrderSearch($chatId, false);
            case 'settings':
                return $this->showSettings($chatId, false);
            case 'admin_today':
                return $this->showStats($chatId, 'today', true);
            case 'admin_yesterday':
                return $this->showStats($chatId, 'yesterday', true);
            case 'admin_week':
                return $this->showStats($chatId, 'week', true);
            case 'admin_month':
                return $this->showStats($chatId, 'month', true);
            case 'admin_order':
                return $this->promptOrderSearch($chatId, true);
            case 'channel':
                return $this->showChannelStats($chatId);
            case 'queue':
                return $this->showQueueStats($chatId);
            case 'admin_settings':
                return $this->showSettings($chatId, true);
        }

        if(strpos($data, 'toggle_') === 0){
            return $this->toggleSetting($chatId, substr($data, 7), false);
        }
        if(strpos($data, 'admin_toggle_') === 0){
            return $this->toggleSetting($chatId, substr($data, 13), true);
        }

        return false;
    }

    public function showMainMenu($chatId)
    {
        $isAdmin = $this->isCurrentAdmin($chatId);
        $merchant = $this->botService->getMerchantInfo($chatId);

        $text = "<b>支付通知机器人</b>\n\n";
        if($merchant){
            $text .= "当前绑定商户：".$this->e($merchant['username'])."\n";
            $text .= "商户号：".$merchant['uid']."\n";
            $text .= "余额：".$this->money($merchant['money'])."\n\n";
        }else{
            $text .= "当前未绑定商户。\n";
            $text .= "请在商户后台生成一次性绑定码，然后发送：<code>/bind 绑定码</code>\n\n";
        }
        if($isAdmin){
            $text .= "您拥有管理员权限，可进入管理员菜单。";
        }else{
            $text .= "发送 /help 可查看可用命令。";
        }

        return $this->send($chatId, $text, ['reply_markup'=>$this->mainKeyboard($isAdmin, (bool)$merchant)]);
    }

    private function showAdminMenu($chatId)
    {
        if(!$this->requireAdmin($chatId)) return false;
        $text = "<b>管理员菜单</b>\n\n请选择要查看的数据。";
        return $this->send($chatId, $text, ['reply_markup'=>$this->adminKeyboard()]);
    }

    private function showHelp($chatId)
    {
        $isAdmin = $this->isCurrentAdmin($chatId);
        $merchant = $this->botService->getMerchantInfo($chatId);
        $text = "<b>可用命令</b>\n\n";
        $text .= "/start - 显示菜单\n";
        $text .= "/help - 查看帮助\n";
        $text .= "/bind 绑定码 - 绑定商户\n";
        $text .= "/unbind - 解绑商户\n";
        if($merchant){
            $text .= "/info - 商户信息\n";
            $text .= "/today - 今日统计\n";
            $text .= "/yesterday - 昨日统计\n";
            $text .= "/week - 近7日统计\n";
            $text .= "/month - 本月统计\n";
            $text .= "/order 订单号 - 查询订单\n";
            $text .= "/settings - 通知设置\n";
        }
        if($isAdmin){
            $text .= "\n<b>管理员命令</b>\n";
            $text .= "/admin - 管理员菜单\n";
            $text .= "/admin_today - 全站今日统计\n";
            $text .= "/admin_yesterday - 全站昨日统计\n";
            $text .= "/channel - 通道统计\n";
            $text .= "/admin_order 订单号 - 查询任意订单\n";
            $text .= "/queue - 通知队列状态\n";
        }
        return $this->send($chatId, $text);
    }

    private function bindByCode($chatId, $code)
    {
        $result = $this->botService->bindMerchantByCode($chatId, $code);
        if(!$result['success']){
            return $this->send($chatId, $this->e($result['message']));
        }
        $user = $result['user'];
        $text = "<b>绑定成功</b>\n\n";
        $text .= "商户号：".intval($user['uid'])."\n";
        $text .= "商户名称：".$this->e(isset($user['username']) && $user['username'] !== '' ? $user['username'] : $user['account'])."\n";
        $text .= "后续将按通知设置接收消息。";
        return $this->send($chatId, $text, ['reply_markup'=>$this->mainKeyboard($this->isCurrentAdmin($chatId), true)]);
    }

    private function confirmUnbind($chatId)
    {
        if(!$this->botService->getBoundMerchant($chatId)){
            return $this->send($chatId, '您还未绑定商户。');
        }
        $keyboard = BotAPI::createInlineKeyboard([
            [
                BotAPI::createInlineButton('确认解绑', 'confirm_unbind'),
                BotAPI::createInlineButton('取消', 'cancel'),
            ],
        ]);
        return $this->send($chatId, "<b>确认解绑</b>\n\n解绑后将不再收到该商户的 Telegram 通知。", ['reply_markup'=>$keyboard]);
    }

    private function processUnbind($chatId)
    {
        $result = $this->botService->unbindMerchant($chatId);
        return $this->send($chatId, $this->e($result['message']), ['reply_markup'=>$this->mainKeyboard($this->isCurrentAdmin($chatId), false)]);
    }

    private function showMerchantInfo($chatId)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if(!$info) return $this->send($chatId, '您还未绑定商户，请先绑定。');

        $status = $info['status'] == 0 ? '已禁用' : '正常';
        if($info['pay'] == 0) $status .= '，支付关闭';
        if($info['settle'] == 0) $status .= '，结算关闭';

        $text = "<b>商户信息</b>\n\n";
        $text .= "商户号：".$info['uid']."\n";
        $text .= "商户名称：".$this->e($info['username'])."\n";
        $text .= "余额：".$this->money($info['money'])."\n";
        $text .= "状态：".$this->e($status)."\n";
        $text .= "绑定时间：".$this->e($info['bindtime']);
        return $this->send($chatId, $text);
    }

    private function showStats($chatId, $range, $admin)
    {
        if($admin){
            if(!$this->requireAdmin($chatId)) return false;
            list($start, $end, $label) = $this->dateRange($range);
            $stats = $this->botService->getAdminOrderStats($start, $end);
            $text = "<b>全站".$label."统计</b>\n\n";
        }else{
            $merchant = $this->botService->getMerchantInfo($chatId);
            if(!$merchant) return $this->send($chatId, '您还未绑定商户，请先绑定。');
            list($start, $end, $label) = $this->dateRange($range);
            $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $start, $end);
            $text = "<b>商户".$label."统计</b>\n\n";
            $text .= "商户号：".$merchant['uid']."\n";
        }

        $text .= "日期范围：".$this->e($start)." 至 ".$this->e($end)."\n";
        $text .= "订单总数：".$stats['total_orders']."\n";
        $text .= "成功订单：".$stats['success_orders']."\n";
        $text .= "订单金额：".$this->money($stats['total_money'])."\n";
        $text .= "成功金额：".$this->money($stats['success_money'])."\n";
        $text .= "成功率：".$stats['success_rate']."%";
        if($admin && isset($stats['profit_money'])){
            $text .= "\n平台利润：".$this->money($stats['profit_money']);
        }
        return $this->send($chatId, $text);
    }

    private function promptOrderSearch($chatId, $admin)
    {
        if($admin && !$this->requireAdmin($chatId)) return false;
        if(!$admin && !$this->botService->getBoundMerchant($chatId)){
            return $this->send($chatId, '您还未绑定商户，请先绑定。');
        }
        $this->setSession($chatId, ['action'=>$admin ? 'admin_order_search' : 'order_search']);
        return $this->send($chatId, "请输入要查询的系统订单号或商户订单号。");
    }

    private function showOrder($chatId, $keyword, $admin)
    {
        $uid = null;
        if($admin){
            if(!$this->requireAdmin($chatId)) return false;
        }else{
            $merchant = $this->botService->getMerchantInfo($chatId);
            if(!$merchant) return $this->send($chatId, '您还未绑定商户，请先绑定。');
            $uid = $merchant['uid'];
        }

        $order = $this->botService->getOrderInfo($keyword, $uid);
        if(!$order) return $this->send($chatId, '未找到相关订单或无权查看。');

        $text = "<b>订单详情</b>\n\n";
        $text .= "系统订单号：".$this->e($order['trade_no'])."\n";
        $text .= "商户订单号：".$this->e($order['out_trade_no'])."\n";
        if($admin) $text .= "商户ID：".intval($order['uid'])."\n";
        $text .= "商品名称：".$this->e($order['name'])."\n";
        $text .= "支付方式：".$this->e(isset($order['type_name']) ? $order['type_name'] : $order['type'])."\n";
        $text .= "支付通道：".$this->e(isset($order['channel_name']) ? $order['channel_name'] : $order['channel'])."\n";
        $text .= "订单金额：".$this->money($order['money'])."\n";
        $text .= "实付金额：".$this->money(isset($order['realmoney']) ? $order['realmoney'] : 0)."\n";
        $text .= "订单状态：".$this->statusText($order['status'])."\n";
        $text .= "通知状态：".$this->notifyText(isset($order['notify']) ? $order['notify'] : 0)."\n";
        $text .= "创建时间：".$this->e($order['addtime'])."\n";
        $text .= "完成时间：".$this->e($order['endtime']);
        return $this->send($chatId, $text);
    }

    private function showChannelStats($chatId)
    {
        if(!$this->requireAdmin($chatId)) return false;
        list($start, $end) = $this->dateRange('today');
        $rows = $this->botService->getChannelStats($start, $end, 12);
        $text = "<b>今日通道统计</b>\n\n";
        if(!$rows){
            $text .= "暂无通道数据。";
            return $this->send($chatId, $text);
        }
        foreach($rows as $row){
            $total = intval($row['total_orders']);
            $success = intval($row['success_orders']);
            $rate = $total > 0 ? round($success / $total * 100, 2) : 0;
            $text .= $this->e($row['name'])."\n";
            $text .= "金额：".$this->money($row['success_money'])."；订单：{$success}/{$total}；成功率：{$rate}%\n\n";
        }
        return $this->send($chatId, trim($text));
    }

    private function showQueueStats($chatId)
    {
        if(!$this->requireAdmin($chatId)) return false;
        $stats = $this->botService->getQueueStats();
        $text = "<b>Telegram 通知队列</b>\n\n";
        $text .= "待发送：".$stats['pending']."\n";
        $text .= "已发送：".$stats['sent']."\n";
        $text .= "失败：".$stats['failed'];
        if($stats['last_error']){
            $text .= "\n\n最近失败：".$this->e($stats['last_error']['error_msg']);
        }
        return $this->send($chatId, $text);
    }

    private function showSettings($chatId, $admin)
    {
        if($admin){
            if(!$this->requireAdmin($chatId)) return false;
            $settings = $this->botService->getAdminSettings($chatId);
            $prefix = 'admin_toggle_';
            $title = '管理员通知设置';
        }else{
            $settings = $this->botService->getMerchantInfo($chatId);
            if(!$settings) return $this->send($chatId, '您还未绑定商户，请先绑定。');
            $prefix = 'toggle_';
            $title = '商户通知设置';
        }

        $text = "<b>".$title."</b>\n\n";
        $map = $this->settingMap();
        $buttons = [];
        foreach($map as $type=>$label){
            $field = 'notify_'.$type;
            $on = !empty($settings[$field]);
            $text .= $label.'：'.($on ? '开启' : '关闭')."\n";
            $buttons[] = [BotAPI::createInlineButton(($on ? '关闭' : '开启').$label, $prefix.$type)];
        }
        $buttons[] = [BotAPI::createInlineButton('返回菜单', $admin ? 'admin' : 'menu')];
        return $this->send($chatId, $text, ['reply_markup'=>BotAPI::createInlineKeyboard($buttons)]);
    }

    private function toggleSetting($chatId, $type, $admin)
    {
        if($admin){
            if(!$this->requireAdmin($chatId)) return false;
            $result = $this->botService->updateAdminSetting($chatId, $type);
        }else{
            $result = $this->botService->updateNotifySetting($chatId, $type);
        }
        if(!$result['success']) return $this->send($chatId, $this->e($result['message']));
        return $this->showSettings($chatId, $admin);
    }

    private function requireAdmin($chatId)
    {
        if($this->isCurrentAdmin($chatId)) return true;
        $merchant = $this->botService->getMerchantInfo($chatId);
        $this->send($chatId, '此功能仅限管理员使用。', ['reply_markup'=>$this->mainKeyboard(false, (bool)$merchant)]);
        return false;
    }

    private function isCurrentAdmin($chatId)
    {
        if($this->currentFromId === null || $this->currentFromId === '') return false;
        return $this->botService->isAdmin($chatId, $this->currentFromId);
    }

    private function mainKeyboard($isAdmin, $hasMerchant)
    {
        $rows = [];
        if($hasMerchant){
            $rows[] = [['text'=>'商户信息'], ['text'=>'今日统计']];
            $rows[] = [['text'=>'昨日统计'], ['text'=>'近7日统计']];
            $rows[] = [['text'=>'本月统计'], ['text'=>'订单查询']];
            $rows[] = [['text'=>'通知设置'], ['text'=>'解绑商户']];
        }else{
            $rows[] = [['text'=>'绑定商户']];
        }
        if($isAdmin){
            $rows[] = [['text'=>'管理员菜单']];
        }
        return BotAPI::createKeyboard($rows, true, false);
    }

    private function adminKeyboard()
    {
        return BotAPI::createKeyboard([
            [['text'=>'全站今日'], ['text'=>'全站昨日']],
            [['text'=>'全站近7日'], ['text'=>'全站本月']],
            [['text'=>'通道统计'], ['text'=>'查询订单']],
            [['text'=>'队列状态'], ['text'=>'返回主菜单']],
        ], true, false);
    }

    private function settingMap()
    {
        return [
            'order' => '订单通知',
            'settle' => '结算通知',
            'login' => '登录通知',
            'complain' => '投诉通知',
            'mchrisk' => '风控通知',
            'balance' => '余额提醒',
        ];
    }

    private function dateRange($range)
    {
        if($range === 'yesterday'){
            $day = date('Y-m-d', strtotime('-1 day'));
            return [$day, $day, '昨日'];
        }
        if($range === 'week'){
            return [date('Y-m-d', strtotime('-6 day')), date('Y-m-d'), '近7日'];
        }
        if($range === 'month'){
            return [date('Y-m-01'), date('Y-m-d'), '本月'];
        }
        $day = date('Y-m-d');
        return [$day, $day, '今日'];
    }

    private function setSession($chatId, $data)
    {
        global $CACHE;
        if(!isset($CACHE)) return false;
        $data['expires_at'] = time() + 300;
        return $CACHE->save('telegram_session_'.$chatId, json_encode($data), 300);
    }

    private function getSession($chatId)
    {
        global $CACHE;
        if(!isset($CACHE)) return null;
        $raw = $CACHE->read('telegram_session_'.$chatId);
        if(!$raw) return null;
        $data = json_decode($raw, true);
        if(!is_array($data)) return null;
        if(isset($data['expires_at']) && intval($data['expires_at']) < time()){
            $this->clearSession($chatId);
            return null;
        }
        return $data;
    }

    private function clearSession($chatId)
    {
        global $CACHE;
        if(isset($CACHE)) $CACHE->delete('telegram_session_'.$chatId);
    }

    private function send($chatId, $text, $options = [])
    {
        return $this->botAPI->sendMessage($chatId, $text, $options);
    }

    private function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function money($value)
    {
        return '￥'.number_format((float)$value, 2, '.', '');
    }

    private function statusText($status)
    {
        $map = [
            0 => '未支付',
            1 => '已支付',
            2 => '已退款',
            3 => '已冻结',
            4 => '预授权',
        ];
        $status = intval($status);
        return isset($map[$status]) ? $map[$status] : '未知';
    }

    private function notifyText($notify)
    {
        $notify = intval($notify);
        if($notify === 0) return '未通知';
        if($notify > 0) return '已通知 '.$notify.' 次';
        return '通知失败';
    }
}
