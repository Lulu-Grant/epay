<?php
namespace lib\Defense;

class DefenseException extends \RuntimeException
{
    public $reason;
    public function __construct($reason, $message = null)
    {
        $this->reason = $reason;
        $messages = [
            'DEFENSE_BROWSER_REQUIRED'=>'本次支付需要浏览器安全验证',
            'DEFENSE_VERIFY_FAILED'=>'安全验证已失效，请重新验证',
            'DEFENSE_IP_BLOCKED'=>'当前网络暂时无法发起支付，请稍后再试或联系商户',
            'DEFENSE_DAILY_LIMIT'=>'当前网络今天已成功支付 5 单，请明天再试',
            'DEFENSE_PENDING_LIMIT'=>'当前网络有订单等待付款结果确认，请先完成或等待已有订单',
            'DEFENSE_LOCAL_HOLD'=>'该通道不支持上游关单，已发出的付款信息仍须确认结果，名额暂不释放',
            'DEFENSE_CHANNEL_UNSUPPORTED'=>'当前支付路径尚未支持严格防御，请联系管理员',
            'DEFENSE_TEMP_UNAVAILABLE'=>'安全校验暂不可用，请稍后重试',
            'DEFENSE_VERSION_CONFLICT'=>'商户防御状态已变化，请刷新后重试',
            'DEFENSE_ORDER_RETIRED'=>'该付款资格已关闭，请返回商户重新创建订单',
            'DEFENSE_LEGACY_ORDER'=>'该订单创建于防御启用前或曾未经防御发放付款信息，请返回商户重新创建订单',
            'DEFENSE_QUERY_MISMATCH'=>'渠道查单结果与订单不符，保留名额等待核对',
            'DEFENSE_CLOSE_UNCONFIRMED'=>'尚未取得可信关单确认，保留名额等待核对',
        ];
        parent::__construct($message ?? ($messages[$reason] ?? $messages['DEFENSE_TEMP_UNAVAILABLE']));
    }
}
