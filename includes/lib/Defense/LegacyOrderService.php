<?php
namespace lib\Defense;

/** Read-only planning by default. Retirement never changes the financial order or invents an IP. */
class LegacyOrderService
{
    private $store;
    public function __construct(Repository $store){$this->store=$store;}
    public function installed()
    {
        $row=$this->store->row("SELECT v FROM pre_config WHERE k='merchant_defense_legacy_version'");
        return ($row['v']??'')==='1';
    }
    public function predicate()
    {
        $closed=$this->installed()?" AND NOT EXISTS (SELECT 1 FROM pre_merchant_defense_legacy l WHERE l.trade_no=o.trade_no AND l.uid=o.uid AND l.channel=o.channel AND l.amount_cents=ROUND(o.realmoney*100) AND l.state='closed_unpaid')":'';
        return "((o.status IN (0,4) AND (o.channel>0 OR o.ext IS NOT NULL OR o.payurl IS NOT NULL)".$closed.") OR (o.date=:day AND o.status IN (1,2,3))) AND NOT EXISTS (SELECT 1 FROM pre_merchant_defense_order d WHERE d.trade_no=o.trade_no AND d.unsafe_exposure=0 AND d.channel=o.channel)";
    }
    public function summary($uid)
    {
        $row=$this->store->row('SELECT COALESCE(SUM(o.status IN (0,4)),0) issued_unpaid,COALESCE(SUM(o.status IN (1,2,3)),0) today_success FROM pre_order o WHERE o.uid=:uid AND '.$this->predicate(),[':uid'=>(int)$uid,':day'=>Repository::day($this->store->now())]);
        return ['issued_unpaid'=>(int)$row['issued_unpaid'],'today_success'=>(int)$row['today_success'],'takeover_installed'=>$this->installed()];
    }
    public static function fingerprint(array $order)
    {
        $values=[];foreach(['trade_no','uid','channel','subchannel','realmoney','status','date','endtime','payurl','ext'] as $key)$values[$key]=$order[$key]??null;
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public function snapshot(array $order)
    {
        // Bind the review to the same receiving account configuration, without returning credentials.
        $binding=$this->store->row('SELECT c.plugin,c.type,c.apptype,c.mode,c.config,u.channelinfo FROM pre_channel c JOIN pre_user u ON u.uid=:uid WHERE c.id=:channel',
            [':uid'=>$order['uid'],':channel'=>$order['channel']]);
        return hash('sha256',self::fingerprint($order).json_encode($binding,JSON_THROW_ON_ERROR));
    }
    public function page($uid,$after='',$limit=50)
    {
        if(!is_string($after)||($after!==''&&!preg_match('/^[A-Za-z0-9]{1,32}$/D',$after)))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $this->store->merchant($uid);$limit=max(1,min(100,(int)$limit));
        $rows=$this->store->rows('SELECT o.* FROM pre_order o WHERE o.uid=:uid AND o.trade_no>:after AND '.$this->predicate().' ORDER BY o.trade_no LIMIT '.$limit,
            [':uid'=>(int)$uid,':after'=>$after,':day'=>Repository::day($this->store->now())]);
        $items=[];
        foreach($rows as $order){
            $channel=$this->store->row('SELECT id,plugin,type,apptype,mode FROM pre_channel WHERE id=:id',[':id'=>$order['channel']]);
            if((int)$order['subchannel']>0||(int)$order['tid']!==0)$channel=null;
            $paid=in_array((int)$order['status'],[1,2,3],true);
            $items[]=['trade_no'=>$order['trade_no'],'status'=>(int)$order['status'],'channel'=>(int)$order['channel'],
                'fingerprint'=>$this->snapshot($order),'action'=>$paid?'wait_next_day_or_manual_accounting':($channel&&PolicyService::channelCanClose($channel)?'query_then_close':($channel&&PolicyService::channelSupported($channel)?'block_local_keep_review':'unsupported_channel_review')),
                'trusted_page_ip_present'=>!empty($order['payer_ip'])];
        }
        return ['summary'=>$this->summary($uid),'orders'=>$items,'next_after'=>count($rows)===$limit?end($rows)['trade_no']:null];
    }
    public function retired($trade)
    {
        return $this->installed()&&(bool)$this->store->row('SELECT trade_no FROM pre_merchant_defense_legacy WHERE trade_no=:trade',[':trade'=>$trade]);
    }
    public function retire($uid,$trade,$fingerprint,$actor,callable $adapterFactory,$localOnly=false)
    {
        if(!$this->installed()||!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)
            ||!is_string($trade)||!preg_match('/^[A-Za-z0-9]{1,32}$/D',$trade))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        // Fence new plugin handoffs under the same merchant lock used by activation and legacy handoffs.
        $order=$this->store->transaction(function()use($uid,$trade,$fingerprint,$actor,$localOnly){
            $merchant=$this->store->merchant($uid,true);
            if((int)$merchant['defense_mode'])throw new DefenseException('DEFENSE_VERSION_CONFLICT','接管旧链接前须保持本商户防御关闭');
            $order=$this->store->row('SELECT * FROM pre_order WHERE trade_no=:trade AND uid=:uid FOR UPDATE',[':trade'=>$trade,':uid'=>(int)$uid]);
            if(!$order||!hash_equals($this->snapshot($order),$fingerprint)||!in_array((int)$order['status'],[0,4],true)
                ||(int)$order['subchannel']>0||(int)$order['tid']!==0||AlipayQrAdapter::cents($order['realmoney'])===null||AlipayQrAdapter::cents($order['realmoney'])<=0)throw new DefenseException('DEFENSE_VERIFY_FAILED');
            if($this->store->row('SELECT trade_no FROM pre_merchant_defense_order WHERE trade_no=:trade',[':trade'=>$trade]))throw new DefenseException('DEFENSE_VERIFY_FAILED','已纳管订单应由补偿任务处理');
            $channel=$this->store->row('SELECT id,plugin,type,apptype,mode FROM pre_channel WHERE id=:id',[':id'=>$order['channel']]);
            if(!$channel||!PolicyService::channelSupported($channel)||(!$localOnly&&!PolicyService::channelCanClose($channel)))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
            $this->store->query("INSERT IGNORE INTO pre_merchant_defense_legacy (trade_no,uid,channel,amount_cents,fingerprint,state,created_at,updated_at) VALUES (:trade,:uid,:channel,:amount,:fingerprint,'closing',:now,:updated)",
                [':trade'=>$trade,':uid'=>(int)$uid,':channel'=>$order['channel'],':amount'=>AlipayQrAdapter::cents($order['realmoney']),':fingerprint'=>$fingerprint,':now'=>$this->store->now(),':updated'=>$this->store->now()]);
            $old=$this->store->row('SELECT * FROM pre_merchant_defense_legacy WHERE trade_no=:trade FOR UPDATE',[':trade'=>$trade]);
            if(!hash_equals($old['fingerprint'],$fingerprint))throw new DefenseException('DEFENSE_VERSION_CONFLICT');
            $order['_legacy_already_closed']=$old['state']==='closed_unpaid';
            if($localOnly&&!$order['_legacy_already_closed']){
                $this->store->query("UPDATE pre_merchant_defense_legacy SET state='local_blocked',updated_at=:now WHERE trade_no=:trade",[':now'=>$this->store->now(),':trade'=>$trade]);
                $this->store->audit($uid,$trade,'legacy_local_block','external_link_unresolved_keep_blocker',$actor);
            }
            $this->store->audit($uid,$trade,'legacy_retire','handoff_fenced',$actor);return $order;
        });
        if($order['_legacy_already_closed'])return ['state'=>'closed_unpaid'];
        if($localOnly)return ['state'=>'local_blocked','upstream_closed'=>false,'quota_released'=>false];
        try{
            // No database transaction is held during upstream requests. Factory supplies the real adapter.
            $adapter=call_user_func($adapterFactory,$order);$result=$adapter->query($order);
            if(($result['trade_status']??'')==='WAIT_BUYER_PAY')$result=$adapter->close($order);
            if(($result['trade_status']??'')!=='TRADE_CLOSED'||($result['_defense_closed_unpaid']??false)!==true){
                $this->store->noteOnce($uid,$trade,'legacy_review','payment_or_closure_requires_review');return ['state'=>'review'];
            }
            return $this->store->transaction(function()use($uid,$trade,$fingerprint,$actor){
                $this->store->merchant($uid,true);
                $current=$this->store->row('SELECT * FROM pre_order WHERE trade_no=:trade AND uid=:uid FOR UPDATE',[':trade'=>$trade,':uid'=>(int)$uid]);
                if(!$current||!hash_equals($this->snapshot($current),$fingerprint))throw new DefenseException('DEFENSE_VERSION_CONFLICT');
                $this->store->query("UPDATE pre_merchant_defense_legacy SET state='closed_unpaid',updated_at=:now WHERE trade_no=:trade AND fingerprint=:fingerprint",[':now'=>$this->store->now(),':trade'=>$trade,':fingerprint'=>$fingerprint]);
                $this->store->audit($uid,$trade,'legacy_closed','verified_unpaid_close_ack_and_query',$actor);return ['state'=>'closed_unpaid'];
            });
        }catch(\Throwable $e){
            $this->store->noteOnce($uid,$trade,'legacy_review','upstream_unknown_keep_fence');return ['state'=>'review'];
        }
    }
}
