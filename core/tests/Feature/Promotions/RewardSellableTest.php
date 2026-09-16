<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use App\Domain\Promotions\PromotionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

/*
 * A promotion may never give away something the shop has withdrawn (review 🔴-1, 2026-09-15).
 *
 * ── Two places this has to be true, and they are not the same place ─────────────────────────
 *
 *   1. THE DOOR — `InventoryService` refuses a `promotion_reward` movement against an inactive
 *      product or variant. That is the guard that cannot be bypassed, and it is what makes any
 *      future reward path safe without its author remembering anything.
 *
 *   2. THE CHOICE — `PromotionEngine::rewardIsVisible()` must not SELECT one in the first place.
 *      The door alone is not enough: an engine that picks a gift the checkout then cannot reserve
 *      produces an exception in the middle of a customer's checkout, or a reward line whose stock
 *      never moved. Both are worse than simply not offering the gift.
 *
 * The gap this file was written for was in (2), and specifically in the VARIANT half: the check
 * resolved a variant to its parent product and then looked only at the parent. A withdrawn size of
 * a product still on sale — the likelier arrangement of the two, since deactivating one size is
 * routine — passed straight through.
 */

/** A variant on a giftable product, created the way the fixtures do it. */
function rewardVariant(int $productId, bool $active): int
{
    return T::int(StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->insertGetId([
        'product_id' => $productId,
        'label' => 'RW-'.Str::random(4),
        'sku' => 'RW-'.Str::random(8),
        'is_active' => $active ? 1 : 0,
        'stock_express' => 10,
        'stock_market' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ])));
}

// ── 1. the CHOICE — the engine must not pick it ─────────────────────────────────────────────

it('does not offer an INACTIVE PRODUCT as a reward', function () {
    $gift = PromotionFixture::giftableProduct();
    DB::table('catalog_products')->where('id', $gift)->update(['is_active' => 0]);

    $visible = (new ReflectionClass(PromotionEngine::class))->getMethod('rewardIsVisible');
    $visible->setAccessible(true);

    expect($visible->invoke(app(PromotionEngine::class), $gift, null, 1))->toBeFalse();
});

it('does not offer an INACTIVE VARIANT of an ACTIVE product — the gap this fixes', function () {
    /*
     * The parent stays on sale throughout. Before the fix the check resolved the variant to this
     * still-active parent and answered "visible", so a withdrawn size was giftable.
     */
    $gift = PromotionFixture::giftableProduct();
    $variantId = rewardVariant($gift, active: false);

    $visible = (new ReflectionClass(PromotionEngine::class))->getMethod('rewardIsVisible');
    $visible->setAccessible(true);
    $engine = app(PromotionEngine::class);

    expect($visible->invoke($engine, null, $variantId, 1))->toBeFalse()
        // …and the parent is genuinely still active, or this proves nothing.
        ->and(T::int(DB::table('catalog_products')->where('id', $gift)->value('is_active')))->toBe(1);

    // An ACTIVE variant of the same product is still offered — the check is not a blanket.
    $live = rewardVariant($gift, active: true);
    expect($visible->invoke($engine, null, $live, 1))->toBeTrue();
});

// ── 2. the DOOR — the service refuses the movement regardless ───────────────────────────────

it('refuses a promotion_reward movement against an inactive PRODUCT', function () {
    $gift = PromotionFixture::giftableProduct();
    DB::table('catalog_products')->where('id', $gift)->update(['is_active' => 0]);

    expect(fn () => app(InventoryService::class)->adjust(
        StockTarget::product($gift), InventoryService::BUCKET_EXPRESS, -1, 'promotion_reward'
    ))->toThrow(InvalidArgumentException::class, 'not active');
});

it('refuses a promotion_reward movement against an inactive VARIANT', function () {
    $gift = PromotionFixture::giftableProduct();
    $variantId = rewardVariant($gift, active: false);

    expect(fn () => app(InventoryService::class)->adjust(
        StockTarget::variant($gift, $variantId), InventoryService::BUCKET_EXPRESS, -1, 'promotion_reward'
    ))->toThrow(InvalidArgumentException::class, 'not active');
});

it('names promotion_reward in the SELLING set, so the door cannot be reopened by a new reason', function () {
    /*
     * The literal `'order'` is how this escaped once already. Anything that means "units left the
     * shop for a customer" belongs in this list, and the list is what the guard reads.
     */
    expect(InventoryService::SELLING_REASONS)->toContain('promotion_reward')->toContain('order');
});

// ── 3. the other half: a reward on a LIVE product still works ───────────────────────────────

it('still grants a reward when product and variant are both active', function () {
    $gift = PromotionFixture::giftableProduct();
    $variantId = rewardVariant($gift, active: true);

    $movement = app(InventoryService::class)->adjust(
        StockTarget::variant($gift, $variantId), InventoryService::BUCKET_EXPRESS, -1, 'promotion_reward'
    );

    expect($movement->quantity_delta)->toBe(-1);
});

it('still allows the reward to be RETURNED when the product is withdrawn afterwards', function () {
    /*
     * A gift granted while the product was on sale, then cancelled after it was withdrawn, must
     * give its unit back. A release is a positive delta and is allowed by direction — pinned here
     * because losing a unit is the quiet failure a blunter guard would have caused.
     */
    $gift = PromotionFixture::giftableProduct();
    DB::table('catalog_products')->where('id', $gift)->update(['is_active' => 0]);

    $movement = app(InventoryService::class)->adjust(
        StockTarget::product($gift), InventoryService::BUCKET_EXPRESS, 1, 'order_cancel'
    );

    expect($movement->quantity_delta)->toBe(1);
});
