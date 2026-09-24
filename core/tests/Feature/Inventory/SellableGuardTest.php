<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * 🔴-3 — nothing withdrawn from sale may be sold. Both layers, every path.
 *
 * ── The defect ──────────────────────────────────────────────────────────────────────────────
 *
 * Deactivating a product is how the team takes it off sale. It vanished from every listing, and
 * nothing in the quantity arithmetic stopped a SALE from decrementing it — the compat door checked
 * only `deleted_at`, and `InventoryService`'s guard covered VARIANTS on reason `order` alone. So a
 * deactivated product could still be bought, and a promotion could still give one away.
 *
 * ── The shape of the fix, which is what these tests pin ─────────────────────────────────────
 *
 * The real guard is INSIDE `InventoryService`, because that is the door every movement passes
 * through — cart, checkout, promotion reward, and anything written later. The compat layer's own
 * checks are the courtesy on top: a cart that accepts a line it can never check out is a customer
 * who finds out at the last step.
 *
 * ── The half that matters just as much ──────────────────────────────────────────────────────
 *
 * A guard that refuses everything is not a fix. Deactivating a product must NOT strand its units:
 * the team still has to correct, restock, zero out and release them. Half of this file proves the
 * administrative paths still work — that is the failure mode a blunter guard would have created.
 */

const SELL_KEY = 'test-api-code';

beforeEach(function () {
    CatalogFixture::assumeSwitched();
    config(['compat.api_key' => SELL_KEY]);
});

/**
 * A product the COMPAT CART can actually hold, and a fresh guest token.
 *
 * `cart_items` is a legacy table whose foreign key points at the legacy `products` table, so a line
 * may only name a product the TRANSFORM produced — a fixture row exists on the clean side only and
 * fails the constraint. That is why these cases take a real storefront-1 product and toggle it,
 * rather than building one.
 *
 * @return array{0: int, 1: string}
 */
function sellableSubject(bool $active = true): array
{
    $productId = T::int(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)
        ->whereNull('p.deleted_at')
        ->whereNull('p.import_ref')
        ->whereNotExists(function (Builder $q): void {
            $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->selectRaw('1');
        })
        ->orderBy('p.id')
        ->value('p.id'));

    DB::table('catalog_products')->where('id', $productId)->update(['is_active' => $active ? 1 : 0]);

    // Enough stock that the quantity check is never what refuses the line.
    app(InventoryService::class)->adjust(
        StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, 10, 'manual'
    );

    return [$productId, (string) Str::uuid()];
}

/** @return TestResponse<Response> */
function addToCart(int $productId, string $token, int $quantity = 1): TestResponse
{
    return withHeaders(['Api-Code' => SELL_KEY, 'X-Guest-Token' => $token])
        ->postJson('/api/add_to_cart', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'piece_price' => 500,
            'total_price' => 500 * $quantity,
            'type_stock' => 'Express',
        ]);
}

// ── 1. the compat door ───────────────────────────────────────────────────────────────────────

it('refuses an INACTIVE product at add-to-cart', function () {
    [$productId, $token] = sellableSubject(active: false);
    $before = T::int(DB::table('cart_items')->count());

    // Before this fix the reader returned nothing and every check was skipped, so the line was
    // added anyway. The refusal is now explicit, and it writes nothing.
    addToCart($productId, $token)->assertStatus(422);

    expect(T::int(DB::table('cart_items')->count()))->toBe($before);
});

it('still accepts an ACTIVE product, or the test above proves nothing', function () {
    [$productId, $token] = sellableSubject(active: true);
    $before = T::int(DB::table('cart_items')->count());

    addToCart($productId, $token)->assertOk();

    expect(T::int(DB::table('cart_items')->count()))->toBe($before + 1);
});

it('refuses an ARCHIVED product too', function () {
    [$productId, $token] = sellableSubject(active: true);
    $before = T::int(DB::table('cart_items')->count());
    DB::table('catalog_products')->where('id', $productId)->update(['deleted_at' => now()]);

    /*
     * Refused with a DIFFERENT status from the inactive case, and deliberately so: `deleted_at` is
     * caught by the `exists` validation rule, and this controller reproduces legacy's "a validation
     * failure is a 500 with a ref" (its own comment: "copied, not corrected"). Asserting 422 here
     * would be asserting a contract the compat layer does not promise — what matters is that it is
     * refused and writes nothing.
     */
    $response = addToCart($productId, $token);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(T::arr($response->json())['success'] ?? null)->toBeFalse()
        ->and(T::int(DB::table('cart_items')->count()))->toBe($before);
});

