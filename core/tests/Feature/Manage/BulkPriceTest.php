<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\BulkPrice;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * BULK PRICE CHANGE on the products list (M1v, 2026-10-30): the arithmetic of every mode, target and rounding; the
 * preview writes nothing; the cap refuses before anything is written; apply needs a confirm, writes through the
 * patcher (each storefront's effective price follows), logs one row per product with old → new, and records the
 * batch; undo restores only what nobody changed since; the price-range and grade filters select what they say.
 *
 * Products are chosen from the data (active, placed on Watchizer) and their prices SET inside the test transaction.
 */

/**
 * The n first active products placed on storefront 1, with the given prices.
 *
 * @param  list<array{0: float, 1: float|null}>  $prices  selling, sale per product
 * @return list<int>
 */
function bpProducts(array $prices): array
{
    $ids = DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1))
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->orderBy('p.id')->limit(count($prices))->pluck('p.id')
        ->map(fn ($v) => T::int($v))->values()->all();
    foreach ($ids as $i => $id) {
        DB::table('catalog_products')->where('id', $id)->update([
            'selling_price' => number_format($prices[$i][0], 2, '.', ''),
            'sale_price' => $prices[$i][1] === null ? null : number_format($prices[$i][1], 2, '.', ''),
        ]);
    }

    return array_values($ids);
}

/** @return array{0: float, 1: float|null} */
function bpPrice(int $id): array
{
    $row = T::row(DB::table('catalog_products')->where('id', $id)->first(['selling_price', 'sale_price']));

    return [T::float($row->selling_price), $row->sale_price === null ? null : T::float($row->sale_price)];
}

