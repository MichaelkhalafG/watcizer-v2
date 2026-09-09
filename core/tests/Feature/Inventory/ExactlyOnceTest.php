<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Reference;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * Review finding 🔴-1: `releaseOrder()` was idempotent only in SEQUENCE. It asked `isReleased()`
 * and then wrote, and a dozen concurrent cancellations all read "not released yet" and all
 * credited the stock back.
 *
 * The fix is two layers, and this file pins both of them plus the thing the review was right to
 * worry about — that a unique index strict enough to stop a double release could also block valid
 * history:
 *
 *   layer 1  a `SELECT … FOR UPDATE` on the order row taken BEFORE the check
 *   layer 2  M1e's UNIQUE (reference_type, reference_id, reason, reference_line_id)
 *
 * The concurrency itself cannot be proven here (one process, one rolled-back transaction) —
 * `inventory:prove-release-race` spawns twelve real processes for that. What IS proven here is
 * that layer 2 holds on its own, which is what makes the concurrent result trustworthy.
 */

/** @return array{order: int, product: int, lines: list<int>, before: int} */
function exactlyOnceOrder(int $quantity = 2, int $lineCount = 1): array
{
    $row = T::row(DB::table('catalog_products')->whereNull('deleted_at')
        // Headroom for one MORE line than the order has, so a duplicate-movement attempt reaches
        // the unique index instead of being turned away earlier by InsufficientStock.
        ->where('stock_express', '>=', $quantity * ($lineCount + 1))->orderBy('id')
        ->first(['id', 'stock_express']));
    $productId = Row::int($row, 'id');
    $before = Row::int($row, 'stock_express');

    $addressId = T::int(DB::table('addresses')->orderBy('id')->value('id'));
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null, 'address_id' => $addressId,
        'total_price_for_order' => '0.00', 'payment_method' => 'cash',
        'order_number' => 'ZZ'.random_int(100000, 999999), 'status' => 'processing',
        'guest_name' => 'exactly-once', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lines = [];
    for ($i = 0; $i < $lineCount; $i++) {
        $lines[] = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
            'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
            'type_stock' => 'Express',
            // Two lines for the SAME product and bucket, told apart only by the colour — the
            // shape that rules out (order, product, bucket) as a unique key.
            'color_band' => $i === 0 ? null : '#ABCDEF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['order' => $orderId, 'product' => $productId, 'lines' => $lines, 'before' => $before];
}

it('carries the order LINE on every order movement, which is what the unique key is built on', function () {
    $f = exactlyOnceOrder(1, 2);

    app(InventoryService::class)->commitOrder($f['order'], Actor::system(), 1);

    $movements = DB::table('inventory_movements')
        ->where('reference_type', 'orders')->where('reference_id', $f['order'])
        ->orderBy('id')->get();

    expect($movements)->toHaveCount(2);
    $lineIds = [];
    foreach ($movements as $movement) {
        $lineIds[] = Row::nint($movement, 'reference_line_id');
    }
    expect($lineIds)->toBe($f['lines']);
});

it('lets ONE order carry two movements for the same product and bucket', function () {
    // The exact case that makes the bare (reference_type, reference_id, reason) triple wrong:
    // the same watch in two band colours is two cart lines, two order lines and two movements.
    $f = exactlyOnceOrder(1, 2);

    $movements = app(InventoryService::class)->commitOrder($f['order'], Actor::system(), 1);

    expect($movements)->toHaveCount(2)
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2);
});

it('refuses a second movement for the SAME line and reason at the database level', function () {
    $f = exactlyOnceOrder(2, 1);
    $service = app(InventoryService::class);
    $service->commitOrder($f['order'], Actor::system(), 1);

    // Reach around the service's own lock to prove layer 2 stands alone: the index refuses this
    // whatever the caller believed about the state.
    expect(fn () => $service->adjust(
        $f['product'], 'express', -2, 'order',
        Reference::orderLine($f['order'], $f['lines'][0]), Actor::system(), 1,
    ))->toThrow(QueryException::class);

    expect(DB::table('inventory_movements')->where('reference_id', $f['order'])->where('reason', 'order')->count())->toBe(1);
});

