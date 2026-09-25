<?php
namespace lib\Shop;

use RuntimeException;

/** Preserve the inner table's order-number index across legacy character sets. */
class TradeJoin
{
    private static ?\WeakMap $metadata = null;

    public static function condition($paymentAlias, $shopAlias, $indexed = 'payment')
    {
        global $DB;
        foreach ([$paymentAlias, $shopAlias] as $alias) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) {
                throw new RuntimeException('商城关联别名无效');
            }
        }
        if (!in_array($indexed, ['payment', 'shop'], true)) {
            throw new RuntimeException('商城关联方向无效');
        }
        self::$metadata ??= new \WeakMap();
        if (!isset(self::$metadata[$DB])) {
            $result = \lib\ListReadCache::forSite()->remember('metadata', 'shop.trade_join.v1', ['audience'=>'schema'], [], ['schema'], function () use ($DB) {
            $columns = [];
            foreach (['payment' => ['order', 'trade_no'], 'shop' => ['shop_orders', 'pay_trade_no']] as $key => [$table, $field]) {
                $column = $DB->getRow("SHOW FULL COLUMNS FROM pre_{$table} WHERE Field=:field", [':field' => $field]);
                $collation = $column['Collation'] ?? '';
                if (!preg_match('/^(utf8|utf8mb3|utf8mb4)_[a-z0-9_]+$/D', $collation)) {
                    throw new RuntimeException('商城订单号字符集不受支持');
                }
                $entry = $DB->getRow('SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME=:collation', [':collation' => $collation]);
                $charset = $entry['CHARACTER_SET_NAME'] ?? '';
                if (!in_array($charset, ['utf8', 'utf8mb3', 'utf8mb4'], true)) {
                    throw new RuntimeException('无法读取商城订单号字符集');
                }
                $columns[$key] = [$charset, $collation];
            }
            return $columns;
            });
            self::$metadata[$DB] = $result['value'];
        }
        $columns = self::$metadata[$DB];
        foreach (['payment', 'shop'] as $key) {
            if (!isset($columns[$key][0], $columns[$key][1])
                || !in_array($columns[$key][0], ['utf8', 'utf8mb3', 'utf8mb4'], true)
                || !is_string($columns[$key][1]) || !preg_match('/^(utf8|utf8mb3|utf8mb4)_[a-z0-9_]+$/D', $columns[$key][1])) {
                throw new RuntimeException('商城订单号字符集不受支持');
            }
        }
        $fields = ['payment' => $paymentAlias.'.trade_no', 'shop' => $shopAlias.'.pay_trade_no'];
        $outer = $indexed === 'payment' ? 'shop' : 'payment';
        if ($columns['payment'] === $columns['shop']) {
            return $fields[$indexed].'='.$fields[$outer];
        }
        [$charset, $collation] = $columns[$indexed];
        return $fields[$indexed].'=CONVERT('.$fields[$outer].' USING '.$charset.') COLLATE '.$collation;
    }
}
