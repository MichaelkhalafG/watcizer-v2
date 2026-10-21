<?php

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * THE CART'S QUERY AND TIME BUDGET — compat before, v2 after (slice 3, 2026-10-21).
 *
 * `QueryBudgetTest` holds the listing, the product page and the sitemaps to a ceiling so an N+1 cannot ship
 * unnoticed. The cart had none, on either API. This measures BOTH paths the way the storefront drives them —
 * compat's `add_to_cart` → `me/cart` → `cart/validate`, which is what Watchizer's screen did until slice 3, and
 * v2's `POST cart/items` → `GET cart` → `PATCH` → `DELETE`, which is what it does now — and prints every number
 * for the record. The ceilings below are the v2 numbers plus headroom; the compat numbers are printed, not
 * asserted, because compat is what is being left.
 *
 * What a cart read must NOT do is grow with the number of lines: a GET at one line and at three is measured
 * for exactly that.
 */

const CQB_API_KEY = 'cart-budget-test-key';

beforeEach(function () {
    H::flush();
    config(['compat.api_key' => CQB_API_KEY]);
});

/** @return array{queries: int, ms: float} */
function cqbMeasure(Closure $request): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $t = hrtime(true);
    $request();
    $ms = (hrtime(true) - $t) / 1e6;
    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    if (getenv('CQB_DUMP')) { fwrite(STDERR, "
--- ".count($log)." queries ---
"); foreach ($log as $q) { fwrite(STDERR, '  '.substr($q['query'], 0, 150)."
"); } }

    return ['queries' => count($log), 'ms' => round($ms, 1)];
}

/**
 * A visible, in-stock storefront-1 product with NO active variants.
 *
 * @return array{id: int, price: float}
 */
function cqbPlain(int $skip = 0): array
{
    $row = T::row(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>=', 3)
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')
            ->whereColumn('v.product_id', 'sp.product_id')->where('v.is_active', 1))
        ->orderBy('sp.product_id')->skip($skip)
        ->first(['sp.product_id', 'sp.effective_price', 'sp.effective_sale_price']));

    return [
        'id' => Row::int($row, 'product_id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/**
 * A visible storefront-1 product with an active, in-stock variant.
 *
 * @return array{id: int, variant: int}
 */
function cqbVariant(): array
{
    $row = T::row(DB::table('catalog_product_variants as v')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'v.product_id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1))
        ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
        ->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->where('v.is_active', 1)->where('v.stock_express', '>=', 3)
        ->orderBy('v.product_id')->orderBy('v.id')
        ->first(['v.product_id', 'v.id as variant_id']));

    return ['id' => Row::int($row, 'product_id'), 'variant' => Row::int($row, 'variant_id')];
}

it('prints the compat cart path for the record, and holds the v2 cart to its budget', function () {
    $a = cqbPlain(0);
    $b = cqbPlain(1);
    $v = cqbVariant();
    expect($a['id'])->toBeGreaterThan(0)->and($b['id'])->not->toBe($a['id'])->and($v['variant'])->toBeGreaterThan(0);

    // ── BEFORE: compat, driven as the storefront drove it ─────────────────────────────────────
    $compatToken = (string) Str::uuid();
    $compat = fn () => withHeaders(['Api-Code' => CQB_API_KEY, 'X-Guest-Token' => $compatToken]);
    $before = [
        'add_to_cart' => cqbMeasure(fn () => $compat()->postJson('/api/add_to_cart', [
            'product_id' => $a['id'], 'quantity' => 1, 'piece_price' => $a['price'], 'total_price' => $a['price'], 'type_stock' => 'Express',
        ])->assertOk()),
        'me_cart_1_line' => cqbMeasure(fn () => $compat()->getJson('/api/me/cart')->assertOk()),
        'cart_validate_1_line' => cqbMeasure(fn () => $compat()->postJson('/api/cart/validate')->assertOk()),
    ];

    // ── AFTER: v2, driven as the storefront drives it now ─────────────────────────────────────
    $token = (string) Str::uuid();
    $v2 = fn () => withHeaders(['X-Guest-Token' => $token]);
    $after = [];
    $after['add_plain_cold'] = cqbMeasure(fn () => $v2()->postJson(H::base('cart/items'), ['product_id' => $a['id'], 'quantity' => 1])->assertOk());
    $after['get_1_line'] = cqbMeasure(fn () => $v2()->getJson(H::base('cart'))->assertOk());
    $after['add_variant'] = cqbMeasure(fn () => $v2()->postJson(H::base('cart/items'), ['product_id' => $v['id'], 'variant_id' => $v['variant'], 'quantity' => 1])->assertOk());
    $after['add_plain_warm'] = cqbMeasure(fn () => $v2()->postJson(H::base('cart/items'), ['product_id' => $b['id'], 'quantity' => 2])->assertOk());
    $after['get_3_lines'] = cqbMeasure(fn () => $v2()->getJson(H::base('cart'))->assertOk());

    $lines = T::arr($v2()->getJson(H::base('cart'))->json('data.items'));
    expect(count($lines))->toBe(3);
    $lineId = T::int(T::arr($lines[0])['id'] ?? null);
    $after['patch_line'] = cqbMeasure(fn () => $v2()->patchJson(H::base('cart/items/'.$lineId), ['quantity' => 2])->assertOk());
    $after['delete_line'] = cqbMeasure(fn () => $v2()->deleteJson(H::base('cart/items/'.$lineId))->assertOk());

    fwrite(STDERR, "\ncart budget — BEFORE (compat): ".json_encode($before)."\ncart budget — AFTER (v2): ".json_encode($after)."\n");

    // A read must not grow with the lines in it. The one query a three-line read may add over a one-line read
    // is the variants-by-id lookup, which exists only when a variant line exists — not one per line. Before
    // the batched `productsWithVariants()` this read paid one `exists` per plain line (11 → 13 measured).
    expect($after['get_3_lines']['queries'])->toBeLessThanOrEqual($after['get_1_line']['queries'] + 1);

    // The ceilings: measured 2026-10-21 on the dev database, demo variants on (RUN record), plus headroom.
    // Warm: GET 11/12, POST 19–21, PATCH 18, DELETE 13; 12–23 ms in-process. Compat's path, for the record:
    // add 9, me/cart 6, cart/validate 6 — v2 pays more per call because every answer carries the product
    // cards and re-prices every line, which is the point of it; what it must never do is scale with the cart.
    expect($after['get_1_line']['queries'])->toBeLessThanOrEqual(12)
        ->and($after['get_3_lines']['queries'])->toBeLessThanOrEqual(12)
        ->and($after['add_plain_warm']['queries'])->toBeLessThanOrEqual(22)
        ->and($after['add_variant']['queries'])->toBeLessThanOrEqual(22)
        ->and($after['patch_line']['queries'])->toBeLessThanOrEqual(20)
        ->and($after['delete_line']['queries'])->toBeLessThanOrEqual(14);
    foreach (['get_1_line', 'get_3_lines', 'add_plain_warm', 'add_variant', 'patch_line', 'delete_line'] as $k) {
        expect($after[$k]['ms'])->toBeLessThan(250.0, $k.' took '.$after[$k]['ms'].' ms warm');
    }
});
