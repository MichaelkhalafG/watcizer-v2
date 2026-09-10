<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerState;
use Tests\Support\LegacyShadow;
use Tests\Support\T;

/*
 * Wave 3.5 — the transform's VARIANT path, proven by fixtures rather than by wishful thinking.
 *
 * Legacy `product_variants` is empty on today's dump, so steps 13 and 20 would otherwise carry
 * variant code that has never executed, and the first run of it would be the day Brand Fashion's
 * clothing catalogue lands. Every test here injects legacy variant rows into a session TEMPORARY
 * shadow (the wave-1 recipe, Tests\Support\LegacyShadow), runs the real steps against them, and
 * asserts the 65-table legacy digest is unchanged around the exercise.
 *
 * The whole file runs inside the suite's DatabaseTransactions, on both connections: the shadow,
 * the clean rows and the movements all disappear at the end of each test.
 */

afterEach(function () {
    LegacyShadow::closeAll();
});

/**
 * A legacy product to hang fixture variants on, with its PRODUCT-level baseline removed first.
 *
 * That deletion is not a convenience: a product that gains variants after its ledger was opened at
 * product level is exactly the un-rebuilt case, and it is a real mismatch — the last test in this
 * file asserts `inventory:verify` says so out loud. Switch night is a drop-and-rebuild (study §3.4
 * step 3b), so the honest fixture for "a product that sells through variants" is one whose ledger
 * has not already been opened at the wrong level.
 */
function variantFixtureProduct(): int
{
    $id = T::int(DB::connection('legacy')->table('products')->orderBy('id')->value('id'));
    DB::table('inventory_movements')->where('product_id', $id)->whereNull('variant_id')->delete();

    return $id;
}

/** Run only the two steps wave 3.5 touched. */
function runVariantSteps(): int
{
    return Artisan::call('core:transform', ['--only' => '13,20', '--force' => true]);
}

/** @param  list<array{id: int, stock: int}>  $variants */
function injectLegacyVariants(int $productId, array $variants): void
{
    LegacyShadow::open('product_variants', function (callable $table) use ($productId, $variants): void {
        foreach ($variants as $v) {
            $table()->insert([
                'id' => $v['id'],
                'product_id' => $productId,
                'name' => 'Size '.$v['id'],
                'price' => null,
                'stock' => $v['stock'],
                'sku' => 'FX-'.$v['id'],
                'image' => '',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
        }
    });
}

beforeEach(function () {
    // A dirty ledger is a database state, not a defect — skip with the reason instead of failing
    // this file's tests one broken assertion at a time. See Tests\Support\LedgerState.
    LedgerState::skipIfDirty();
});

it('leaves the real catalogue at product level: no variants, no variant movements', function () {
    // The no-regression baseline. Today's 464 watches and bags have no variants, so every
    // transform movement is product-level and there are exactly two per legacy product.
    $legacyProducts = DB::connection('legacy')->table('products')->count();

    expect(DB::table('catalog_product_variants')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('reason', 'transform')->whereNotNull('variant_id')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('reason', 'transform')->distinct()->count(DB::raw("CONCAT(product_id, ':', bucket)")))
        ->toBe($legacyProducts * 2);
});

it('makes the product the SUM of its variants and opens the ledger per variant', function () {
    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];
    $productId = variantFixtureProduct();
    injectLegacyVariants($productId, [['id' => 90001, 'stock' => 4], ['id' => 90002, 'stock' => 6]]);

    expect(runVariantSteps())->toBe(0);

    $variants = DB::table('catalog_product_variants')->where('product_id', $productId)->orderBy('id')->get();
    expect($variants)->toHaveCount(2)
        ->and(Row::int(T::row($variants[0]), 'stock_express'))->toBe(4)
        ->and(Row::int(T::row($variants[1]), 'stock_express'))->toBe(6);

    // The product's own columns became the aggregate, not legacy `products.stock`.
    $product = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market', 'in_stock']);
    expect($product)->not->toBeNull();
    expect(Row::int(T::row($product), 'stock_express'))->toBe(10)
        ->and(Row::int(T::row($product), 'stock_market'))->toBe(0)
        ->and(Row::bool(T::row($product), 'in_stock'))->toBeTrue();

    // The ledger opened per VARIANT — two buckets each — and names the legacy table it came from.
    $movements = DB::table('inventory_movements')->whereIn('variant_id', [90001, 90002])->where('reason', 'transform')->get();
    expect($movements)->toHaveCount(4);
    foreach ($movements as $movement) {
        expect(Row::str($movement, 'reference_type'))->toBe('legacy:product_variants')
            ->and(Row::int($movement, 'product_id'))->toBe($productId)
            ->and(Row::nstr($movement, 'note'))->toBe('transform baseline');
    }

    // …and NOT at product level, which is invariant 3 of inventory:verify stated as an assertion.
    expect(DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->count())->toBe(0);

    // The legacy set is byte-identical: the shadow was never the base table.
    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore);
});

