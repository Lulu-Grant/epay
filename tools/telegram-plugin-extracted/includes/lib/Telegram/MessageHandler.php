<?php
namespace lib\Telegram;

class MessageHandler
{
    private $botService;
    private $botAPI;

    public function __construct($botService)
    {
        $this->botService = $botService;
        $this->botAPI = $botService->getBotAPI();
    }

    public function handleUpdate($update)
    {
        if (isset($update['message'])) {
            return $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }
        return false;
    }

    private function handleMessage($message)
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        $from = $message['from'];

        if (!$text) {
            return false;
        }

        if (strpos($text, '/') === 0) {
            return $this->handleCommand($chatId, $text, $from);
        }

        return $this->handleTextMessage($chatId, $text, $from);
    }

    private function handleCommand($chatId, $text, $from)
    {
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        switch ($command) {
            case '/start':
                return $this->showMainMenu($chatId);
            case '/bind':
                return $this->handleBindCommand($chatId, $args);
            case '/unbind':
                return $this->handleUnbindCommand($chatId);
            case '/info':
                return $this->showMerchantInfo($chatId);
            case '/today':
                return $this->showMerchantTodayStats($chatId);
            case '/yesterday':
                return $this->showMerchantYesterdayStats($chatId);
            case '/week':
                return $this->showMerchantWeekStats($chatId);
            case '/month':
                return $this->showMerchantMonthStats($chatId);
            case '/order':
                return $this->handleOrderCommand($chatId, $args);
            case '/settings':
                return $this->showSettings($chatId);
            case '/help':
                return $this->showHelp($chatId);
            default:
                return $this->botAPI->sendMessage($chatId, '未知命令，请使用 /start 查看菜单');
        }
    }

    private function handleTextMessage($chatId, $text, $from)
    {
        $session = $this->getSession($chatId);
        
        if ($session && $session['action']) {
            switch ($session['action']) {
                case 'bind_uid':
                    return $this->processBindUid($chatId, $text);
                case 'bind_key':
                    return $this->processBindKey($chatId, $text);
                case 'search_order':
                    return $this->processSearchOrder($chatId, $text);
                case 'settings':
                    return $this->processSettingsToggle($chatId, $text);
                case 'admin_settings':
                    return $this->processAdminSettingsToggle($chatId, $text);
                case 'admin_search_order':
                    return $this->processAdminSearchOrder($chatId, $text);
            }
        }

        $text = trim($text);
        switch ($text) {
            case '📋 商户信息':
                return $this->showMerchantInfo($chatId);
            case '📊 今日流水':
                return $this->showMerchantTodayStats($chatId);
            case '📈 昨日流水':
                return $this->showMerchantYesterdayStats($chatId);
            case '📉 近周流水':
                return $this->showMerchantWeekStats($chatId);
            case '📅 近月流水':
                return $this->showMerchantMonthStats($chatId);
            case '🔍 订单查询':
                $buttons = [[BotAPI::createInlineButton('« 返回', 'menu')]];
                $keyboard = BotAPI::createInlineKeyboard($buttons);
                $this->setSession($chatId, ['action' => 'search_order']);
                return $this->botAPI->sendMessage($chatId, "🔍 <b>订单查询</b>\n\n请输入订单号或商户订单号进行查询：", ['reply_markup' => $keyboard]);
            case '⚙️ 通知设置':
                return $this->showSettings($chatId);
            case '🔓 解绑商户':
                $buttons = [
                    [
                        BotAPI::createInlineButton('✅ 确认解绑', 'confirmUnbind'),
                        BotAPI::createInlineButton('❌ 取消', 'menu')
                    ]
                ];
                $keyboard = BotAPI::createInlineKeyboard($buttons);
                return $this->botAPI->sendMessage($chatId, "🔓 <b>确认解绑</b>\n\n确定要解绑当前商户吗？解绑后将不再收到订单通知。", ['reply_markup' => $keyboard]);
            case '🔗 绑定商户':
                $buttons = [[BotAPI::createInlineButton('« 返回', 'menu')]];
                $keyboard = BotAPI::createInlineKeyboard($buttons);
                $this->setSession($chatId, ['action' => 'bind_uid']);
                return $this->botAPI->sendMessage($chatId, "🔗 <b>绑定商户</b>\n\n请按以下格式输入绑定信息：\n<code>商户号 密钥</code>\n\n例如：<code>1000 abc123def456</code>", ['reply_markup' => $keyboard]);
            case '🔐 管理员面板':
                return $this->showAdminMenuAsReplyKeyboard($chatId);
            case '📊 全站今日':
                return $this->showAdminTodayStats($chatId);
            case '📈 全站昨日':
                return $this->showAdminYesterdayStats($chatId);
            case '📉 全站近周':
                return $this->showAdminWeekStats($chatId);
            case '📅 全站近月':
                return $this->showAdminMonthStats($chatId);
            case '💳 通道统计':
                return $this->showAdminChannelStats($chatId);
            case '🔍 查询订单':
                return $this->showAdminSearchOrderForm($chatId);
            case '⚙️ 设置':
                return $this->showAdminSettings($chatId);
            case '« 返回主菜单':
                return $this->showMainMenu($chatId);
            default:
                return $this->showMainMenu($chatId);
        }
    }

    private function handleCallback($callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $data = $callback['data'];
        $callbackId = $callback['id'];

        $this->botAPI->answerCallbackQuery($callbackId);

        $params = explode('_', $data);
        $action = $params[0];

        switch ($action) {
            case 'menu':
                return $this->editToMainMenu($chatId, $messageId);
            case 'bind':
                return $this->showBindForm($chatId, $messageId);
            case 'unbind':
                return $this->confirmUnbind($chatId, $messageId);
            case 'confirmUnbind':
                return $this->processUnbind($chatId, $messageId);
            case 'info':
                return $this->editMerchantInfo($chatId, $messageId);
            case 'today':
                return $this->editTodayStats($chatId, $messageId);
            case 'yesterday':
                return $this->editYesterdayStats($chatId, $messageId);
            case 'week':
                return $this->editWeekStatsForMerchant($chatId, $messageId);
            case 'month':
                return $this->editMonthStatsForMerchant($chatId, $messageId);
            case 'admin_today':
                return $this->editAdminTodayStats($chatId, $messageId);
            case 'admin_yesterday':
                return $this->editAdminYesterdayStats($chatId, $messageId);
            case 'admin_week':
                return $this->editWeekStats($chatId, $messageId);
            case 'admin_month':
                return $this->editMonthStats($chatId, $messageId);
            case 'admin_channel':
                return $this->editChannelStats($chatId, $messageId);
            case 'admin_order':
                return $this->editAdminSearchOrderForm($chatId, $messageId);
            case 'admin_settings':
                return $this->editAdminSettings($chatId, $messageId);
            case 'admin_toggleOrder':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'order');
            case 'admin_toggleSettle':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'settle');
            case 'admin_toggleLogin':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'login');
            case 'admin_toggleComplain':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'complain');
            case 'admin_toggleMchrisk':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'mchrisk');
            case 'admin_toggleBalance':
                return $this->toggleAdminNotifySetting($chatId, $messageId, 'balance');
            case 'channel':
                return $this->editChannelStats($chatId, $messageId);
            case 'channelDetail':
                return $this->editChannelDetail($chatId, $messageId, $params[1] ?? null);
            case 'order':
                return $this->showOrderSearchForm($chatId, $messageId);
            case 'orderDetail':
                return $this->showOrderDetail($chatId, $messageId, $params[1] ?? null);
            case 'settings':
                return $this->editSettings($chatId, $messageId);
            case 'toggleOrder':
                return $this->toggleNotifySetting($chatId, $messageId, 'order');
            case 'toggleSettle':
                return $this->toggleNotifySetting($chatId, $messageId, 'settle');
            case 'toggleLogin':
                return $this->toggleNotifySetting($chatId, $messageId, 'login');
            case 'toggleComplain':
                return $this->toggleNotifySetting($chatId, $messageId, 'complain');
            case 'toggleMchrisk':
                return $this->toggleNotifySetting($chatId, $messageId, 'mchrisk');
            case 'toggleBalance':
                return $this->toggleNotifySetting($chatId, $messageId, 'balance');
            case 'admin':
                return $this->showAdminMenu($chatId, $messageId);
            default:
                return false;
        }
    }

    public function showMainMenu($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);

        $text = "👋 <b>欢迎使用支付通知机器人</b>\n\n";

        if ($merchant) {
            $userInfo = $this->botService->getMerchantInfo($chatId);
            $text .= "当前绑定商户：{$userInfo['username']}\n";
            $text .= "商户号：{$userInfo['uid']}\n\n";
        } else {
            $text .= "您还未绑定商户，请先绑定。\n\n";
        }

        $buttons = [];
        
        if ($merchant) {
            $buttons = [
                [['text' => '📋 商户信息'], ['text' => '📊 今日流水']],
                [['text' => '📈 昨日流水'], ['text' => '📉 近周流水']],
                [['text' => '📅 近月流水'], ['text' => '🔍 订单查询']],
                [['text' => '⚙️ 通知设置'], ['text' => '🔓 解绑商户']]
            ];
        } else {
            $buttons = [
                [['text' => '🔗 绑定商户']]
            ];
        }

        if ($isAdmin) {
            $buttons[] = [['text' => '🔐 管理员面板']];
        }

        $keyboard = BotAPI::createKeyboard($buttons, true, false);
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function editToMainMenu($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);

        $text = "👋 <b>欢迎使用支付通知机器人</b>\n\n";

        if ($merchant) {
            $userInfo = $this->botService->getMerchantInfo($chatId);
            $text .= "当前绑定商户：{$userInfo['username']}\n";
            $text .= "商户号：{$userInfo['uid']}\n\n";
        } else {
            $text .= "您还未绑定商户，请先绑定。\n\n";
        }

        $buttons = [];
        
        if ($merchant) {
            $buttons[] = [
                BotAPI::createInlineButton('📋 商户信息', 'info'),
                BotAPI::createInlineButton('📊 今日流水', 'today')
            ];
            $buttons[] = [
                BotAPI::createInlineButton('📈 昨日流水', 'yesterday'),
                BotAPI::createInlineButton('🔍 订单查询', 'order')
            ];
            $buttons[] = [
                BotAPI::createInlineButton('⚙️ 通知设置', 'settings'),
                BotAPI::createInlineButton('🔓 解绑商户', 'unbind')
            ];
        } else {
            $buttons[] = [
                BotAPI::createInlineButton('🔗 绑定商户', 'bind')
            ];
        }

        if ($isAdmin) {
            $buttons[] = [
                BotAPI::createInlineButton('🔐 管理员面板', 'admin')
            ];
        }

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showBindForm($chatId, $messageId)
    {
        $text = "🔗 <b>绑定商户</b>\n\n";
        $text .= "请按以下格式输入绑定信息：\n";
        $text .= "<code>商户号 密钥</code>\n\n";
        $text .= "例如：<code>1000 abc123def456</code>\n\n";
        $text .= "或使用命令：<code>/bind 商户号 密钥</code>";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        $this->setSession($chatId, ['action' => 'bind_uid']);
        
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function handleBindCommand($chatId, $args)
    {
        if (!$args) {
            return $this->botAPI->sendMessage($chatId, "请使用格式：<code>/bind 商户号 密钥</code>");
        }

        $parts = preg_split('/\s+/', $args);
        if (count($parts) < 2) {
            return $this->botAPI->sendMessage($chatId, "参数错误，请使用格式：<code>/bind 商户号 密钥</code>");
        }

        $uid = intval($parts[0]);
        $key = $parts[1];

        $result = $this->botService->bindMerchant($chatId, $uid, $key);
        
        if ($result['success']) {
            $text = "✅ {$result['message']}\n\n";
            $text .= "商户号：{$result['user']['uid']}\n";
            $text .= "商户名：{$result['user']['username']}\n\n";
            $text .= "您将收到订单支付通知。";
        } else {
            $text = "❌ {$result['message']}";
        }

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function confirmUnbind($chatId, $messageId)
    {
        $text = "🔓 <b>确认解绑</b>\n\n";
        $text .= "确定要解绑当前商户吗？解绑后将不再收到订单通知。";

        $buttons = [
            [
                BotAPI::createInlineButton('✅ 确认解绑', 'confirmUnbind'),
                BotAPI::createInlineButton('❌ 取消', 'menu')
            ]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function processUnbind($chatId, $messageId)
    {
        $result = $this->botService->unbindMerchant($chatId);
        $text = $result['success'] ? "✅ {$result['message']}" : "❌ {$result['message']}";
        
        return $this->botAPI->editMessageText($chatId, $messageId, $text);
    }

    private function handleUnbindCommand($chatId)
    {
        $result = $this->botService->unbindMerchant($chatId);
        $text = $result['success'] ? "✅ {$result['message']}" : "❌ {$result['message']}";
        
        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function showMerchantInfo($chatId)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $text = "📋 <b>商户信息</b>\n\n";
        $text .= "商户号：{$info['uid']}\n";
        $text .= "商户名：{$info['username']}\n";
        $text .= "邮箱：{$info['email']}\n";
        $text .= "手机：{$info['phone']}\n";
        $text .= "余额：¥{$info['money']}\n";
        $text .= "绑定时间：{$info['bindtime']}\n";
        $text .= "订单通知：" . ($info['notify_order'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "结算通知：" . ($info['notify_settle'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "登录通知：" . ($info['notify_login'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "投诉通知：" . ($info['notify_complain'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "违规通知：" . ($info['notify_mchrisk'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "余额提醒：" . ($info['notify_balance'] ? '✅ 开启' : '❌ 关闭');

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editMerchantInfo($chatId, $messageId)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $text = "📋 <b>商户信息</b>\n\n";
        $text .= "商户号：{$info['uid']}\n";
        $text .= "商户名：{$info['username']}\n";
        $text .= "邮箱：{$info['email']}\n";
        $text .= "手机：{$info['phone']}\n";
        $text .= "余额：¥{$info['money']}\n";
        $text .= "绑定时间：{$info['bindtime']}\n";
        $text .= "订单通知：" . ($info['notify_order'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "结算通知：" . ($info['notify_settle'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "登录通知：" . ($info['notify_login'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "投诉通知：" . ($info['notify_complain'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "违规通知：" . ($info['notify_mchrisk'] ? '✅ 开启' : '❌ 关闭') . "\n";
        $text .= "余额提醒：" . ($info['notify_balance'] ? '✅ 开启' : '❌ 关闭');

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showMerchantTodayStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);
        
        if (!$isAdmin && !$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $today = date('Y-m-d');
        if ($isAdmin) {
            $stats = $this->botService->getAdminOrderStats($today, $today);
            $text = "📊 <b>今日流水统计（管理员）</b>\n\n";
            $text .= "日期：{$today}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
            $text .= "平台利润：¥{$stats['profit_money']}\n";
        } else {
            $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $today, $today);
            $text = "📊 <b>今日流水统计</b>\n\n";
            $text .= "日期：{$today}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
        }
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editTodayStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);
        
        if (!$isAdmin && !$merchant) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $today = date('Y-m-d');
        if ($isAdmin) {
            $stats = $this->botService->getAdminOrderStats($today, $today);
            $text = "📊 <b>今日流水统计（管理员）</b>\n\n";
            $text .= "日期：{$today}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
            $text .= "平台利润：¥{$stats['profit_money']}\n";
        } else {
            $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $today, $today);
            $text = "📊 <b>今日流水统计</b>\n\n";
            $text .= "日期：{$today}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
        }
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [$isAdmin ? BotAPI::createInlineButton('« 返回', 'admin') : BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showMerchantYesterdayStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);
        
        if (!$isAdmin && !$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($isAdmin) {
            $stats = $this->botService->getAdminOrderStats($yesterday, $yesterday);
            $text = "📈 <b>昨日流水统计（管理员）</b>\n\n";
            $text .= "日期：{$yesterday}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
            $text .= "平台利润：¥{$stats['profit_money']}\n";
        } else {
            $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $yesterday, $yesterday);
            $text = "📈 <b>昨日流水统计</b>\n\n";
            $text .= "日期：{$yesterday}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
        }
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editYesterdayStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        $merchant = $this->botService->getBoundMerchant($chatId);
        
        if (!$isAdmin && !$merchant) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($isAdmin) {
            $stats = $this->botService->getAdminOrderStats($yesterday, $yesterday);
            $text = "📈 <b>昨日流水统计（管理员）</b>\n\n";
            $text .= "日期：{$yesterday}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
            $text .= "平台利润：¥{$stats['profit_money']}\n";
        } else {
            $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $yesterday, $yesterday);
            $text = "📈 <b>昨日流水统计</b>\n\n";
            $text .= "日期：{$yesterday}\n\n";
            $text .= "订单总数：{$stats['total_orders']}\n";
            $text .= "成功订单：{$stats['success_orders']}\n";
            $text .= "订单金额：¥{$stats['total_money']}\n";
            $text .= "成功金额：¥{$stats['success_money']}\n";
        }
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [$isAdmin ? BotAPI::createInlineButton('« 返回', 'admin') : BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showAdminWeekStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $dateStart = date('Y-m-d', strtotime('-7 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($dateStart, $dateEnd);

        $text = "📊 <b>近一周流水统计（管理员）</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editWeekStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $dateStart = date('Y-m-d', strtotime('-7 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($dateStart, $dateEnd);

        $text = "📊 <b>近一周流水统计（管理员）</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showAdminMonthStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $dateStart = date('Y-m-d', strtotime('-30 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($dateStart, $dateEnd);

        $text = "📊 <b>近一月流水统计（管理员）</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editMonthStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $dateStart = date('Y-m-d', strtotime('-30 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($dateStart, $dateEnd);

        $text = "📊 <b>近一月流水统计（管理员）</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showAdminChannelStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $today = date('Y-m-d');
        $channels = $this->botService->getChannelStats($today, $today);

        $text = "📊 <b>今日通道收款统计（管理员）</b>\n\n";
        $text .= "日期：{$today}\n\n";

        if (empty($channels)) {
            $text .= "暂无数据";
        } else {
            foreach ($channels as $channel) {
                $text .= "【{$channel['name']}】\n";
                $text .= "收款金额：¥{$channel['money']}\n";
                $text .= "成功率：{$channel['success_rate']}%\n\n";
            }
        }

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editChannelStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $today = date('Y-m-d');
        $channels = $this->botService->getChannelStats($today, $today);

        $text = "📊 <b>今日通道收款统计（管理员）</b>\n\n";
        $text .= "日期：{$today}\n\n";

        if (empty($channels)) {
            $text .= "暂无数据";
        } else {
            foreach ($channels as $channel) {
                $text .= "【{$channel['name']}】\n";
                $text .= "收款金额：¥{$channel['money']}\n";
                $text .= "成功率：{$channel['success_rate']}%\n\n";
            }
        }

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showOrderSearchForm($chatId, $messageId)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $text = "🔍 <b>订单查询</b>\n\n";
        $text .= "请输入订单号或商户订单号进行查询：";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        $this->setSession($chatId, ['action' => 'search_order']);
        
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function handleOrderCommand($chatId, $args)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        if (!$args) {
            return $this->botAPI->sendMessage($chatId, "请使用格式：<code>/order 订单号</code>");
        }

        return $this->showOrderDetailByTradeNo($chatId, trim($args), $merchant['uid']);
    }

    private function processSearchOrder($chatId, $keyword)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $this->clearSession($chatId);

        $order = $this->botService->getOrderInfo($keyword, $merchant['uid']);
        if ($order) {
            return $this->showOrderDetailMessage($chatId, $order);
        }

        $orders = $this->botService->searchOrders($merchant['uid'], $keyword, 5);
        if (empty($orders)) {
            return $this->botAPI->sendMessage($chatId, "未找到相关订单。");
        }

        $text = "🔍 <b>搜索结果</b>\n\n";
        $buttons = [];
        
        foreach ($orders as $order) {
            $text .= "订单：{$order['trade_no']}\n";
            $text .= "商品：{$order['name']}\n";
            $text .= "金额：¥{$order['money']}\n";
            $text .= "状态：{$order['status_text']}\n\n";
            
            $buttons[] = [BotAPI::createInlineButton(
                "{$order['trade_no']} - ¥{$order['money']}", 
                "orderDetail_{$order['trade_no']}"
            )];
        }

        $buttons[] = [BotAPI::createInlineButton('« 返回', 'menu')];
        $keyboard = BotAPI::createInlineKeyboard($buttons);
        
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function showOrderDetail($chatId, $messageId, $tradeNo)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        $uid = $merchant ? $merchant['uid'] : null;
        
        $order = $this->botService->getOrderInfo($tradeNo, $uid);
        if (!$order) {
            return $this->botAPI->editMessageText($chatId, $messageId, "订单不存在或无权查看。");
        }

        $text = "📝 <b>订单详情</b>\n\n";
        $text .= "系统订单号：{$order['trade_no']}\n";
        $text .= "商户订单号：{$order['out_trade_no']}\n";
        $text .= "支付方式：{$order['type']}\n";
        $text .= "支付通道：{$order['channel']}\n";
        $text .= "商品名称：{$order['name']}\n";
        $text .= "订单金额：¥{$order['money']}\n";
        $text .= "实付金额：¥{$order['realmoney']}\n";
        $text .= "订单状态：{$order['status_text']}\n";
        $text .= "创建时间：{$order['addtime']}\n";
        $text .= "支付时间：{$order['endtime']}\n";
        $text .= "支付账号：{$order['buyer']}\n";
        $text .= "支付IP：{$order['ip']}\n";
        $text .= "来源域名：{$order['domain']}";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showOrderDetailMessage($chatId, $order)
    {
        $text = "📝 <b>订单详情</b>\n\n";
        $text .= "系统订单号：{$order['trade_no']}\n";
        $text .= "商户订单号：{$order['out_trade_no']}\n";
        $text .= "支付方式：{$order['type']}\n";
        $text .= "支付通道：{$order['channel']}\n";
        $text .= "商品名称：{$order['name']}\n";
        $text .= "订单金额：¥{$order['money']}\n";
        $text .= "实付金额：¥{$order['realmoney']}\n";
        $text .= "订单状态：{$order['status_text']}\n";
        $text .= "创建时间：{$order['addtime']}\n";
        $text .= "支付时间：{$order['endtime']}\n";
        $text .= "支付账号：{$order['buyer']}\n";
        $text .= "支付IP：{$order['ip']}\n";
        $text .= "来源域名：{$order['domain']}";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function showOrderDetailByTradeNo($chatId, $tradeNo, $uid)
    {
        $order = $this->botService->getOrderInfo($tradeNo, $uid);
        if (!$order) {
            return $this->botAPI->sendMessage($chatId, "订单不存在或无权查看。");
        }

        return $this->showOrderDetailMessage($chatId, $order);
    }

    private function showSettings($chatId)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $text = "⚙️ <b>通知设置</b>\n\n";
        $text .= "订单通知：" . ($info['notify_order'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "结算通知：" . ($info['notify_settle'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "登录通知：" . ($info['notify_login'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "投诉通知：" . ($info['notify_complain'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "违规通知：" . ($info['notify_mchrisk'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "余额提醒：" . ($info['notify_balance'] ? '✅ 已开启' : '❌ 已关闭') . "\n\n";
        $text .= "点击下方按钮切换设置：";

        $buttons = [
            [
                ['text' => ($info['notify_order'] ? '❌ 订单' : '✅ 订单')],
                ['text' => ($info['notify_settle'] ? '❌ 结算' : '✅ 结算')]
            ],
            [
                ['text' => ($info['notify_login'] ? '❌ 登录' : '✅ 登录')],
                ['text' => ($info['notify_complain'] ? '❌ 投诉' : '✅ 投诉')]
            ],
            [
                ['text' => ($info['notify_mchrisk'] ? '❌ 违规' : '✅ 违规')],
                ['text' => ($info['notify_balance'] ? '❌ 余额' : '✅ 余额')]
            ],
            [['text' => '« 返回主菜单']]
        ];

        $keyboard = BotAPI::createKeyboard($buttons, true, false);
        $this->setSession($chatId, ['action' => 'settings']);
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function editSettings($chatId, $messageId)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $text = "⚙️ <b>通知设置</b>\n\n";
        $text .= "订单通知：" . ($info['notify_order'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "结算通知：" . ($info['notify_settle'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "登录通知：" . ($info['notify_login'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "投诉通知：" . ($info['notify_complain'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "违规通知：" . ($info['notify_mchrisk'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "余额提醒：" . ($info['notify_balance'] ? '✅ 已开启' : '❌ 已关闭');

        $buttons = [
            [
                BotAPI::createInlineButton(
                    $info['notify_order'] ? '关闭订单通知' : '开启订单通知',
                    'toggleOrder'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $info['notify_settle'] ? '关闭结算通知' : '开启结算通知',
                    'toggleSettle'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $info['notify_login'] ? '关闭登录通知' : '开启登录通知',
                    'toggleLogin'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $info['notify_complain'] ? '关闭投诉通知' : '开启投诉通知',
                    'toggleComplain'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $info['notify_mchrisk'] ? '关闭违规通知' : '开启违规通知',
                    'toggleMchrisk'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $info['notify_balance'] ? '关闭余额提醒' : '开启余额提醒',
                    'toggleBalance'
                )
            ],
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function toggleNotifySetting($chatId, $messageId, $type)
    {
        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        switch ($type) {
            case 'order':
                $newValue = !$info['notify_order'];
                $this->botService->updateNotifySettings($chatId, $newValue, null, null, null, null, null);
                break;
            case 'settle':
                $newValue = !$info['notify_settle'];
                $this->botService->updateNotifySettings($chatId, null, $newValue, null, null, null, null);
                break;
            case 'login':
                $newValue = !$info['notify_login'];
                $this->botService->updateNotifySettings($chatId, null, null, $newValue, null, null, null);
                break;
            case 'complain':
                $newValue = !$info['notify_complain'];
                $this->botService->updateNotifySettings($chatId, null, null, null, $newValue, null, null);
                break;
            case 'mchrisk':
                $newValue = !$info['notify_mchrisk'];
                $this->botService->updateNotifySettings($chatId, null, null, null, null, $newValue, null);
                break;
            case 'balance':
                $newValue = !$info['notify_balance'];
                $this->botService->updateNotifySettings($chatId, null, null, null, null, null, $newValue);
                break;
        }

        return $this->editSettings($chatId, $messageId);
    }

    private function toggleAdminNotifySetting($chatId, $messageId, $type)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $settings = $this->botService->getAdminSettings($chatId);

        switch ($type) {
            case 'order':
                $newValue = !$settings['notify_order'];
                $this->botService->updateAdminSettings($chatId, $newValue, null, null, null, null, null);
                break;
            case 'settle':
                $newValue = !$settings['notify_settle'];
                $this->botService->updateAdminSettings($chatId, null, $newValue, null, null, null, null);
                break;
            case 'login':
                $newValue = !$settings['notify_login'];
                $this->botService->updateAdminSettings($chatId, null, null, $newValue, null, null, null);
                break;
            case 'complain':
                $newValue = !$settings['notify_complain'];
                $this->botService->updateAdminSettings($chatId, null, null, null, $newValue, null, null);
                break;
            case 'mchrisk':
                $newValue = !$settings['notify_mchrisk'];
                $this->botService->updateAdminSettings($chatId, null, null, null, null, $newValue, null);
                break;
            case 'balance':
                $newValue = !$settings['notify_balance'];
                $this->botService->updateAdminSettings($chatId, null, null, null, null, null, $newValue);
                break;
        }

        return $this->editAdminSettings($chatId, $messageId);
    }

    private function showAdminMenu($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $text = "🔐 <b>管理员面板</b>\n\n";
        $text .= "请选择操作：";

        $buttons = [
            [
                BotAPI::createInlineButton('📊 今日流水', 'admin_today'),
                BotAPI::createInlineButton('📈 昨日流水', 'admin_yesterday')
            ],
            [
                BotAPI::createInlineButton('📉 近一周', 'admin_week'),
                BotAPI::createInlineButton('📅 近一月', 'admin_month')
            ],
            [
                BotAPI::createInlineButton('💳 通道统计', 'admin_channel'),
                BotAPI::createInlineButton('🔍 查询订单', 'admin_order')
            ],
            [
                BotAPI::createInlineButton('⚙️ 通知设置', 'admin_settings')
            ],
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showAdminMenuAsReplyKeyboard($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $text = "🔐 <b>管理员面板</b>\n\n";
        $text .= "请选择操作：";

        $buttons = [
            [['text' => '📊 全站今日'], ['text' => '📈 全站昨日']],
            [['text' => '📉 全站近周'], ['text' => '📅 全站近月']],
            [['text' => '💳 通道统计'], ['text' => '🔍 查询订单']],
            [['text' => '⚙️ 通知设置'], ['text' => '« 返回主菜单']]
        ];

        $keyboard = BotAPI::createKeyboard($buttons, true, false);
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function showHelp($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        
        $text = "📖 <b>帮助信息</b>\n\n";
        $text .= "<b>商户端命令：</b>\n";
        $text .= "/start - 显示主菜单\n";
        $text .= "/bind 商户号 密钥 - 绑定商户\n";
        $text .= "/unbind - 解绑商户\n";
        $text .= "/info - 查看商户信息\n";
        $text .= "/today - 今日流水统计\n";
        $text .= "/yesterday - 昨日流水统计\n";
        $text .= "/week - 近一周流水统计\n";
        $text .= "/month - 近一月流水统计\n";
        $text .= "/order 订单号 - 查询订单\n";
        $text .= "/settings - 通知设置\n\n";

        if ($isAdmin) {
            $text .= "<b>管理员命令：</b>\n";
            $text .= "/admin_today - 全站今日流水\n";
            $text .= "/admin_yesterday - 全站昨日流水\n";
            $text .= "/admin_week - 全站近周统计\n";
            $text .= "/admin_month - 全站近月流水统计\n";
            $text .= "/admin_channel - 通道收款统计\n";
            $text .= "/admin_order 订单号 - 查询订单\n";
            $text .= "/admin_settings - 通知设置\n";
        }

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function getSession($chatId)
    {
        global $CACHE;
        $key = 'telegram_session_' . $chatId;
        $data = $CACHE->read($key);
        return $data ? json_decode($data, true) : null;
    }

    private function setSession($chatId, $data)
    {
        global $CACHE;
        $key = 'telegram_session_' . $chatId;
        $CACHE->save($key, json_encode($data), 300);
    }

    private function clearSession($chatId)
    {
        global $CACHE;
        $key = 'telegram_session_' . $chatId;
        $CACHE->delete($key);
    }

    private function processBindUid($chatId, $text)
    {
        $this->clearSession($chatId);
        
        $parts = preg_split('/\s+/', $text);
        if (count($parts) < 2) {
            return $this->botAPI->sendMessage($chatId, "格式错误，请输入：<code>商户号 密钥</code>");
        }

        $uid = intval($parts[0]);
        $key = $parts[1];

        $result = $this->botService->bindMerchant($chatId, $uid, $key);
        
        if ($result['success']) {
            $text = "✅ {$result['message']}\n\n";
            $text .= "商户号：{$result['user']['uid']}\n";
            $text .= "商户名：{$result['user']['username']}\n\n";
            $text .= "您将收到订单支付通知。";
        } else {
            $text = "❌ {$result['message']}";
        }

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function processBindKey($chatId, $text)
    {
        $this->clearSession($chatId);
        return $this->botAPI->sendMessage($chatId, "绑定流程已取消，请重新开始。");
    }

    private function showAdminTodayStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $today = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($today, $today);
        $text = "📊 <b>今日流水统计（管理员）</b>\n\n";
        $text .= "日期：{$today}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editAdminTodayStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $today = date('Y-m-d');
        $stats = $this->botService->getAdminOrderStats($today, $today);
        $text = "📊 <b>今日流水统计（管理员）</b>\n\n";
        $text .= "日期：{$today}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showAdminYesterdayStats($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stats = $this->botService->getAdminOrderStats($yesterday, $yesterday);
        $text = "📈 <b>昨日流水统计（管理员）</b>\n\n";
        $text .= "日期：{$yesterday}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editAdminYesterdayStats($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stats = $this->botService->getAdminOrderStats($yesterday, $yesterday);
        $text = "📈 <b>昨日流水统计（管理员）</b>\n\n";
        $text .= "日期：{$yesterday}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        $text .= "平台利润：¥{$stats['profit_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showMerchantWeekStats($chatId)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $dateStart = date('Y-m-d', strtotime('-7 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $dateStart, $dateEnd);

        $text = "📉 <b>近一周流水统计</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editWeekStatsForMerchant($chatId, $messageId)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $dateStart = date('Y-m-d', strtotime('-7 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $dateStart, $dateEnd);

        $text = "📉 <b>近一周流水统计</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function showMerchantMonthStats($chatId)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $dateStart = date('Y-m-d', strtotime('-30 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $dateStart, $dateEnd);

        $text = "📅 <b>近一月流水统计</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        return $this->botAPI->sendMessage($chatId, $text);
    }

    private function editMonthStatsForMerchant($chatId, $messageId)
    {
        $merchant = $this->botService->getBoundMerchant($chatId);
        if (!$merchant) {
            return $this->botAPI->editMessageText($chatId, $messageId, "您还未绑定商户，请先绑定。");
        }

        $dateStart = date('Y-m-d', strtotime('-30 days'));
        $dateEnd = date('Y-m-d');
        $stats = $this->botService->getMerchantOrderStats($merchant['uid'], $dateStart, $dateEnd);

        $text = "📅 <b>近一月流水统计</b>\n\n";
        $text .= "日期范围：{$dateStart} 至 {$dateEnd}\n\n";
        $text .= "订单总数：{$stats['total_orders']}\n";
        $text .= "成功订单：{$stats['success_orders']}\n";
        $text .= "订单金额：¥{$stats['total_money']}\n";
        $text .= "成功金额：¥{$stats['success_money']}\n";
        
        $successRate = $stats['total_orders'] > 0 
            ? round($stats['success_orders'] / $stats['total_orders'] * 100, 2) 
            : 0;
        $text .= "成功率：{$successRate}%";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'menu')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function processSettingsToggle($chatId, $text)
    {
        $text = trim($text);
        
        if ($text === '« 返回主菜单') {
            $this->clearSession($chatId);
            return $this->showMainMenu($chatId);
        }

        $info = $this->botService->getMerchantInfo($chatId);
        if (!$info) {
            $this->clearSession($chatId);
            return $this->botAPI->sendMessage($chatId, "您还未绑定商户，请先绑定。");
        }

        $type = null;
        $currentValue = null;
        
        if (strpos($text, '订单') !== false) {
            $type = 'order';
            $currentValue = $info['notify_order'];
        } elseif (strpos($text, '结算') !== false) {
            $type = 'settle';
            $currentValue = $info['notify_settle'];
        } elseif (strpos($text, '登录') !== false) {
            $type = 'login';
            $currentValue = $info['notify_login'];
        } elseif (strpos($text, '投诉') !== false) {
            $type = 'complain';
            $currentValue = $info['notify_complain'];
        } elseif (strpos($text, '违规') !== false) {
            $type = 'mchrisk';
            $currentValue = $info['notify_mchrisk'];
        } elseif (strpos($text, '余额') !== false) {
            $type = 'balance';
            $currentValue = $info['notify_balance'];
        }

        if ($type) {
            $newValue = !$currentValue;
            $this->botService->updateNotifySettings(
                $chatId,
                $type === 'order' ? $newValue : null,
                $type === 'settle' ? $newValue : null,
                $type === 'login' ? $newValue : null,
                $type === 'complain' ? $newValue : null,
                $type === 'mchrisk' ? $newValue : null,
                $type === 'balance' ? $newValue : null
            );
        }

        return $this->showSettings($chatId);
    }

    private function showAdminSettings($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $settings = $this->botService->getAdminSettings($chatId);

        $text = "🔐 <b>管理员通知设置</b>\n\n";
        $text .= "订单通知：" . ($settings['notify_order'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "结算通知：" . ($settings['notify_settle'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "登录通知：" . ($settings['notify_login'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "投诉通知：" . ($settings['notify_complain'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "违规通知：" . ($settings['notify_mchrisk'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "余额提醒：" . ($settings['notify_balance'] ? '✅ 已开启' : '❌ 已关闭') . "\n\n";
        $text .= "点击下方按钮切换设置：";

        $buttons = [
            [
                ['text' => ($settings['notify_order'] ? '❌ 订单' : '✅ 订单')],
                ['text' => ($settings['notify_settle'] ? '❌ 结算' : '✅ 结算')]
            ],
            [
                ['text' => ($settings['notify_login'] ? '❌ 登录' : '✅ 登录')],
                ['text' => ($settings['notify_complain'] ? '❌ 投诉' : '✅ 投诉')]
            ],
            [
                ['text' => ($settings['notify_mchrisk'] ? '❌ 违规' : '✅ 违规')],
                ['text' => ($settings['notify_balance'] ? '❌ 余额' : '✅ 余额')]
            ],
            [['text' => '« 返回管理员菜单']]
        ];

        $keyboard = BotAPI::createKeyboard($buttons, true, false);
        $this->setSession($chatId, ['action' => 'admin_settings']);
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function processAdminSettingsToggle($chatId, $text)
    {
        $text = trim($text);
        
        if ($text === '« 返回管理员菜单') {
            $this->clearSession($chatId);
            return $this->showAdminMenuAsReplyKeyboard($chatId);
        }

        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            $this->clearSession($chatId);
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $settings = $this->botService->getAdminSettings($chatId);

        $type = null;
        $currentValue = null;
        
        if (strpos($text, '订单') !== false) {
            $type = 'order';
            $currentValue = $settings['notify_order'];
        } elseif (strpos($text, '结算') !== false) {
            $type = 'settle';
            $currentValue = $settings['notify_settle'];
        } elseif (strpos($text, '登录') !== false) {
            $type = 'login';
            $currentValue = $settings['notify_login'];
        } elseif (strpos($text, '投诉') !== false) {
            $type = 'complain';
            $currentValue = $settings['notify_complain'];
        } elseif (strpos($text, '违规') !== false) {
            $type = 'mchrisk';
            $currentValue = $settings['notify_mchrisk'];
        } elseif (strpos($text, '余额') !== false) {
            $type = 'balance';
            $currentValue = $settings['notify_balance'];
        }

        if ($type) {
            $newValue = !$currentValue;
            $this->botService->updateAdminSettings(
                $chatId,
                $type === 'order' ? $newValue : null,
                $type === 'settle' ? $newValue : null,
                $type === 'login' ? $newValue : null,
                $type === 'complain' ? $newValue : null,
                $type === 'mchrisk' ? $newValue : null,
                $type === 'balance' ? $newValue : null
            );
        }

        return $this->showAdminSettings($chatId);
    }

    private function showAdminSearchOrderForm($chatId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $text = "🔍 <b>查询全站订单</b>\n\n";
        $text .= "请输入订单号或商户订单号进行查询：";

        $buttons = [
            [['text' => '« 返回管理员菜单']]
        ];

        $keyboard = BotAPI::createKeyboard($buttons, true, false);
        $this->setSession($chatId, ['action' => 'admin_search_order']);
        return $this->botAPI->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function processAdminSearchOrder($chatId, $text)
    {
        $text = trim($text);
        
        if ($text === '« 返回管理员菜单') {
            $this->clearSession($chatId);
            return $this->showAdminMenuAsReplyKeyboard($chatId);
        }

        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            $this->clearSession($chatId);
            return $this->botAPI->sendMessage($chatId, "此功能仅限管理员使用。");
        }

        $this->clearSession($chatId);

        $order = $this->botService->getOrderInfo($text, null);
        if ($order) {
            return $this->showOrderDetailMessage($chatId, $order);
        }

        return $this->botAPI->sendMessage($chatId, "未找到相关订单。");
    }

    private function editAdminSearchOrderForm($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $text = "🔍 <b>查询全站订单</b>\n\n";
        $text .= "请输入订单号或商户订单号进行查询：";

        $buttons = [
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        $this->setSession($chatId, ['action' => 'admin_search_order']);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }

    private function editAdminSettings($chatId, $messageId)
    {
        $isAdmin = $this->botService->isAdmin($chatId);
        if (!$isAdmin) {
            return $this->botAPI->editMessageText($chatId, $messageId, "此功能仅限管理员使用。");
        }

        $settings = $this->botService->getAdminSettings($chatId);

        $text = "🔐 <b>管理员通知设置</b>\n\n";
        $text .= "订单通知：" . ($settings['notify_order'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "结算通知：" . ($settings['notify_settle'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "登录通知：" . ($settings['notify_login'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "投诉通知：" . ($settings['notify_complain'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "违规通知：" . ($settings['notify_mchrisk'] ? '✅ 已开启' : '❌ 已关闭') . "\n";
        $text .= "余额提醒：" . ($settings['notify_balance'] ? '✅ 已开启' : '❌ 已关闭');

        $buttons = [
            [
                BotAPI::createInlineButton(
                    $settings['notify_order'] ? '关闭订单通知' : '开启订单通知',
                    'admin_toggleOrder'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $settings['notify_settle'] ? '关闭结算通知' : '开启结算通知',
                    'admin_toggleSettle'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $settings['notify_login'] ? '关闭登录通知' : '开启登录通知',
                    'admin_toggleLogin'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $settings['notify_complain'] ? '关闭投诉通知' : '开启投诉通知',
                    'admin_toggleComplain'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $settings['notify_mchrisk'] ? '关闭违规通知' : '开启违规通知',
                    'admin_toggleMchrisk'
                )
            ],
            [
                BotAPI::createInlineButton(
                    $settings['notify_balance'] ? '关闭余额提醒' : '开启余额提醒',
                    'admin_toggleBalance'
                )
            ],
            [BotAPI::createInlineButton('« 返回', 'admin')]
        ];

        $keyboard = BotAPI::createInlineKeyboard($buttons);
        return $this->botAPI->editMessageText($chatId, $messageId, $text, ['reply_markup' => $keyboard]);
    }
}
