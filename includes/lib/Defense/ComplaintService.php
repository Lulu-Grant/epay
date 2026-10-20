<?php
namespace lib\Defense;

class ComplaintService
{
    const BAN_SECONDS = 172800;
    private $store;
    public function __construct(Repository $store){$this->store=$store;}

    /** Only trusted adapter persistence/reconciliation supplies complaints; never a public request. */
    public function observe(array $complaint, $accountScope)
    {
        if(!is_string($accountScope) || $accountScope==='' || !is_scalar($complaint['thirdid']??null) || (string)$complaint['thirdid']==='' || strlen((string)$complaint['thirdid'])>150 || empty($complaint['trade_no']))return false;
        $event=hash('sha256',json_encode([$accountScope,(int)($complaint['source']??0),(string)$complaint['thirdid']],JSON_THROW_ON_ERROR));
        return $this->store->transaction(function()use($complaint,$event){
            if((int)($complaint['uid']??0)<=0)return false;
            $merchant=$this->store->merchant($complaint['uid'],true);
            if(!(int)$merchant['defense_mode'])return false;
            $context=$this->store->row('SELECT uid,ip FROM pre_merchant_defense_context WHERE trade_no=:trade', [':trade'=>$complaint['trade_no']]);
            if(!$context || (int)$context['uid']!==(int)$complaint['uid']){
                $this->store->noteOnce($complaint['uid'],$complaint['trade_no'],'complaint_skipped','missing_or_mismatched_trusted_ip');
                return false;
            }
            $occurred=strtotime((string)($complaint['addtime']??''));
            if(!$occurred || $occurred < (int)$merchant['defense_enabled_at'] || $occurred>$this->store->now()+60)return false;
            // Persist immutable first observation before optional replies, refunds or other effects.
            $now=is_string($complaint['defense_received_at']??null) ? strtotime($complaint['defense_received_at']) : false;
            if(!$now || $now < (int)$merchant['defense_enabled_at'] || $now>$this->store->now()+60)return false;
            $stmt=$this->store->query('INSERT IGNORE INTO pre_merchant_defense_complaint (event_key,complaint_id,trade_no,uid,ip,observed_at,expires_at) VALUES (:event,:id,:trade,:uid,:ip,:now,:expires)',
                [':event'=>$event,':id'=>substr((string)$complaint['thirdid'],0,150),':trade'=>$complaint['trade_no'],':uid'=>$context['uid'],':ip'=>$context['ip'],':now'=>$now,':expires'=>$now+self::BAN_SECONDS]);
            if($stmt->rowCount()===1){$this->store->audit($context['uid'],$complaint['trade_no'],'complaint_ban','new_complaint_48h');return true;}
            return false;
        });
    }
    public function release($uid, $event, $actor, $reason)
    {
        if(!is_string($event)||!preg_match('/^[a-f0-9]{64}$/D',$event)||trim((string)$reason)===''||strlen((string)$reason)>255)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $this->store->transaction(function()use($uid,$event,$actor,$reason){
            $this->store->merchant($uid,true);
            $row=$this->store->row('SELECT * FROM pre_merchant_defense_complaint WHERE event_key=:event AND uid=:uid FOR UPDATE', [':event'=>$event,':uid'=>$uid]);
            if(!$row)throw new DefenseException('DEFENSE_VERIFY_FAILED');
            $stmt=$this->store->query('UPDATE pre_merchant_defense_complaint SET released_at=:now,released_by=:actor,release_reason=:reason WHERE event_key=:event AND released_at IS NULL', [':now'=>$this->store->now(),':actor'=>substr((string)$actor,0,80),':reason'=>trim($reason),':event'=>$event]);
            if($stmt->rowCount())$this->store->audit($uid,$row['trade_no'],'manual_release','complaint_ban_released',$actor);
            return true;
        });
    }
}
