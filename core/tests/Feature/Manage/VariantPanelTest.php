<?php

use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\VariantWriter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;

/**
 * A real size id. Since task 4.2 a variant must name a colour or a size — a row with neither is
 * not a variant, it is a second copy of the product with its own stock bucket — so every payload
 * below carries one.
 */
function aSizeId(): int
{
    return T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));
}

/*
 * The variants panel — where the four rules wave 3.5 settled meet a human with a mouse.
 *
 * Wave 3.5 built the mechanism and said in its own review that the DASHBOARD is what must enforce
 * three of them; the fourth (the conversion refusal) was written into AGENTS §3 as wave 4's exit
 * gate. All four are asserted here through the real endpoints, because "the service refuses" is
 * not the same claim as "the screen cannot ask".
 *
 *   1. a LIVE product may not be converted to variants before the write-switch,
 *   2. a quantity typed here becomes a LEDGER MOVEMENT, never a column write,
 *   3. a variant an order line references is never deleted,
 *   4. an `is_active` change re-derives `catalog_products.in_stock`.
 */

it('REFUSES the first variant on a live, legacy-backed product before the write-switch', function () {
    // The hard rule of AGENTS §3. `VariantFixture::converted()` needs a real product; here we
    // want an untouched one — a product the transform created, which therefore carries a
    // `reason = transform` baseline movement and is still being sold by the legacy checkout.
    $productId = T::int(DB::table('inventory_movements')
        ->where('reason', 'transform')
        ->whereNull('variant_id')
        ->orderBy('product_id')
        ->value('product_id'));

    expect(ConversionGuard::isLegacyBacked($productId))->toBeTrue('the fixture product must be legacy-backed')
        ->and(ConversionGuard::writeSwitchCompleted())->toBeFalse('the flag must default to pre-switch');

    $before = DB::table('catalog_product_variants')->where('product_id', $productId)->count();

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => '42mm',
        'size_id' => aSizeId(),
        'is_active' => true,
    ])->assertSessionHasErrors('variants');

    expect(DB::table('catalog_product_variants')->where('product_id', $productId)->count())->toBe($before)
        ->and(app(ConversionGuard::class)->mayConvert($productId)['allowed'])->toBeFalse();
});

it('states the refusal ON the product form instead of showing a button the server will reject', function () {
    $productId = T::int(DB::table('inventory_movements')
        ->where('reason', 'transform')->whereNull('variant_id')->orderBy('product_id')->value('product_id'));

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());

    expect($props['variants'])->toBeArray();
    /** @var array<string, mixed> $variants */
    $variants = $props['variants'];
    /** @var array<string, mixed> $state */
    $state = $variants['state'];

    expect($state['may_convert'])->toBeFalse()
        ->and($state['legacy_backed'])->toBeTrue()
        ->and($state['write_switch_completed'])->toBeFalse()
        // The reason is on the screen: "cannot" without "why" is a dead end for the person
        // holding the keyboard.
        ->and($state['reason'])->toBeString()
        ->and(T::str($state['reason']))->toContain('قبل التحويل النهائي');
});

it('ALLOWS variants on a product created in this dashboard — the safe path the rule names', function () {
    CatalogFixture::assumeSwitched();
    // "The safe paths are a NEW product that has variants from birth, or a conversion performed
    // after the switch" (AGENTS §3). A product created here has no legacy row and therefore no
    // baseline movement, so nothing else is selling against its stock column.
    $productId = CatalogFixture::product();

    expect(ConversionGuard::isLegacyBacked($productId))->toBeFalse()
        ->and(app(ConversionGuard::class)->mayConvert($productId)['allowed'])->toBeTrue();

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'أسود / 42مم',
        'size_id' => aSizeId(),
        'is_active' => true,
        'stock_express' => 7,
        'stock_market' => 3,
    ])->assertSessionHasNoErrors();

    $variant = T::one(DB::table('catalog_product_variants')->where('product_id', $productId));
    expect(Row::int($variant, 'stock_express'))->toBe(7)
        ->and(Row::int($variant, 'stock_market'))->toBe(3);
});

it('allows the conversion once the write-switch flag is on, and only then', function () {
    // The flag is fail-safe: FALSE by default, so forgetting it refuses. This proves the other
    // direction too — the guard is reading the flag rather than always saying no.
    $productId = T::int(DB::table('inventory_movements')
        ->where('reason', 'transform')->whereNull('variant_id')->orderBy('product_id')->value('product_id'));

    expect(app(ConversionGuard::class)->mayConvert($productId)['allowed'])->toBeFalse();

    config()->set('transform.write_switch_completed', true);

    expect(ConversionGuard::writeSwitchCompleted())->toBeTrue()
        ->and(app(ConversionGuard::class)->mayConvert($productId)['allowed'])->toBeTrue();

    config()->set('transform.write_switch_completed', false);
});

