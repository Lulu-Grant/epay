<?php
namespace lib\Complain;

use InvalidArgumentException;

class AdminFetch
{
    public static function request($input)
    {
        if (!is_array($input)) throw new InvalidArgumentException('获取参数错误');
        $raw = [];
        foreach (['channel'=>'', 'subchannel'=>'0', 'num'=>'', 'source'=>'0'] as $key=>$default) {
            $value = isset($input[$key]) && $input[$key] !== '' ? $input[$key] : $default;
            if (!is_scalar($value)) throw new InvalidArgumentException('获取参数错误');
            $raw[$key] = (string)$value;
        }
        if (!ctype_digit($raw['channel']) || !ctype_digit($raw['subchannel']) ||
            !ctype_digit($raw['num']) || !in_array($raw['source'], ['0','1'], true)) {
            throw new InvalidArgumentException('获取参数错误');
        }
        $channel = filter_var($raw['channel'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $subchannel = filter_var($raw['subchannel'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $num = filter_var($raw['num'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>10, 'max_range'=>1000]]);
        if ($channel === false || $subchannel === false || $num === false) {
            throw new InvalidArgumentException('请选择通道，获取条数须为 10–1000');
        }
        return ['channel'=>$channel, 'subchannel'=>$subchannel, 'num'=>$num, 'source'=>intval($raw['source'])];
    }

    public static function encode($payload)
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE |
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $encoded === false ? '{"code":-1,"msg":"响应编码失败"}' : $encoded;
    }

    public static function hasUnresolvedConfig($channel)
    {
        if (!is_array($channel)) return true;
        foreach ($channel as $key=>$value) {
            if (in_array($key, ['id','subid','name','mode','type','plugin','apptype','costrate','daytop'], true)) continue;
            if (is_string($value) && preg_match('/^\[[A-Za-z0-9_]+\]$/D', $value)) return true;
        }
        return false;
    }
}
