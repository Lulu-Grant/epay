<?php
namespace lib\Defense;

/** Official single-mode precreate or WAP. WAP not-found is never evidence of closure. */
class AlipayQrAdapter
{
    private $service;
    private $wap;
    private $appId;
    public function __construct(array $channel, array $conf, array $order, $service = null)
    {
        if(!PolicyService::channelCanClose($channel))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
        $this->wap=($channel['apptype']??'')==='2' || ($channel['apptype']??[])===['2'];
        $this->appId=(string)($channel['appid']??'');
        if($service){$this->service=$service;return;}
        $previous=$GLOBALS['channel']??null;
        try{
            $GLOBALS['channel']=$channel;
            $config=require dirname(__DIR__,3).'/plugins/alipay/inc/config.php';
            $config['notify_url']=rtrim((string)$conf['localurl'],'/').'/pay/notify/'.$order['trade_no'].'/';
            $config['return_url']=rtrim((string)$conf['localurl'],'/').'/pay/return/'.$order['trade_no'].'/';
            if($this->wap)$config['pageMethod']='1';
            $this->service=new \Alipay\AlipayTradeService($config);
        }finally{$GLOBALS['channel']=$previous;}
    }
    public function pay(array $order, $name, $ip)
    {
        if($this->wap){
            $url=$this->service->wapPay(['out_trade_no'=>$order['trade_no'],'total_amount'=>$order['realmoney'],'subject'=>$name,
                'timeout_express'=>'30m','business_params'=>['mc_create_trade_ip'=>$ip]]);
            $result=['type'=>'redirect','url'=>$url];
            if(!self::validResult($result,$order,$this->appId!==''?$this->appId:null))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            return $result;
        }
        $result=$this->service->qrPay(['out_trade_no'=>$order['trade_no'],'total_amount'=>$order['realmoney'],'subject'=>$name,
            'timeout_express'=>'30m','business_params'=>['mc_create_trade_ip'=>$ip]]);
        $url=$result['qr_code']??null;
        if(($result['out_trade_no']??'')!==$order['trade_no'] || !is_string($url) || strlen($url)>4096
            || preg_match('/[\x00-\x20\x7f\x22\x27<>\\\\]/',$url) || !preg_match('#^https://qr\.alipay\.com/[A-Za-z0-9_/?=&%.-]+$#D',$url))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        return ['type'=>'qrcode','page'=>'alipay_qrcode','url'=>$url];
    }
    public static function validResult($result, array $order, $appId = null)
    {
        if(!is_array($result)||!is_string($result['url']??null))return false;
        $url=$result['url'];
        if(($result['type']??'')==='qrcode')return ($result['page']??'')==='alipay_qrcode'
            && strlen($url)<=4096 && (bool)preg_match('#^https://qr\.alipay\.com/[A-Za-z0-9_/?=&%.-]+$#D',$url);
        if(($result['type']??'')!=='redirect'||strlen($url)>7000||preg_match('/[\x00-\x20\x7f]/',$url))return false;
        $p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='openapi.alipay.com'||($p['path']??'')!=='/gateway.do'
            ||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||(isset($p['port'])&&(int)$p['port']!==443))return false;
        parse_str($p['query']??'',$q);
        if($appId!==null&&($q['app_id']??null)!==$appId)return false;
        if(($q['method']??'')!=='alipay.trade.wap.pay'||($q['sign_type']??'')!=='RSA2'||!is_string($q['sign']??null)||$q['sign']===''||!is_string($q['biz_content']??null))return false;
        $biz=json_decode($q['biz_content'],true);
        return is_array($biz)&&($biz['out_trade_no']??'')===$order['trade_no']
            &&self::cents($biz['total_amount']??null)!==null&&self::cents($biz['total_amount'])===self::cents($order['realmoney'])
            &&($biz['product_code']??'')==='QUICK_WAP_WAY'&&($biz['timeout_express']??'')==='30m';
    }
    public static function cents($amount)
    {
        if(!is_scalar($amount)||!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D',(string)$amount,$m))return null;
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public function query(array $order)
    {
        try{$result=$this->service->query(null,$order['trade_no']);}
        catch(\Alipay\Aop\AlipayResponseException $e){
            // A signed H5 link may not have created an upstream transaction yet. This response
            // is not proof that the link can no longer be paid, so keep its qualification held.
            if((string)$e->getRetCode()==='40004' && $e->getErrCode()==='ACQ.TRADE_NOT_EXIST')return ['out_trade_no'=>$order['trade_no'],'trade_status'=>'NOT_CREATED'];
            throw $e;
        }
        if(($result['out_trade_no']??'')!==$order['trade_no'] || self::cents($result['total_amount']??null)===null
            || self::cents($result['total_amount'])!==self::cents($order['realmoney']))throw new DefenseException('DEFENSE_QUERY_MISMATCH');
        $state=$result['trade_status']??'';
        if(!in_array($state,['WAIT_BUYER_PAY','TRADE_SUCCESS','TRADE_FINISHED','TRADE_CLOSED'],true))throw new DefenseException('DEFENSE_QUERY_MISMATCH');
        if(in_array($state,['TRADE_SUCCESS','TRADE_FINISHED'],true) && (!is_string($result['trade_no']??null)||!preg_match('/^[0-9]{16,64}$/D',$result['trade_no'])))throw new DefenseException('DEFENSE_QUERY_MISMATCH');
        unset($result['_defense_closed_unpaid']);
        return $result;
    }
    public function close(array $order)
    {
        $closed=$this->service->close(['out_trade_no'=>$order['trade_no']]);
        if(($closed['out_trade_no']??'')!==$order['trade_no'] || (string)($closed['code']??'')!=='10000')throw new DefenseException('DEFENSE_CLOSE_UNCONFIRMED');
        // Only a separately verified terminal query can release a payment reservation.
        $result=$this->query($order);
        if($result['trade_status']==='TRADE_CLOSED')$result['_defense_closed_unpaid']=true;
        return $result;
    }
}
