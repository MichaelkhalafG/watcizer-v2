<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Reference;
use App\Domain\Inventory\StockWriteGuard;
use App\Events\StockChanged;
use App\Listeners\EnqueueStockChangedOutbox;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\T;

/*
 * The single door for stock (CLEAN_CORE_STUDY §4.2). These pin the properties the rest of wave 3
 * relies on: atomic, append-only, observable — and the negative half, that nothing else can get
 * at a stock column at all.
 *
 * Real concurrency is NOT proven here and cannot be: the suite runs in one process inside one
 * transaction, so "concurrent" decrements would serialise and pass even against a broken
 * implementation. `php artisan inventory:prove-concurrency` starts real OS processes for that.
 */

/** A product with room in both buckets, restored by the enclosing transaction rollback. */
function stockedProductId(): int
{
    $id = DB::table('catalog_products')->whereNull('deleted_at')->where('stock_express', '>=', 3)->orderBy('id')->value('id');
    $id ??= DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id');

    return (int) (is_numeric($id) ? $id : 0);
}

function bucketValue(int $productId, string $bucket): int
{
    $column = InventoryService::columns()[$bucket];

    return T::int(DB::table('catalog_products')->where('id', $productId)->value($column));
}

it('decrements the column, appends one movement and reads quantity_after back inside the transaction', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();
    $before = bucketValue($id, 'express');

    $movement = $service->adjust($id, 'express', -2, 'order', Reference::order(4242), Actor::user(7), 1);

    expect(bucketValue($id, 'express'))->toBe($before - 2)
        ->and($movement->quantity_delta)->toBe(-2)
        ->and($movement->quantity_after)->toBe($before - 2)
        ->and($movement->reason)->toBe('order')
        ->and($movement->reference_type)->toBe('orders')
        ->and($movement->reference_id)->toBe(4242)
        ->and($movement->actor_type)->toBe('user')
        ->and($movement->actor_id)->toBe(7)
        ->and($movement->storefront_id)->toBe(1);
});

it('refuses a decrement the bucket cannot cover, and changes nothing when it does', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();
    $before = bucketValue($id, 'express');
    $movements = DB::table('inventory_movements')->where('product_id', $id)->count();

    expect(fn () => $service->adjust($id, 'express', -($before + 1), 'order'))
        ->toThrow(InsufficientStock::class);

    expect(bucketValue($id, 'express'))->toBe($before)
        ->and(DB::table('inventory_movements')->where('product_id', $id)->count())->toBe($movements);
});

it('never updates a movement: a correction is a new row with the opposite delta', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();

    $down = $service->adjust($id, 'express', -1, 'order', Reference::order(1));
    $up = $service->adjust($id, 'express', 1, 'order_cancel', Reference::order(1));

    expect($up->id)->not->toBe($down->id)
        ->and($down->quantity_delta + $up->quantity_delta)->toBe(0)
        ->and(DB::table('inventory_movements')->where('id', $down->id)->value('quantity_delta'))->toBe(-1);
});

it('keeps Σ quantity_delta equal to the column after a run of movements', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();

    $service->adjust($id, 'express', -1, 'order');
    $service->adjust($id, 'market', -1, 'order');
    $service->adjust($id, 'express', 1, 'order_cancel');
    $service->adjust($id, 'market', 5, 'restock');

    expect($service->ledgerQuantity($id, 'express'))->toBe(bucketValue($id, 'express'))
        ->and($service->ledgerQuantity($id, 'market'))->toBe(bucketValue($id, 'market'));
});

it('maintains in_stock as "either bucket has something"', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();

    $service->set($id, 'express', 0, 'manual');
    $service->set($id, 'market', 0, 'manual');
    expect(T::int(DB::table('catalog_products')->where('id', $id)->value('in_stock')))->toBe(0);

    $service->set($id, 'market', 3, 'restock');
    expect(T::int(DB::table('catalog_products')->where('id', $id)->value('in_stock')))->toBe(1);

    $service->set($id, 'market', 0, 'manual');
    $service->set($id, 'express', 2, 'restock');
    expect(T::int(DB::table('catalog_products')->where('id', $id)->value('in_stock')))->toBe(1);
});

it('set() writes nothing when the bucket already holds that quantity', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();
    $current = bucketValue($id, 'express');
    $movements = DB::table('inventory_movements')->count();

    expect($service->set($id, 'express', $current, 'import'))->toBeNull()
        ->and(DB::table('inventory_movements')->count())->toBe($movements);
});

