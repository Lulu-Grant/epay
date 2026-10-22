<?php
namespace lib\Defense;

class AdapterFactory
{
    public static function accountScope(array $channel)
    {
        $id=$channel['appid']??null;
        if(!PolicyService::channelSupported($channel)||!is_scalar($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$id))throw new DefenseException('DEFENSE_CHANNEL_UNSUPPORTED');
        return $channel['plugin'].':'.(string)$id;
    }
    public static function make(array $channel,array $conf,array $order)
    {
        if(PolicyService::channelMode($channel)==='local_hold')return new WeilaileAdapter($channel,$conf);
        return new AlipayQrAdapter($channel,$conf,$order);
    }
    public static function validResult($result,array $order,array $channel)
    {
        return PolicyService::channelMode($channel)==='local_hold'
            ?WeilaileAdapter::validResult($result,$order,self::accountScope($channel))
            :AlipayQrAdapter::validResult($result,$order,(string)($channel['appid']??''));
    }
}
