<?php
namespace lib\Health;

class TelegramRenderer
{
    const MAX_LENGTH = 3500;
    const MAX_RULE_ISSUES = 8;

    public function render($report)
    {
        $metrics = isset($report['metrics']) ? $report['metrics'] : [];
        $rules = isset($report['rules']) ? $report['rules'] : [];
        $ai = isset($report['ai']) && is_array($report['ai']) ? $report['ai'] : null;
        if($ai && !empty($ai['telegram_brief'])){
            $date = isset($report['report_date']) ? $report['report_date'] : date('Y-m-d');
            $brief = trim((string)$ai['telegram_brief']);
            if(strpos($brief, $date) === false) $brief = '支付系统每日健康简报 | '.$date."\n".$brief;
            return $this->renderAiText($brief);
        }
        $platform = isset($metrics['platform']) ? $metrics['platform'] : [];
        $date = isset($report['report_date']) ? $report['report_date'] : date('Y-m-d');
        $level = isset($rules['level']) ? $rules['level'] : 'unknown';
        $labels = ['healthy'=>'正常', 'attention'=>'需关注', 'warning'=>'告警', 'critical'=>'严重', 'unknown'=>'数据不足'];
        $levelText = isset($labels[$level]) ? $labels[$level] : '未知';
        $classificationReason = RuleEngine::classificationReason($metrics, $rules);
		if($classificationReason !== null && $level !== 'unknown') $levelText .= '（数据不足）';

        $lines = [];
        $lines[] = "支付系统每日健康简报 | {$date}";
        $lines[] = "总体状态：{$levelText}";
        $lines[] = '消息说明：Telegram仅展示摘要，完整规则与调查明细请在管理后台查看。';
        $lines[] = '统计窗口：'.(isset($metrics['period_start']) ? $metrics['period_start'] : '-').' 至 '.(isset($metrics['period_end']) ? $metrics['period_end'] : '-').'（UTC+8）';
        $lines[] = '快照完整度：'.intval(isset($metrics['snapshot_hours']) ? $metrics['snapshot_hours'] : 0).'/24小时';
        $lines[] = '统计最终快照：'.(!empty($metrics['snapshot_finalized_at']) ? $metrics['snapshot_finalized_at'] : '-');
        $lines[] = '订单：'.intval(isset($platform['total_orders']) ? $platform['total_orders'] : 0)
            .'，曾支付成功：'.intval(isset($platform['paid_orders']) ? $platform['paid_orders'] : 0)
            .'，曾支付成功率：'.$this->rate(isset($platform['success_rate']) ? $platform['success_rate'] : null);
        $lines[] = '支付后状态：退款'.intval(isset($platform['refunded_orders']) ? $platform['refunded_orders'] : 0)
            .'，冻结'.intval(isset($platform['frozen_orders']) ? $platform['frozen_orders'] : 0)
            .'，预授权'.intval(isset($platform['preauth_orders']) ? $platform['preauth_orders'] : 0);
        $notifyAsOf = !empty($metrics['notification_as_of']) ? $metrics['notification_as_of'] : (!empty($metrics['snapshot_finalized_at']) ? $metrics['snapshot_finalized_at'] : null);
        $oldestAge = $this->ageMinutes(isset($platform['notify_oldest_time']) ? $platform['notify_oldest_time'] : null, $notifyAsOf);
        $lines[] = '商户回调（符合条件'.intval(isset($platform['notify_total']) ? $platform['notify_total'] : 0).'笔，截至'.($notifyAsOf ?: '-').'）：成功'.intval(isset($platform['notify_success']) ? $platform['notify_success'] : 0)
            .'，重试中'.intval(isset($platform['notify_pending']) ? $platform['notify_pending'] : 0)
            .'，最终失败'.intval(isset($platform['notify_failed']) ? $platform['notify_failed'] : 0)
            .'，最早等待'.($oldestAge === null ? '-' : $oldestAge.'分钟');

        $allIssues = isset($rules['issues']) && is_array($rules['issues']) ? $rules['issues'] : [];
        $issues = array_slice($allIssues, 0, self::MAX_RULE_ISSUES);
        if($issues){
            $lines[] = '';
            $lines[] = '规则告警：共'.count($allIssues).'项，按严重度展开前'.count($issues).'项，完整明细见后台。';
			$issueLabels = ['critical'=>'严重','warning'=>'告警','attention'=>'需关注','info'=>'提示'];
			foreach($issues as $index => $issue){
				$issueLevelKey = isset($issue['level']) ? $issue['level'] : '';
				$issueLevel = isset($issueLabels[$issueLevelKey]) ? $issueLabels[$issueLevelKey] : '未知';
				$lines[] = ($index + 1).'. ['.$issueLevel.'] '.trim((string)$issue['message']);
			}
        }else{
            $lines[] = '';
            $lines[] = '规则告警：未发现达到阈值的异常。';
        }
        $groundedFindings = [];
        foreach($ai && isset($ai['findings']) && is_array($ai['findings']) ? $ai['findings'] : [] as $finding){
            if(!is_array($finding) || empty($finding['rule_code']) || empty($finding['scope']) || empty($finding['title']) || empty($finding['action'])) continue;
            $groundedFindings[] = $finding;
        }
        if($groundedFindings){
            $lines[] = '';
            $lines[] = 'AI调查建议：事实、级别与证据均来自本地规则。';
            foreach(array_slice($groundedFindings, 0, 3) as $finding){
                $title = trim(isset($finding['title']) ? (string)$finding['title'] : '');
                $evidence = trim(isset($finding['evidence']) ? (string)$finding['evidence'] : '');
                $action = trim(isset($finding['action']) ? (string)$finding['action'] : '');
                if($title !== '') $lines[] = '• '.$title.($evidence !== '' ? '；证据：'.$evidence : '').($action !== '' ? '；建议：'.$action : '');
            }
        }elseif($classificationReason !== null){
            $lines[] = '';
            $lines[] = 'AI调查建议：已跳过，'.$this->classificationReasonText($classificationReason).'。';
        }elseif(empty($allIssues)){
            $lines[] = '';
            $lines[] = 'AI调查建议：未执行，本地规则未发现需要外部调查的异常。';
        }else{
            $lines[] = '';
            $lines[] = 'AI调查建议：未启用或本次服务不可用，以上为本地规则结果。';
        }
        if($classificationReason !== null){
            $lines[] = '';
            $lines[] = '数据提示：'.$this->classificationReasonText($classificationReason).'，不能判定系统正常。';
        }
        $lines[] = '';
        $lines[] = '本简报只提供只读分析，不会自动调整通道。';

        $plainLines = [];
        $htmlLines = [];
        $html = '';
        foreach($lines as $index => $line){
            $line = $this->bmpText($line);
            $bold = $index === 0 || strpos($line, '总体状态：') === 0 || strpos($line, '规则告警：') === 0 || strpos($line, 'AI调查建议：') === 0;
            $prefix = $htmlLines ? "\n" : '';
            $available = self::MAX_LENGTH - mb_strlen(implode("\n", $htmlLines), 'UTF-8') - mb_strlen($prefix, 'UTF-8') - ($bold ? 7 : 0);
            if($available <= 0) break;
            $line = $this->fitEscaped($line, $available);
            if($line === '' && $lines[$index] !== '') break;
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if($bold) $escaped = '<b>'.$escaped.'</b>';
            $plainLines[] = $line;
            $htmlLines[] = $escaped;
        }
        $html = implode("\n", $htmlLines);
        $plain = mb_substr(implode("\n", $plainLines), 0, self::MAX_LENGTH, 'UTF-8');
        return ['html'=>$html, 'plain'=>$plain];
    }

