<?php

use App\Compat\CompatCart;
use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\withHeaders;

/*
 * Wave 3.5 — the compat layer's variant invariant, named in CompatCheckout::priceLines().
 *
 * The legacy Next.js frontend has no size picker and its cart payload has no variant field, so the
 * compat endpoints cannot choose a variant. The invariant that makes that safe is:
 *
 *   NO product reachable through the compat layer sells through variants.
 *
 * Today that is true by construction — storefront 1 is watches and bags, and legacy
 * `product_variants` is empty — and the first test asserts it rather than trusting it. The rest
 * pin what happens if it ever stops being true: a LOUD refusal at both doors, never a
 * product-level decrement that no variant backs.
 *
 * The byte-level proof that the shared-table column change did not move the wire format is
 * `compat:diff`, which runs both hosts against the same database.
 */

const VARIANT_API_KEY = 'test-api-code';

beforeEach(function () {
    config(['compat.api_key' => VARIANT_API_KEY, 'compat.jwt_secret' => 'wave3-test-secret-not-a-real-one', 'compat.jwt_algo' => 'HS256']);
});

/** @return array<string, string> */
function variantGuestHeaders(string $token): array
{
    return ['Api-Code' => VARIANT_API_KEY, 'X-Guest-Token' => $token];
}

/** @return array{id: int, price: float} */
function compatProduct(int $minStock = 3): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', $minStock)
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

it('holds the invariant: no product on storefront 1 sells through variants', function () {
    $reachable = DB::table('storefront_product as sp')
        ->join('catalog_product_variants as v', 'v.product_id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->distinct()->count('sp.product_id');

    expect($reachable)->toBe(0, 'the compat layer cannot pick a size — see CompatCheckout::priceLines()');
});

it('refuses to add a variant product to a compat cart, and writes no line', function () {
    $token = (string) Str::uuid();
    $f = VariantFixture::converted(2, 9);
    $items = DB::table('cart_items')->count();

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $f['product'], 'quantity' => 1, 'piece_price' => 100,
        'total_price' => 100, 'type_stock' => 'Express',
    ])->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'This product requires selecting an option']);

    // Not "no row for this product anywhere" — it is a real product and real shoppers' carts in
    // the dump already name it. The claim is that THIS request wrote nothing.
    expect(DB::table('cart_items')->count())->toBe($items);
    withHeaders(variantGuestHeaders($token))->getJson('/api/me/cart')->assertOk()->assertJson(['cart_item' => []]);
});

it('refuses a checkout line whose product gained variants while it sat in the cart', function () {
    // The realistic path: the line was legal when it was added. Conversion happens later, in the
    // dashboard, and the compat checkout must notice at PRICING time rather than decrement an
    // aggregate at commit time.
    $token = (string) Str::uuid();
    $product = compatProduct();
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk();

    VariantFixture::converted(1, 9, $product['id']);

    $orders = DB::table('orders')->count();
    $movements = DB::table('inventory_movements')->count();

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => Row::int($city, 'id'), 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
        'payment_method' => 'cash',
        'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express']],
    ])->assertStatus(422)->assertExactJson([
        // The legacy body, unchanged: the compat layer never invents a message the old app
        // could not have sent.
        'success' => false, 'message' => 'One of the items is no longer available.',
    ]);

    expect(DB::table('orders')->count())->toBe($orders)
        ->and(DB::table('inventory_movements')->count())->toBe($movements);
});

it('still shows the stale line, so the shopper can see what to remove', function () {
    $token = (string) Str::uuid();
    $product = compatProduct();

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk();
    VariantFixture::converted(1, 0, $product['id']);        // converted AND out of stock

    $body = T::arr(withHeaders(variantGuestHeaders($token))->getJson('/api/me/cart')->json());
    $items = T::rows($body['cart_item']);

    expect($items)->toHaveCount(1)
        ->and($items[0]['product_id'])->toBe($product['id'])
        ->and($items[0]['variant_id'])->toBeNull();
});

it('keeps the legacy cart_item key order with variant_id where the shared column sits', function () {
    // M1f added `variant_id` to the SHARED `cart_items` table, so the LEGACY app's `toArray()`
    // emits it too, in this position. This asserts the position core emits; `compat:diff` proves
    // the two hosts agree.
    $token = (string) Str::uuid();
    $product = compatProduct();

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk();

    $items = T::rows(T::arr(withHeaders(variantGuestHeaders($token))->getJson('/api/me/cart')->json())['cart_item']);

    expect(array_keys($items[0]))->toBe([
        'id', 'cart_id', 'product_id', 'variant_id', 'offer_id', 'quantity', 'piece_price',
        'total_price', 'type_stock', 'color_band', 'color_dial', 'created_at', 'updated_at',
    ]);
});

it('leaves an ordinary watch on the product level, end to end', function () {
    // The no-regression case at the compat door: nothing about a product without variants moved.
    $token = (string) Str::uuid();
    $product = compatProduct(2);
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $before = T::int(DB::table('catalog_products')->where('id', $product['id'])->value('stock_express'));

    withHeaders(variantGuestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk();

    $response = withHeaders(variantGuestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => Row::int($city, 'id'), 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
        'payment_method' => 'cash', 'guest_name' => 'Test Guest',
        'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express']],
    ]);
    $response->assertOk()->assertJson(['success' => true]);

    $orderId = T::int(DB::table('orders')->where('order_number', T::str($response->json('order_number')))->value('id'));
    $line = T::row(DB::table('order_items')->where('order_id', $orderId)->first());
    $movement = T::row(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $orderId)->first());

    expect(Row::nint($line, 'variant_id'))->toBeNull()
        ->and(Row::nint($movement, 'variant_id'))->toBeNull()
        ->and(Row::int($movement, 'quantity_delta'))->toBe(-1)
        ->and(T::int(DB::table('catalog_products')->where('id', $product['id'])->value('stock_express')))->toBe($before - 1);
});

it('never lets a variant product reach commitOrder, even if a line is forged past the door', function () {
    // Belt and braces: if something wrote an order line for a variant product directly (an import,
    // a dashboard bug), the SERVICE still refuses — a product-level movement against a product
    // with variants is rejected at the single door. The order stays uncommitted.
    $f = VariantFixture::converted(1, 5);
    $orderId = VariantFixture::order($f['product'], 0, 1);
    DB::table('order_items')->where('order_id', $orderId)->update(['variant_id' => null]);

    expect(fn () => app(InventoryService::class)->commitOrder($orderId))
        ->toThrow(InvalidArgumentException::class, 'has variants');

    expect(DB::table('inventory_movements')->where('reference_id', $orderId)->where('reference_type', 'orders')->count())->toBe(0);
});
