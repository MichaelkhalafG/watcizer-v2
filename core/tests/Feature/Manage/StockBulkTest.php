<?php

use App\Domain\Activity\ActivityLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * The stock screen: narrowing, recognising, and bulk work (item 6, 2026-09-20).
 *
 * ── The scope decision this file also pins ──────────────────────────────────────────────────
 *
 * This screen is CATALOGUE-WIDE and stays that way. Stock lives on `catalog_products` and nowhere
 * else — `storefront_product` carries visibility, ordering, slug and price overrides, and no stock
 * — so there is one physical figure per product and both shops draw on it. Scoping the list to a
 * storefront would show the same twelve units on the shelf as Watchizer's twelve AND Brand
 * Fashion's twelve: a wrong number that looks right, which is worse than the confusion it cures.
 *
 * So the storefront filter is opt-in and never a default, and the screen says why.
 */

/** A product with no variants, so the service will take a product-level movement on it. */
function bulkProduct(): int
{
    $id = T::int(DB::table('catalog_products as p')
        ->whereNull('p.deleted_at')
        ->whereNotExists(function (Builder $sub): void {
            $sub->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->selectRaw('1');
        })
        ->value('p.id'));

    expect($id)->toBeGreaterThan(0, 'no variant-free product to exercise bulk stock on');

    return $id;
}

// ── the scope decision ───────────────────────────────────────────────────────────────────────

it('is catalogue-wide by default, and the storefront filter is opt-in', function () {
    actingAs(Staff::admin());

    $all = T::int(T::arr(T::arr(Props::of(get('/manage/inventory')->assertOk())['table'] ?? null)['meta'] ?? null)['total'] ?? null);
    $everything = T::int(DB::table('catalog_products')->whereNull('deleted_at')->count());

    expect($all)->toBe($everything, 'the stock list must show the whole warehouse by default');

    // …and asking for one shop narrows it, without claiming the stock belongs to that shop.
    $watchizer = T::int(T::arr(T::arr(Props::of(
        get('/manage/inventory?filters[storefront]=1')->assertOk()
    )['table'] ?? null)['meta'] ?? null)['total'] ?? null);

    $placed = T::int(DB::table('storefront_product')->where('storefront_id', 1)->distinct()->count('product_id'));

    expect($watchizer)->toBe($placed)
        ->and($watchizer)->toBeLessThan($all);
});

it('says on every row which shops sell it, and shows a cover', function () {
    // The column that answers the question the catalogue-wide scope raises: "why is this here?"
    actingAs(Staff::admin());
    $rows = T::arr(T::arr(Props::of(get('/manage/inventory')->assertOk())['table'] ?? null)['data'] ?? null);

    expect($rows)->not->toBe([]);

    $withShops = 0;
    foreach ($rows as $row) {
        $cast = T::arr($row);
        expect($cast)->toHaveKey('sold_on')->toHaveKey('cover');
        if (T::arr($cast['sold_on'] ?? null) !== []) {
            $withShops++;
        }
    }

    expect($withShops)->toBeGreaterThan(0, 'not one row said where it is sold');
});

// ── 6.1: narrowing ───────────────────────────────────────────────────────────────────────────

it('narrows by family, brand and category, in the products list own vocabulary', function () {
    actingAs(Staff::admin());

    $all = T::int(T::arr(T::arr(Props::of(get('/manage/inventory')->assertOk())['table'] ?? null)['meta'] ?? null)['total'] ?? null);

    $watches = T::int(T::arr(T::arr(Props::of(
        get('/manage/inventory?filters[p.family]=watch')->assertOk()
    )['table'] ?? null)['meta'] ?? null)['total'] ?? null);

    expect($watches)->toBe(T::int(DB::table('catalog_products')->whereNull('deleted_at')->where('family', 'watch')->count()))
        ->and($watches)->toBeLessThan($all);

    $brandId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->whereNotNull('brand_id')->value('brand_id'));
    $byBrand = T::int(T::arr(T::arr(Props::of(
        get('/manage/inventory?filters[p.brand_id]='.$brandId)->assertOk()
    )['table'] ?? null)['meta'] ?? null)['total'] ?? null);

    expect($byBrand)->toBe(T::int(DB::table('catalog_products')->whereNull('deleted_at')->where('brand_id', $brandId)->count()));
});