    private function renderAiText($brief)
    {
        $plainLines = [];
        $htmlLines = [];
        foreach(preg_split('/\r?\n/u', $brief) as $index=>$line){
            $line = $this->bmpText(trim($line));
            $bold = $index === 0 || preg_match('/^(总体|结论|重点|风险|建议|回调|投诉)[：:]/u', $line);
            $prefix = $htmlLines ? "\n" : '';
            $available = self::MAX_LENGTH - mb_strlen(implode("\n",$htmlLines),'UTF-8') - mb_strlen($prefix,'UTF-8') - ($bold?7:0);
            if($available <= 0) break;
            $line = $this->fitEscaped($line,$available);
            if($line === '' && $index > 0) continue;
            $escaped = htmlspecialchars($line,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if($bold) $escaped = '<b>'.$escaped.'</b>';
            $plainLines[] = $line;
            $htmlLines[] = $escaped;
        }
        return ['html'=>implode("\n",$htmlLines),'plain'=>mb_substr(implode("\n",$plainLines),0,self::MAX_LENGTH,'UTF-8')];
    }

    private function rate($value)
    {
        return $value === null ? '-' : number_format((float)$value, 2, '.', '').'%';
    }

    private function ageMinutes($start, $end)
    {
        $startTime = $start ? strtotime($start) : false;
        $endTime = $end ? strtotime($end) : false;
        return $startTime === false || $endTime === false ? null : max(0, (int)floor(($endTime - $startTime) / 60));
    }

    private function fitEscaped($value, $limit)
    {
        if(mb_strlen(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8') <= $limit) return $value;
        $low = 0;
        $high = mb_strlen($value, 'UTF-8');
        while($low < $high){
            $mid = (int)ceil(($low + $high) / 2);
            $part = mb_substr($value, 0, $mid, 'UTF-8');
            if(mb_strlen(htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8') <= max(0, $limit - 1)) $low = $mid;
            else $high = $mid - 1;
        }
        return $low > 0 ? mb_substr($value, 0, $low, 'UTF-8').'…' : '';
    }

    private function classificationReasonText($reason)
    {
        $messages = [
            'data_incomplete'=>'当前统计窗口快照不完整',
            'baseline_missing'=>'最近7日基线快照不完整',
            'no_traffic'=>'统计窗口没有订单',
            'sample_insufficient'=>'统计窗口订单样本不足',
            'unknown'=>'健康状态原因未确定',
        ];
        return isset($messages[$reason]) ? $messages[$reason] : $messages['unknown'];
    }

    private function bmpText($value)
    {
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string)$value);
    }
}
