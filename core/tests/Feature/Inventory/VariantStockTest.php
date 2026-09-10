<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Reference;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use App\Events\StockChanged;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\T;
use Tests\Support\VariantFixture;

/*
 * Wave 3.5 — stock per VARIANT.
 *
 * The rule the whole wave rests on: a product with variants is authoritative at the VARIANT, its
 * own columns are a maintained aggregate, and neither level can be written the other's way. These
 * tests pin that rule, the guards that hold it, and the `in_stock` semantics that no quantity
 * check would catch.
 *
 * Real concurrency lives in `inventory:prove-concurrency --variants=1|2`, which found a genuine
 * deadlock here on its first run (two variants of one product each holding a row the other
 * needed). A single-process test cannot reproduce that and does not pretend to.
 */

/** @return array{product: int, variants: list<int>} */
function variantFixture(int $count = 2, int $express = 5, int $market = 3): array
{
    return VariantFixture::synthetic($count, $express, $market);
}

function productStock(int $productId, string $column): int
{
    return T::int(DB::table('catalog_products')->where('id', $productId)->value($column));
}

function variantStock(int $variantId, string $column): int
{
    return T::int(DB::table('catalog_product_variants')->where('id', $variantId)->value($column));
}

it('keeps the product columns as the exact sum of its variants', function () {
    $f = variantFixture(3, 5, 3);

    expect(productStock($f['product'], 'stock_express'))->toBe(15)
        ->and(productStock($f['product'], 'stock_market'))->toBe(9);

    app(InventoryService::class)->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -2, 'order');

    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(3)
        ->and(variantStock($f['variants'][1], 'stock_express'))->toBe(5)   // untouched
        ->and(productStock($f['product'], 'stock_express'))->toBe(13);      // 3 + 5 + 5
});

it('writes the ledger at the VARIANT level, and the variant ledger equals the variant column', function () {
    $f = variantFixture(2, 5, 0);
    $service = app(InventoryService::class);

    $service->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -2, 'order');

    expect($service->ledgerQuantity(StockTarget::variant($f['product'], $f['variants'][0]), 'express'))->toBe(3)
        ->and($service->ledgerQuantity(StockTarget::variant($f['product'], $f['variants'][1]), 'express'))->toBe(5)
        // …and NOTHING was written at product level for a product that sells through variants.
        ->and($service->ledgerQuantity(StockTarget::product($f['product']), 'express'))->toBe(0)
        ->and(DB::table('inventory_movements')->where('product_id', $f['product'])->whereNull('variant_id')->count())->toBe(0);

    $movement = T::row(DB::table('inventory_movements')->where('variant_id', $f['variants'][0])->where('reason', 'order')->first());
    expect(Row::nint($movement, 'variant_id'))->toBe($f['variants'][0])
        ->and(Row::int($movement, 'product_id'))->toBe($f['product'])
        ->and(Row::int($movement, 'quantity_after'))->toBe(3);
});

it('refuses a PRODUCT-level movement against a product that has variants', function () {
    $f = variantFixture(2);

    expect(fn () => app(InventoryService::class)->adjust(StockTarget::product($f['product']), 'express', -1, 'order'))
        ->toThrow(InvalidArgumentException::class, 'has variants');

    expect(productStock($f['product'], 'stock_express'))->toBe(10);
});

it('refuses a variant that belongs to another product', function () {
    $a = variantFixture(1);
    $b = variantFixture(1);

    expect(fn () => app(InventoryService::class)->adjust(StockTarget::variant($a['product'], $b['variants'][0]), 'express', -1, 'order'))
        ->toThrow(InvalidArgumentException::class, 'does not belong to product');
});

it('refuses to oversell a variant, and leaves its siblings alone', function () {
    $f = variantFixture(2, 5, 0);

    expect(fn () => app(InventoryService::class)->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -6, 'order'))
        ->toThrow(InsufficientStock::class);

    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(5)
        ->and(variantStock($f['variants'][1], 'stock_express'))->toBe(5)
        ->and(productStock($f['product'], 'stock_express'))->toBe(10);
});