it('refuses a second RELEASE for the same line, and leaves the stock where it was', function () {
    $f = exactlyOnceOrder(2, 1);
    $service = app(InventoryService::class);
    $service->commitOrder($f['order'], Actor::system(), 1);
    $service->releaseOrder($f['order'], 'order_cancel', Actor::system(), 1);

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before']);

    expect(fn () => $service->adjust(
        $f['product'], 'express', 2, 'order_cancel',
        Reference::orderLine($f['order'], $f['lines'][0]), Actor::system(), 1,
    ))->toThrow(QueryException::class);

    // The stock did NOT go one release higher than the real quantity.
    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'])
        ->and($service->ledgerQuantity($f['product'], 'express'))->toBe($f['before']);
});

it('still lets a cancel and a payment failure be told apart on the same line', function () {
    // Different reasons are different keys. An order cancelled after a failed payment is odd but
    // not impossible, and the ledger must be able to say both things happened.
    $f = exactlyOnceOrder(2, 1);
    $service = app(InventoryService::class);
    $service->commitOrder($f['order'], Actor::system(), 1);
    $service->releaseOrder($f['order'], 'payment_failed', Actor::system(), 1);

    $second = $service->adjust(
        $f['product'], 'express', 0 + 2, 'order_cancel',
        Reference::orderLine($f['order'], $f['lines'][0]), Actor::system(), 1,
    );

    expect($second->reason)->toBe('order_cancel');
});

it('leaves every legitimate repeat reason completely unconstrained', function (string $reason) {
    // The review's worry, answered: `reference_line_id` is NULL for anything that is not an order
    // line, and MariaDB does not treat two NULLs as equal in a unique index. A product can be
    // restocked every week and re-based twice in a row without the constraint noticing.
    $id = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $service = app(InventoryService::class);
    $before = DB::table('inventory_movements')->where('product_id', $id)->where('reason', $reason)->count();

    $service->adjust($id, 'market', 1, $reason);
    $service->adjust($id, 'market', 1, $reason);
    $service->adjust($id, 'market', 1, $reason);

    expect(DB::table('inventory_movements')->where('product_id', $id)->where('reason', $reason)->count())->toBe($before + 3);
})->with(['restock', 'manual', 'adjustment', 'import', 'erp_sync']);

it('lets the transform append as many baseline corrections as legacy movement needs', function () {
    // Step 20's corrections all carry reference_type `legacy:products` and a NULL line, so the
    // index never sees them. A transform that had to correct the same bucket twice would
    // otherwise fail on the second correction.
    $id = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $before = DB::table('inventory_movements')->where('product_id', $id)->where('reason', 'transform')->count();

    foreach ([1, 1, 1] as $delta) {
        DB::table('inventory_movements')->insert([
            'product_id' => $id, 'bucket' => 'express', 'quantity_delta' => $delta,
            'quantity_after' => 0, 'reason' => 'transform',
            'reference_type' => 'legacy:products', 'reference_id' => $id, 'reference_line_id' => null,
            'actor_type' => 'system', 'created_at' => now()->toDateTimeString(),
        ]);
    }

    expect(DB::table('inventory_movements')->where('product_id', $id)->where('reason', 'transform')->count())->toBe($before + 3);
});

it('refuses a second payment_statuses row for the same Paymob transaction', function () {
    $txn = random_int(900000000, 999999999);
    $row = fn (): array => [
        'order_id' => null, 'pay_order_id' => null, 'pay_transaction_id' => $txn,
        'amount_cents' => 1000, 'success' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('payment_statuses')->insert($row());

    expect(fn () => DB::table('payment_statuses')->insert($row()))->toThrow(QueryException::class);
    expect(DB::table('payment_statuses')->where('pay_transaction_id', $txn)->count())->toBe(1);
});

it('still allows several callbacks that carry no transaction id at all', function () {
    // The legacy column is nullable and a callback without an id is still worth recording; NULL
    // is not equal to NULL in a unique index, so the constraint does not touch them.
    $before = DB::table('payment_statuses')->whereNull('pay_transaction_id')->count();

    foreach ([1, 2] as $ignored) {
        DB::table('payment_statuses')->insert([
            'order_id' => null, 'pay_order_id' => null, 'pay_transaction_id' => null,
            'amount_cents' => 500, 'success' => 'false', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('payment_statuses')->whereNull('pay_transaction_id')->count())->toBe($before + 2);
});
