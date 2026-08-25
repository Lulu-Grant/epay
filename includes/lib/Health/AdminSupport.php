<?php
namespace lib\Health;

class AdminSupport
{
    public static function deliveryReadiness($config)
    {
        $chat = trim(isset($config['health_report_chat_id']) ? (string)$config['health_report_chat_id'] : '');
        if($chat === '') $chat = trim(isset($config['telegram_admin_chat_id']) ? (string)$config['telegram_admin_chat_id'] : '');
        if(empty($config['health_report_enabled'])) return ['ok'=>false, 'message'=>'发送前请先启用每日简报'];
        if(empty($config['health_ai_enabled'])) return ['ok'=>false, 'message'=>'发送前请先启用 AI 订单健康分析'];
        if(empty($config['telegram_notice']) || empty($config['telegram_bot_token']) || !preg_match('/^-?[0-9]{5,20}$/D', $chat)){
            return ['ok'=>false, 'message'=>'发送前请先配置并启用 Telegram Bot 与接收 Chat ID'];
        }
        return ['ok'=>true, 'message'=>'可发送', 'destination'=>$chat];
    }

    public static function aiEnabled($config)
    {
        return !empty($config['health_ai_enabled']);
    }

    public static function aiFeedback($status, $error)
    {
        $status = intval($status);
        if($status === 1) return ['label'=>'已完成', 'reason'=>'AI 订单健康简报已生成'];
        if($status === 2) return ['label'=>'失败', 'reason'=>'AI 订单健康分析失败'];
        if($status === 4) return ['label'=>'等待重试', 'reason'=>'AI 分析未完成，将按计划重试'];
        if($status === 5) return ['label'=>'已终止', 'reason'=>'AI 分析超过6小时或达到每日调用上限'];
        if($status === 3){
            $reasons = [
                'Data is incomplete; AI analysis was skipped'=>'数据不完整，AI 分析已跳过',
                'Current snapshot is incomplete; AI analysis was skipped'=>'当前统计窗口快照不完整，AI 分析已跳过',
                'Seven-day baseline is incomplete; AI analysis was skipped'=>'最近7日基线快照不完整，AI 分析已跳过',
                'No orders were observed; AI analysis was skipped'=>'统计窗口没有订单，AI 分析已跳过',
                'Order sample is below the external analysis threshold'=>'订单样本低于外部分析阈值，AI 分析已跳过',
                'Health classification is unknown; AI analysis was skipped'=>'健康状态原因未确定，AI 分析已跳过',
                'No local rule issue requires external investigation advice'=>'没有需要外部调查建议的本地规则问题',
                'Daily AI call limit reached'=>'已达到当日 AI 调用上限',
            ];
            return ['label'=>'已跳过', 'reason'=>isset($reasons[$error]) ? $reasons[$error] : 'AI 未执行，请查看报告详情'];
        }
        return ['label'=>'未调用', 'reason'=>'本次未执行 AI 订单健康分析'];
    }
}