it('is idempotent on the variant path: a second run writes nothing', function () {
    $productId = variantFixtureProduct();
    injectLegacyVariants($productId, [['id' => 90003, 'stock' => 7]]);

    runVariantSteps();
    $movements = DB::table('inventory_movements')->count();
    $stock = T::int(DB::table('catalog_product_variants')->where('id', 90003)->value('stock_express'));

    runVariantSteps();

    expect(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(T::int(DB::table('catalog_product_variants')->where('id', 90003)->value('stock_express')))->toBe($stock)
        ->and(Artisan::output())->toContain('inserted 0');
});

it('APPENDS a correction when a legacy variant quantity moves, and never edits the opening row', function () {
    $productId = variantFixtureProduct();
    injectLegacyVariants($productId, [['id' => 90004, 'stock' => 2]]);
    runVariantSteps();

    $opening = DB::table('inventory_movements')->where('variant_id', 90004)->where('bucket', 'express')->orderBy('id')->first();
    expect($opening)->not->toBeNull();
    $openingId = Row::int(T::row($opening), 'id');

    // The team restocks that size in the legacy dashboard.
    DB::connection('legacy')->table('product_variants')->where('id', 90004)->update(['stock' => 9]);
    runVariantSteps();

    $rows = DB::table('inventory_movements')->where('variant_id', 90004)->where('bucket', 'express')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(Row::int(T::row($rows[0]), 'id'))->toBe($openingId)
        ->and(Row::int(T::row($rows[0]), 'quantity_delta'))->toBe(2)                    // untouched
        ->and(Row::int(T::row($rows[1]), 'quantity_delta'))->toBe(7)                    // 9 − 2, appended
        ->and(Row::int(T::row($rows[1]), 'quantity_after'))->toBe(9)
        ->and(Row::nstr(T::row($rows[1]), 'note'))->toBe('transform re-baseline')
        ->and(T::int(DB::table('catalog_product_variants')->where('id', 90004)->value('stock_express')))->toBe(9)
        ->and(T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express')))->toBe(9);
});

it('leaves inventory:verify green over a transformed variant product', function () {
    $productId = variantFixtureProduct();
    injectLegacyVariants($productId, [['id' => 90005, 'stock' => 3], ['id' => 90006, 'stock' => 0]]);
    runVariantSteps();

    expect(Artisan::call('inventory:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('Ledger, variant columns, product aggregate and in_stock all agree');
});

it('DETECTS a product that gained variants without a rebuild — the stale product-level ledger', function () {
    // No variantFixtureProduct() here: the product keeps the product-level baseline it already
    // had. This is the un-rebuilt case, and it must be loud rather than silently absorbed —
    // switch night drops and rebuilds precisely so it cannot happen in production.
    $productId = T::int(DB::connection('legacy')->table('products')->orderBy('id')->value('id'));
    injectLegacyVariants($productId, [['id' => 90007, 'stock' => 5]]);
    runVariantSteps();

    expect(Artisan::call('inventory:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('product-level movement on a variant product');
});
