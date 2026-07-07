<?php
namespace lib\Telegram;

class NotifyHelper
{
    public static function getTelegramMessage($scene, $param)
    {
        global $conf;

        $siteName = self::e(isset($conf['sitename']) ? $conf['sitename'] : '支付平台');
        if($scene == 'regaudit'){
            return "<b>新注册商户待审核</b>\n\n".
                "商户ID：".self::e($param['uid'])."\n".
                "注册账号：".self::e($param['account'])."\n".
                "注册时间：".date('Y-m-d H:i:s');
        }elseif($scene == 'apply'){
            return "<b>新的提现待处理 - {$siteName}</b>\n\n".
                "商户ID：".self::e($param['uid'])."\n".
                "提现方式：".self::e($param['type'])."\n".
                "提现金额：".self::money($param['realmoney'])."\n".
                "提交时间：".date('Y-m-d H:i:s');
        }elseif($scene == 'domain'){
            return "<b>授权支付域名待审核 - {$siteName}</b>\n\n".
                "商户ID：".self::e($param['uid'])."\n".
                "授权域名：".self::e($param['domain'])."\n".
                "提交时间：".date('Y-m-d H:i:s');
        }elseif($scene == 'order'){
            return "<b>新订单通知 - {$siteName}</b>\n\n".
                "商品名称：".self::e($param['name'])."\n".
                "订单金额：".self::money($param['money'])."\n".
                "支付方式：".self::e($param['type'])."\n".
                "商户订单号：".self::e($param['out_trade_no'])."\n".
                "系统订单号：".self::e($param['trade_no'])."\n".
                "支付完成时间：".self::e($param['time']);
        }elseif($scene == 'settle'){
            return "<b>结算完成通知 - {$siteName}</b>\n\n".
                "结算金额：".self::money($param['money'])."\n".
                "实际到账：".self::money($param['realmoney'])."\n".
                "结算账号：".self::e($param['account'])."\n".
                "结算完成时间：".self::e($param['time']);
        }elseif($scene == 'login'){
            return "<b>账号登录通知 - {$siteName}</b>\n\n".
                "登录账号：".self::e($param['user'])."\n".
                "登录IP：".self::e($param['clientip'])."\n".
                "IP归属地：".self::e(isset($param['ipinfo']) ? $param['ipinfo'] : '')."\n".
                "登录时间：".self::e($param['time']);
        }elseif($scene == 'complain'){
            return "<b>支付交易投诉通知 - {$siteName}</b>\n\n".
                "系统订单号：".self::e($param['trade_no'])."\n".
                "投诉原因：".self::e(isset($param['title']) ? $param['title'] : '')."\n".
                "投诉详情：".self::e(isset($param['content']) ? $param['content'] : '')."\n".
                "商品名称：".self::e(isset($param['ordername']) ? $param['ordername'] : '')."\n".
                "订单金额：".self::money(isset($param['money']) ? $param['money'] : 0)."\n".
                "投诉时间：".self::e($param['time']);
        }elseif($scene == 'balance'){
            return "<b>商户余额不足提醒 - {$siteName}</b>\n\n".
                "提醒金额：".self::money(isset($param['msgmoney']) ? $param['msgmoney'] : 0)."\n".
                "当前余额：".self::money(isset($param['money']) ? $param['money'] : 0);
        }elseif($scene == 'group'){
            return "<b>会员用户组到期提醒 - {$siteName}</b>\n\n".
                "用户组：".self::e($param['group'])."\n".
                "到期时间：".self::e($param['endtime']);
        }
        return false;
    }

    private static function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function money($value)
    {
        return '￥'.self::e($value);
    }
}
