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

    /**
     * Validate an edited rule without losing decimal precision in the browser.
     *
     * New weights must be positive decimal integers up to PHP_INT_MAX. A stored
     * zero is a legacy compatibility value: it may only be submitted unchanged
     * for the same channel and cannot be introduced for another channel.
     */
    public static function fromInput($list, $storedItems = [])
    {
        if (!is_array($list) || !$list) throw new InvalidArgumentException('通道配置不能为空');
        if (!is_array($storedItems)) throw new InvalidArgumentException('原轮询规则格式错误');

        $storedByChannel = [];
        foreach ($storedItems as $storedItem) {
            if (!is_array($storedItem) || !isset($storedItem['channel'], $storedItem['weight'])) continue;
            $storedByChannel[(string)$storedItem['channel']] = (int)$storedItem['weight'];
        }

        $result = [];
        $seen = [];
        $sum = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['channel'], $item['weight']) ||
                !preg_match('/^[1-9][0-9]*$/D', (string)$item['channel']) ||
                !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$item['weight'])) {
                throw new InvalidArgumentException('通道和权重必须是十进制整数');
            }
            $channel = filter_var($item['channel'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $weight = filter_var($item['weight'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($channel === false || $weight === false) {
                throw new InvalidArgumentException('通道或权重超出当前 PHP 整数范围');
            }
            if (isset($seen[$channel])) {
                throw new InvalidArgumentException('轮询组不能包含重复通道');
            }
            if ($weight === 0 && (!isset($storedByChannel[(string)$channel]) || $storedByChannel[(string)$channel] !== 0)) {
                throw new InvalidArgumentException('新增或修改的权重必须大于 0；历史 0 权重只能原样保留');
            }
            if ($sum > PHP_INT_MAX - $weight) {
                throw new InvalidArgumentException('权重总和超出当前 PHP 整数范围');
            }
            $sum += $weight;
            $seen[$channel] = true;
            $result[] = ['channel'=>$channel, 'weight'=>$weight];
        }
        return $result;
    }

    /** Return strings for JSON so JavaScript never rounds a valid PHP integer. */
    public static function forClient($items)
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'channel'=>(string)$item['channel'],
                'weight'=>(string)$item['weight'],
                'legacyZero'=>(int)$item['weight'] === 0,
            ];
        }
        return $result;
    }

    public static function equivalent($left, $right)
    {
        if (count($left) !== count($right)) return false;
        foreach ($left as $index => $item) {
            if (!isset($right[$index]) ||
                (int)$item['channel'] !== (int)$right[$index]['channel'] ||
                (int)$item['weight'] !== (int)$right[$index]['weight']) return false;
        }
        return true;
    }

    public static function sameChannels($left, $right)
    {
        if (count($left) !== count($right)) return false;
        foreach ($left as $index => $item) {
            if (!isset($right[$index]) || (int)$item['channel'] !== (int)$right[$index]['channel']) return false;
        }
        return true;
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