it('names the variant in the refusal, which the legacy 422 body has no field for', function () {
    $f = variantFixture(1, 2, 0);

    try {
        app(InventoryService::class)->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -9, 'order');
        expect(false)->toBeTrue();
    } catch (InsufficientStock $e) {
        expect($e->variantId)->toBe($f['variants'][0])
            ->and($e->productId)->toBe($f['product'])
            ->and($e->getMessage())->toContain('variant');
    }
});

it('sets in_stock from the ACTIVE variants, not from the quantity', function () {
    $f = variantFixture(2, 1, 0);
    $service = app(InventoryService::class);
    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('in_stock')))->toBe(1);

    // Sell one variant out: the other still has stock, so the product stays orderable.
    $service->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -1, 'order');
    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('in_stock')))->toBe(1);

    // Sell the second out too: now nothing is orderable.
    $service->adjust(StockTarget::variant($f['product'], $f['variants'][1]), 'express', -1, 'order');
    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('in_stock')))->toBe(0)
        ->and(productStock($f['product'], 'stock_express'))->toBe(0);
});

it('counts a DEACTIVATED variant in the quantity but never in in_stock', function () {
    // The distinction the aggregate rule exists for: units of a hidden variant are real units
    // sitting in the warehouse, and the ledger has to balance on them — but they must not make
    // the product look buyable.
    $f = variantFixture(2, 4, 0);
    $service = app(InventoryService::class);

    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->whereIn('id', $f['variants'])->update(['is_active' => 0]));
    $service->recomputeInStock($f['product']);

    expect(productStock($f['product'], 'stock_express'))->toBe(8)     // the units are still there
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('in_stock')))->toBe(0);

    // A movement on a deactivated variant is still legal — a release of an old order must work.
    $service->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', 2, 'order_cancel');
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(6)
        ->and(productStock($f['product'], 'stock_express'))->toBe(10);
});

it('commits and releases an order line that names a variant, exactly once', function () {
    $f = convertedVariantFixture(2, 5);
    $service = app(InventoryService::class);
    $orderId = seedVariantOrder($f['product'], $f['variants'][0], 3);

    $service->commitOrder($orderId, Actor::system(), 1);
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(2)
        ->and(productStock($f['product'], 'stock_express'))->toBe(7);

    $service->releaseOrder($orderId, 'order_cancel');
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(5)
        ->and(productStock($f['product'], 'stock_express'))->toBe(10);

    // Idempotent at variant level too.
    expect($service->releaseOrder($orderId, 'order_cancel'))->toBe([])
        ->and(variantStock($f['variants'][0], 'stock_express'))->toBe(5);
});

it('applies the exactly-once index to a variant line', function () {
    $f = convertedVariantFixture(1, 9);
    $service = app(InventoryService::class);
    $orderId = seedVariantOrder($f['product'], $f['variants'][0], 2);
    $lineId = T::int(DB::table('order_items')->where('order_id', $orderId)->value('id'));

    $service->commitOrder($orderId, Actor::system(), 1);

    expect(fn () => $service->adjust(
        StockTarget::variant($f['product'], $f['variants'][0]), 'express', -2, 'order',
        Reference::orderLine($orderId, $lineId), Actor::system(), 1,
    ))->toThrow(QueryException::class);

    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(7);
});

it('reports a variant drift and re-bases it at the variant level', function () {
    $f = variantFixture(1, 5, 0);
    $service = app(InventoryService::class);

    // A rogue writer moves the column without a movement.
    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->where('id', $f['variants'][0])->update(['stock_express' => 9]));

    $movement = $service->rebase(StockTarget::variant($f['product'], $f['variants'][0]), 'express', 'test');

    expect($movement)->not->toBeNull()
        ->and($movement?->variant_id)->toBe($f['variants'][0])
        ->and($movement?->quantity_delta)->toBe(4)
        ->and($service->ledgerQuantity(StockTarget::variant($f['product'], $f['variants'][0]), 'express'))->toBe(9);
});

