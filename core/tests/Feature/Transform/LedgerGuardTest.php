<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * Wave-3 guard rail: the transform refuses to run once the ledger holds movements it did not
 * write.
 *
 * Step 20 re-baselines `inventory_movements` from legacy `products.stock`. That is only
 * meaningful while the ledger holds nothing but transform rows. The moment a real sale,
 * cancellation or dashboard adjustment lands, legacy stock is no longer the truth and a
 * re-baseline would quietly contradict the movements that ARE the truth. There is no override:
 * the answer is the drop-and-rebuild of study §3.4 step 3b, which drops the ledger with the rest
 * of the clean tables.
 */

it('refuses to run when the ledger holds a movement the transform did not write', function () {
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    app(InventoryService::class)->adjust(StockTarget::product($productId), 'market', 1, 'restock');

    $exit = Artisan::call('core:transform', ['--force' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('inventory_movements holds movements the transform did not write')
        ->and($output)->toContain('restock')
        ->and($output)->toContain('There is no override')
        // It aborts BEFORE the audit, so nothing was read and nothing was written.
        ->and($output)->not->toContain('reconciliation');
});

it('names every foreign reason and its count', function () {
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $service = app(InventoryService::class);
    $service->adjust(StockTarget::product($productId), 'market', 1, 'restock');
    $service->adjust(StockTarget::product($productId), 'market', 1, 'manual');
    $service->adjust(StockTarget::product($productId), 'market', 1, 'manual');

    Artisan::call('core:transform', ['--force' => true]);

    expect(Artisan::output())->toContain('manual × 2')->toContain('restock × 1');
});

it('still allows --audit, which writes nothing', function () {
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    app(InventoryService::class)->adjust(StockTarget::product($productId), 'market', 1, 'restock');

    $exit = Artisan::call('core:transform', ['--audit' => true, '--force' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('holds movements the transform did not write');
});

it('runs normally while the ledger holds only transform rows', function () {
    expect(DB::table('inventory_movements')->where('reason', '!=', 'transform')->count())->toBe(0);

    $exit = Artisan::call('core:transform', ['--force' => true, '--only' => '20']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('holds movements the transform did not write');
});

it('appends a re-baseline row instead of editing the opening one when legacy stock moved', function () {
    // The milestone audit caught step 20 UPDATING its baseline row in place, which made the
    // ledger a mutable snapshot instead of a ledger. Simulate a drifted ledger and prove the
    // step corrects it by APPENDING.
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $legacyStock = T::int(DB::connection('legacy')->table('products')->where('id', $productId)->value('stock'));

    $opening = T::row(DB::table('inventory_movements')
        ->where('product_id', $productId)->where('bucket', 'express')->where('reason', 'transform')
        ->orderByDesc('id')->first());
    $openingId = Row::int($opening, 'id');

    // Pretend the last transform saw a different quantity.
    DB::table('inventory_movements')->where('id', $openingId)->update(['quantity_after' => $legacyStock + 7]);
    $rows = DB::table('inventory_movements')->where('product_id', $productId)->where('bucket', 'express')->count();

    Artisan::call('core:transform', ['--force' => true, '--only' => '20']);

    $after = T::row(DB::table('inventory_movements')->where('product_id', $productId)->where('bucket', 'express')->orderByDesc('id')->first());

    expect(DB::table('inventory_movements')->where('product_id', $productId)->where('bucket', 'express')->count())->toBe($rows + 1)
        ->and(Row::int($after, 'id'))->not->toBe($openingId)
        ->and(Row::int($after, 'quantity_after'))->toBe($legacyStock)
        ->and(Row::int($after, 'quantity_delta'))->toBe(-7)
        ->and(Row::nstr($after, 'note'))->toBe('transform re-baseline')
        // The row it disagreed with is untouched: an append-only ledger never edits history.
        ->and(T::int(DB::table('inventory_movements')->where('id', $openingId)->value('quantity_after')))->toBe($legacyStock + 7);
});

it('writes nothing on a second pass once the ledger agrees again', function () {
    Artisan::call('core:transform', ['--force' => true, '--only' => '20']);
    $rows = DB::table('inventory_movements')->count();

    Artisan::call('core:transform', ['--force' => true, '--only' => '20']);

    expect(DB::table('inventory_movements')->count())->toBe($rows);
});
