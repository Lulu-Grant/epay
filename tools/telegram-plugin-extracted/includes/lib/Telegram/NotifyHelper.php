<?php
namespace lib\Telegram;

class NotifyHelper
{
    public static function sendOrderNotify($order, $type = 'success', $uid = null)
    {
        global $DB, $conf;
        
        $token = $conf['telegram_bot_token'];
        if (empty($token)) {
            return false;
        }

        if ($uid === null && isset($order['uid'])) {
            $uid = $order['uid'];
        }
        
        if (empty($uid)) {
            return false;
        }
        
        $bind = $DB->getRow(
            "SELECT * FROM pre_telegram_bind WHERE uid=:uid AND status=1",
            [':uid' => $uid]
        );

        if (!$bind) {
            return false;
        }

        if ($type === 'success' && !$bind['notify_order']) {
            return false;
        }

        $botAPI = new BotAPI($token);
        
        $emoji = $type === 'success' ? '✅' : '❌';
        $title = $type === 'success' ? '支付成功' : '支付失败';
        
        $message = "{$emoji} <b>{$title}</b>\n\n";
        $message .= "订单号：{$order['trade_no']}\n";
        $message .= "商户订单：{$order['out_trade_no']}\n";
        $message .= "商品名称：{$order['name']}\n";
        $message .= "订单金额：¥{$order['money']}\n";
        
        if ($type === 'success' && !empty($order['realmoney'])) {
            $message .= "实付金额：¥{$order['realmoney']}\n";
        }
        
        $message .= "支付时间：" . date('Y-m-d H:i:s') . "\n";

        return $botAPI->sendMessage($bind['chat_id'], $message);
    }

    public static function sendNotify($scene, $uid, $param)
    {
        global $DB, $conf;
        
        $token = $conf['telegram_bot_token'];
        if (empty($token)) {
            return false;
        }
        
        $bind = $DB->getRow(
            "SELECT * FROM pre_telegram_bind WHERE uid=:uid AND status=1",
            [':uid' => $uid]
        );

        if (!$bind) {
            return false;
        }

        switch ($scene) {
            case 'order':
                if (empty($bind['notify_order'])) {
                    return false;
                }
                break;
            case 'settle':
                if (empty($bind['notify_settle'])) {
                    return false;
                }
                break;
            case 'login':
                if (empty($bind['notify_login'])) {
                    return false;
                }
                break;
            case 'complain':
                if (empty($bind['notify_complain'])) {
                    return false;
                }
                break;
            case 'mchrisk':
                if (empty($bind['notify_mchrisk'])) {
                    return false;
                }
                break;
            case 'balance':
                if (empty($bind['notify_balance'])) {
                    return false;
                }
                break;
        }

        $botAPI = new BotAPI($token);
        $message = self::getTelegramMessage($scene, $param);
        
        if (empty($message)) {
            return false;
        }

        return $botAPI->sendMessage($bind['chat_id'], $message);
    }

    public static function getTelegramMessage($scene, $param)
    {
        global $conf;
        
        switch ($scene) {
            case 'regaudit':
                $emoji = '📋';
                $title = '新注册商户待审核';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "商户ID：{$param['uid']}\n";
                $message .= "注册账号：{$param['account']}\n";
                $message .= "注册时间：" . date('Y-m-d H:i:s') . "\n";
                break;
            
            case 'apply':
                $emoji = '💸';
                $title = '新的提现待处理';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "商户ID：{$param['uid']}\n";
                $message .= "提现方式：{$param['type']}\n";
                $message .= "提现金额：¥{$param['realmoney']}\n";
                $message .= "提交时间：" . date('Y-m-d H:i:s') . "\n";
                break;
            
            case 'domain':
                $emoji = '🌐';
                $title = '新的授权支付域名待审核';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "商户ID：{$param['uid']}\n";
                $message .= "授权域名：{$param['domain']}\n";
                $message .= "提交时间：" . date('Y-m-d H:i:s') . "\n";
                break;
            
            case 'order':
                $emoji = '📦';
                $title = '新订单通知';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "商品名称：{$param['name']}\n";
                $message .= "订单金额：¥{$param['money']}\n";
                $message .= "支付方式：{$param['type']}\n";
                $message .= "商户订单号：{$param['out_trade_no']}\n";
                $message .= "系统订单号：{$param['trade_no']}\n";
                $message .= "支付完成时间：{$param['time']}\n";
                break;
            
            case 'settle':
                $emoji = '✅';
                $title = '结算完成通知';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "结算金额：¥{$param['money']}\n";
                $message .= "实际到账：¥{$param['realmoney']}\n";
                $message .= "结算账号：{$param['account']}\n";
                $message .= "结算完成时间：{$param['time']}\n";
                break;
            
            case 'login':
                $emoji = '🔑';
                $title = '账号登录通知';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "登录账号：{$param['user']}\n";
                $message .= "登录IP：{$param['clientip']}\n";
                $message .= "登录时间：{$param['time']}\n";
                break;
            
            case 'complain':
                $emoji = '⚠️';
                $title = '支付交易投诉通知';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "系统订单号：{$param['trade_no']}\n";
                $message .= "投诉原因：{$param['title']}\n";
                $message .= "投诉详情：{$param['content']}\n";
                $message .= "商品名称：{$param['ordername']}\n";
                $message .= "订单金额：¥{$param['money']}\n";
                $message .= "投诉时间：{$param['time']}\n";
                break;
            
            case 'mchrisk':
                $emoji = '🚫';
                $title = '渠道商户违规处置通知';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "渠道子商户号：{$param['mchid']}\n";
                $message .= "商户名称：{$param['mchname']}\n";
                $message .= "风险类型：{$param['risk_desc']}\n";
                $message .= "管控能力：{$param['punish_type']}\n";
                $message .= "管控开始时间：{$param['punish_time']}\n";
                $message .= "解除方式：{$param['recover_way']}\n";
                break;
            
            case 'balance':
                $emoji = '💰';
                $title = '商户余额不足提醒';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "余额不足：{$param['msgmoney']}元\n";
                $message .= "当前余额：{$param['money']}元\n";
                break;
            
            case 'group':
                $emoji = '🎯';
                $title = '会员用户组到期提醒';
                $message = "{$emoji} <b>{$title}</b>\n\n";
                $message .= "用户组：{$param['group']}\n";
                $message .= "到期时间：{$param['endtime']}\n";
                break;
            
            default:
                return false;
        }
        
        return $message;
    }

    public static function sendAdminNotify($message)
    {
        global $conf;
        
        $token = $conf['telegram_bot_token'];
        $adminChatId = $conf['telegram_admin_chat_id'];
        
        if (empty($token) || empty($adminChatId)) {
            return false;
        }

        $botAPI = new BotAPI($token);
        return $botAPI->sendMessage($adminChatId, $message);
    }
}
