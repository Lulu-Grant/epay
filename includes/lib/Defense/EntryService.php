<?php
namespace lib\Defense;

class EntryService
{
    private $store;
    public function __construct(Repository $store){$this->store=$store;}
    public function issue(array $order, $type, $reportedIp)
    {
        $token=bin2hex(random_bytes(32));$now=$this->store->now();
        try{$reportedIp=ClientIp::normalize($reportedIp);}catch(DefenseException $e){$reportedIp=null;}
        $this->store->query('INSERT INTO pre_merchant_defense_entry (digest,trade_no,requested_type,reported_ip,created_at,expires_at) VALUES (:digest,:trade,:type,:ip,:now,:expires)',
            [':digest'=>hash('sha256',$token),':trade'=>$order['trade_no'],':type'=>(int)$type,':ip'=>$reportedIp,':now'=>$now,':expires'=>$now+7200]);
        return $token;
    }
    public function verify($trade, $token)
    {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $row=$this->store->row('SELECT * FROM pre_merchant_defense_entry WHERE digest=:digest AND trade_no=:trade', [':digest'=>hash('sha256',$token),':trade'=>$trade]);
        if(!$row || (int)$row['expires_at']<=$this->store->now())throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $row;
    }
}