it('turns a typed quantity into a LEDGER MOVEMENT, not a column write', function () {
    // Rule 2, and the reason the panel posts per row: a quantity is a ledger event. The assertion
    // is not "the column changed" — it is "a movement exists, with an actor, and the column equals
    // the ledger", which is the property `inventory:verify` checks in production.
    $fixture = VariantFixture::synthetic(count: 1, express: 0, market: 0);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];
    $admin = Staff::admin();

    $movementsBefore = DB::table('inventory_movements')->where('variant_id', $variantId)->count();

    actingAs($admin)->put("/manage/products/{$productId}/variants/{$variantId}", [
        '_complete' => 1,
        'label' => 'Size 1',
        'size_id' => aSizeId(),
        'is_active' => true,
        'stock_express' => 12,
    ])->assertSessionHasNoErrors();

    $movement = T::one(DB::table('inventory_movements')
        ->where('variant_id', $variantId)->where('bucket', 'express')
        ->orderByDesc('id'));

    expect(DB::table('inventory_movements')->where('variant_id', $variantId)->count())->toBeGreaterThan($movementsBefore)
        ->and(Row::int($movement, 'quantity_after'))->toBe(12)
        ->and(Row::str($movement, 'reason'))->toBe('manual')
        // The actor is recorded: the ledger is the audit trail of a stock number, and "who" is
        // part of the trail.
        ->and(Row::str($movement, 'actor_type'))->toBe('user')
        ->and(Row::int($movement, 'actor_id'))->toBe($admin->id);

    // Column equals the ledger, at the variant level — the wave-3.5 invariant.
    $ledger = app(InventoryService::class)->ledgerQuantity(StockTarget::variant($productId, $variantId), 'express');
    $column = T::int(DB::table('catalog_product_variants')->where('id', $variantId)->value('stock_express'));
    expect($column)->toBe($ledger)->toBe(12);
});

it('writes NO movement when the quantity is unchanged', function () {
    // `set()` returns null for a no-op, so saving a form without touching stock must not grow the
    // ledger. An import that changes nothing must change nothing.
    $fixture = VariantFixture::synthetic(count: 1, express: 5, market: 0);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];

    $before = DB::table('inventory_movements')->where('variant_id', $variantId)->count();

    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$variantId}", [
        '_complete' => 1,
        'label' => 'renamed but same stock',
        'size_id' => aSizeId(),
        'is_active' => true,
        'stock_express' => 5,
    ])->assertSessionHasNoErrors();

    expect(DB::table('inventory_movements')->where('variant_id', $variantId)->count())->toBe($before);
});

it('maintains the product aggregate as the exact sum of its variants', function () {
    $fixture = VariantFixture::synthetic(count: 2, express: 4, market: 1);
    $productId = $fixture['product'];

    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$fixture['variants'][0]}", [
        'label' => 'Size 1',
        'size_id' => aSizeId(), 'is_active' => true, 'stock_express' => 10,
    ])->assertSessionHasNoErrors();

    $product = T::row(DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']));
    $sum = T::one(DB::table('catalog_product_variants')->where('product_id', $productId)
        ->selectRaw('SUM(stock_express) as e, SUM(stock_market) as m'));

    expect(Row::int($product, 'stock_express'))->toBe(Row::int($sum, 'e'))
        ->and(Row::int($product, 'stock_market'))->toBe(Row::int($sum, 'm'));
});

it('REFUSES to delete a variant an order line references, and says which', function () {
    // Rule 3. `order_items.variant_id` would become a dangling id and a later cancellation could
    // not return the units it took.
    $fixture = VariantFixture::converted(count: 1, express: 5);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];
    VariantFixture::order($productId, $variantId, 2);

    // Bring it to zero first, so the refusal under test is the ORDER-LINE one and not the
    // "still holds units" one — two different refusals with two different fixes.
    app(InventoryService::class)->set(StockTarget::variant($productId, $variantId), 'express', 0, 'manual');

    actingAs(Staff::admin())->delete("/manage/products/{$productId}/variants/{$variantId}")
        ->assertSessionHasErrors('variants');

    expect(DB::table('catalog_product_variants')->where('id', $variantId)->exists())->toBeTrue();

    expect(T::err('variants'))->toContain('سطر طلب');
});

it('REFUSES to delete a variant that still holds units, for a different reason', function () {
    $fixture = VariantFixture::synthetic(count: 1, express: 3, market: 0);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];

    actingAs(Staff::admin())->delete("/manage/products/{$productId}/variants/{$variantId}")
        ->assertSessionHasErrors('variants');

    expect(DB::table('catalog_product_variants')->where('id', $variantId)->exists())->toBeTrue();
    expect(T::err('variants'))->toContain('وحدة');
});