it('carries the variant on the StockChanged event', function () {
    $f = variantFixture(1, 5, 0);
    Event::fake([StockChanged::class]);

    app(InventoryService::class)->adjust(StockTarget::variant($f['product'], $f['variants'][0]), 'express', -1, 'order');

    Event::assertDispatched(StockChanged::class,
        fn (StockChanged $e): bool => $e->variantId === $f['variants'][0] && $e->isVariant() && $e->productId === $f['product']);
});

it('names the variant in the ERP outbox row, so a size change is not reported as a product change', function () {
    $f = variantFixture(2, 5, 0);
    DB::table('integration_outbox')->where('aggregate_type', 'catalog_products')->where('aggregate_id', $f['product'])->delete();

    app(InventoryService::class)->adjust(StockTarget::variant($f['product'], $f['variants'][1]), 'express', -2, 'order');

    $row = T::row(DB::table('integration_outbox')->where('aggregate_id', $f['product'])->orderByDesc('id')->first());
    $payload = T::arr(json_decode(Row::str($row, 'payload'), true));

    expect($payload['product_id'])->toBe($f['product'])
        ->and($payload['variant_id'])->toBe($f['variants'][1])
        ->and($payload['quantity_delta'])->toBe(-2)
        ->and($payload['quantity_after'])->toBe(3);          // the VARIANT's number, not the aggregate
});

// ── review 2026-09-10 ─────────────────────────────────────────────────────────────────────────

it('refuses to SELL a deactivated variant, and still lets a release and a correction through', function () {
    // 🟠-3. Deactivating a size takes it off sale, but its units stay in the warehouse and in the
    // ledger — so nothing in the quantity arithmetic stopped a sale from decrementing it. A stale
    // cart line or a queued order would have sold a size the shop had withdrawn.
    $f = variantFixture(1, 6, 0);
    $target = StockTarget::variant($f['product'], $f['variants'][0]);
    $service = app(InventoryService::class);

    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->where('id', $f['variants'][0])->update(['is_active' => 0]));
    $service->recomputeInStock($f['product']);

    // A SALE is refused, and nothing moved.
    expect(fn () => $service->adjust($target, 'express', -1, 'order'))
        ->toThrow(InvalidArgumentException::class, 'is not active, so it cannot be sold');
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(6)
        ->and(productStock($f['product'], 'stock_express'))->toBe(6)
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('in_stock')))->toBe(0);

    // A RELEASE must still work: it gives back stock taken while the variant was still active,
    // and refusing it would lose units.
    $service->adjust($target, 'express', 2, 'order_cancel');
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(8)
        ->and(productStock($f['product'], 'stock_express'))->toBe(8);

    // …and a NEGATIVE administrative correction is deliberately still allowed (wider than the
    // review asked for): refusing it would make a withdrawn size impossible to zero out or
    // retire, and would leave `inventory:verify --fix` unable to re-base a drifted one.
    $service->adjust($target, 'express', -3, 'adjustment');
    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(5)
        ->and($service->ledgerQuantity($target, 'express'))->toBe(5);
});

it('sells an ACTIVE variant normally, so the refusal is about is_active and nothing else', function () {
    $f = variantFixture(1, 6, 0);
    $target = StockTarget::variant($f['product'], $f['variants'][0]);

    app(InventoryService::class)->adjust($target, 'express', -1, 'order');

    expect(variantStock($f['variants'][0], 'stock_express'))->toBe(5);
});

it('keeps a plain stocked product IN STOCK when recomputeInStock is called on it', function () {
    // 🟠-2. The first version of `recomputeInStock()` wrote EXISTS(active variant with stock)
    // unconditionally, so calling it on a product with no variants set in_stock = 0 and removed a
    // fully stocked watch from every listing. Wave 4's dashboard is told to call this after a
    // catalog edit, so the trap was one screen away from being sprung on the live catalogue.
    $productId = T::int(DB::table('catalog_products')->where('stock_express', '>', 0)->orderBy('id')->value('id'));
    expect(DB::table('catalog_product_variants')->where('product_id', $productId)->exists())->toBeFalse();

    app(InventoryService::class)->recomputeInStock($productId);

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('in_stock')))->toBe(1);
});

