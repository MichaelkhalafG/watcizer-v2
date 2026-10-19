<?php

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * `min_discount` on the v2 listing (the night run of 2026-10-19 — Michael: the deals band is 30 %+ and in stock; the
 * Sale page's sidebar offers Any / 20 / 30 / 40 %+). A valid sale whose discount, ROUNDED AS THE CARD PRINTS IT, is
 * at least N: a card showing −30 % is always in a "30 %+" list, one showing −29 % never is.
 */

/** @return array{product: int, brand: int} a product listed on storefront 2, with its brand */
function minDiscountFixture(): array
{
    $row = T::row(DB::table('storefront_product as sp')
        ->join('catalog_products as p', fn (JoinClause $j) => $j->on('p.id', '=', 'sp.product_id'))
        ->where('sp.storefront_id', 2)->where('sp.is_visible', 1)
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->whereNotNull('p.brand_id')
        ->orderByDesc('sp.product_id')
        ->first(['sp.product_id', 'p.brand_id']));

    return ['product' => T::int($row->product_id), 'brand' => T::int($row->brand_id)];
}

/** @return list<int> the ids a brand's v2 listing returns for a minimum discount */
function minDiscountIds(int $brand, int $min, int $perPage): array
{
    $body = getJson("/api/v2/brandfashion/products?brand={$brand}&min_discount={$min}&per_page={$perPage}")->assertOk()->json('data');

    return array_values(array_map(fn (mixed $p): int => T::int(T::arr($p)['id'] ?? null), T::arr($body)));
}

it('lists a sale at the percentage the card prints — 29.5 % is "−30 %" and in, 28.5 % is "−29 %" and out', function () {
    ['product' => $id, 'brand' => $brand] = minDiscountFixture();
    $set = fn (string $sale) => DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)
        ->update(['effective_price' => '1000.00', 'effective_sale_price' => $sale]);

    $set('705.00');   // (1000 − 705) / 1000 = 29.5 % → the card prints −30 %
    expect(minDiscountIds($brand, 30, 48))->toContain($id)
        ->and(minDiscountIds($brand, 31, 48))->not->toContain($id);

    $set('715.00');   // 28.5 % → −29 %
    expect(minDiscountIds($brand, 30, 47))->not->toContain($id)
        ->and(minDiscountIds($brand, 29, 47))->toContain($id);

    $set('1000.00');  // no valid sale: never on a discount list
    expect(minDiscountIds($brand, 1, 46))->not->toContain($id);
});

it('answers only valid sales at or above the bar, and narrows as the bar rises', function () {
    $totals = [];
    foreach ([20, 30, 40] as $min) {
        $res = getJson("/api/v2/brandfashion/products?min_discount={$min}&per_page=48")->assertOk();
        foreach (T::arr($res->json('data')) as $p) {
            $price = T::arr(T::arr($p)['price'] ?? null);
            expect($price['has_sale'] ?? null)->toBeTrue()
                ->and(T::int($price['discount_pct'] ?? null))->toBeGreaterThanOrEqual($min);
        }
        $totals[$min] = T::int($res->json('meta.total') ?? $res->json('meta.pagination.total'));
    }
    expect($totals[20])->toBeGreaterThanOrEqual($totals[30])
        ->and($totals[30])->toBeGreaterThanOrEqual($totals[40]);
});

it('refuses a bar that is not a whole percentage from 1 to 95', function (string $bad) {
    getJson("/api/v2/brandfashion/products?min_discount={$bad}")->assertStatus(422);
})->with(['0', '96', 'abc', '12.5']);

it('puts every card of the home deals band on the Sale page its "See all" opens (30 %+, in stock)', function () {
    $deals = array_values(array_map(
        fn (mixed $c): int => T::int(T::arr($c)['id'] ?? null),
        T::arr(getJson('/api/v2/brandfashion/home')->assertOk()->json('deals')),
    ));
    expect($deals)->not->toBeEmpty();
    $sale = array_values(array_map(
        fn (mixed $p): int => T::int(T::arr($p)['id'] ?? null),
        T::arr(getJson('/api/v2/brandfashion/products?min_discount=30&in_stock=1&on_sale=1&sort=newest&per_page=48')->assertOk()->json('data')),
    ));
    foreach ($deals as $id) {
        expect($sale)->toContain($id);
    }
});
