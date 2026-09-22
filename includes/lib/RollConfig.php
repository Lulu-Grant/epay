<?php
namespace lib;

use InvalidArgumentException;

/** Parse and validate the legacy pre_roll.info format without changing stored data. */
class RollConfig
{
    public static function parse($content)
    {
        if ($content === null || $content === '') return [];
        if (!is_string($content)) throw new InvalidArgumentException('轮询规则格式错误');

        $result = [];
        $seen = [];
        foreach (explode(',', $content) as $part) {
            $fields = explode(':', $part);
            if (count($fields) > 2 || !preg_match('/^[1-9][0-9]*$/D', $fields[0])) {
                throw new InvalidArgumentException('轮询规则包含无效通道');
            }
            $channel = filter_var($fields[0], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($channel === false || isset($seen[$channel])) {
                throw new InvalidArgumentException('轮询规则包含重复或无效通道');
            }
            $weight = 1;
            if (count($fields) === 2) {
                if (!preg_match('/^(0|[1-9][0-9]*)$/D', $fields[1])) {
                    throw new InvalidArgumentException('轮询规则包含无效权重');
                }
                $weight = filter_var($fields[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
                if ($weight === false) throw new InvalidArgumentException('轮询规则权重超出范围');
            }
            $seen[$channel] = true;
            $result[] = ['channel'=>$channel, 'weight'=>$weight];
        }
        return $result;
    }

    public static function fromInput($list)
    {
        if (!is_array($list) || !$list) throw new InvalidArgumentException('通道配置不能为空');
        $result = [];
        $seen = [];
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['channel'], $item['weight']) ||
                !preg_match('/^[1-9][0-9]*$/D', (string)$item['channel']) ||
                !preg_match('/^[1-9][0-9]*$/D', (string)$item['weight'])) {
                throw new InvalidArgumentException('通道和权重必须是有效整数');
            }
            $channel = filter_var($item['channel'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $weight = filter_var($item['weight'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>99]]);
            if ($channel === false || $weight === false || isset($seen[$channel])) {
                throw new InvalidArgumentException('通道重复或权重不在 1–99 范围');
            }
            $seen[$channel] = true;
            $result[] = ['channel'=>$channel, 'weight'=>$weight];
        }
        return $result;
    }

    public static function serialize($items)
    {
        $parts = [];
        foreach ($items as $item) $parts[] = $item['channel'].':'.$item['weight'];
        return implode(',', $parts);
    }

    public static function hasPositiveWeight($items)
    {
        foreach ($items as $item) {
            if ($item['weight'] > 0) return true;
        }
        return false;
    }

    /** $draw is only supplied by deterministic tests. */
    public static function chooseWeighted($items, $draw = null)
    {
        $sum = 0;
        foreach ($items as $item) {
            if ($item['weight'] < 0 || $sum > PHP_INT_MAX - $item['weight']) return false;
            $sum += $item['weight'];
        }
        if ($sum <= 0) return false;
        if ($draw === null) $draw = random_int(1, $sum);
        if ($draw < 1 || $draw > $sum) throw new InvalidArgumentException('抽样值超出范围');
        foreach ($items as $item) {
            if ($draw <= $item['weight']) return $item['channel'];
            $draw -= $item['weight'];
        }
        return false;
    }
}
