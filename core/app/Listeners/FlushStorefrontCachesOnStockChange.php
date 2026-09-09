<?php

namespace App\Listeners;

use App\Events\StockChanged;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;

/**
 * `StockChanged` → the storefront caches that embed a stock number go stale.
 *
 * `StorefrontCache::INVALIDATION_MAP` names StockChanged as an invalidating event for the
 * `count`, `product` and `compat_all_product` families. `product` is forgotten by key; the other
 * two are version-keyed, and the file store has no tags, so the only way to retire them is the
 * storefront version bump — which is one `Cache::increment`, not a scan.
 *
 * This is the first listener wired to a real writer: before wave 3 the study noted "no writer
 * exists yet, so no flush is wired to an event".
 */
final class FlushStorefrontCachesOnStockChange
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public function handle(StockChanged $event): void
    {
        $storefrontIds = DB::table('storefront_product')
            ->where('product_id', $event->productId)
            ->orderBy('storefront_id')
            ->pluck('storefront_id')
            ->map(fn (mixed $v): int => (int) (is_numeric($v) ? $v : 0))
            ->unique()
            ->all();

        foreach ($storefrontIds as $storefrontId) {
            if ($storefrontId <= 0) {
                continue;
            }
            $this->cache->forgetProduct($storefrontId, $event->productId);
            $this->cache->flush($storefrontId);
        }
    }
}
