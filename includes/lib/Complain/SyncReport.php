<?php
namespace lib\Complain;

/** Per-run accounting. Diagnostics contain no request bodies, credentials or exception messages. */
class SyncReport
{
    private array $counts = ['fetched'=>0, 'inserted'=>0, 'updated'=>0, 'unchanged'=>0,
        'skipped_unmatched'=>0, 'failed'=>0, 'auto_reply_failed'=>0, 'notification_failed'=>0];
    private string $reference;
    private array $channel;

    public function __construct(array $channel)
    {
        $this->channel = $channel;
        $this->reference = 'cs'.bin2hex(random_bytes(8));
    }

    public function fetched(): int { return ++$this->counts['fetched']; }

    public function saved(int $result): void
    {
        $key = [1=>'inserted', 2=>'updated', 3=>'skipped_unmatched'][$result] ?? 'unchanged';
        ++$this->counts[$key];
    }

    public function warning(string $action, \Throwable $error, int $complaintId, float $started): void
    {
        $key = $action === 'auto_reply' ? 'auto_reply_failed' : 'notification_failed';
        ++$this->counts[$key];
        $this->log($action, $error, $complaintId, $started);
    }

    public function failure(string $kind, string $stage, ?\Throwable $error = null, float $started = 0): array
    {
        ++$this->counts['failed'];
        $this->log($stage, $error, 0, $started);
        return $this->result(false, $kind);
    }

    public function success(): array { return $this->result(true); }

    private function result(bool $complete, ?string $error = null): array
    {
        $c = $this->counts;
        $warnings = $c['auto_reply_failed'] + $c['notification_failed'];
        $message = ($complete ? '本次投诉同步完成' : '本次投诉同步未完成')
            .'：新增 '.$c['inserted'].' 条、更新 '.$c['updated'].' 条、未变化 '.$c['unchanged']
            .' 条、未匹配订单 '.$c['skipped_unmatched'].' 条';
        if($c['auto_reply_failed']) $message .= '；自动回复未确认成功 '.$c['auto_reply_failed'].' 条，请核对上游结果后处理';
        if($c['notification_failed']) $message .= '；通知失败 '.$c['notification_failed'].' 条';
        if(!$complete) $message .= '；'.(['QUERY_FAILED'=>'上游查询失败', 'INVALID_RESPONSE'=>'上游响应格式异常',
            'SAVE_FAILED'=>'投诉保存失败', 'ACTION_FAILED'=>'订单自动处理异常'][$error] ?? '同步异常');
        if($warnings || !$complete) $message .= '；诊断编号：'.$this->reference;
        $result = ['code'=>$complete ? 0 : -1, 'sync_complete'=>$complete, 'warning'=>$warnings > 0,
            'partial'=>!$complete && ($c['inserted'] + $c['updated'] > 0), 'counts'=>$c, 'msg'=>$message];
        if($warnings || !$complete) $result['reference'] = $this->reference;
        if($error !== null) $result['error'] = $error;
        return $result;
    }

    private function log(string $stage, ?\Throwable $error, int $complaintId, float $started): void
    {
        $entry = ['reference'=>$this->reference, 'channel'=>(int)($this->channel['id'] ?? 0),
            'subchannel'=>(int)($this->channel['subid'] ?? 0), 'source'=>1,
            'stage'=>$stage, 'complaint_id'=>$complaintId,
            'exception'=>$error ? get_class($error) : null,
            'elapsed_ms'=>$started > 0 ? max(0, (int)((microtime(true)-$started)*1000)) : null];
        // Only structured SDK error codes; never parse the human-readable exception message.
        if($error instanceof \Alipay\Aop\AlipayResponseException){
            foreach(['upstream_code'=>$error->getRetCode(), 'upstream_sub_code'=>$error->getErrCode()] as $key=>$value){
                if(is_scalar($value) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string)$value)) $entry[$key] = (string)$value;
            }
        }
        error_log('Complaint sync diagnostic '.json_encode($entry, JSON_UNESCAPED_SLASHES));
    }
}