it('can be sorted by NAME, which it never could', function () {
    // The list sorted by code and by three numbers, and not by the one thing every row is headed
    // with. Somebody hunting "the brown leather one" had no way to bring the names into an order.
    actingAs(Staff::admin());
    $meta = T::arr(T::arr(Props::of(get('/manage/inventory?sort=title&direction=asc')->assertOk())['table'] ?? null)['meta'] ?? null);

    expect(T::str($meta['sort'] ?? null))->toBe('title')
        ->and(T::arr($meta['sortable'] ?? null))->toContain('title');
});

// ── 6.3: bulk, and the two modes ─────────────────────────────────────────────────────────────

it('COUNT TO replaces the quantity, and RECEIVE adds to it', function () {
    /*
     * The two modes, proven to be two different arithmetics on the same input — which is the whole
     * reason they are two buttons and not one dropdown. An operator meaning "a shipment of 5
     * arrived" who gets "set the shelf to 5" has written off the difference, and the ledger records
     * it as a deliberate correction because that is exactly what it was told.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $productId = bulkProduct();
    $before = T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));

    // COUNT TO 7 — the shelf now holds 7, whatever it held before.
    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 7, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express')))->toBe(7);

    // RECEIVE 5 — a delivery on top of the 7, not a replacement of it.
    post('/manage/inventory/bulk', [
        'action' => 'receive', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 5, 'reason' => 'restock',
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express')))->toBe(12);

    // Put it back through the same door, so the ledger stays consistent with the column.
    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => $before, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();
});

it('writes every movement through the LEDGER, never straight to the column', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $productId = bulkProduct();
    $before = T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));
    $movements = T::int(DB::table('inventory_movements')->count());

    post('/manage/inventory/bulk', [
        'action' => 'receive', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 3, 'reason' => 'restock', 'note' => 'a delivery',
    ])->assertSessionHasNoErrors();

    // The ledger grew, which is what makes the number explainable six months from now.
    expect(T::int(DB::table('inventory_movements')->count()))->toBeGreaterThan($movements);

    $movement = T::one(DB::table('inventory_movements')->orderByDesc('id'));
    expect(T::int($movement->quantity_delta))->toBe(3)
        ->and(T::str($movement->reason))->toBe('restock');

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => $before, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();
});

it('moves stock between buckets as TWO movements, leaving the total alone', function () {
    /*
     * Not one "transfer" row: the ledger's invariant is that the sum of a bucket's movements equals
     * that bucket's column, and `inventory:verify` checks exactly that nightly. A single row would
     * satisfy neither bucket.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $productId = bulkProduct();
    $row = T::one(DB::table('catalog_products')->where('id', $productId));
    $express = T::int($row->stock_express);
    $market = T::int($row->stock_market);

    // Make sure there is something to move.
    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 10, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    $totalBefore = 10 + T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_market'));
    $movements = T::int(DB::table('inventory_movements')->count());

    post('/manage/inventory/bulk', [
        'action' => 'move_bucket', 'ids' => [$productId],
        'bucket' => 'express', 'to_bucket' => 'market',
        'quantity' => 4, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    $after = T::one(DB::table('catalog_products')->where('id', $productId));

    expect(T::int($after->stock_express))->toBe(6)
        ->and(T::int($after->stock_express) + T::int($after->stock_market))->toBe($totalBefore)
        // TWO movements, one out and one in.
        ->and(T::int(DB::table('inventory_movements')->count()) - $movements)->toBe(2);

    // Restore both buckets exactly.
    post('/manage/inventory/bulk', ['action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express', 'quantity' => $express, 'reason' => 'adjustment']);
    post('/manage/inventory/bulk', ['action' => 'count_to', 'ids' => [$productId], 'bucket' => 'market', 'quantity' => $market, 'reason' => 'adjustment']);
});

it('SKIPS a product with sizes and names it, rather than failing the batch', function () {
    /*
     * Stock on a product with variants lives per variant, and the service refuses a product-level
     * movement on it — which is right, and is not a reason to abandon the other rows the operator
     * selected. Do what can be done; say exactly what could not.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $withVariants = T::int(DB::table('catalog_product_variants')->orderBy('product_id')->value('product_id'));
    $plain = bulkProduct();
    $before = T::int(DB::table('catalog_products')->where('id', $plain)->value('stock_express'));

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$withVariants, $plain], 'bucket' => 'express',
        'quantity' => 9, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    // The plain product moved…
    expect(T::int(DB::table('catalog_products')->where('id', $plain)->value('stock_express')))->toBe(9);

    // …and the one with sizes was named in the message rather than silently ignored.
    $status = T::str(session('status'));
    $code = T::str(DB::table('catalog_products')->where('id', $withVariants)->value('wa_code'));
    expect($status)->toContain($code);

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$plain], 'bucket' => 'express',
        'quantity' => $before, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();
});

it('offers NO apply-to-all-matching, because this writes the ledger', function () {
    /*
     * The products list offers "apply to all matching" for visibility and thresholds, which are
     * reversible. Every movement here is permanent, signed and reconciled nightly, so one crafted
     * query must not be able to rewrite the warehouse. The ids are explicit and capped.
     */
    $admin = Staff::admin();
    actingAs($admin);

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'scope' => 'matching', 'query' => '?filters[p.family]=watch',
        'bucket' => 'express', 'quantity' => 0, 'reason' => 'adjustment',
    ])->assertSessionHasErrors('ids');

    // …and the cap is real.
    $tooMany = range(1, 200);
    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => $tooMany, 'bucket' => 'express',
        'quantity' => 1, 'reason' => 'adjustment',
    ])->assertSessionHasErrors('ids');
});

