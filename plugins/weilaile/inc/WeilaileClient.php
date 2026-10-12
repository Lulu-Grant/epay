<?php

/** V1 protocol: https://yun.weilaile.xyz/developer_v1.php (2026-10-12). */
class WeilaileException extends RuntimeException {}

class WeilaileClient
{
    const GATEWAY = 'https://yun.weilaile.xyz/';

    private $pid;
    private $key;

    public function __construct($pid, $key)
    {
        if(!is_scalar($pid) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string)$pid)
            || !is_string($key) || trim($key) === ''){
            throw new WeilaileException('请配置 Weilaile 商户 PID 和 MD5 密钥');
        }
        $this->pid = (string)$pid;
        $this->key = trim($key);
    }

    public function sign(array $params)
    {
        ksort($params, SORT_STRING);
        $parts = [];
        foreach($params as $name=>$value){
            if(!is_scalar($value) && $value !== null)throw new WeilaileException('支付参数格式错误');
            if($name === 'sign' || $name === 'sign_type' || $value === '' || $value === null)continue;
            $parts[] = $name.'='.(string)$value;
        }
        return md5(implode('&', $parts).$this->key);
    }

    public function verify(array $params)
    {
        if(($params['sign_type'] ?? null) !== 'MD5' || !is_string($params['sign'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/iD', $params['sign']))return false;
        try{
            return hash_equals($this->sign($params), strtolower($params['sign']));
        }catch(RuntimeException $e){
            return false;
        }
    }

    public static function amountCents($amount)
    {
        if(!is_scalar($amount) || !preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', (string)$amount, $m))return false;
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }

    public function pay(array $params)
    {
        if(($params['type'] ?? null) !== 'alipay')throw new WeilaileException('该网关当前仅支持 alipay 支付编码');
        foreach(['out_trade_no','name','notify_url','clientip'] as $field){
            if(!is_string($params[$field] ?? null) || $params[$field] === '')throw new WeilaileException('支付参数不完整');
        }
        if(!filter_var($params['clientip'], FILTER_VALIDATE_IP))throw new WeilaileException('付款用户 IP 无效');
        $cents = self::amountCents($params['money'] ?? null);
        if($cents === false || $cents <= 0)throw new WeilaileException('支付金额无效');
        $params['money'] = intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
        $params['pid'] = $this->pid;
        $params['sign_type'] = 'MD5';
        $params['sign'] = $this->sign($params);
        $result = $this->request($params);
        if(!in_array($result['code'] ?? null, [1, '1'], true)){
            // Gateway messages may contain echoed signed parameters; never expose them.
            throw new WeilaileException('Weilaile 网关下单失败，请检查通道配置或联系网关');
        }
        if(!is_string($result['trade_no'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $result['trade_no'])){
            throw new WeilaileException('Weilaile 网关未返回有效交易号');
        }
        foreach(['payurl'=>'jump', 'qrcode'=>'qrcode', 'urlscheme'=>'scheme', 'html'=>'html'] as $field=>$type){
            if(!isset($result[$field]) || $result[$field] === '')continue;
            if(!is_string($result[$field]) || strlen($result[$field]) > 262144)throw new WeilaileException('网关付款信息格式错误');
            if($type !== 'html' && !self::validPayUrl($result[$field], $type))throw new WeilaileException('网关付款链接无效');
            return ['type'=>$type, 'value'=>$result[$field], 'trade_no'=>$result['trade_no']];
        }
        throw new WeilaileException('Weilaile 网关未返回付款信息');
    }

    private static function validPayUrl($url, $type)
    {
        // Existing cashier templates interpolate URLs into JavaScript strings.
        if(preg_match('/[\x00-\x20\x7f\x27\x22<>\\\\]/', $url))return false;
        if($type === 'qrcode' && preg_match('#^data:image/(?:png|jpeg|gif);base64,[A-Za-z0-9+/=]+$#D', $url))return true;
        $parts = parse_url($url);
        if(!is_array($parts) || empty($parts['scheme']) || isset($parts['user']) || isset($parts['pass']))return false;
        $scheme = strtolower($parts['scheme']);
        if($type === 'scheme')return in_array($scheme, ['alipay','alipays'], true);
        return in_array($scheme, $type === 'jump' ? ['https'] : ['http','https','alipay','alipays'], true)
            && (!in_array($scheme, ['http','https'], true) || !empty($parts['host']));
    }

    /** Protected transport allows local fixtures without real credentials or payments. */
    protected function endpoint()
    {
        return self::GATEWAY.'mapi.php';
    }

    protected function request(array $params)
    {
        if(!function_exists('curl_init'))throw new WeilaileException('PHP cURL 扩展尚未启用');
        $ch = curl_init($this->endpoint());
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>function($curl, $chunk) use (&$body){
                if(strlen($body) + strlen($chunk) > 1048576)return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($ok === false || $status !== 200)throw new WeilaileException('Weilaile 网关请求失败或超时，请稍后重试');
        try{
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        }catch(JsonException $e){
            throw new WeilaileException('Weilaile 网关返回了无效 JSON');
        }
        if(!is_array($result))throw new WeilaileException('Weilaile 网关返回数据格式错误');
        return $result;
    }
}