it('REFUSES to delete a variant that has ledger movements, because the FK would RE-LEVEL them', function () {
    // Found by this test rather than by reading the schema: `inventory_movements.variant_id` is
    // ON DELETE SET NULL, so deleting a variant does not erase its movements — it strips their
    // LEVEL, turning variant-level rows into product-level rows on a product that HAS variants.
    // That is exactly the state the wave-3.5 reconciliation check
    // `catalog_products[no product-level movement on a variant product]` exists to catch, and
    // `inventory:verify` would report it forever after.
    $fixture = VariantFixture::synthetic(count: 2, express: 4, market: 0);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][1];

    // Zeroed through the service, so neither of the other two refusals applies…
    app(InventoryService::class)->set(StockTarget::variant($productId, $variantId), 'express', 0, 'manual');
    expect(DB::table('inventory_movements')->where('variant_id', $variantId)->count())->toBeGreaterThan(0)
        ->and(T::int(DB::table('catalog_product_variants')->where('id', $variantId)->value('stock_express')))->toBe(0);

    // …and it is STILL refused, with the third reason.
    actingAs(Staff::admin())->delete("/manage/products/{$productId}/variants/{$variantId}")
        ->assertSessionHasErrors('variants');

    expect(DB::table('catalog_product_variants')->where('id', $variantId)->exists())->toBeTrue();
    expect(T::err('variants'))->toContain('حركة');

    // And the invariant the refusal protects still holds: no product-level movement exists on a
    // product that has variants.
    $productLevel = DB::table('inventory_movements')
        ->where('product_id', $productId)->whereNull('variant_id')->count();
    expect($productLevel)->toBe(0);
});

it('deletes a variant that has NO history at all — the mistyped row', function () {
    CatalogFixture::assumeSwitched();
    // A row added by mistake, never stocked: no order line, no units, no movements. There is
    // nothing for the FK to re-level, so deleting it is safe and the panel allows it.
    $productId = CatalogFixture::product();
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'typo',
        'size_id' => aSizeId(), 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $variantId = T::int(DB::table('catalog_product_variants')->where('product_id', $productId)->value('id'));
    expect(DB::table('inventory_movements')->where('variant_id', $variantId)->count())->toBe(0);

    actingAs(Staff::admin())->delete("/manage/products/{$productId}/variants/{$variantId}")
        ->assertSessionHasNoErrors();

    expect(DB::table('catalog_product_variants')->where('id', $variantId)->exists())->toBeFalse();
});

it('re-derives in_stock when a variant is deactivated, without moving a unit', function () {
    // Rule 4. Deactivating moves no units and writes no movement, but it does change whether the
    // product is orderable: `in_stock` means "some ACTIVE variant has stock". Forgetting the
    // recompute leaves a product that looks orderable with nothing buyable behind it.
    $fixture = VariantFixture::synthetic(count: 1, express: 6, market: 0);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('in_stock')) === 1)->toBeTrue();
    $movementsBefore = DB::table('inventory_movements')->where('variant_id', $variantId)->count();

    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$variantId}", [
        '_complete' => 1,
        'label' => 'Size 1',
        'size_id' => aSizeId(), 'is_active' => false, 'stock_express' => 6,
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('in_stock')) === 1)->toBeFalse()
        // No movement: the units are still in the warehouse and still in the ledger.
        ->and(DB::table('inventory_movements')->where('variant_id', $variantId)->count())->toBe($movementsBefore)
        ->and(T::int(DB::table('catalog_product_variants')->where('id', $variantId)->value('stock_express')))->toBe(6);

    // …and reactivating brings it back, so the flag is derived and not stamped.
    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$variantId}", [
        '_complete' => 1,
        'label' => 'Size 1',
        'size_id' => aSizeId(), 'is_active' => true, 'stock_express' => 6,
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $productId)->value('in_stock')) === 1)->toBeTrue();
});

it('never lets the panel write a stock column directly', function () {
    // The payload carries stock columns as if they were attributes. `VariantWriter::COLUMNS` does
    // not include them, so they are ignored — and the quantity fields that ARE honoured go
    // through the service. This is the "no stock write bypass" non-negotiable, asserted rather
    // than asserted-by-comment.
    expect(VariantWriter::COLUMNS)->not->toContain('stock_express')
        ->and(VariantWriter::COLUMNS)->not->toContain('stock_market');

    // And the same for the product writer: a product save can never move stock.
    expect(ProductWriter::COLUMNS)->not->toContain('stock_express')
        ->and(ProductWriter::COLUMNS)->not->toContain('stock_market')
        ->and(ProductWriter::COLUMNS)->not->toContain('in_stock');
});

it('shows the panel why each row may or may not be deleted', function () {
    $fixture = VariantFixture::converted(count: 1, express: 4);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];
    VariantFixture::order($productId, $variantId, 1);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    /** @var array<string, mixed> $variants */
    $variants = $props['variants'];
    /** @var list<array<string, mixed>> $rows */
    $rows = $variants['rows'];

    $row = [];
    foreach ($rows as $candidate) {
        if (T::int($candidate['id']) === $variantId) {
            $row = $candidate;
        }
    }

    expect($row)->not->toBe([])
        ->and($row['may_delete'])->toBeFalse()
        ->and($row['order_lines'])->toBe(1)
        ->and($row['delete_blocked_reason'])->toBeString();
});