it('records the batch in the activity log', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $productId = bulkProduct();
    $before = T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    post('/manage/inventory/bulk', [
        'action' => 'receive', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 2, 'reason' => 'restock',
    ])->assertSessionHasNoErrors();

    $entry = T::one(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)
        ->where('subject_type', 'catalog_products')->where('action', ActivityLog::ADJUSTED)
        ->whereNull('subject_id')->orderByDesc('id'));

    expect(T::str($entry->user_name))->toBe(Staff::nameOf($admin));

    $changes = T::str($entry->changes);
    expect($changes)->toContain('receive');

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => $before, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();
});

it('lets DATA-ENTRY do it, because counting the shelf is their job', function () {
    /*
     * The first draft of this test asserted a 403 and was wrong about the product, not about the
     * code: data-entry holds `manage-inventory` deliberately (§2.7, and the note on the variants
     * routes says so in as many words — "the QUANTITY is a stock movement, and `can:manage-inventory`
     * is the ability that says so. Data-entry holds both"). The people who count shelves are the
     * people this screen is for.
     *
     * So the safety here is not the ROLE. It is the cap of 100 ids, the absence of any
     * apply-to-all-matching, the ledger recording every movement with its actor and reason, and the
     * two modes being two buttons. Inventing a role restriction on top would have made the screen
     * unusable by the team it exists for, in exchange for none of those.
     */
    $operator = Staff::dataEntry();
    actingAs($operator);

    $productId = bulkProduct();
    $before = T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => 4, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express')))->toBe(4);

    // …and the ledger names WHO, which is what makes a permissive role safe to grant.
    $movement = T::one(DB::table('inventory_movements')->orderByDesc('id'));
    expect(T::int($movement->actor_id))->toBe(T::int($operator->getAuthIdentifier()))
        ->and(T::str($movement->actor_type))->not->toBe('');

    post('/manage/inventory/bulk', [
        'action' => 'count_to', 'ids' => [$productId], 'bucket' => 'express',
        'quantity' => $before, 'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();
});
