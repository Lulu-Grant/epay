<?php
namespace lib\Defense;

/** Shared entry points. Nothing is enabled until the additive migration marker exists. */
class Runtime
{
    const COOKIE = 'merchant_defense_browser';
    public static function store(){global $DB;return new Repository($DB);}
    public static function installed()
    {
        $row=self::store()->row("SELECT v FROM pre_config WHERE k='merchant_defense_version'");
        return $row && $row['v']==='1';
    }
    public static function enabled($uid){return self::installed() && (int)self::store()->merchant($uid)['defense_mode']===1;}
    public static function ip()
    {
        global $conf;
        if(!(new PolicyService(self::store(),$conf))->networkReady())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        if(($conf['merchant_defense_ip_mode']??'')==='esa'){
            $observed=\lib\PayerIp::resolve($_SERVER,$conf);
            if(!$observed)throw new DefenseException('DEFENSE_VERIFY_FAILED');
            return $observed['ip'];
        }
        return ClientIp::resolve($_SERVER,($conf['merchant_defense_ip_mode']??'')==='trusted_proxy'?ClientIp::ranges($conf):[]);
    }
    public static function initial($trade, $type, $reportedIp)
    {
        global $conf;
        $store=self::store();$order=$store->row('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade'=>$trade]);
        if(!$order || !self::installed())return null;
        $active=$store->transaction(function()use($store,$order){
            $merchant=$store->merchant($order['uid'],true);
            if(!(int)$merchant['defense_mode'])return false;
            $legacy=new LegacyOrderService($store);
            if($legacy->retired($order['trade_no']))throw new DefenseException('DEFENSE_ORDER_RETIRED');
            $legacy->assertIssuable($order,$merchant);return true;
        });
        if(!$active)return null;
        $payType=$type===''?null:$store->row('SELECT id FROM pre_type WHERE name=:name AND status=1', [':name'=>$type]);
        $access=(new EntryService($store))->issue($order,(int)($payType['id']??0),$reportedIp);
        $base=rtrim((string)($conf['payurl']??$conf['localurl']??''),'/');
        if(!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]+)?$#D',$base))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        // Fragments stay out of HTTP request URIs and access logs. JS submits this capability in a POST body.
        // The existing top-level redirect validator deliberately rejects fragments. Use the normal
        // JSON-encoded browser jump; this is a verification URL, never an upstream payment link.
        return ['type'=>'jump','url'=>$base.'/defense.php?trade_no='.rawurlencode($trade).'#'.$access];
    }
    public static function order($trade)
    {
        if(!is_string($trade)||!preg_match('/^(?:LP)?[0-9]{19}$/D',$trade))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $row=self::store()->row('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade'=>$trade]);
        if(!$row)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        return $row;
    }
    public static function beforePlugin($plugin, $method, $trade)
    {
        global $channel,$conf,$ordername,$clientip;
        if(!$trade || !self::installed())return null;
        $order=self::order($trade);
        // preauthnotify creates a charge, so it is deliberately not a confirmation-only exemption.
        $confirmation=in_array($method,['notify','return','ok','refundnotify','refund_notify'],true);
        $store=self::store();
        if($confirmation){$merchant=$store->merchant($order['uid']);}
        else{
            // Serialize handoff with activation. A pre-activation handoff may already be in flight;
            // its callback remains valid, but no new capability is issued for that old order.
            $merchant=$store->transaction(function()use($store,$order,$trade){
                $merchant=$store->merchant($order['uid'],true);
                if((new LegacyOrderService($store))->retired($trade))throw new DefenseException('DEFENSE_ORDER_RETIRED');
                (new LegacyOrderService($store))->assertIssuable($order,$merchant);
                if(!(int)$merchant['defense_mode'])$store->query("UPDATE pre_merchant_defense_order SET state='unmanaged',unsafe_exposure=1,pay_result=NULL WHERE trade_no=:trade AND state<>'paid'",[':trade'=>$trade]);
                return $merchant;
            });
        }
        if(!(int)$merchant['defense_mode'])return null;
        // These methods only authenticate/confirm or display existing order status for the supported adapter.
        if($plugin==='alipay' && $method==='return')return ['type'=>'page','page'=>'return'];
        if($confirmation)return null;
        try{
            $methods=$plugin==='weilaile'?['submit','mapi','alipay','qrcode']:['submit','mapi','qrcode','submitwap'];
            if(!in_array($method,$methods,true) || !PolicyService::channelSupported($channel)
                || $plugin!==($channel['plugin']??'') || (int)$order['tid']!==0)throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
            $store=self::store();$ip=self::ip();
            $context=(new SessionService($store))->context($order,$ip,$_COOKIE[self::COOKIE]??'',$_SERVER['HTTP_USER_AGENT']??'');
            $context['account_scope']=AdapterFactory::accountScope($channel);
            $quota=new QuotaService($store);$reservation=$quota->reserve($order,$context);
            if($reservation['pay_result']){
                $result=json_decode($reservation['pay_result'],true,8,JSON_THROW_ON_ERROR);
                if(!AdapterFactory::validResult($result,$order,$channel))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
                return $result;
            }
            $adapter=AdapterFactory::make($channel,$conf,$order);
            if(!$quota->attempt($trade))throw new DefenseException('DEFENSE_PENDING_LIMIT');
            $clientip=$ip;
            $result=$adapter->pay($order,!empty($ordername)?$ordername:$order['name'],$ip);
            $quota->ready($trade,$result);
            return $result;
        }catch(\Throwable $e){
            $error=$e instanceof DefenseException?$e:new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            try{self::store()->denial($order['uid'],$trade,$error->reason);}catch(\Throwable $ignored){}
            return ['type'=>'error','msg'=>$error->getMessage(),'reason'=>$error->reason];
        }
    }
    public static function paid(array $order)
    {
        try{
            if(self::installed())(new QuotaService(self::store()))->paid($order['trade_no'], !empty($order['endtime'])?strtotime($order['endtime']):null);
        }catch(\Throwable $e){
            // Reservation remains held; the worker reconciles against persisted payment confirmation.
            error_log('[merchant-defense] payment_accounting_retry trade_no='.preg_replace('/[^A-Za-z0-9]/','',(string)($order['trade_no']??'')));
        }
    }
    public static function complaint(array $row, ?Repository $store = null)
    {
        $store=$store??self::store();
        $reservation=$store->row('SELECT account_scope FROM pre_merchant_defense_order WHERE trade_no=:trade',[':trade'=>$row['trade_no']]);
        if(!empty($reservation['account_scope']))return (new ComplaintService($store))->observe($row,$reservation['account_scope']);
        $channel=$store->row('SELECT plugin,config FROM pre_channel WHERE id=:id', [':id'=>(int)$row['channel']]);
        if(!$channel)return false;
        $config=json_decode($channel['config']??'',true);
        $account=$config['appid']??'';
        if(!is_scalar($account)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$account))return false;
        return (new ComplaintService($store))->observe($row,$channel['plugin'].':'.(string)$account);
    }
    public static function complaintsForTrade($trade)
    {
        try{
            if(!self::installed())return;
            foreach(self::store()->rows('SELECT * FROM pre_complain WHERE trade_no=:trade', [':trade'=>$trade]) as $row)self::complaint($row);
        }catch(\Throwable $e){error_log('[merchant-defense] complaint_reconciliation_required');}
    }
}
