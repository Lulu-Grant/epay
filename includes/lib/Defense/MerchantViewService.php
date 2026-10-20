<?php
namespace lib\Defense;

class MerchantViewService
{
    private $store;
    public function __construct(Repository $store){$this->store=$store;}
    /** The controller supplies the authenticated session UID, never an HTTP filter. */
    public function summaryForMerchant($uid)
    {
        $uid=filter_var($uid,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($uid===false)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $merchant=$this->store->merchant($uid);
        $bans=$this->store->row('SELECT COUNT(DISTINCT ip) n FROM pre_merchant_defense_complaint WHERE uid=:uid AND released_at IS NULL AND expires_at>:now',[':uid'=>$uid,':now'=>$this->store->now()]);
        return ['uid'=>$uid,'enabled'=>(int)$merchant['defense_mode']===1,'daily_limit'=>5,'ban_hours'=>48,'active_banned_ips'=>(int)$bans['n']];
    }
}
