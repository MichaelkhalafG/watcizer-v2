<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * Legacy defect #6 (study §4.1 row 6): cancelling an order in the dashboard sets `orders.status`
 * and NOTHING else, so the reserved stock is never given back. The customer-facing cancellations
 * (Paymob session failure, failed callback) always restored; only the admin path did not, and it
 * has been leaking quantities for as long as the dashboard has existed.
 *
 * Wave 3 fixes it as a DELIBERATE, DOCUMENTED deviation (D-21 in the study's table): after the
 * switch, core's stock numbers differ from what the legacy code would have produced, and that
 * difference IS the bug being fixed.
 *
 * The fix is a reconciler rather than a hook because the cancellation does not come through
 * core: the Blade dashboard writes `orders.status` directly and keeps running until it is retired
 * at the switch. These tests cancel an order the same way it does — a bare UPDATE — and then
 * prove the stock comes back.
 */

/** @return array{order: int, product: int, before: int} */
function cancellationFixture(int $quantity = 2): array
{
    $row = T::row(DB::table('catalog_products')->whereNull('deleted_at')->where('stock_express', '>=', $quantity)->orderBy('id')->first(['id', 'stock_express']));
    $productId = Row::int($row, 'id');
    $before = Row::int($row, 'stock_express');

    $addressId = DB::table('addresses')->orderBy('id')->value('id');
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'address_id' => (int) (is_numeric($addressId) ? $addressId : 0),
        'total_price_for_order' => '0.00',
        'payment_method' => 'cash',
        'order_number' => '910'.random_int(100, 999),
        'status' => 'processing',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $productId, 'before' => $before];
}

it('gives the stock back when an order is cancelled the way the Blade dashboard cancels it', function () {
    $f = cancellationFixture(2);
    $service = app(InventoryService::class);

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2);

    // Exactly what Admin\OrderController::update does, and all it does.
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);
    expect($service->isReleased($f['order']))->toBeFalse();

    Artisan::call('inventory:reconcile-cancellations');

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'])
        ->and($service->isReleased($f['order']))->toBeTrue()
        ->and($service->ledgerQuantity(StockTarget::product($f['product']), 'express'))->toBe($f['before']);

    $movement = T::row(DB::table('inventory_movements')
        ->where('reference_type', 'orders')->where('reference_id', $f['order'])->where('reason', 'order_cancel')->first());
    expect(Row::int($movement, 'quantity_delta'))->toBe(2)
        ->and(Row::int($movement, 'quantity_after'))->toBe($f['before']);
});

it('never double-credits: running the reconciler again writes nothing', function () {
    $f = cancellationFixture(2);
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);

    Artisan::call('inventory:reconcile-cancellations');
    $movements = DB::table('inventory_movements')->count();
    $stock = T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express'));

    Artisan::call('inventory:reconcile-cancellations');
    Artisan::call('inventory:reconcile-cancellations');

    expect(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($stock)
        ->and($stock)->toBe($f['before']);
});

it('leaves an order that is not cancelled alone', function () {
    $f = cancellationFixture(2);

    Artisan::call('inventory:reconcile-cancellations');

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2)
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeFalse();
});

it('leaves an order that already released alone (the Paymob path got there first)', function () {
    $f = cancellationFixture(2);
    $service = app(InventoryService::class);

    $service->releaseOrder($f['order'], 'payment_failed');
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);
    $movements = DB::table('inventory_movements')->count();

    Artisan::call('inventory:reconcile-cancellations');

    expect(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'])
        // …and the reason recorded is the one that actually happened, not order_cancel.
        ->and(DB::table('inventory_movements')->where('reference_id', $f['order'])->where('reason', 'payment_failed')->exists())->toBeTrue()
        ->and(DB::table('inventory_movements')->where('reference_id', $f['order'])->where('reason', 'order_cancel')->exists())->toBeFalse();
});

it('reports without writing under --dry-run', function () {
    $f = cancellationFixture(2);
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);

    Artisan::call('inventory:reconcile-cancellations', ['--dry-run' => true]);

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2)
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeFalse()
        ->and(Artisan::output())->toContain('DRY RUN');
});

it('keeps inventory:verify green through a commit-cancel cycle', function () {
    $f = cancellationFixture(2);
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);
    Artisan::call('inventory:reconcile-cancellations');

    expect(Artisan::call('inventory:verify'))->toBe(0);
});
