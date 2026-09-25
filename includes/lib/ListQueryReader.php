<?php
namespace lib;

/** Read-only query orchestration, called after the endpoint's existing authentication. */
final class ListQueryReader
{
    public static function scope(?int $uid = null): array
    {
        // Current admin AJAX has one full-access role. Increment if visibility rules change.
        return $uid === null ? ['audience'=>'admin', 'policy'=>'full-v1']
            : ['audience'=>'merchant', 'uid'=>$uid, 'policy'=>'own-v1'];
    }

    public static function paymentTags(?int $uid): array
    {
        return $uid === null ? ['payment.global', 'payment.bulk'] : ['payment.uid.'.$uid, 'payment.bulk'];
    }

    public static function paymentList($db, array $input, ?int $uid = null): array
    {
        $filter = ListQueryFilter::payment($input, $uid);
        [$offset, $limit] = ListQueryFilter::pagination($input['offset'] ?? 0, $input['limit'] ?? 20);
        $scope = self::scope($uid);
        $types = ListReadCache::forSite()->remember('dictionaries', 'payment.types.v1', $scope, [], ['display.types'],
            function () use ($db, $uid) {
                $rows = $db->getAll('SELECT id,name,showname,status FROM pre_type'.($uid === null ? '' : ' WHERE status=1'));
                if (!is_array($rows)) throw new \RuntimeException('支付类型查询失败，请重试');
                $map = [];
                foreach ($rows as $row) $map[$row['id']] = $row;
                return $map;
            });
        $result = self::page($db, 'payment.count.v1', $scope, $filter->key(), self::paymentTags($uid),
            fn()=>self::count($db, 'SELECT COUNT(*) FROM pre_order A WHERE '.$filter->where, $filter->bind),
            function (int $start, int $size) use ($db, $filter) {
                // LIMIT in the derived table prevents merging, and limits display JOINs to one page.
                if ($start >= 1000) {
                    $sql = 'SELECT A.*,B.plugin FROM (SELECT A.trade_no FROM pre_order A WHERE '.$filter->where.
                        ' ORDER BY A.trade_no DESC LIMIT '.$start.','.$size.') page_ids'.
                        ' JOIN pre_order A ON A.trade_no=page_ids.trade_no LEFT JOIN pre_channel B ON A.channel=B.id ORDER BY A.trade_no DESC';
                } else $sql = 'SELECT A.*,B.plugin FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE '.$filter->where.
                    ' ORDER BY A.trade_no DESC LIMIT '.$start.','.$size;
                return $db->getAll($sql, $filter->bind);
            }, $offset, $limit, ListQueryFilter::fresh($input));
        foreach ($result['rows'] as &$row) {
            $type = $types['value'][$row['type']] ?? [];
            $row['typename'] = $type['name'] ?? null;
            $row['typeshowname'] = $type['showname'] ?? null;
        }
        return $result;
    }

