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
        // `compat_all_product` and `compat_all_product_image` were RETIRED by L8 (2026-10-06): nothing
        // builds or caches the whole catalogue any more (the index and nav are built leanly, a listing
        // page reads its per-product `compat_card` entries), so they are gone from this map — which
        // makes the whole-catalogue cache structurally unwritable (key() refuses an unlisted family).
        // One product's card row + gallery (2026-09-28): a listing page reads 24 of these instead of
        // the whole catalogue. The events that stale a product's card.
        'compat_card' => ['ProductUpdated', 'StockChanged', 'StorefrontProductChanged', 'PlacementChanged', 'LookupChanged'],
        // The header menu's facts (C-1 stage 2), built leanly from the catalogue since L8: the same events.
        'compat_nav' => ['ProductUpdated', 'StockChanged', 'StorefrontProductChanged', 'PlacementChanged', 'LookupChanged'],
        // The listing index (C-1 stage 3), built leanly since L8, plus the brand names in compat_meta.
        'compat_listing' => ['ProductUpdated', 'StockChanged', 'StorefrontProductChanged', 'PlacementChanged', 'LookupChanged'],
    ];

    /**
     * Storefronts flushed during this request / command — warmed once it has answered
     * (`catalog:warm`'s after-write half, wired in AppServiceProvider).
     *
     * @var array<int, true>
     */
    private static array $flushed = [];

    /**
     * The storefronts flushed since the last call, and forget them.
     *
     * @return list<int>
     */
    public static function takeFlushed(): array
    {
        $ids = array_keys(self::$flushed);
        self::$flushed = [];

        return $ids;
    }

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
        self::$flushed[$storefrontId] = true;

        return is_int($v) ? $v : $this->version($storefrontId);
    }

    /** Forget one product's DTO on one storefront (ProductUpdated / StockChanged). */
    public function forgetProduct(int $storefrontId, int $productId): void
    {
        Cache::forget($this->key($storefrontId, 'product', (string) $productId));
    }

    /**
     * Forget one product's storefront CARD (the compat_card entry CompatListing::cards() reads — the
     * product page's row, with its rating average). A new rating needs only this and forgetProduct
     * (2026-09-30, K6): the listings' averages may lag until the next catalog:warm (5 min) or the
     * cache TTL (compat.ttl.all_product, 10 min), which nobody notices in a grid.
     */
    public function forgetCard(int $storefrontId, int $productId): void
    {
        Cache::forget($this->key($storefrontId, 'compat_card', config()->string('compat.pinned_locale').':'.$productId));
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

    /** The key StorefrontHost caches the active shops' `[id => domain]` map under. */
    public const HOSTS_KEY = 'sf:hosts';

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
        // The host → storefront map (StorefrontHost) holds every active shop's domain: a domain or
        // is_active change must reach the host binding as fast as it reaches the row above.
        Cache::forget(self::HOSTS_KEY);
        Cache::forget(StorefrontUrls::CACHE_KEY);

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

    /**
     * Build one entry NOW and store it, whether or not it is cached (the warm-up).
     *
     * The key is taken BEFORE the build: a write that bumps the version while this runs leaves the
     * result under the old key, which nothing reads, instead of passing it off as current.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function refresh(int $storefrontId, string $what, string $suffix, int $ttl, Closure $build): mixed
    {
        $key = $this->key($storefrontId, $what, $suffix);
        $value = $build();
        Cache::put($key, $value, $ttl);

        return $value;
    }

    /**
     * Several entries of one family at once — the version is read ONCE, not per key.
     *
     * @param  list<string>  $suffixes
     * @return array<string, mixed> suffix => value, for the entries that are cached
     */
    public function many(int $storefrontId, string $what, array $suffixes): array
    {
        if ($suffixes === []) {
            return [];
        }
        $keys = $this->keysFor($storefrontId, $what, $suffixes);
        $found = Cache::many(array_values($keys));
        $out = [];
        foreach ($keys as $suffix => $key) {
            if (($found[$key] ?? null) !== null) {
                $out[$suffix] = $found[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values  suffix => value
     * @param  int|null  $version  store under this version — the one read BEFORE the values were
     *                             built, so a write that bumped it meanwhile leaves them unread
     *                             (as `refresh`); null = the current version
     */
    public function putMany(int $storefrontId, string $what, array $values, int $ttl, ?int $version = null): void
    {
        if ($values === []) {
            return;
        }
        $keys = $this->keysFor($storefrontId, $what, array_map('strval', array_keys($values)), $version);
        $byKey = [];
        foreach ($values as $suffix => $value) {
            $byKey[$keys[(string) $suffix]] = $value;
        }
        Cache::putMany($byKey, $ttl);
    }

    /**
     * @param  list<string>  $suffixes
     * @return array<string, string> suffix => key
     */
    private function keysFor(int $storefrontId, string $what, array $suffixes, ?int $version = null): array
    {
        $this->key($storefrontId, $what); // refuses a family that is not in INVALIDATION_MAP
        $v = $version ?? $this->version($storefrontId);
        $keys = [];
        foreach ($suffixes as $suffix) {
            $keys[$suffix] = "sf:{$storefrontId}:{$what}:{$suffix}:v{$v}";
        }

        return $keys;
    }

    private function versionKey(int $storefrontId): string
    {
        return "sf:{$storefrontId}:version";
    }
}