it('rejects an unknown bucket, an unknown reason and a zero delta', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();

    expect(fn () => $service->adjust($id, 'warehouse', -1, 'order'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->adjust($id, 'express', -1, 'shrinkage'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->adjust($id, 'express', 0, 'order'))->toThrow(InvalidArgumentException::class);
});

it('fires StockChanged once per movement, with the before value derivable', function () {
    Event::fake([StockChanged::class]);
    $id = stockedProductId();
    $before = bucketValue($id, 'express');

    app(InventoryService::class)->adjust($id, 'express', -1, 'order');

    Event::assertDispatchedTimes(StockChanged::class, 1);
    Event::assertDispatched(StockChanged::class, fn (StockChanged $e): bool => $e->productId === $id
        && $e->bucket === 'express'
        && $e->delta === -1
        && $e->quantityAfter === $before - 1
        && $e->quantityBefore() === $before);
});

it('writes exactly one outbox row per movement (proving the listener is registered once)', function () {
    $id = stockedProductId();
    $before = DB::table('integration_outbox')->where('channel', EnqueueStockChangedOutbox::CHANNEL)->count();

    app(InventoryService::class)->adjust($id, 'express', -1, 'order');

    $rows = DB::table('integration_outbox')->where('channel', EnqueueStockChangedOutbox::CHANNEL)->orderByDesc('id')->get();
    expect($rows->count() - $before)->toBe(1);

    $row = T::row($rows->first());
    $payload = T::arr(json_decode(Row::str($row, 'payload'), true));
    expect(Row::str($row, 'event'))->toBe(EnqueueStockChangedOutbox::EVENT)
        ->and(Row::str($row, 'status'))->toBe('pending')
        ->and(Row::str($row, 'aggregate_type'))->toBe('catalog_products')
        ->and(Row::int($row, 'aggregate_id'))->toBe($id)
        ->and($payload)->toHaveKeys(['product_id', 'bucket', 'quantity_delta', 'quantity_after', 'quantity_before', 'reason'])
        ->and($payload['quantity_delta'])->toBe(-1);
});

it('bumps the storefront cache version of every storefront the product is placed in', function () {
    $cache = app(StorefrontCache::class);
    $id = stockedProductId();
    $storefronts = DB::table('storefront_product')->where('product_id', $id)->pluck('storefront_id')->map(fn (mixed $v): int => T::int($v))->all();
    $before = [];
    foreach ($storefronts as $sf) {
        $before[$sf] = $cache->version($sf);
    }

    app(InventoryService::class)->adjust($id, 'express', -1, 'order');

    foreach ($storefronts as $sf) {
        expect($cache->version($sf))->toBeGreaterThan($before[$sf]);
    }
})->skip(fn () => DB::table('storefront_product')->where('product_id', stockedProductId())->doesntExist(), 'product not placed on any storefront');

it('commits an order from the persisted lines and releases it exactly once', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();
    $before = bucketValue($id, 'express');
    $orderId = seedOrderWithLine($id, 2, 'Express');

    $service->commitOrder($orderId, Actor::system(), 1);
    expect(bucketValue($id, 'express'))->toBe($before - 2)
        ->and($service->isCommitted($orderId))->toBeTrue()
        ->and($service->isReleased($orderId))->toBeFalse();

    $first = $service->releaseOrder($orderId, 'order_cancel');
    expect(count($first))->toBe(1)
        ->and(bucketValue($id, 'express'))->toBe($before)
        ->and($service->isReleased($orderId))->toBeTrue();

    // Idempotent: a second release writes nothing and returns nothing.
    $second = $service->releaseOrder($orderId, 'order_cancel');
    expect($second)->toBe([])
        ->and(bucketValue($id, 'express'))->toBe($before);
});

it('maps type_stock to a bucket exactly as the legacy checkout did', function () {
    expect(InventoryService::bucketForTypeStock('Express'))->toBe('express')
        ->and(InventoryService::bucketForTypeStock('Market'))->toBe('market')
        // Anything that is not the literal 'Express' means market — NULL and a typo included.
        ->and(InventoryService::bucketForTypeStock(null))->toBe('market')
        ->and(InventoryService::bucketForTypeStock('express'))->toBe('market');
});

it('rebase() corrects the ledger onto the column without moving the column', function () {
    $service = app(InventoryService::class);
    $id = stockedProductId();
    $column = bucketValue($id, 'express');

    // Simulate a rogue writer: the column moved, the ledger did not.
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update(['stock_express' => $column + 4]));
    expect($service->ledgerQuantity($id, 'express'))->toBe($column);

    $movement = $service->rebase($id, 'express', 'test');

    expect($movement)->not->toBeNull()
        ->and(bucketValue($id, 'express'))->toBe($column + 4)          // column untouched
        ->and($service->ledgerQuantity($id, 'express'))->toBe($column + 4)
        ->and($movement?->reason)->toBe('adjustment')
        ->and($movement?->quantity_delta)->toBe(4);
});

/** An order with one product line, on the shared commerce tables; rolled back with the test. */
function seedOrderWithLine(int $productId, int $quantity, string $typeStock): int
{
    $addressId = T::int(DB::table('addresses')->orderBy('id')->value('id'));
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'address_id' => $addressId,
        'total_price_for_order' => '0.00',
        'payment_method' => 'cash',
        'order_number' => '900'.random_int(100, 999),
        'status' => 'processing',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => $productId,
        'offer_id' => null,
        'quantity' => $quantity,
        'piece_price' => '0.00',
        'total_price' => '0.00',
        'type_stock' => $typeStock,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $orderId;
}
