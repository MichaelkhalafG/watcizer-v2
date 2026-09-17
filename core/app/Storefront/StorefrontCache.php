<?php

namespace App\Storefront;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Application cache with per-storefront version keys (CLEAN_CORE_STUDY §5.2 / §5.2.1).
 *
 * Every key embeds `v{n}` read from `sf:{id}:version`; flush() is one increment, so all
 * listing/tree/meta/sitemap keys of a storefront go stale at once on the file store, which
 * has no tags. Product DTOs are forgotten individually. The INVALIDATION_MAP below is the
 * contract "no cache key may be written without a listed event that forgets it": the unit
 * test tests/Unit/CacheInvalidationMapTest.php scans every remember() call in app/ against it.
 */
final class StorefrontCache
{
    /**
     * what => the domain events (dashboard writes, wave 4) that flush it.
     *
     * @var array<string, list<string>>
     */
    public const INVALIDATION_MAP = [
        'meta' => ['CategoryTreeChanged', 'LookupChanged', 'BannerChanged', 'StorefrontSettingsChanged', 'PlacementChanged', 'StorefrontProductChanged'],
        'tree' => ['CategoryTreeChanged', 'PlacementChanged', 'StorefrontProductChanged', 'ProductUpdated'],
        'lookups' => ['LookupChanged', 'CategoryTreeChanged'],
        'count' => ['PlacementChanged', 'StorefrontProductChanged', 'ProductUpdated', 'StockChanged'],
        'product' => ['ProductUpdated', 'StockChanged', 'StorefrontProductChanged'],
        'sitemap' => ['StorefrontProductChanged', 'CategoryTreeChanged', 'PlacementChanged', 'ProductUpdated'],
        'compat_names' => ['LookupChanged', 'CategoryTreeChanged'],
        'compat_meta' => ['CategoryTreeChanged', 'LookupChanged', 'BannerChanged', 'PlacementChanged', 'StorefrontProductChanged'],
        'compat_all_product' => ['ProductUpdated', 'StockChanged', 'StorefrontProductChanged', 'PlacementChanged', 'LookupChanged'],
        'compat_all_product_image' => ['ProductUpdated'],
    ];

    public function version(int $storefrontId): int
    {
        $v = Cache::get($this->versionKey($storefrontId));

        return is_int($v) ? $v : 1;
    }

    /** Bump the storefront version: every versioned key goes stale at once. */
    public function flush(int $storefrontId): int
    {
        $key = $this->versionKey($storefrontId);
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        $v = Cache::increment($key);

        return is_int($v) ? $v : $this->version($storefrontId);
    }

    /** Forget one product's DTO on one storefront (ProductUpdated / StockChanged). */
    public function forgetProduct(int $storefrontId, int $productId): void
    {
        Cache::forget($this->key($storefrontId, 'product', (string) $productId));
    }

    /**
     * The key `ResolveStorefront` caches the raw storefront ROW under.
     *
     * Declared here rather than inline in the middleware so the writer and the reader cannot drift:
     * before this existed the middleware was the only place that knew the shape, and the dashboard's
     * update path did not forget it because there was nothing to call (C-BUG-1).
     */
    public static function resolvedKey(string $code): string
    {
        return "sf:code:{$code}";
    }

    /**
     * Everything that goes stale when a storefront's own settings change (C-BUG-1, 2026-09-17).
     *
     * ── Why two different invalidations, not one ────────────────────────────────────────────
     *
     * They cache different things and neither covers the other:
     *
     *  1. **The resolved ROW** (`sf:code:{code}`) is what `ResolveStorefront` reads on EVERY
     *     storefront request to decide the name, currency, locales and — the one that matters —
     *     whether the shop is active at all. It is a plain 10-minute `Cache::put`, with no version
     *     in its key, so bumping the version below does nothing to it. Measured before this fix:
     *     deactivating a storefront left it serving customers for up to ten more minutes.
     *
     *  2. **The versioned payloads** (`meta`, `tree`, `lookups`, …) embed `v{n}`. `meta` carries the
     *     shop's name, currency and locales, so a rename that only forgot the row above would still
     *     serve the old name from `meta` until its own TTL expired.
     *
     * `INVALIDATION_MAP` has listed `StorefrontSettingsChanged` against `meta` since it was written.
     * The contract was right; nothing fired it. This method is the firing.
     */
    public function forgetStorefront(int $storefrontId, string $code): int
    {
        Cache::forget(self::resolvedKey($code));

        return $this->flush($storefrontId);
    }

    public function key(int $storefrontId, string $what, string $suffix = ''): string
    {
        if (! array_key_exists($what, self::INVALIDATION_MAP)) {
            throw new \InvalidArgumentException("Cache key family [$what] is not in StorefrontCache::INVALIDATION_MAP.");
        }
        $v = $this->version($storefrontId);

        return "sf:{$storefrontId}:{$what}".($suffix !== '' ? ":{$suffix}" : '').":v{$v}";
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function remember(int $storefrontId, string $what, string $suffix, int $ttl, Closure $build): mixed
    {
        /** @var T */
        return Cache::remember($this->key($storefrontId, $what, $suffix), $ttl, $build);
    }

    private function versionKey(int $storefrontId): string
    {
        return "sf:{$storefrontId}:version";
    }
}
