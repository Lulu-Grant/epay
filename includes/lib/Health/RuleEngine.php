<?php
namespace lib\Health;

class RuleEngine
{
    private $settings;

    public function __construct($settings = [])
    {
        $this->settings = array_merge([
            'min_sample'=>30,
            'attention_drop'=>5.0,
            'warning_drop'=>10.0,
            'failure_streak'=>10,
            'stale_hours'=>6,
            'refund_attention_rate'=>5.0,
            'refund_warning_rate'=>20.0,
            'notify_critical_count'=>20,
            'notify_critical_rate'=>20.0,
        ], $settings);
    }

    public function evaluate($current, $baseline = null)
    {
        $issues = [];
        $dataIncomplete = empty($current['data_complete']);
        $baselineMissing = !is_array($baseline) || empty($baseline['data_complete']);
        if($dataIncomplete){
            $hours = intval(isset($current['snapshot_hours']) ? $current['snapshot_hours'] : 0);
            $issues[] = $this->issue('attention', 'data_incomplete', 'platform',
                '健康快照不完整，本报告不能判定系统正常', ['snapshot_hours'=>$hours, 'expected_hours'=>24]);
        }
        if($baselineMissing){
            $issues[] = $this->issue('attention', 'baseline_missing', 'platform',
                '最近7天基线快照不完整，本报告不能判定系统正常',
                ['baseline_days'=>7, 'baseline_complete'=>false]);
        }
        $platform = isset($current['platform']) ? $current['platform'] : [];
        $baselinePlatform = is_array($baseline) && isset($baseline['platform']) ? $baseline['platform'] : [];
        $platformTotal = intval(isset($platform['total_orders']) ? $platform['total_orders'] : 0);
        $sampleInsufficient = $platformTotal < intval($this->settings['min_sample']);
        if($sampleInsufficient){
            $code = $platformTotal === 0 ? 'no_traffic' : 'sample_insufficient';
            $message = $platformTotal === 0 ? '统计窗口内没有订单，不能判定支付系统正常' : '统计窗口订单样本不足，不能判定支付系统正常';
            $issues[] = $this->issue('attention', $code, 'platform', $message,
                ['sample'=>$platformTotal, 'min_sample'=>intval($this->settings['min_sample'])]);
        }
        $referenceTime = !empty($current['notification_as_of']) ? $current['notification_as_of']
            : (!empty($current['snapshot_finalized_at']) ? $current['snapshot_finalized_at']
            : (isset($current['period_end']) ? $current['period_end'] : null));
        $this->evaluateScope('platform', '平台', $platform, $baselinePlatform, $issues);
        $this->evaluateReversals('platform', '平台', $platform, $issues);
        $this->evaluateNotifications('platform', '平台', $platform, $referenceTime, $issues);

        $channels = isset($current['channels']) && is_array($current['channels']) ? $current['channels'] : [];
        $baselineChannels = is_array($baseline) && isset($baseline['channels']) ? $baseline['channels'] : [];
        foreach($channels as $id => $channel){
            if(intval(isset($channel['total_orders']) ? $channel['total_orders'] : 0) === 0 && empty($channel['channel_status'])) continue;
            $channelTotal = intval(isset($channel['total_orders']) ? $channel['total_orders'] : 0);
            $name = '通道'.intval($id);
            $channelName = $this->channelName(isset($channel['channel_name']) ? $channel['channel_name'] : '');
            if($channelName !== '') $name .= '（'.$channelName.'）';
            if($channelTotal < intval($this->settings['min_sample'])) $name .= '（低样本'.$channelTotal.'笔）';
            $base = isset($baselineChannels[$id]) ? $baselineChannels[$id] : [];
            $this->evaluateScope('channel:'.intval($id), $name, $channel, $base, $issues);
            $this->evaluateReversals('channel:'.intval($id), $name, $channel, $issues);
            $streak = max(intval(isset($channel['failure_streak']) ? $channel['failure_streak'] : 0), intval(isset($channel['failure_streak_max']) ? $channel['failure_streak_max'] : 0));
            if($streak >= intval($this->settings['failure_streak'])){
                $issues[] = $this->issue('warning', 'failure_streak', 'channel:'.intval($id),
                    $name.'连续未支付订单达到'.$streak.'笔',
                    ['failure_streak'=>$streak, 'sample'=>$channelTotal, 'min_sample'=>intval($this->settings['min_sample'])]);
            }
            $this->evaluateNotifications('channel:'.intval($id), $name, $channel, $referenceTime, $issues);
            $this->evaluateStaleness('channel:'.intval($id), $name, $channel, isset($current['period_end']) ? $current['period_end'] : null, $issues);
        }

        usort($issues, function($a, $b){
            $rank = ['critical'=>4, 'warning'=>3, 'attention'=>2, 'info'=>1];
            return $rank[$b['level']] <=> $rank[$a['level']];
        });
        $classificationBlocked = $dataIncomplete || $baselineMissing || $sampleInsufficient;
		$level = $classificationBlocked ? 'unknown' : 'healthy';
		foreach($issues as $issue){
			if($classificationBlocked && !$this->isConfirmedDuringIncompleteData($issue)) continue;
			if($issue['level'] === 'critical'){ $level = 'critical'; break; }
			if($issue['level'] === 'warning' && $level !== 'critical') $level = 'warning';
			elseif($issue['level'] === 'attention' && in_array($level, ['healthy','unknown'], true)) $level = 'attention';
		}

        return [
            'version'=>'1203',
            'level'=>$level,
            'issues'=>$issues,
			'summary'=>$this->summary($level, $issues, $classificationBlocked),
            'generated_at'=>date('Y-m-d H:i:s'),
        ];
    }

