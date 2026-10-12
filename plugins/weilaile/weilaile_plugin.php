<?php

require_once __DIR__.'/inc/WeilaileClient.php';

class weilaile_plugin
{
    static public $info = [
        'name'=>'weilaile',
        'showname'=>'Weilaile 支付网关（MD5/MAPI）',
        'author'=>'乐跑',
        'link'=>'https://yun.weilaile.xyz/developer_v1.php',
        'types'=>['alipay'],
        'inputs'=>[
            'appid'=>['name'=>'商户 PID', 'type'=>'input', 'note'=>'填写网关商户 ID'],
            'appkey'=>['name'=>'商户 MD5 密钥', 'type'=>'input', 'note'=>'仅在服务器后台填写，禁止写入代码或日志'],
        ],
        'select'=>null,
        'note'=>'固定网关 https://yun.weilaile.xyz/；POST mapi.php；支付编码固定 alipay。同步返回只等待异步通知，不直接入账。',
        'bindwxmp'=>false,
        'bindwxa'=>false,
    ];

    static protected function client()
    {
        global $channel;
        return new WeilaileClient($channel['appid'] ?? '', $channel['appkey'] ?? '');
    }

    static public function submit()
    {
        global $order;
        if(($order['typename'] ?? '') !== 'alipay')return ['type'=>'error', 'msg'=>'该网关当前仅支持支付宝'];
        return ['type'=>'jump', 'url'=>'/pay/alipay/'.TRADE_NO.'/'];
    }

    static public function mapi()
    {
        return static::alipay();
    }

    static public function alipay()
    {
        global $order, $ordername, $siteurl, $conf, $clientip;
        try{
            if(($order['typename'] ?? '') !== 'alipay')throw new WeilaileException('该网关当前仅支持支付宝');
            $client = static::client();
            $params = [
                'type'=>'alipay',
                'out_trade_no'=>TRADE_NO,
                'notify_url'=>rtrim($conf['localurl'], '/').'/pay/notify/'.TRADE_NO.'/',
                'return_url'=>rtrim($siteurl, '/').'/pay/return/'.TRADE_NO.'/',
                'name'=>!empty($ordername) ? $ordername : $order['name'],
                'money'=>$order['realmoney'],
                'clientip'=>$clientip,
                'device'=>static::device(),
            ];
            $result = \lib\Payment::lockPayData(TRADE_NO, function() use ($client, $params){
                return $client->pay($params);
            });
            if($result['type'] === 'html')return ['type'=>'html', 'data'=>$result['value']];
            if($result['type'] === 'jump')return ['type'=>'jump', 'url'=>$result['value']];
            // The existing QR cashier also supports scheme payloads and API echoJson preserves their type.
            return ['type'=>$result['type'], 'page'=>'alipay_qrcode', 'url'=>$result['value']];
        }catch(Throwable $e){
            $msg = $e instanceof WeilaileException ? $e->getMessage() : '支付处理异常，请稍后重试';
            return ['type'=>'error', 'msg'=>$msg];
        }
    }

    static private function device()
    {
        global $device, $mdevice;
        // MAPI normalizes embedded browsers to mobile and keeps their identity in mdevice.
        if(in_array($mdevice ?? '', ['wechat','alipay'], true))return $mdevice;
        if(in_array($device ?? '', ['pc','mobile','jump'], true))return $device;
        foreach(['checkwechat'=>'wechat', 'checkalipay'=>'alipay', 'checkmobile'=>'mobile'] as $function=>$browserDevice){
            if(function_exists($function) && $function())return $browserDevice;
        }
        return 'pc';
    }

    static public function notify()
    {
        global $channel, $order;
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        $reason = 'verify';
        try{
            if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !static::client()->verify($_GET))throw new RuntimeException('verify');
            $reason = 'order';
            foreach(['pid','out_trade_no','trade_no','type','trade_status','money'] as $field){
                if(!is_string($_GET[$field] ?? null))throw new RuntimeException('order');
            }
            $amount = WeilaileClient::amountCents($_GET['money']);
            $expected = WeilaileClient::amountCents($order['realmoney']);
            if($_GET['pid'] !== (string)$channel['appid'] || $_GET['out_trade_no'] !== TRADE_NO
                || $_GET['type'] !== 'alipay' || ($order['typename'] ?? '') !== 'alipay'
                || $_GET['trade_status'] !== 'TRADE_SUCCESS' || $amount === false || $amount <= 0 || $amount !== $expected
                || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $_GET['trade_no'])
                || (!empty($order['api_trade_no']) && $_GET['trade_no'] !== $order['api_trade_no']))throw new RuntimeException('order');
            $reason = 'process';
            // Reuse the platform's conditional paid-status transition and idempotent ledger processing.
            processNotify($order, $_GET['trade_no']);
            return ['type'=>'html', 'data'=>'success'];
        }catch(Throwable $e){
            if(function_exists('logPaymentCallbackEvent')){
                logPaymentCallbackEvent('weilaile_notify_rejected', TRADE_NO, $channel['id'] ?? 0, $reason);
            }
            return ['type'=>'html', 'data'=>'fail'];
        }
    }

    static public function return()
    {
        // A browser return cannot mark an order paid: the shared page polls persisted order status.
        return ['type'=>'page', 'page'=>'return'];
    }
}