it('refuses a product deactivated AFTER it was already in the cart, at checkout', function () {
    /*
     * The case the door alone cannot catch, and the reason the guard lives in the service: the line
     * was legitimate when it was added. `priceLines()` reads through the same catalog reader, so a
     * withdrawn product becomes "no longer available" instead of a priced line.
     */
    [$productId, $token] = sellableSubject(active: true);
    addToCart($productId, $token)->assertOk();

    DB::table('catalog_products')->where('id', $productId)->update(['is_active' => 0]);

    // `cart/validate` and `add_order` both price through `priceLines()`, which reads the same
    // catalog reader — so the withdrawn product is "no longer available" at both.
    $response = withHeaders(['Api-Code' => SELL_KEY, 'X-Guest-Token' => $token])
        ->postJson('/api/cart/validate', []);

    expect(T::str(json_encode($response->json())))->toContain('no longer available');
});

// ── 2. the single door: InventoryService ─────────────────────────────────────────────────────

it('refuses to SELL an inactive product through the service, whatever the caller', function () {
    [$productId] = sellableSubject(active: false);
    $service = app(InventoryService::class);

    foreach (InventoryService::SELLING_REASONS as $reason) {
        expect(fn () => $service->adjust(
            StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, -1, $reason
        ))->toThrow(InvalidArgumentException::class, 'not active');
    }
});

it('refuses a PROMOTION REWARD of an inactive product — the reason the old guard missed', function () {
    /*
     * The old guard compared the reason against the literal `'order'`. Wave 4D added
     * `promotion_reward`, which is every bit as much a thing leaving the shop, and it walked
     * straight past. `SELLING_REASONS` is a named list so the next one cannot.
     */
    [$productId] = sellableSubject(active: false);

    expect(fn () => app(InventoryService::class)->adjust(
        StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, -1, 'promotion_reward'
    ))->toThrow(InvalidArgumentException::class, 'not active');

    expect(InventoryService::SELLING_REASONS)->toContain('order')->toContain('promotion_reward');
});

it('refuses to sell an inactive VARIANT, and an active variant of an INACTIVE product', function () {
    [$productId] = sellableSubject(active: true);
    // `StockWriteGuard` refuses a stock column written outside the service — correctly. Creating
    // a variant is a CATALOG write that happens to carry stock columns, which is the exemption the
    // transform and the fixtures use too.
    $variantId = T::int(StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->insertGetId([
        'product_id' => $productId, 'label' => 'M', 'sku' => 'SELL-'.Str::random(6),
        'is_active' => 0, 'stock_express' => 5, 'stock_market' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ])));

    $service = app(InventoryService::class);
    $target = StockTarget::variant($productId, $variantId);

    // The variant is off sale…
    expect(fn () => $service->adjust($target, InventoryService::BUCKET_EXPRESS, -1, 'order'))
        ->toThrow(InvalidArgumentException::class);

    // …and so is an ACTIVE variant whose PARENT was withdrawn, which the old guard did not see.
    DB::table('catalog_product_variants')->where('id', $variantId)->update(['is_active' => 1]);
    DB::table('catalog_products')->where('id', $productId)->update(['is_active' => 0]);

    expect(fn () => $service->adjust($target, InventoryService::BUCKET_EXPRESS, -1, 'order'))
        ->toThrow(InvalidArgumentException::class, 'not active');
});

// ── 3. the other half: withdrawing must not STRAND the units ─────────────────────────────────

it('still allows every ADMINISTRATIVE movement on an inactive product', function () {
    /*
     * The failure a blunter guard would have caused. A withdrawn product still has units in the
     * warehouse, and the team must be able to correct, restock and zero them — refusing every
     * negative delta would make a deactivated product impossible to tidy up, and would leave
     * `inventory:verify --fix` unable to re-base it.
     */
    [$productId] = sellableSubject(active: false);
    $service = app(InventoryService::class);
    $target = StockTarget::product($productId);

    foreach (['adjustment', 'restock', 'manual', 'import', 'erp_sync'] as $reason) {
        $up = $service->adjust($target, InventoryService::BUCKET_EXPRESS, 2, $reason);
        expect($up->quantity_delta)->toBe(2, "a positive [{$reason}] was refused");

        $down = $service->adjust($target, InventoryService::BUCKET_EXPRESS, -1, $reason);
        expect($down->quantity_delta)->toBe(-1, "a negative [{$reason}] was refused");
    }
});

it('still allows a RELEASE on an inactive product, so cancelling gives the units back', function () {
    /*
     * The one that would lose stock. An order placed while the product was on sale, then cancelled
     * after it was withdrawn, must still return its units — a release is a POSITIVE delta and is
     * allowed by direction alone, but this pins it against the reason list too.
     */
    [$productId] = sellableSubject(active: false);
    $service = app(InventoryService::class);

    foreach (InventoryService::RELEASE_REASONS as $reason) {
        $movement = $service->adjust(
            StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, 3, $reason
        );
        expect($movement->quantity_delta)->toBe(3, "a release [{$reason}] was refused on an inactive product");
    }
});

it('leaves an ACTIVE product sellable through the service — the guard is not a blanket', function () {
    [$productId] = sellableSubject(active: true);

    $movement = app(InventoryService::class)->adjust(
        StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, -1, 'order'
    );

    expect($movement->quantity_delta)->toBe(-1);
});
