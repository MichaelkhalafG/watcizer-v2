<?php

use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * AN OFFER IN THE CART STOPS THE CHECKOUT — it is never dropped from it (2026-10-21).
 *
 * `cart_items` is shared with compat, and compat sells offers: a row may carry `offer_id` instead of
 * `product_id`. v2 has no offer in its cart — it cannot price or sell one.
 *
 * Leaving those rows out of the DISPLAY is harmless. Leaving them out of `StorefrontCart::lines()` was not,
 * because `StorefrontCheckout` reads that method: a cart holding an offer would have been checked out with
 * the offer missing from the order and never charged. A line lost in silence, at the moment money is taken.
 *
 * Found by diffing what v2's cart serves against what compat's cart already does, BEFORE moving Watchizer's
 * cart to v2. Nothing can hit it today — production holds zero offers, none in a cart, none in any order ever
 * — which is exactly why it needs a test: the dashboard can create one tomorrow.
 */

/**
 * An offer row, made here because production has none — `cart_items.offer_id` carries a foreign key to
 * `offers`, so the row this test needs cannot be faked with an invented id. Rolled back with the test.
 */
function makeOffer(): int
{
    $productId = T::int(DB::table('products')->value('id'));
    $categoryTypeId = T::int(DB::table('category_types')->value('id'));

    // `offers.stock` is a stock column, so the write goes through the guard's sanctioned escape rather than
    // around it — the guard exists to catch stock written outside InventoryService, and a test is no exception.
    return T::int(StockWriteGuard::allow(fn () => DB::table('offers')->insertGetId([
        'main_product_id' => $productId,
        'category_type_id' => $categoryTypeId,
        'gift_product_ids' => '[]',
        'selling_price' => '100.00',
        'sale_price_after_discount' => null,
        'image' => 'offer.webp',
        'stock' => 5,
        'wa_code' => 'TESTOFFER',
        'in_season' => 'no',
        'created_at' => now(),
        'updated_at' => now(),
    ])));
}

/**
 * A cart row with one offer line on it, for this storefront.
 *
 * @return array{0: int, 1: string} the cart id and its guest token
 */
function cartWithOfferLine(): array
{
    $token = (string) Str::uuid();
    $cartId = T::int(DB::table('carts')->insertGetId([
        'storefront_id' => 1,
        'user_id' => null,
        'guest_token' => $token,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    DB::table('cart_items')->insert([
        'cart_id' => $cartId,
        'product_id' => null,
        'variant_id' => null,
        'offer_id' => makeOffer(),
        'quantity' => 1,
        'piece_price' => '100.00',
        'total_price' => '100.00',
        'type_stock' => 'Express',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$cartId, $token];
}

it('reports the blocked count on the cart payload, so a shopper is told', function () {
    [, $token] = cartWithOfferLine();

    $body = T::arr(getJson(H::base('cart'), ['X-Guest-Token' => $token])->assertOk()->json('data'));

    expect(T::int($body['blocked'] ?? null))->toBe(1)
        // the offer is not counted as an item, and not in the money
        ->and(T::arr($body['items'] ?? []))->toBe([])
        ->and(T::int(T::arr($body['totals'] ?? [])['count'] ?? null))->toBe(0);
});

it('REFUSES the checkout instead of writing an order without the line', function () {
    [, $token] = cartWithOfferLine();

    $before = DB::table('orders')->count();

    // a VALID body, so the request reaches the cart at all: an invalid one is refused by validation long
    // before the guard, which is how the first version of this test passed while proving nothing
    $cityId = T::int(DB::table('shipping_cities')->value('id'));
    $response = postJson(H::base('orders'), [
        'payment_method' => 'cash',
        'address_line' => 'Somewhere on a test street',
        'shipping_city_id' => $cityId,
        'phone' => '01000000000',
        'total' => '100.00',
    ], ['X-Guest-Token' => $token]);

    // whatever else the checkout wants, it must NOT be a 2xx: an order written here would be missing a line
    expect($response->status())->not->toBeLessThan(400)
        ->and(DB::table('orders')->count())->toBe($before, 'an order was written for a cart holding an offer');

    // and when the offer is the ONLY thing stopping it, the refusal says so by name
    // and it is THIS guard refusing, by name — not some unrelated validation error that happens to 422
    expect(T::str(T::arr($response->json() ?? [])['error'] ?? ''))->toBe('offer_in_cart');
});

it('refuses a MIXED cart too — the case where an order WOULD have been written missing a line', function () {
    [$cartId, $token] = cartWithOfferLine();

    // a real, buyable product line beside the offer: before the guard, the checkout wrote THIS and silently
    // dropped the offer, charging for one of the two things the shopper had added
    $productId = T::int(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.in_stock', 1)
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')
            ->whereColumn('v.product_id', 'sp.product_id')->where('v.is_active', 1))
        ->value('sp.product_id'));
    expect($productId)->toBeGreaterThan(0);

    DB::table('cart_items')->insert([
        'cart_id' => $cartId,
        'product_id' => $productId,
        'variant_id' => null,
        'offer_id' => null,
        'quantity' => 1,
        'piece_price' => '100.00',
        'total_price' => '100.00',
        'type_stock' => 'Express',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $before = DB::table('orders')->count();
    $cityId = T::int(DB::table('shipping_cities')->value('id'));

    $response = postJson(H::base('orders'), [
        'payment_method' => 'cash',
        'address_line' => 'Somewhere on a test street',
        'shipping_city_id' => $cityId,
        'phone' => '01000000000',
        'total' => '100.00',
    ], ['X-Guest-Token' => $token]);

    expect(T::str(T::arr($response->json() ?? [])['error'] ?? ''))->toBe('offer_in_cart')
        ->and(DB::table('orders')->count())->toBe($before, 'an order was written for a mixed cart');
});