    public static function paymentSummary($db, array $input, ?int $uid = null): array
    {
        $filter = ListQueryFilter::payment($input, $uid);
        $moneyColumn = $uid === null ? 'profitmoney' : 'getmoney';
        $moneyAlias = $uid === null ? 'profit_money' : 'get_money';
        $result = ListReadCache::forSite()->remember('summaries', 'payment.summary.'.$moneyAlias.'.v1', self::scope($uid),
            $filter->key(), self::paymentTags($uid), function () use ($db, $filter, $moneyColumn, $moneyAlias) {
                $row = $db->getRow("SELECT
                    COUNT(*) total_count,
                    ROUND(COALESCE(SUM(A.money),0),2) total_money,
                    ROUND(COALESCE(SUM(CASE WHEN A.status=1 THEN COALESCE(A.realmoney,A.money,0) ELSE 0 END),0),2) paid_money,
                    ROUND(COALESCE(SUM(CASE WHEN A.status=0 THEN COALESCE(A.money,0) ELSE 0 END),0),2) unpaid_money,
                    ROUND(COALESCE(SUM(CASE WHEN A.status=2 THEN COALESCE(A.refundmoney,A.realmoney,A.money,0) ELSE 0 END),0),2) refund_money,
                    ROUND(COALESCE(SUM(CASE WHEN A.status=3 THEN COALESCE(A.realmoney,A.money,0) ELSE 0 END),0),2) frozen_money,
                    ROUND(COALESCE(SUM(CASE WHEN A.status=1 THEN COALESCE(A.{$moneyColumn},0) ELSE 0 END),0),2) {$moneyAlias},
                    SUM(CASE WHEN A.status=1 THEN 1 ELSE 0 END) paid_count,
                    SUM(CASE WHEN A.status=0 THEN 1 ELSE 0 END) unpaid_count,
                    SUM(CASE WHEN A.status=2 THEN 1 ELSE 0 END) refund_count,
                    SUM(CASE WHEN A.status=3 THEN 1 ELSE 0 END) frozen_count,
                    SUM(CASE WHEN A.status=4 THEN 1 ELSE 0 END) preauth_count,
                    SUM(CASE WHEN A.status>0 AND A.notify<>0 THEN 1 ELSE 0 END) notify_bad_count
                    FROM pre_order A WHERE ".$filter->where, $filter->bind);
                if (!is_array($row)) throw new \RuntimeException('订单统计查询失败，请重试');
                $total = (int)$row['total_count'];
                $row['success_rate'] = $total > 0 ? round((int)$row['paid_count'] / $total * 100, 2) : 0;
                return $row;
            }, ListQueryFilter::fresh($input));
        return ['code'=>0, 'data'=>$result['value'], 'meta'=>$result['meta']];
    }

    public static function ledgerList($db, array $input, int $uid): array
    {
        $filter = ListQueryFilter::ledger($input, $uid);
        [$offset, $limit] = ListQueryFilter::pagination($input['offset'] ?? 0, $input['limit'] ?? 20);
        return self::page($db, 'ledger.count.v1', self::scope($uid), $filter->key(), ['ledger.uid.'.$uid, 'ledger.bulk'],
            fn()=>self::count($db, 'SELECT COUNT(*) FROM pre_record A WHERE '.$filter->where, $filter->bind),
            fn(int $start, int $size)=>$db->getAll('SELECT A.* FROM pre_record A WHERE '.$filter->where.
                ' ORDER BY A.id DESC LIMIT '.$start.','.$size, $filter->bind),
            $offset, $limit, ListQueryFilter::fresh($input));
    }

    public static function count($db, string $sql, array $bind): int
    {
        $value = $db->getColumn($sql, $bind);
        if ($value === false || $value === null || !is_numeric($value)) throw new \RuntimeException('列表总数查询失败，请重试');
        return (int)$value;
    }

    public static function page($db, string $namespace, array $scope, array $filters, array $tags,
        callable $count, callable $rows, int $offset, int $limit, bool $fresh = false): array
    {
        $total = ListReadCache::forSite()->remember('counts', $namespace, $scope, $filters, $tags, $count, $fresh);
        $list = $rows($offset, $limit);
        if (!is_array($list)) throw new \RuntimeException('列表查询失败，请重试');
        $actualOffset = $offset;
        if (($list && $offset + count($list) > (int)$total['value']) || (!$list && $offset > 0)) {
            $owned = false;
            try {
                if (!$db->db->inTransaction()) {
                    if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false
                        || $db->exec('SET TRANSACTION READ ONLY') === false || !$db->beginTransaction()) {
                        throw new \RuntimeException('列表一致性查询失败，请重试');
                    }
                    $owned = true;
                }
                $total = ListReadCache::forSite()->remember('counts', $namespace, $scope, $filters, $tags, $count, true);
                $actualOffset = min($offset, max(0, (int)ceil($total['value'] / $limit) - 1) * $limit);
                $list = $rows($actualOffset, $limit);
                if (!is_array($list)) throw new \RuntimeException('列表查询失败，请重试');
                if ($owned && !$db->commit()) throw new \RuntimeException('列表一致性查询失败，请重试');
            } catch (\Throwable $e) {
                if ($owned && $db->db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        return ['total'=>(int)$total['value'], 'rows'=>$list, 'meta'=>[
            'rows_live'=>true, 'total_as_of'=>$total['meta']['as_of'],
            'total_max_age_seconds'=>$total['meta']['max_age_seconds'], 'total_cached'=>$total['meta']['cached'],
            'offset'=>$actualOffset, 'limit'=>$limit,
        ]];
    }
}
