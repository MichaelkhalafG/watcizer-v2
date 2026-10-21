<?php

use App\Domain\Content\BannerWriter;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * The home page's banners (D1 + F1, 2026-10-20).
 *
 * D1: the hero showed one banner out of three because `StorefrontHome::banner()` ended in `->first()` — the
 * storefront could not rotate what it was never sent (measured on production: the dashboard had three, the payload
 * had one object). The payload now carries EVERY active in-window banner, in the dashboard's order.
 *
 * F1: each one carries its `device`, and THE ENDPOINT NEVER FILTERS BY IT. The CDN in front of both API hosts keeps
 * one copy per URL and ignores `Vary`, so a payload narrowed server-side would reach the wrong device from a cached
 * copy (V2-CDN-PRIVATE, 2026-10-19). The client picks; this proves the server does not.
 */

/**
 * A home banner on storefront 2, with everything the row needs.
 *
 * @param  array<string, mixed>  $attributes
 */
function homeBanner(array $attributes = []): int
{
    // `$attributes` FIRST: `+` keeps the left side's value for a key both sides carry, so the defaults go second
    // or every row would be written with the default sort_order and is_active whatever the caller asked for.
    return (int) DB::table('storefront_banners')->insertGetId($attributes + [
        'storefront_id' => 2, 'placement' => 'home', 'image_path' => 'Banner_home/test.webp',
        'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<array{id: int, device: string}> the banners the home payload carries, in order */
function homeBanners(): array
{
    app(StorefrontCache::class)->flush(2);
    $out = [];
    foreach (T::arr(getJson('/api/v2/brandfashion/home')->assertOk()->json('banners')) as $banner) {
        $row = T::arr($banner);
        $out[] = ['id' => T::int($row['id'] ?? null), 'device' => T::str($row['device'] ?? null)];
    }

    return $out;
}

beforeEach(function () {
    DB::table('storefront_banners')->where('storefront_id', 2)->delete();
});

it('carries EVERY active banner, in the dashboard\'s order — not just the first', function () {
    $second = homeBanner(['sort_order' => 1]);
    $first = homeBanner(['sort_order' => 0]);
    $third = homeBanner(['sort_order' => 2]);

    expect(array_column(homeBanners(), 'id'))->toBe([$first, $second, $third]);
});

it('keeps `banner`, the first of the same list, for the build still running while core deploys ahead of it', function () {
    $first = homeBanner(['sort_order' => 0]);
    homeBanner(['sort_order' => 1]);

    app(StorefrontCache::class)->flush(2);
    $home = getJson('/api/v2/brandfashion/home')->assertOk();
    expect(T::int(T::arr($home->json('banner'))['id'] ?? null))->toBe($first)
        ->and(T::int(T::arr(T::arr($home->json('banners'))[0] ?? null)['id'] ?? null))->toBe($first);
});

it('leaves out what the shop is not showing: switched off, not started, finished', function () {
    $live = homeBanner(['sort_order' => 0]);
    homeBanner(['sort_order' => 1, 'is_active' => 0]);
    homeBanner(['sort_order' => 2, 'starts_at' => now()->addDay()]);
    homeBanner(['sort_order' => 3, 'ends_at' => now()->subDay()]);

    expect(array_column(homeBanners(), 'id'))->toBe([$live]);
});

it('sends every device\'s banner to every caller — the endpoint must not narrow by device', function () {
    $both = homeBanner(['sort_order' => 0, 'device' => 'both']);
    $desktop = homeBanner(['sort_order' => 1, 'device' => 'desktop']);
    $mobile = homeBanner(['sort_order' => 2, 'device' => 'mobile']);

    // the same URL asked as a phone and as a desktop: one payload, all three, because the CDN would serve
    // whichever copy it had to both of them
    foreach (['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'] as $agent) {
        app(StorefrontCache::class)->flush(2);
        $seen = [];
        foreach (T::arr(getJson('/api/v2/brandfashion/home', ['User-Agent' => $agent])->assertOk()->json('banners')) as $banner) {
            $row = T::arr($banner);
            $seen[] = ['id' => T::int($row['id'] ?? null), 'device' => T::str($row['device'] ?? null)];
        }
        expect(array_column($seen, 'id'))->toBe([$both, $desktop, $mobile])
            ->and(array_column($seen, 'device'))->toBe(['both', 'desktop', 'mobile']);
    }
});

it('reads a row with no device, or a value it does not know, as `both`', function () {
    $blank = homeBanner(['sort_order' => 0, 'device' => '']);
    $odd = homeBanner(['sort_order' => 1, 'device' => 'tablet']);

    expect(array_column(homeBanners(), 'device'))->toBe(['both', 'both'])
        ->and(BannerWriter::device(null))->toBe('both')
        ->and([$blank, $odd])->toHaveCount(2);
});

/*
 * WHERE A BANNER SENDS A SHOPPER, and what a screen reader reads (the addendum, 2026-10-20).
 *
 * `href` and `alt` are resolved by core ({@see \App\Domain\Content\HomeBanners}) because the shared hero runs
 * in both apps and knows nothing about this taxonomy — and because the obvious client-side guess was WRONG: the
 * first cut built `/category/{path}`, and a node's `path` is a slug PATH (`watches/diver`) while the route is one
 * segment, so every banner below depth 1 would have 404ed. Category 33 below is depth 2 for exactly that reason.
 */

/**
 * The banner rows the v2 home carries, with every key.
 *
 * @return list<array<array-key, mixed>>
 */
function homeBannerRows(): array
{
    app(StorefrontCache::class)->flush(2);
    $out = [];
    foreach (T::arr(getJson('/api/v2/brandfashion/home')->assertOk()->json('banners')) as $banner) {
        $out[] = T::arr($banner);
    }

    return $out;
}

it('sends a shopper where the dashboard pointed the banner, in a URL both apps route', function () {
    $url = homeBanner(['sort_order' => 0, 'link_url' => '/listing?sale=1']);
    // depth 2: its `path` is `watches/diver`, which the one-segment category route would not match
    $category = homeBanner(['sort_order' => 1, 'storefront_category_id' => 33]);
    $product = homeBanner(['sort_order' => 2, 'product_id' => 4]);
    $none = homeBanner(['sort_order' => 3]);

    $rows = homeBannerRows();
    expect(array_column($rows, 'href'))->toBe(['/listing?sale=1', '/category/diver', '/products/4', '/listing'])
        ->and(array_column($rows, 'id'))->toBe([$url, $category, $product, $none]);
});

it('reads a banner its category names, and says nothing where there is nothing to say', function () {
    $category = homeBanner(['sort_order' => 0, 'storefront_category_id' => 33]);
    $plain = homeBanner(['sort_order' => 1]);

    $rows = homeBannerRows();
    $named = T::arr($rows[0] ?? []);
    $plainRow = T::arr($rows[1] ?? []);
    // both languages, so ONE payload serves an Arabic and an English shopper — the hero picks
    expect(T::arr($named['alt'] ?? []))->toMatchArray(['en' => 'Diver'])
        ->and(T::str(T::arr($named['alt'] ?? [])['ar'] ?? null))->not->toBe('')
        // a banner with no category has no alt text to invent: an empty alt beats a made-up description.
        // `array_key_exists`, not `??`: the key is PRESENT and null, and `??` cannot tell those apart.
        ->and(array_key_exists('alt', $plainRow))->toBeTrue()
        ->and($plainRow['alt'])->toBeNull()
        ->and([$category, $plain])->toHaveCount(2);
});

it('answers compat with the SAME banner shape, because ONE hero component reads both endpoints', function () {
    homeBanner(['sort_order' => 0, 'storefront_category_id' => 33]);

    // storefront 2's list, built by the same code compat's `catalog/home` calls for its own storefront
    $v2 = homeBannerRows();
    $compat = \App\Domain\Content\HomeBanners::list(2);

    expect(array_keys(T::arr($compat[0] ?? [])))
        ->toBe(['id', 'device', 'image', 'href', 'alt', 'link_url', 'product_id', 'category_id'])
        // v2 adds `category` (its own, since the endpoint was written) and drops the raw id
        ->and(array_keys(T::arr($v2[0] ?? [])))
        ->toBe(['id', 'device', 'image', 'href', 'alt', 'link_url', 'product_id', 'category'])
        ->and(T::str(T::arr($v2[0] ?? [])['href'] ?? null))->toBe(T::str(T::arr($compat[0] ?? [])['href'] ?? null))
        ->and(T::arr(T::arr($v2[0] ?? [])['alt'] ?? []))->toBe(T::arr(T::arr($compat[0] ?? [])['alt'] ?? []));
});

