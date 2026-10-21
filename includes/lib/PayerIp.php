<?php
namespace lib;

use lib\Defense\ClientIp;

/** Gateway-page observations, independent of merchant-supplied order.ip and payment decisions. */
class PayerIp
{
    private const HEADERS = [
        'ali-cdn-real-ip'=>'HTTP_ALI_CDN_REAL_IP',
        'ali-real-client-ip'=>'HTTP_ALI_REAL_CLIENT_IP',
    ];

    public static function configured(array $conf)
    {
        if(($conf['payer_ip_version']??'')!=='1' || ($conf['payer_ip_enabled']??'')!=='1'
            || ($conf['payer_ip_esa_verified']??'')!=='1')return false;
        $header=strtolower((string)($conf['payer_ip_header']??''));
        if(!isset(self::HEADERS[$header]))return false;
        $ranges=self::ranges($conf);
        if(!$ranges || count($ranges)>4096)return false;
        foreach($ranges as $range){
            $parts=explode('/',$range,2);
            if(!filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)
                || (isset($parts[1]) && (int)$parts[1]===0) || !ClientIp::contains($parts[0],$range))return false;
        }
        return true;
    }

    private static function ranges(array $conf)
    {
        $raw=$conf['payer_ip_esa_cidrs']??'';
        if(!is_string($raw)||strlen($raw)>65535)return [];
        return preg_split('/[\s,]+/',trim($raw),-1,PREG_SPLIT_NO_EMPTY);
    }

    /** Only the actual connection peer authorizes the one explicitly selected ESA header. */
    public static function resolve(array $server, array $conf)
    {
        if(!self::configured($conf))return null;
        try{
            $peer=ClientIp::normalize($server['REMOTE_ADDR']??'');
            if(!ClientIp::trusted($peer,self::ranges($conf)))return null;
            $header=strtolower($conf['payer_ip_header']);
            $value=$server[self::HEADERS[$header]]??null;
            if(!is_string($value)||strlen($value)>45 || $value!==trim($value)
                || !filter_var($value,FILTER_VALIDATE_IP))return null;
            $ip=ClientIp::normalize($value);
            if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE))return null;
            return ['ip'=>$ip,'peer'=>$peer,'source'=>'esa:'.$header];
        }catch(\Throwable $e){return null;}
    }

    private static function pageRequest($entry, array $server)
    {
        if(!in_array($entry,['pay_submit','pay_qrcode','web_submit','cashier','submit2'],true))return false;
        $method=$server['REQUEST_METHOD']??'';
        if($method!=='GET' && !($entry==='web_submit' && $method==='POST'))return false;
        $accept=$server['HTTP_ACCEPT']??'';$ua=$server['HTTP_USER_AGENT']??'';
        if(!is_string($accept)||!preg_match('#(?:^|,)\s*text/html\s*(?:;|,|$)#i',$accept)
            || !is_string($ua)||strlen($ua)<8||strlen($ua)>1024)return false;
        if(preg_match('#curl/|libcurl/|wget/|python|Go-http-client|HeadlessChrome|PhantomJS|spider|bot/#i',$ua))return false;
        $dest=$server['HTTP_SEC_FETCH_DEST']??'';
        return $dest==='' || in_array($dest,['document','iframe','frame'],true);
    }

    public static function observe(array &$order, $entry)
    {
        global $DB,$conf;
        return self::capture($order,$entry,$_SERVER,$conf,$DB);
    }

    /** First trustworthy page observation wins atomically. Failure never blocks payment. */
    public static function capture(array &$order, $entry, array $server, array $conf, $db)
    {
        // Legacy web submit builds a partial order without status; the SQL predicate is authoritative.
        if(!self::pageRequest($entry,$server) || !empty($order['payer_ip']) || (isset($order['status']) && (int)$order['status']!==0)
            || (int)($order['uid']??0)<=0 || !is_string($order['trade_no']??null)
            || !preg_match('/^[A-Za-z0-9]{1,32}$/D',$order['trade_no']))return false;
        $observation=self::resolve($server,$conf);
        if(!$observation)return false;
        try{
            $store=new \lib\Defense\Repository($db);
            $stmt=$store->query('UPDATE pre_order SET payer_ip=:ip,payer_ip_at=NOW(),payer_ip_peer=:peer,payer_ip_source=:source WHERE trade_no=:trade AND uid=:uid AND status=0 AND payer_ip IS NULL',
                [':ip'=>$observation['ip'],':peer'=>$observation['peer'],':source'=>$observation['source'],':trade'=>$order['trade_no'],':uid'=>$order['uid']]);
            if($stmt->rowCount()!==1)return false;
            // Prevent a second hook in this same page request from issuing another UPDATE.
            $order['payer_ip']=$observation['ip'];
            return true;
        }catch(\Throwable $e){error_log('[payer-ip] capture_storage_failed');return false;}
    }
}
