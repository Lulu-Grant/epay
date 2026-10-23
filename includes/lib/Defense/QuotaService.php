<?php
namespace lib\Defense;

class QuotaService
{
    private $store;
    public function __construct(Repository $store){$this->store = $store;}
    private function lock($uid, $ip)
    {
        $now = $this->store->now();$day = Repository::day($now);
        $this->store->query('INSERT IGNORE INTO pre_merchant_defense_quota (uid,ip,quota_day,success_count,updated_at) VALUES (:uid,:ip,:day,0,:now)', [':uid'=>$uid,':ip'=>$ip,':day'=>$day,':now'=>$now]);
        $quota = $this->store->row('SELECT * FROM pre_merchant_defense_quota WHERE uid=:uid AND ip=:ip FOR UPDATE', [':uid'=>$uid,':ip'=>$ip]);
        if($quota['quota_day'] !== $day){
            $count = $this->store->row('SELECT COUNT(*) n FROM pre_merchant_defense_order WHERE uid=:uid AND ip=:ip AND success_day=:day', [':uid'=>$uid,':ip'=>$ip,':day'=>$day]);
            $this->store->query('UPDATE pre_merchant_defense_quota SET quota_day=:day,success_count=:n,updated_at=:now WHERE uid=:uid AND ip=:ip', [':day'=>$day,':n'=>$count['n'],':now'=>$now,':uid'=>$uid,':ip'=>$ip]);
            $quota['success_count'] = $count['n'];$quota['quota_day'] = $day;
        }
        return $quota;
    }

    public function blocked($uid, $ip)
    {
        $row = $this->store->row('SELECT MAX(expires_at) expires_at FROM pre_merchant_defense_complaint WHERE uid=:uid AND ip=:ip AND released_at IS NULL AND expires_at>:now', [':uid'=>(int)$uid,':ip'=>ClientIp::normalize($ip),':now'=>$this->store->now()]);
        return (int)($row['expires_at'] ?? 0);
    }

    public function reserve(array $order, array $context)
    {
        $uid = (int)$order['uid'];$ip = ClientIp::normalize($context['ip']);
        $amount=AlipayQrAdapter::cents($order['realmoney']??null);
        if($amount===null||$amount<=0)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $this->store->transaction(function() use ($order,$context,$uid,$ip,$amount){
            // Merchant -> IP quota -> payment reservation is the common mutation lock order.
            $merchant = $this->store->merchant($uid, true);
            if(!(int)$merchant['defense_mode'] || (int)$merchant['defense_revision'] !== (int)$context['revision'])throw new DefenseException('DEFENSE_VERSION_CONFLICT');
            $legacy=new LegacyOrderService($this->store);
            if($legacy->retired($order['trade_no']))throw new DefenseException('DEFENSE_ORDER_RETIRED');
            $legacy->assertIssuable($order,$merchant);
            $quota = $this->lock($uid, $ip);
            if((int)$quota['faulted'])throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            if($this->blocked($uid,$ip))throw new DefenseException('DEFENSE_IP_BLOCKED');
            $old = $this->store->row('SELECT * FROM pre_merchant_defense_order WHERE trade_no=:trade FOR UPDATE', [':trade'=>$order['trade_no']]);
            if($old){
                if((int)$old['uid'] !== $uid || $old['ip'] !== $ip || (int)$old['channel'] !== (int)$order['channel'] || (int)$old['amount_cents']!==$amount
                    || $old['account_scope']!==($context['account_scope']??''))throw new DefenseException('DEFENSE_VERIFY_FAILED');
                if(in_array($old['state'], ['closed','paid'], true))throw new DefenseException('DEFENSE_ORDER_RETIRED');
                return $old;
            }
            $active = $this->store->row("SELECT COUNT(*) n FROM pre_merchant_defense_order WHERE uid=:uid AND ip=:ip AND state IN ('reserved','unknown','ready','unmanaged')", [':uid'=>$uid,':ip'=>$ip]);
            if((int)$quota['success_count'] >= 5)throw new DefenseException('DEFENSE_DAILY_LIMIT');
            if((int)$quota['success_count'] + (int)$active['n'] >= 5)throw new DefenseException('DEFENSE_PENDING_LIMIT');
            $this->store->query("INSERT INTO pre_merchant_defense_order (trade_no,uid,ip,state,channel,account_scope,amount_cents,revision,created_at) VALUES (:trade,:uid,:ip,'reserved',:channel,:scope,:amount,:revision,:now)",
                [':trade'=>$order['trade_no'],':uid'=>$uid,':ip'=>$ip,':channel'=>$order['channel'],':scope'=>$context['account_scope']??'',':amount'=>$amount,':revision'=>$context['revision'],':now'=>$this->store->now()]);
            return $this->store->row('SELECT * FROM pre_merchant_defense_order WHERE trade_no=:trade', [':trade'=>$order['trade_no']]);
        });
    }