    public static function wilsonLower($success, $total, $z = 1.96)
    {
        $success = max(0, intval($success));
        $total = max(0, intval($total));
        if($total === 0) return null;
        $p = min(1, $success / $total);
        $z2 = $z * $z;
        $center = $p + $z2 / (2 * $total);
        $margin = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $total)) / $total);
        return round(100 * ($center - $margin) / (1 + $z2 / $total), 2);
    }

    public static function classificationReason($current, $rules)
    {
        $codes = [];
        foreach(isset($rules['issues']) && is_array($rules['issues']) ? $rules['issues'] : [] as $issue){
            if(is_array($issue) && !empty($issue['code'])) $codes[(string)$issue['code']] = true;
        }
        if(empty($current['data_complete']) || isset($codes['data_incomplete'])) return 'data_incomplete';
        foreach(['baseline_missing','no_traffic','sample_insufficient'] as $code){
            if(isset($codes[$code])) return $code;
        }
        return isset($rules['level']) && $rules['level'] === 'unknown' ? 'unknown' : null;
    }

    private function evaluateScope($scope, $label, $current, $baseline, &$issues)
    {
        $total = intval(isset($current['total_orders']) ? $current['total_orders'] : 0);
        $paid = intval(isset($current['paid_orders']) ? $current['paid_orders'] : 0);
        if($total < intval($this->settings['min_sample'])) return;
        $rate = $total > 0 ? $paid * 100 / $total : 0;
        $baseTotal = intval(isset($baseline['total_orders']) ? $baseline['total_orders'] : 0);
        $basePaid = intval(isset($baseline['paid_orders']) ? $baseline['paid_orders'] : 0);
        if($baseTotal >= intval($this->settings['min_sample'])){
            $baseRate = $basePaid * 100 / $baseTotal;
            $drop = $baseRate - $rate;
            if($drop >= floatval($this->settings['warning_drop'])){
                $issues[] = $this->issue('warning', 'success_rate_drop', $scope,
                    $label.'曾支付成功率较基线下降'.round($drop, 2).'个百分点',
                    ['current_rate'=>round($rate,2), 'baseline_rate'=>round($baseRate,2), 'sample'=>$total]);
            }elseif($drop >= floatval($this->settings['attention_drop'])){
                $issues[] = $this->issue('attention', 'success_rate_drop', $scope,
                    $label.'曾支付成功率较基线下降'.round($drop, 2).'个百分点',
                    ['current_rate'=>round($rate,2), 'baseline_rate'=>round($baseRate,2), 'sample'=>$total]);
            }
        }
        $lower = self::wilsonLower($paid, $total);
        if($lower !== null && $lower < 10){
            $issues[] = $this->issue('critical', 'very_low_conversion', $scope,
                $label.'曾支付成功率置信下界低于10%', ['rate'=>round($rate,2), 'wilson_lower'=>$lower, 'sample'=>$total]);
        }
    }

    private function evaluateReversals($scope, $label, $metrics, &$issues)
    {
        $paid = intval(isset($metrics['paid_orders']) ? $metrics['paid_orders'] : 0);
        $refunded = intval(isset($metrics['refunded_orders']) ? $metrics['refunded_orders'] : 0);
        $frozen = intval(isset($metrics['frozen_orders']) ? $metrics['frozen_orders'] : 0);
        if($refunded > 0){
            $rate = $paid > 0 ? $refunded * 100 / $paid : 100.0;
            $level = null;
            if($rate >= floatval($this->settings['refund_warning_rate'])) $level = 'warning';
            elseif($rate >= floatval($this->settings['refund_attention_rate'])) $level = 'attention';
            if($level !== null){
                $issues[] = $this->issue($level, 'high_refund_rate', $scope,
                    $label.'退款订单占曾支付成功订单'.round($rate, 2).'%',
                    ['paid_orders'=>$paid, 'refunded_orders'=>$refunded, 'refund_rate'=>round($rate, 2)]);
            }
        }
        if($frozen > 0){
            $issues[] = $this->issue('warning', 'frozen_orders', $scope,
                $label.'存在'.$frozen.'笔冻结订单', ['frozen_orders'=>$frozen, 'paid_orders'=>$paid]);
        }
    }

    private function evaluateNotifications($scope, $label, $metrics, $referenceTime, &$issues)
    {
        $failed = intval(isset($metrics['notify_failed']) ? $metrics['notify_failed'] : 0);
        $notifyTotal = intval(isset($metrics['notify_total']) ? $metrics['notify_total'] : 0);
        if($failed > 0){
            $rate = $notifyTotal > 0 ? $failed * 100 / $notifyTotal : 100.0;
            $level = $failed >= intval($this->settings['notify_critical_count']) || $rate >= floatval($this->settings['notify_critical_rate']) ? 'critical' : 'warning';
            $issues[] = $this->issue($level, 'notify_failed', $scope,
                $label.'存在最终通知失败', ['notify_total'=>$notifyTotal, 'notify_failed'=>$failed, 'notify_failed_rate'=>round($rate, 2)]);
        }
        $pending = intval(isset($metrics['notify_pending']) ? $metrics['notify_pending'] : 0);
        if($pending <= 0) return;
        $denominator = max(1, $notifyTotal);
        $oldest = isset($metrics['notify_oldest_time']) ? strtotime($metrics['notify_oldest_time']) : false;
        $reference = $referenceTime ? strtotime($referenceTime) : false;
        $ageMinutes = $oldest === false || $reference === false ? null : max(0, (int)floor(($reference - $oldest) / 60));
        $ratio = $pending / $denominator;
        $level = $pending >= 5 || $ratio >= 0.05 || ($ageMinutes !== null && $ageMinutes >= 60) ? 'warning' : 'attention';
        $issues[] = $this->issue($level, 'notify_pending', $scope,
            $label.'截至统计最终快照存在'.$pending.'笔商户通知等待重试',
            ['notify_total'=>$notifyTotal, 'notify_pending'=>$pending, 'pending_ratio'=>round($ratio * 100, 2), 'oldest_age_minutes'=>$ageMinutes]);
    }

    private function evaluateStaleness($scope, $label, $metrics, $periodEnd, &$issues)
    {
        if(empty($metrics['channel_status']) || intval(isset($metrics['total_orders']) ? $metrics['total_orders'] : 0) === 0) return;
        $end = $periodEnd ? strtotime($periodEnd) : false;
        $last = !empty($metrics['last_success_time']) ? strtotime($metrics['last_success_time']) : false;
        if($end === false || $last === false) return;
        $hours = max(0, ($end - $last) / 3600);
        if($hours >= intval($this->settings['stale_hours'])){
            $issues[] = $this->issue('attention', 'stale_success', $scope,
                $label.'距统计窗口内最后成功已超过'.round($hours, 1).'小时',
                ['last_success_time'=>$metrics['last_success_time'], 'stale_hours'=>round($hours, 1)]);
        }
    }

    private function issue($level, $code, $scope, $message, $evidence)
    {
        return ['level'=>$level, 'code'=>$code, 'scope'=>$scope, 'message'=>$message, 'evidence'=>$evidence];
    }

	private function isConfirmedDuringIncompleteData($issue)
	{
		$provisionalCodes = [
			'data_incomplete','baseline_missing','no_traffic','sample_insufficient',
			'success_rate_drop','very_low_conversion',
		];
		return !in_array(isset($issue['code']) ? $issue['code'] : '', $provisionalCodes, true);
	}

    private function channelName($value)
    {
        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return mb_substr($value, 0, 40, 'UTF-8');
    }

	private function summary($level, $issues, $classificationBlocked = false)
    {
        if(!$issues) return '未发现达到阈值的订单或通道异常。';
        $labels = ['attention'=>'需关注', 'warning'=>'存在告警', 'critical'=>'严重异常', 'unknown'=>'数据不足'];
		$label = isset($labels[$level]) ? $labels[$level] : '运行正常';
		if($classificationBlocked && $level !== 'unknown') $label .= '（同时存在数据不足）';
		return $label.'，共发现'.count($issues).'项规则命中。';
    }
}