/**
 * @param  list<int>  $ids
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function bpBody(array $ids, array $over = []): array
{
    return $over + ['ids' => $ids, 'mode' => 'increase_percent', 'value' => 10, 'target' => 'selling', 'rounding' => 0];
}

// ── 1. the arithmetic ────────────────────────────────────────────────────────────────────────

it('prices every mode, target and rounding as asked', function () {
    expect(BulkPrice::price(1000.0, null, 'increase_amount', 250, 'selling', 0))->toBe([1250.0, null, null])
        ->and(BulkPrice::price(1000.0, null, 'decrease_amount', 250, 'selling', 0))->toBe([750.0, null, null])
        ->and(BulkPrice::price(1000.0, null, 'increase_percent', 7, 'selling', 10))->toBe([1070.0, null, null])
        ->and(BulkPrice::price(1003.0, null, 'decrease_percent', 7, 'selling', 50))->toBe([950.0, null, null])   // 932.79 → 950
        ->and(BulkPrice::price(1000.0, null, 'set', 1499, 'selling', 100))->toBe([1500.0, null, null])
        // a sale target starts from the price when there is no sale, and creates one
        ->and(BulkPrice::price(1000.0, null, 'decrease_percent', 10, 'sale', 0))->toBe([1000.0, 900.0, null])
        // …and from the sale when there is one
        ->and(BulkPrice::price(1000.0, 800.0, 'decrease_amount', 100, 'sale', 0))->toBe([1000.0, 700.0, null])
        ->and(BulkPrice::price(1000.0, 800.0, 'set', 0, 'clear_sale', 0))->toBe([1000.0, null, null]);
});

it('never makes a price zero or negative, never a sale at or above the price, and clears a sale the new price meets', function () {
    expect(BulkPrice::price(100.0, null, 'decrease_amount', 100, 'selling', 0)[2])->toBe('not_positive')
        ->and(BulkPrice::price(100.0, null, 'decrease_percent', 100, 'selling', 0)[2])->toBe('not_positive')
        ->and(BulkPrice::price(100.0, null, 'set', 2, 'selling', 5)[2])->toBe('not_positive')                  // rounds to 0
        ->and(BulkPrice::price(1000.0, 800.0, 'increase_percent', 30, 'sale', 0)[2])->toBe('sale_not_below')   // 1,040 ≥ 1,000
        ->and(BulkPrice::price(1000.0, 800.0, 'set', 750, 'selling', 0))->toBe([750.0, null, 'cleared:sale']);
});

// ── 2. preview, cap, apply, undo through the screen's endpoints ─────────────────────────────

it('previews old → new and writes nothing', function () {
    $ids = bpProducts([[1000.0, null], [2000.0, 1500.0], [50.0, null]]);
    $before = array_map(bpPrice(...), $ids);

    $res = actingAs(Staff::admin())->postJson('/manage/storefronts/1/products/bulk-price/preview',
        bpBody($ids, ['mode' => 'decrease_amount', 'value' => 100]))->assertOk();

    $rows = [];
    foreach (T::rows($res->json('rows')) as $r) {
        $rows[T::int($r['id'] ?? null)] = $r;
    }
    expect(T::float($rows[$ids[0]]['new_selling'] ?? null))->toBe(900.0)
        ->and(T::float($rows[$ids[1]]['new_selling'] ?? null))->toBe(1900.0)
        ->and($rows[$ids[2]]['change'] ?? null)->toBeFalse()               // 50 − 100 ≤ 0: refused, named
        ->and($rows[$ids[2]]['note'] ?? null)->not->toBeNull()
        ->and(T::int($res->json('changing')))->toBe(2)
        ->and(array_map(bpPrice(...), $ids))->toBe($before);
});

it('refuses above the cap, with the count, before anything is written', function () {
    $total = T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1))
        ->whereNull('p.deleted_at')->count());
    expect($total)->toBeGreaterThan(BulkPrice::MAX);
    $batches = DB::table('core_price_batches')->count();

    $preview = actingAs(Staff::admin())->postJson('/manage/storefronts/1/products/bulk-price/preview',
        ['scope' => 'matching', 'query' => '', 'mode' => 'increase_percent', 'value' => 5, 'target' => 'selling', 'rounding' => 0])->assertOk();
    expect($preview->json('over_cap'))->toBeTrue()->and(T::int($preview->json('count')))->toBe($total)->and($preview->json('rows'))->toBe([]);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk-price',
        ['scope' => 'matching', 'query' => '', 'mode' => 'increase_percent', 'value' => 5, 'target' => 'selling', 'rounding' => 0, 'confirm' => '1'])
        ->assertSessionHasErrors('bulk_price');
    expect(DB::table('core_price_batches')->count())->toBe($batches);
});

it('applies only after a confirm: writes each product, every storefront follows, one log row each, the batch recorded', function () {
    $ids = bpProducts([[1000.0, null], [2000.0, 1500.0]]);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk-price', bpBody($ids))->assertSessionHasErrors('confirm');
    expect(bpPrice($ids[0]))->toBe([1000.0, null]);

    $admin = Staff::admin();
    actingAs($admin)->post('/manage/storefronts/1/products/bulk-price', bpBody($ids, ['confirm' => '1', 'rounding' => 5]))
        ->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(bpPrice($ids[0]))->toBe([1100.0, null])
        ->and(bpPrice($ids[1]))->toBe([2200.0, 1500.0])
        // the storefront's mirror follows (ProductWriter::refreshEffectivePrices, through the patcher)
        ->and(T::float(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $ids[0])->value('effective_price')))->toBe(1100.0);

    $batch = T::row(DB::table('core_price_batches')->orderByDesc('id')->first(['id', 'product_count', 'user_id', 'mode']));
    expect(T::int($batch->product_count))->toBe(2)->and(T::int($batch->user_id))->toBe(T::int($admin->getAuthIdentifier()))
        ->and(DB::table('core_price_batch_items')->where('batch_id', T::int($batch->id))->count())->toBe(2);

    $log = T::row(DB::table(ActivityLog::TABLE)->where('subject_type', 'catalog_products')->where('subject_id', $ids[0])->orderByDesc('id')->first(['changes', 'user_id', 'subject_label']));
    $changes = T::arr(json_decode(T::str($log->changes), true));
    expect(T::arr($changes['selling_price'] ?? null))->toBe(['from' => '1000.00', 'to' => '1100.00'])
        ->and(T::int($log->user_id))->toBe(T::int($admin->getAuthIdentifier()))
        ->and(T::str($log->subject_label))->toContain('#'.T::int($batch->id));
});

it('undoes a batch, but leaves a product whose price changed since', function () {
    $ids = bpProducts([[1000.0, null], [2000.0, null]]);
    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk-price', bpBody($ids, ['confirm' => '1']))->assertSessionHasNoErrors();
    $batch = T::int(DB::table('core_price_batches')->orderByDesc('id')->value('id'));

    // somebody edits the second product after the batch
    DB::table('catalog_products')->where('id', $ids[1])->update(['selling_price' => '2500.00']);

    actingAs(Staff::admin())->post("/manage/storefronts/1/products/bulk-price/{$batch}/undo")->assertSessionHas('status');

    expect(bpPrice($ids[0]))->toBe([1000.0, null])                          // restored
        ->and(bpPrice($ids[1]))->toBe([2500.0, null])                       // left alone
        ->and(DB::table('core_price_batches')->where('id', $batch)->value('undone_at'))->not->toBeNull();

    // twice is refused, not repeated
    actingAs(Staff::admin())->post("/manage/storefronts/1/products/bulk-price/{$batch}/undo")->assertSessionHas('error');
});

// ── 3. the filters the selection is built from ───────────────────────────────────────────────

it('selects by the charged price range and the grade, exactly as the list shows them', function () {
    $ids = bpProducts([[1234.00, null], [5000.00, 1236.00], [1240.00, null]]);
    $grade = T::int(DB::table('catalog_grades')->orderBy('id')->value('id'));
    DB::table('catalog_products')->whereIn('id', [$ids[0], $ids[1]])->update(['grade_id' => $grade]);
    $query = http_build_query(['filters' => ['price_min' => '1233', 'price_max' => '1237', 'p.grade_id' => (string) $grade, 'scope' => 'all']]);

    $res = actingAs(Staff::admin())->postJson('/manage/storefronts/1/products/bulk-price/preview',
        ['scope' => 'matching', 'query' => $query, 'mode' => 'increase_amount', 'value' => 1, 'target' => 'selling', 'rounding' => 0])->assertOk();

    $selected = collect(T::rows($res->json('rows')))->pluck('id')->map(fn ($v) => T::int($v))->all();
    $expected = T::int(DB::table('catalog_products')->whereNull('deleted_at')->where('grade_id', $grade)
        ->whereRaw('(CASE WHEN sale_price > 0 AND sale_price < selling_price THEN sale_price ELSE selling_price END) BETWEEN 1233 AND 1237')->count());
    expect($selected)->toContain($ids[0], $ids[1])                          // 1,234 and a sale of 1,236
        ->and($selected)->not->toContain($ids[2])                           // 1,240: outside the range
        ->and(count($selected))->toBe($expected);
});
