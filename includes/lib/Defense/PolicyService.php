<?php
namespace lib\Defense;

class PolicyService
{
    private $store;
    private $conf;
    public function __construct(Repository $store, array $conf){$this->store=$store;$this->conf=$conf;}
    public static function channelSupported(array $channel)
    {
        $types=$channel['apptype']??'';
        if(!is_array($types))$types=explode(',',(string)$types);
        sort($types);
        return ($channel['plugin']??'')==='alipay' && (int)($channel['type']??0)===1
            && $types===['3'] && (int)($channel['mode']??0)===0 && empty($channel['subid']);
    }
    public function networkReady()
    {
        $mode=$this->conf['merchant_defense_ip_mode']??'';
        if($mode==='trusted_proxy'){
            foreach(ClientIp::ranges($this->conf) as $range){
                $parts=explode('/',$range,2);
                if((isset($parts[1]) && (int)$parts[1]===0) || !ClientIp::contains($parts[0],$range))return false;
            }
        }
        return ($this->conf['merchant_defense_net_verified']??'')==='1'
            && ($mode==='direct' || ($mode==='trusted_proxy' && ClientIp::ranges($this->conf))
                || ($mode==='esa' && \lib\PayerIp::configured($this->conf)));
    }
    public function eligibleChannels(array $merchant)
    {
        $group=$this->store->row('SELECT info FROM pre_group WHERE gid=:gid', [':gid'=>(int)$merchant['gid']]);
        if(empty($group['info']))$group=$this->store->row('SELECT info FROM pre_group WHERE gid=0');
        $map=empty($group['info'])?[]:json_decode($group['info'],true);
        if(!is_array($map))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
        $channels=[];
        foreach($this->store->rows('SELECT id FROM pre_type WHERE status=1') as $type){
            $rule=$map[$type['id']]??null;$choice=$rule===null?-1:(int)($rule['channel']??0);
            if($choice===0)continue;
            $params=[':type'=>$type['id']];$sql='SELECT id,plugin,type,apptype,mode FROM pre_channel WHERE type=:type AND status=1';
            if($choice===-2)throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED','商户子通道尚未支持严格防御');
            if($choice>0 && ($rule['type']??'')==='roll'){
                $roll=$this->store->row('SELECT info,status FROM pre_roll WHERE id=:id', [':id'=>$choice]);
                if(!$roll || !(int)$roll['status'])continue;
                try{$parts=\lib\RollConfig::parse($roll['info']);}catch(\Throwable $e){throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');}
                $ids=array_map(static function($row){return (int)$row['channel'];},$parts);
                if(!$ids)throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
                $sql.=' AND id IN ('.implode(',',$ids).')';
            }elseif($choice>0){$sql.=' AND id=:id';$params[':id']=$choice;}
            foreach($this->store->rows($sql,$params) as $channel)$channels[$channel['id']]=$channel;
        }
        return array_values($channels);
    }
    public function blockers(array $merchant)
    {
        $reasons=[];
        if(!$this->networkReady())$reasons[]='可信代理或直连 IP 模式尚未完成核验';
        $unique=[];
        foreach($this->store->rows('SHOW INDEX FROM pre_order') as $index){
            if((int)$index['Non_unique']===0)$unique[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
        }
        $orderKey=false;
        foreach($unique as $columns){sort($columns);if($columns===['out_trade_no','uid'])$orderKey=true;}
        if(!$orderKey)$reasons[]='订单商户单号唯一索引尚未完成迁移';
        try{
            if(!$this->store->row("SHOW COLUMNS FROM pre_complain LIKE 'defense_received_at'"))$reasons[]='投诉接收时间字段尚未迁移';
        }catch(DefenseException $e){$reasons[]='投诉模块尚未安装或迁移未完成';}
        try{
            $channels=$this->eligibleChannels($merchant);
            if(!$channels)$reasons[]='没有可验证的支付通道';
            foreach($channels as $channel)if(!self::channelSupported($channel))$reasons[]='通道 '.(int)$channel['id'].' 未支持严格查单关单流程（目前仅支持支付宝官方单一当面付扫码）';
        }catch(DefenseException $e){$reasons[]=$e->getMessage();}
        // A locking read sees handoffs committed after earlier capability reads in this transaction.
        $legacy=$this->store->row("SELECT o.trade_no FROM pre_order o WHERE o.uid=:uid AND ((o.status IN (0,4) AND (o.channel>0 OR o.ext IS NOT NULL OR o.payurl IS NOT NULL)) OR (o.date=:day AND o.status IN (1,2,3))) AND NOT EXISTS (SELECT 1 FROM pre_merchant_defense_order d WHERE d.trade_no=o.trade_no AND d.unsafe_exposure=0 AND d.channel=o.channel) LIMIT 1 FOR UPDATE", [':uid'=>$merchant['uid'],':day'=>Repository::day($this->store->now())]);
        if($legacy)$reasons[]='存在未纳管付款链接或当日成功记录，需先完成核对和安全接管';
        return array_values(array_unique($reasons));
    }
    public function set($uid, $target, $revision, $actor)
    {
        if(!in_array($target,[0,1],true)||!is_int($revision)||$revision<0)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $this->store->transaction(function()use($uid,$target,$revision,$actor){
            $merchant=$this->store->merchant($uid,true);
            if((int)$merchant['defense_revision']!==$revision)throw new DefenseException('DEFENSE_VERSION_CONFLICT');
            if((int)$merchant['defense_mode']===$target)return $merchant;
            if($target && ($reasons=$this->blockers($merchant)))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED',implode('；',$reasons));
            $this->store->query('UPDATE pre_user SET defense_mode=:mode,defense_revision=defense_revision+1,defense_enabled_at=IF(:enabled=1,:now,defense_enabled_at) WHERE uid=:uid AND defense_revision=:revision',
                [':mode'=>$target,':enabled'=>$target,':now'=>$this->store->now(),':uid'=>$uid,':revision'=>$revision]);
            $this->store->audit($uid,'','switch',$target?'enabled':'disabled',$actor);
            return $this->store->merchant($uid);
        });
    }
}
