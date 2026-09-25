<?php
namespace lib;

/** Explicit business hooks, deliberately independent of generic SQL execution. */
final class ListCacheInvalidator
{
    public static function changed(string $kind, ?int $uid = null): void
    {
        try {
            global $DB;
            // Transaction owners must call again after commit. Never publish uncommitted writes.
            if ($DB instanceof PdoHelper && $DB->db->inTransaction()) return;
            $tags = match ($kind) {
                'payment' => ['payment.global', $uid !== null && $uid > 0 ? 'payment.uid.'.$uid : 'payment.bulk'],
                'ledger' => [$uid !== null && $uid > 0 ? 'ledger.uid.'.$uid : 'ledger.bulk'],
                'shop' => ['shop.orders'],
                'goods' => ['shop.goods'],
                'shop_stock' => ['shop.orders', 'shop.goods'],
                'types' => ['display.types'],
                default => [],
            };
            if ($tags) ListReadCache::forSite()->invalidate($tags);
        } catch (\Throwable $e) {
            // A cache problem must not change a committed payment's result or callback.
            error_log('list_read_cache invalidation_setup');
        }
    }
}
