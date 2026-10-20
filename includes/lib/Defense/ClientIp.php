<?php
namespace lib\Defense;

class ClientIp
{
    public static function normalize($ip)
    {
        if(!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $packed = inet_pton($ip);
        if(strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff")$packed = substr($packed, 12);
        return inet_ntop($packed);
    }

    public static function contains($ip, $range)
    {
        try{
            [$network, $bits] = array_pad(explode('/', trim((string)$range), 2), 2, null);
            $a = inet_pton(self::normalize($ip));
            $b = inet_pton(self::normalize($network));
            if(strlen($a) !== strlen($b))return false;
            if($bits !== null && !preg_match('/^[0-9]{1,3}$/D', $bits))return false;
            $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
            if($bits < 0 || $bits > strlen($a) * 8)return false;
            $bytes = intdiv($bits, 8);$remaining = $bits % 8;
            return substr($a, 0, $bytes) === substr($b, 0, $bytes)
                && (!$remaining || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (255 << (8 - $remaining))) === 0);
        }catch(DefenseException $e){return false;}
    }

    public static function trusted($ip, array $ranges)
    {
        foreach($ranges as $range)if(self::contains($ip, $range))return true;
        return false;
    }

    public static function resolve(array $server, array $ranges = [])
    {
        $peer = self::normalize($server['REMOTE_ADDR'] ?? '');
        if(!self::trusted($peer, $ranges))return $peer;
        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if(!is_string($forwarded) || strlen($forwarded) > 2048 || $forwarded === '')throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $chain = explode(',', $forwarded);
        if(count($chain) > 20)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $current = $peer;
        while($chain && self::trusted($current, $ranges))$current = self::normalize(trim(array_pop($chain)));
        return $current;
    }

    public static function ranges(array $conf)
    {
        $value = trim((string)($conf['merchant_defense_trusted_proxies'] ?? ''));
        return $value === '' ? [] : preg_split('/[\s,]+/', $value);
    }
}