    /** Claim once before network IO. An ambiguous attempt is never retried by another request. */
    public function attempt($trade)
    {
        return $this->store->query("UPDATE pre_merchant_defense_order SET state='unknown',attempted_at=:now WHERE trade_no=:trade AND state='reserved' AND attempted_at IS NULL", [':now'=>$this->store->now(),':trade'=>$trade])->rowCount() === 1;
    }

    public function ready($trade, array $result)
    {
        $stmt=$this->store->query("UPDATE pre_merchant_defense_order SET state='ready',pay_result=:result WHERE trade_no=:trade AND state='unknown'", [':result'=>json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),':trade'=>$trade]);
        if($stmt->rowCount()!==1)throw new DefenseException('DEFENSE_ORDER_RETIRED');
    }

    public function releaseUnattempted($trade)
    {
        // Atomic proof that this application never handed this qualification to the upstream adapter.
        return $this->store->query("UPDATE pre_merchant_defense_order SET state='closed',pay_result=NULL WHERE trade_no=:trade AND state='reserved' AND attempted_at IS NULL", [':trade'=>$trade])->rowCount()===1;
    }

    /** Called only after the channel has authenticated a successful payment. Safe on every retry. */
    public function paid($trade, $confirmedAt = null)
    {
        $row = $this->store->row('SELECT uid,ip FROM pre_merchant_defense_order WHERE trade_no=:trade', [':trade'=>$trade]);
        if(!$row)return false;
        return $this->store->transaction(function() use ($trade,$row,$confirmedAt){
            $this->store->merchant($row['uid'],true);
            $quota = $this->lock($row['uid'],$row['ip']);
            $reservation = $this->store->row('SELECT * FROM pre_merchant_defense_order WHERE trade_no=:trade FOR UPDATE', [':trade'=>$trade]);
            if($reservation['success_at'] !== null)return false;
            $current=$this->store->now();
            $now=$confirmedAt===null?$current:(int)$confirmedAt;
            if($now<1 || $now>$current)throw new DefenseException('DEFENSE_VERIFY_FAILED');
            $this->store->query("UPDATE pre_merchant_defense_order SET state='paid',success_at=:now,success_day=:day,pay_result=NULL WHERE trade_no=:trade AND success_at IS NULL", [':now'=>$now,':day'=>Repository::day($now),':trade'=>$trade]);
            if(Repository::day($now)===$quota['quota_day'])$this->store->query('UPDATE pre_merchant_defense_quota SET success_count=success_count+1,updated_at=:now WHERE uid=:uid AND ip=:ip', [':now'=>$current,':uid'=>$row['uid'],':ip'=>$row['ip']]);
            if($reservation['state'] === 'closed' || (int)$quota['success_count'] >= 5){
                $this->store->query('UPDATE pre_merchant_defense_quota SET faulted=1 WHERE uid=:uid AND ip=:ip', [':uid'=>$row['uid'],':ip'=>$row['ip']]);
                $this->store->audit($row['uid'],$trade,'quota_violation','authenticated_payment_after_close_or_over_limit');
            }
            return true;
        });
    }

    /** Caller must possess an authenticated terminal CLOSED result; local timeout is not evidence. */
    public function closed($trade)
    {
        $row = $this->store->row('SELECT uid,ip FROM pre_merchant_defense_order WHERE trade_no=:trade', [':trade'=>$trade]);
        if(!$row)return false;
        return $this->store->transaction(function() use ($trade,$row){
            $this->store->merchant($row['uid'],true);$this->lock($row['uid'],$row['ip']);
            return $this->store->query("UPDATE pre_merchant_defense_order SET state='closed',unsafe_exposure=0,pay_result=NULL,checked_at=:now WHERE trade_no=:trade AND success_at IS NULL AND state IN ('reserved','ready','unknown','unmanaged')", [':now'=>$this->store->now(),':trade'=>$trade])->rowCount() === 1;
        });
    }
}
