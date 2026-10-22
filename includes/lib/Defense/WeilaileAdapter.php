<?php
namespace lib\Defense;

/** Enforce before MAPI; issued links retain capacity until an authenticated payment is confirmed. */
class WeilaileAdapter
{
    private $client;
    private $conf;
    private $scope;
    public function __construct(array $channel,array $conf,$client=null)
    {
        if(PolicyService::channelMode($channel)!=='local_hold')throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
        $this->scope=AdapterFactory::accountScope($channel);$this->conf=$conf;
        if(!class_exists('WeilaileClient',false))require_once dirname(__DIR__,3).'/plugins/weilaile/inc/WeilaileClient.php';
        $this->client=$client??new \WeilaileClient($channel['appid']??'',$channel['appkey']??'');
    }
    public function localHold(){return true;}
    public function pay(array $order,$name,$ip)
    {
        $device='pc';
        foreach(['checkwechat'=>'wechat','checkalipay'=>'alipay','checkmobile'=>'mobile'] as $fn=>$value){if(function_exists($fn)&&$fn()){$device=$value;break;}}
        $response=$this->client->pay(['type'=>'alipay','out_trade_no'=>$order['trade_no'],'money'=>$order['realmoney'],'name'=>$name,
            'notify_url'=>rtrim((string)$this->conf['localurl'],'/').'/pay/notify/'.$order['trade_no'].'/',
            'return_url'=>rtrim((string)$this->conf['localurl'],'/').'/pay/return/'.$order['trade_no'].'/','clientip'=>$ip,'device'=>$device]);
        $type=$response['type']??'';
        $result=['type'=>$type,'_defense'=>['trade_no'=>$order['trade_no'],'amount_cents'=>AlipayQrAdapter::cents($order['realmoney']),
            'account_scope'=>$this->scope,'upstream_trade_no'=>$response['trade_no']??null]];
        if($type==='html')$result['data']=$response['value']??null;
        else{$result['url']=$response['value']??null;if(in_array($type,['qrcode','scheme'],true))$result['page']='alipay_qrcode';}
        if(!self::validResult($result,$order,$this->scope))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
        return $result;
    }
    public static function validResult($result,array $order,$scope)
    {
        if(!is_array($result)||!is_array($result['_defense']??null))return false;
        $meta=$result['_defense'];$amount=AlipayQrAdapter::cents($order['realmoney']??null);
        if($amount===null||$amount<=0||($meta['trade_no']??null)!==$order['trade_no']||($meta['amount_cents']??null)!==$amount
            ||($meta['account_scope']??null)!==$scope||!is_string($meta['upstream_trade_no']??null)
            ||!preg_match('/^[A-Za-z0-9_-]{1,150}$/D',$meta['upstream_trade_no']))return false;
        $type=$result['type']??'';
        if($type==='html')return is_string($result['data']??null)&&$result['data']!==''&&strlen($result['data'])<=262144;
        if(!in_array($type,['jump','qrcode','scheme'],true)||!is_string($result['url']??null)||$result['url']===''||strlen($result['url'])>262144)return false;
        if(in_array($type,['qrcode','scheme'],true)&&($result['page']??'')!=='alipay_qrcode')return false;
        if(!class_exists('WeilaileClient',false))require_once dirname(__DIR__,3).'/plugins/weilaile/inc/WeilaileClient.php';
        return \WeilaileClient::validPayUrl($result['url'],$type);
    }
    public function query(array $order){throw new DefenseException('DEFENSE_LOCAL_HOLD');}
    public function close(array $order){throw new DefenseException('DEFENSE_LOCAL_HOLD');}
}