it('still marks a plain product OUT of stock when both its buckets are empty', function () {
    // The other half of 🟠-2: level-aware must not mean level-blind. A zero-stock product without
    // variants must come out of the same call with in_stock = 0.
    $f = variantFixture(1, 0, 0);
    $plain = T::int(DB::table('catalog_products')->where('id', '!=', $f['product'])
        ->where('stock_express', 0)->where('stock_market', 0)->orderBy('id')->value('id'));
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $plain)->update(['in_stock' => 1]));

    app(InventoryService::class)->recomputeInStock($plain);

    expect(T::int(DB::table('catalog_products')->where('id', $plain)->value('in_stock')))->toBe(0);
});

it('gives set() and adjust() the SAME lock order, so they cannot deadlock against each other', function () {
    // 🔴-1. `set()` used to open its own transaction and lock the VARIANT row to read the current
    // quantity, then call `adjust()`, which locks the PARENT first — an inverted pair. A single
    // process cannot reproduce the deadlock (that is what `inventory:prove-set-race` is for), but
    // it CAN pin the structural claim: neither method takes a lock outside `apply()`.
    $source = file_get_contents(base_path('app/Domain/Inventory/InventoryService.php'));
    expect($source)->toBeString();
    $source = (string) $source;

    // Every lockForUpdate() in the class, with the method it sits in.
    $methods = [];
    $current = '';
    foreach (explode("\n", $source) as $line) {
        if (preg_match('/(?:public|private|protected) function (\w+)/', $line, $m) === 1) {
            $current = $m[1];
        }
        if (str_contains($line, 'lockForUpdate()')) {
            $methods[] = $current;
        }
    }

    expect(array_values(array_unique($methods)))->toBe(['apply', 'lockOrder'])
        ->and($methods)->not->toContain('set')
        ->and($methods)->not->toContain('adjust');
});

it('resolves an absolute set to a delta and writes exactly one movement, at the variant level', function () {
    $f = variantFixture(1, 5, 0);
    $target = StockTarget::variant($f['product'], $f['variants'][0]);
    $service = app(InventoryService::class);
    $before = DB::table('inventory_movements')->where('variant_id', $f['variants'][0])->count();

    $movement = $service->set($target, 'express', 12, 'import', null, null, null, 'erp-doc-1');

    expect($movement)->not->toBeNull()
        ->and(variantStock($f['variants'][0], 'stock_express'))->toBe(12)
        ->and(productStock($f['product'], 'stock_express'))->toBe(12)
        ->and(DB::table('inventory_movements')->where('variant_id', $f['variants'][0])->count())->toBe($before + 1)
        ->and($service->ledgerQuantity($target, 'express'))->toBe(12);

    // The same quantity again is not a movement.
    expect($service->set($target, 'express', 12, 'import'))->toBeNull()
        ->and(DB::table('inventory_movements')->where('variant_id', $f['variants'][0])->count())->toBe($before + 1);
});

it('refuses an absolute set against a row that does not exist, at either level', function () {
    $f = variantFixture(1, 1, 0);

    expect(fn () => app(InventoryService::class)->set(StockTarget::variant($f['product'], 99999999), 'express', 3, 'manual'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(InventoryService::class)->set(StockTarget::product(99999999), 'express', 3, 'manual'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * A real product converted to sell through variants — see Tests\Support\VariantFixture for why
 * the conversion is done by hand.
 *
 * @return array{product: int, variants: list<int>}
 */
function convertedVariantFixture(int $count = 1, int $express = 9): array
{
    return VariantFixture::converted($count, $express);
}

/** An order with one line naming a variant. */
function seedVariantOrder(int $productId, int $variantId, int $quantity): int
{
    return VariantFixture::order($productId, $variantId, $quantity);
}
