<?php
namespace lib\Defense;

/** All SQL passes through the existing prefix-aware database wrapper. */
class Repository
{
    private $db;
    private $clock;
    public function __construct($db, $clock = null){$this->db = $db;$this->clock = $clock;}
    public function query($sql, array $params = [])
    {
        try{$stmt = $this->db->query($sql, $params);}catch(\Throwable $e){throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');}
        if($stmt === false || !is_object($stmt))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        return $stmt;
    }
    public function row($sql, array $params = []){return $this->query($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: null;}
    public function rows($sql, array $params = []){return $this->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);}
    public function now(){return $this->clock ? (int)call_user_func($this->clock) : (int)$this->row('SELECT UNIX_TIMESTAMP() ts')['ts'];}
    public static function day($timestamp){return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');}
    public function transaction(callable $callback)
    {
        if($this->db->inTransaction())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        try{
            if(!$this->db->beginTransaction())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            $result = $callback($this);
            if(!$this->db->commit())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            return $result;
        }catch(\Throwable $e){
            try{if($this->db->inTransaction())$this->db->rollBack();}catch(\Throwable $ignored){}
            if($e instanceof DefenseException)throw $e;
            throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        }
    }
    public function merchant($uid, $lock = false)
    {
        $row = $this->row('SELECT uid,gid,defense_mode,defense_enabled_at,defense_revision FROM pre_user WHERE uid=:uid'.($lock ? ' FOR UPDATE' : ''), [':uid'=>(int)$uid]);
        if(!$row)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $row;
    }
    public function audit($uid, $trade, $action, $reason, $actor = '')
    {
        $this->query('INSERT INTO pre_merchant_defense_audit (uid,trade_no,action,reason,actor,created_at) VALUES (:uid,:trade,:action,:reason,:actor,:now)',
            [':uid'=>(int)$uid,':trade'=>(string)$trade,':action'=>$action,':reason'=>$reason,':actor'=>substr((string)$actor,0,80),':now'=>$this->now()]);
    }
    public function denial($uid,$trade,$reason)
    {
        $now=$this->now();
        $this->query("INSERT INTO pre_merchant_defense_audit (uid,trade_no,action,reason,actor,created_at) SELECT :uid,:trade,'payment_denied',:reason,'',:now WHERE NOT EXISTS (SELECT 1 FROM pre_merchant_defense_audit WHERE uid=:check_uid AND trade_no=:check_trade AND action='payment_denied' AND reason=:check_reason AND created_at>:since)",
            [':uid'=>$uid,':trade'=>$trade,':reason'=>$reason,':now'=>$now,':check_uid'=>$uid,':check_trade'=>$trade,':check_reason'=>$reason,':since'=>$now-60]);
    }
    public function noteOnce($uid,$trade,$action,$reason)
    {
        $this->query('INSERT INTO pre_merchant_defense_audit (uid,trade_no,action,reason,actor,created_at) SELECT :uid,:trade,:action,:reason,\'\',:now WHERE NOT EXISTS (SELECT 1 FROM pre_merchant_defense_audit WHERE uid=:check_uid AND trade_no=:check_trade AND action=:check_action AND reason=:check_reason)',
            [':uid'=>$uid,':trade'=>$trade,':action'=>$action,':reason'=>$reason,':now'=>$this->now(),':check_uid'=>$uid,':check_trade'=>$trade,':check_action'=>$action,':check_reason'=>$reason]);
    }
}
