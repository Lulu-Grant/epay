<?php
namespace lib\Defense;

/** Deliberately limited to official Alipay precreate; no WAP, JSAPI, subchannels or preauthorization. */
class AlipayQrAdapter
{
    private $service;
    public function __construct(array $channel, array $conf, array $order, $service = null)
    {
        if(!PolicyService::channelSupported($channel))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
        if($service){$this->service=$service;return;}
        $previous=$GLOBALS['channel']??null;
        try{
            $GLOBALS['channel']=$channel;
            $config=require dirname(__DIR__,3).'/plugins/alipay/inc/config.php';
            $config['notify_url']=rtrim((string)$conf['localurl'],'/').'/pay/notify/'.$order['trade_no'].'/';
            $this->service=new \Alipay\AlipayTradeService($config);
        }finally{$GLOBALS['channel']=$previous;}
    }
    public function pay(array $order, $name, $ip)
    {
        $result=$this->service->qrPay(['out_trade_no'=>$order['trade_no'],'total_amount'=>$order['realmoney'],'subject'=>$name,
            'timeout_express'=>'30m','business_params'=>['mc_create_trade_ip'=>$ip]]);
        $url=$result['qr_code']??null;
        if(($result['out_trade_no']??'')!==$order['trade_no'] || !is_string($url) || strlen($url)>4096
            || preg_match('/[\x00-\x20\x7f\x22\x27<>\\\\]/',$url) || !preg_match('#^https://qr\.alipay\.com/[A-Za-z0-9_/?=&%.-]+$#D',$url))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        return ['type'=>'qrcode','page'=>'alipay_qrcode','url'=>$url];
    }
    public static function cents($amount)
    {
        if(!is_scalar($amount)||!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D',(string)$amount,$m))return null;
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public function query(array $order)
    {
        $result=$this->service->query(null,$order['trade_no']);
        if(($result['out_trade_no']??'')!==$order['trade_no'] || self::cents($result['total_amount']??null)===null
            || self::cents($result['total_amount'])!==self::cents($order['realmoney']))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        $state=$result['trade_status']??'';
        if(!in_array($state,['WAIT_BUYER_PAY','TRADE_SUCCESS','TRADE_FINISHED','TRADE_CLOSED'],true))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        if(in_array($state,['TRADE_SUCCESS','TRADE_FINISHED'],true) && (!is_string($result['trade_no']??null)||!preg_match('/^[0-9]{16,64}$/D',$result['trade_no'])))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        unset($result['_defense_closed_unpaid']);
        return $result;
    }
    public function close(array $order)
    {
        $closed=$this->service->close(['out_trade_no'=>$order['trade_no']]);
        if(($closed['out_trade_no']??'')!==$order['trade_no'] || (string)($closed['code']??'')!=='10000')throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        // Only a separately verified terminal query can release a payment reservation.
        $result=$this->query($order);
        if($result['trade_status']==='TRADE_CLOSED')$result['_defense_closed_unpaid']=true;
        return $result;
    }
}
