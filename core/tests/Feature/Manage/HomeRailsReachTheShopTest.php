<?php

use App\Domain\Content\HomeRails;
use App\Storefront\StorefrontCache;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * A home-rails write reaches the shop (D2, 2026-10-20).
 *
 * The screen promises "a change reaches the shop within 5 minutes" and reordering changed nothing, on either
 * storefront. The order persisted and compat served it — compat reads the table on every request — but v2's `home`
 * is a whole assembled payload cached under the storefront's version for 600 s, and NOTHING in this controller
 * bumped that version. Measured on a scratch copy before the fix: the row moved to position 10 while
 * `GET /api/v2/brandfashion/home` went on serving the old order, twice, outside any browser.
 *
 * Brand Fashion's home reads v2, so the reorder was invisible until the entry expired. These tests pin the bump:
 * every write on this screen flushes the storefront, which is what the banners screen has always done.
 */

/** @return list<int> the rail ids the v2 home serves, in order */
function shopRailOrder(string $code): array
{
    return array_values(array_map(
        fn (mixed $r): int => T::int(T::arr($r)['id'] ?? null),
        T::arr(getJson("/api/v2/{$code}/home")->assertOk()->json('rails')),
    ));
}

it('serves a REORDER to the shop at once, not when the cache happens to expire', function () {
    $ids = array_column(HomeRails::all(2), 'id');
    expect(count($ids))->toBeGreaterThan(1);

    $before = shopRailOrder('brandfashion');          // warms the cached payload
    $swapped = $ids;
    [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];

    actingAs(Staff::admin())->post('/manage/storefronts/2/home-rails/order', ['order' => $swapped])
        ->assertRedirect();

    $after = shopRailOrder('brandfashion');
    expect($after)->not->toBe($before)
        ->and(array_slice($after, 0, 2))->toBe(array_slice(array_values(array_intersect($swapped, $after)), 0, 2));
});

it('serves a rail switched OFF to the shop at once', function () {
    $rail = HomeRails::all(2)[0];
    $id = T::int($rail['id']);
    expect(shopRailOrder('brandfashion'))->toContain($id);

    actingAs(Staff::admin())->put("/manage/storefronts/2/home-rails/{$id}", [
        'kind' => $rail['kind'], 'target_id' => $rail['target_id'], 'product_ids' => $rail['product_ids'],
        'title_en' => $rail['title_en'], 'title_ar' => $rail['title_ar'],
        'card_count' => $rail['card_count'], 'is_active' => false,
    ])->assertRedirect();

    expect(shopRailOrder('brandfashion'))->not->toContain($id);
});

it('bumps the storefront it was asked for, and leaves the other shop\'s cache alone', function () {
    $cache = app(StorefrontCache::class);
    $bfBefore = $cache->version(2);
    $wzBefore = $cache->version(1);

    $ids = array_column(HomeRails::all(2), 'id');
    actingAs(Staff::admin())->post('/manage/storefronts/2/home-rails/order', ['order' => $ids])->assertRedirect();

    expect($cache->version(2))->toBeGreaterThan($bfBefore)
        ->and($cache->version(1))->toBe($wzBefore);
});
