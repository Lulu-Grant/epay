<?php
namespace lib\Defense;

class SessionService
{
    const CHALLENGE_TTL = 300;
    const SESSION_TTL = 1200;
    private $store;
    public function __construct(Repository $store){$this->store=$store;}
    public static function paramsHash(array $order)
    {
        $fields=[];
        foreach(['trade_no','uid','out_trade_no','money','name','notify_url','return_url','param'] as $field)$fields[$field]=(string)($order[$field]??'');
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    private static function identity($session, $browser)
    {
        if(!is_string($session) || !preg_match('/^[a-f0-9]{64}$/D',$session) || !is_string($browser) || strlen($browser)<8 || strlen($browser)>1024)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        if(preg_match('#(?:curl/|libcurl/|wget/|python-requests|python-urllib|Go-http-client|HeadlessChrome|PhantomJS|Baiduspider|SemrushBot)#i',$browser))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return [hash('sha256',$session),hash('sha256',$browser)];
    }
    public function issue(array $order, $ip, $session, $browser)
    {
        $ip=ClientIp::normalize($ip);[$sessionHash,$browserHash]=self::identity($session,$browser);
        return $this->store->transaction(function()use($order,$ip,$sessionHash,$browserHash){
            $merchant=$this->store->merchant($order['uid'],true);
            if(!(int)$merchant['defense_mode'])throw new DefenseException('DEFENSE_VERSION_CONFLICT');
            if((new QuotaService($this->store))->blocked($order['uid'],$ip))throw new DefenseException('DEFENSE_IP_BLOCKED');
            $recent=$this->store->row('SELECT COUNT(*) n FROM pre_merchant_defense_challenge WHERE trade_no=:trade AND created_at>:since', [':trade'=>$order['trade_no'],':since'=>$this->store->now()-60]);
            if((int)$recent['n']>=10)throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            $token=bin2hex(random_bytes(32));$now=$this->store->now();
            $this->store->query('INSERT INTO pre_merchant_defense_challenge (digest,trade_no,uid,ip,session_hash,browser_hash,params_hash,revision,created_at,expires_at) VALUES (:digest,:trade,:uid,:ip,:session,:browser,:params,:revision,:now,:expires)',
                [':digest'=>hash('sha256',$token),':trade'=>$order['trade_no'],':uid'=>$order['uid'],':ip'=>$ip,':session'=>$sessionHash,':browser'=>$browserHash,':params'=>self::paramsHash($order),':revision'=>$merchant['defense_revision'],':now'=>$now,':expires'=>$now+self::CHALLENGE_TTL]);
            return ['challenge'=>$token,'expires_in'=>self::CHALLENGE_TTL,'work_prefix'=>'000'];
        });
    }
    public function consume(array $order, $ip, $session, $browser, $token, $answer)
    {
        $ip=ClientIp::normalize($ip);[$sessionHash,$browserHash]=self::identity($session,$browser);
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!is_string($answer)||!preg_match('/^[0-9]{1,8}$/D',$answer)
            || !str_starts_with(hash('sha256',$token.':'.$answer),'000'))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $this->store->transaction(function()use($order,$ip,$sessionHash,$browserHash,$token){
            $merchant=$this->store->merchant($order['uid'],true);
            $challenge=$this->store->row('SELECT * FROM pre_merchant_defense_challenge WHERE digest=:digest FOR UPDATE', [':digest'=>hash('sha256',$token)]);
            $now=$this->store->now();
            if(!$challenge || !(int)$merchant['defense_mode'] || $challenge['consumed_at']!==null || (int)$challenge['expires_at']<=$now
                || $challenge['trade_no']!==$order['trade_no'] || (int)$challenge['uid']!==(int)$order['uid'] || $challenge['ip']!==$ip
                || !hash_equals($challenge['session_hash'],$sessionHash) || !hash_equals($challenge['browser_hash'],$browserHash)
                || !hash_equals($challenge['params_hash'],self::paramsHash($order)) || (int)$challenge['revision']!==(int)$merchant['defense_revision'])throw new DefenseException('DEFENSE_VERIFY_FAILED');
            if((new QuotaService($this->store))->blocked($order['uid'],$ip))throw new DefenseException('DEFENSE_IP_BLOCKED');
            $existing=$this->store->row('SELECT ip FROM pre_merchant_defense_order WHERE trade_no=:trade', [':trade'=>$order['trade_no']]);
            if($existing && $existing['ip']!==$ip)throw new DefenseException('DEFENSE_VERIFY_FAILED');
            $this->store->query('UPDATE pre_merchant_defense_challenge SET consumed_at=:now WHERE digest=:digest AND consumed_at IS NULL', [':now'=>$now,':digest'=>$challenge['digest']]);
            $this->store->query('INSERT INTO pre_merchant_defense_context (trade_no,uid,ip,session_hash,browser_hash,params_hash,revision,verified_at,valid_until) VALUES (:trade,:uid,:ip,:session,:browser,:params,:revision,:now,:until) ON DUPLICATE KEY UPDATE ip=VALUES(ip),session_hash=VALUES(session_hash),browser_hash=VALUES(browser_hash),params_hash=VALUES(params_hash),revision=VALUES(revision),verified_at=VALUES(verified_at),valid_until=VALUES(valid_until)',
                [':trade'=>$order['trade_no'],':uid'=>$order['uid'],':ip'=>$ip,':session'=>$sessionHash,':browser'=>$browserHash,':params'=>$challenge['params_hash'],':revision'=>$merchant['defense_revision'],':now'=>$now,':until'=>$now+self::SESSION_TTL]);
            return true;
        });
    }
    public function context(array $order, $ip, $session, $browser)
    {
        $ip=ClientIp::normalize($ip);[$sessionHash,$browserHash]=self::identity($session,$browser);
        $row=$this->store->row('SELECT * FROM pre_merchant_defense_context WHERE trade_no=:trade', [':trade'=>$order['trade_no']]);
        $merchant=$this->store->merchant($order['uid']);
        if(!$row || (int)$row['uid']!==(int)$order['uid'] || $row['ip']!==$ip || (int)$row['valid_until']<=$this->store->now()
            || !hash_equals($row['session_hash'],$sessionHash) || !hash_equals($row['browser_hash'],$browserHash)
            || !hash_equals($row['params_hash'],self::paramsHash($order)) || (int)$row['revision']!==(int)$merchant['defense_revision'])throw new DefenseException('DEFENSE_BROWSER_REQUIRED');
        return $row;
    }
}
