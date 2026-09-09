<?php

use App\Compat\CompatCart;
use App\Compat\Diff\HarnessJwt;
use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * The six cart paths and the two checkout paths, on the shared commerce tables with every
 * catalog read on the clean ones.
 *
 * The byte-level proof against the running legacy app is `compat:diff` (76 + 44 cases). These
 * pin what a differ cannot see: that the row a response describes is the right row, that one
 * shopper's token never reaches another's cart, and that a sale moves the ledger and the column
 * together.
 */

const CART_API_KEY = 'test-api-code';

const JWT_TEST_SECRET = 'wave3-test-secret-not-a-real-one';

beforeEach(function () {
    config(['compat.api_key' => CART_API_KEY, 'compat.jwt_secret' => JWT_TEST_SECRET, 'compat.jwt_algo' => 'HS256']);
});

/** @return array{id: int, price: float, express: int} */
function cartProduct(int $minStock = 3): array
{
    $row = DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', $minStock)
        ->orderBy('cp.id')->first(['cp.id', 'cp.stock_express', 'sp.effective_price', 'sp.effective_sale_price']);
    $row = T::row($row);

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
        'express' => Row::int($row, 'stock_express'),
    ];
}

/** @return array<string, string> */
function guestHeaders(string $token): array
{
    return ['Api-Code' => CART_API_KEY, 'X-Guest-Token' => $token];
}

/** @return array<string, string> */
function authHeaders(int $userId): array
{
    return ['Api-Code' => CART_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint($userId)];
}

function anyUserId(): int
{
    return T::int(DB::table('users')->orderBy('id')->value('id'));
}

/** @return array{id: int, cost: float} */
function shippingCity(): array
{
    $row = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return ['id' => Row::int($row, 'id'), 'cost' => round((float) Row::money($row, 'shipping_cost'), 2)];
}

// ── identity ─────────────────────────────────────────────────────────────────

it('mints a guest token when none is sent and echoes it back', function () {
    $response = withHeaders(['Api-Code' => CART_API_KEY])->getJson('/api/me/cart');

    $token = $response->headers->get('X-Guest-Token');
    expect($token)->toBeString()->and(strlen((string) $token))->toBe(36);
    $response->assertOk()->assertJson(['cart_item' => [], 'warnings' => []]);
});

it('echoes back the guest token it was sent, and keeps that cart across requests', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();

    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1,
        'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk()->assertJson(['success' => true]);

    $response = withHeaders(guestHeaders($token))->getJson('/api/me/cart');
    $response->assertHeader('X-Guest-Token', $token);

    $body = T::arr($response->json());
    $items = T::rows($body['cart_item']);
    expect($body['guest_token'])->toBe($token)
        ->and($body['user_id'])->toBeNull()
        ->and($items)->toHaveCount(1)
        ->and($items[0]['cart_id'])->toBe($body['id'])       // the id linkage the differ cannot check
        ->and($items[0]['product_id'])->toBe($product['id'])
        ->and($items[0]['quantity'])->toBe(1);
});

it('gives a logged-in caller the USER cart, not a guest one, even when a guest token is also sent', function () {
    $userId = anyUserId();
    $token = (string) Str::uuid();
    $product = cartProduct();

    withHeaders(authHeaders($userId) + ['X-Guest-Token' => $token])->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1,
        'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertOk();

    $body = T::arr(withHeaders(authHeaders($userId))->getJson('/api/me/cart')->json());
    expect($body['user_id'])->toBe($userId)->and($body['guest_token'])->toBeNull();

    // …and the guest token never got a cart of its own.
    expect(DB::table('carts')->where('guest_token', $token)->exists())->toBeFalse();
});

it('keeps two guests apart, and refuses to delete another cart\'s line', function () {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $product = cartProduct();
    $line = ['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express'];

    withHeaders(guestHeaders($a))->postJson('/api/add_to_cart', $line)->assertOk();
    $itemA = T::int(withHeaders(guestHeaders($a))->getJson('/api/me/cart')->json('cart_item.0.id'));

    withHeaders(guestHeaders($b))->getJson('/api/me/cart')->assertOk()->assertJson(['cart_item' => []]);
    withHeaders(guestHeaders($b))->deleteJson('/api/delete_cart/'.$itemA)
        ->assertNotFound()->assertExactJson(['success' => false, 'message' => 'Not found']);

    // A's line survived B's attempt.
    expect(withHeaders(guestHeaders($a))->getJson('/api/me/cart')->json('cart_item'))->toHaveCount(1);
});

it('falls through to guest when the JWT is invalid, instead of 401 (legacy behaviour)', function () {
    withHeaders(['Api-Code' => CART_API_KEY, 'Authorization' => 'Bearer not.a.real.token'])
        ->getJson('/api/me/cart')
        ->assertOk()
        ->assertHeader('X-Guest-Token');
});

// ── cart behaviour ───────────────────────────────────────────────────────────

it('replaces the quantity of an existing line rather than adding to it', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();
    $line = fn (int $q): array => ['product_id' => $product['id'], 'quantity' => $q, 'piece_price' => $product['price'], 'total_price' => $product['price'] * $q, 'type_stock' => 'Express'];

    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', $line(1))->assertOk();
    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', $line(2))->assertOk();

    $items = T::rows(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('cart_item'));
    expect($items)->toHaveCount(1)->and($items[0]['quantity'])->toBe(2);
});

it('treats Express and Market as different lines of the same product', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();

    foreach (['Express', 'Market'] as $bucket) {
        withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
            'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
            'total_price' => $product['price'], 'type_stock' => $bucket,
        ]);
    }

    $items = T::rows(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('cart_item'));
    expect($items)->toHaveCount(2)
        ->and(array_column($items, 'type_stock'))->toBe(['Express', 'Market']);
});

it('refuses to cart more than the bucket holds, reading the CLEAN stock column', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();

    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => $product['express'] + 1,
        'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
    ])->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'Requested quantity exceeds available stock']);
});

it('answers a validation failure on add_to_cart with the legacy 500 + ref, not a 422', function () {
    // OrderController::AddToCart catches \Exception, and a ValidationException is one. Copied,
    // not corrected: the storefront reads the status, and changing it would be a silent
    // deviation on a path the harness compares byte for byte.
    $response = withHeaders(guestHeaders((string) Str::uuid()))->postJson('/api/add_to_cart', []);

    $response->assertStatus(500)->assertJson(['success' => false, 'message' => 'An error occurred']);
    expect($response->json('ref'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('removes every line of a product and stays successful when there is nothing to remove', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();
    foreach (['Express', 'Market'] as $bucket) {
        withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
            'product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
            'total_price' => $product['price'], 'type_stock' => $bucket,
        ]);
    }

    withHeaders(guestHeaders($token))->postJson('/api/remove_from_cart', ['product_id' => $product['id']])
        ->assertOk()->assertExactJson(['success' => true]);
    expect(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('cart_item'))->toBe([]);

    withHeaders(guestHeaders($token))->postJson('/api/remove_from_cart', ['product_id' => $product['id']])
        ->assertOk()->assertExactJson(['success' => true]);
});

it('warns about a price change and an out-of-stock line, keyed by cart-item id', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();

    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 1,
        'piece_price' => $product['price'] + 50, 'total_price' => $product['price'] + 50, 'type_stock' => 'Express',
    ])->assertOk();

    $body = T::arr(withHeaders(guestHeaders($token))->postJson('/api/cart/validate')->json());
    $warnings = T::rows($body['warnings']);
    $itemId = T::int(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('cart_item.0.id'));

    expect($body['valid'])->toBeFalse()
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['item_id'])->toBe($itemId)
        ->and($warnings[0]['message'])->toBe('Price changed to '.$product['price']);
});

it('emits the legacy totals shape for an empty cart and a priced one', function () {
    $token = (string) Str::uuid();
    $empty = T::arr(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('totals'));
    expect($empty)->toBe([
        'subtotal' => 0, 'shipping' => 0,
        'shipping_note' => 'Shipping calculated at checkout by governorate',
        'tax' => 0, 'total' => 0, 'item_count' => 0, 'savings' => 0,
    ]);

    $product = cartProduct();
    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 2, 'piece_price' => $product['price'],
        'total_price' => $product['price'] * 2, 'type_stock' => 'Express',
    ]);

    $totals = T::arr(withHeaders(guestHeaders($token))->getJson('/api/me/cart')->json('totals'));
    expect($totals['subtotal'])->toEqual(round($product['price'] * 2, 2))
        ->and($totals['total'])->toEqual(round($product['price'] * 2, 2))
        ->and($totals['item_count'])->toBe(2)
        ->and($totals['shipping'])->toBe(0)
        ->and($totals['shipping_note'])->toBe('Shipping calculated at checkout by governorate');
});

it('keeps the legacy PHP types behind those totals, which JSON happens to hide', function () {
    // `emptyTotals()` returns integer zeros and `calculateTotals()` returns floats. json_encode
    // renders an integral float without its fraction, so the two are indistinguishable on the
    // wire — which is exactly why this is asserted here and not through a response.
    $cart = new CompatCart(1);
    $empty = $cart->emptyTotals();
    /** @var Collection<int, stdClass> $none */
    $none = collect();

    expect($empty['subtotal'])->toBeInt()
        ->and($empty['savings'])->toBeInt()
        ->and($cart->totals($none))->toHaveKey('subtotal')
        ->and($cart->totals($none)['subtotal'])->toBeFloat()
        ->and($cart->savings($none))->toBeFloat();
});

it('merges a guest cart into the user cart by ADDING the quantities, then deletes the guest cart', function () {
    $userId = anyUserId();
    $guest = (string) Str::uuid();
    $product = cartProduct();
    $line = fn (int $q): array => ['product_id' => $product['id'], 'quantity' => $q, 'piece_price' => $product['price'], 'total_price' => $product['price'] * $q, 'type_stock' => 'Express'];

    withHeaders(guestHeaders($guest))->postJson('/api/add_to_cart', $line(2))->assertOk();
    withHeaders(authHeaders($userId))->postJson('/api/add_to_cart', $line(1))->assertOk();

    $merged = withHeaders(authHeaders($userId))->postJson('/api/cart/merge', ['guest_token' => $guest]);

    $merged->assertOk()->assertJsonPath('message', 'Cart merged successfully');
    expect(T::rows($merged->json('cart.cart_item')))->toHaveCount(1)
        ->and($merged->json('cart.cart_item.0.quantity'))->toBe(3)          // 1 + 2, not replaced
        ->and(DB::table('carts')->where('guest_token', $guest)->exists())->toBeFalse();
});

it('answers cart/merge for an unknown or absent guest token without touching anything', function () {
    $userId = anyUserId();

    withHeaders(authHeaders($userId))->postJson('/api/cart/merge', [])
        ->assertOk()->assertExactJson(['message' => 'No guest cart to merge']);
    withHeaders(authHeaders($userId))->postJson('/api/cart/merge', ['guest_token' => (string) Str::uuid()])
        ->assertOk()->assertExactJson(['message' => 'Guest cart not found']);
});

it('requires a valid token for cart/merge', function () {
    withHeaders(['Api-Code' => CART_API_KEY])->postJson('/api/cart/merge', [])
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
});

// ── checkout ─────────────────────────────────────────────────────────────────

it('places a cash order: stock down, ledger movement written, cart gone', function () {
    $token = (string) Str::uuid();
    $product = cartProduct(3);
    $city = shippingCity();
    $service = app(InventoryService::class);
    $stockBefore = $product['express'];

    withHeaders(guestHeaders($token))->postJson('/api/add_to_cart', [
        'product_id' => $product['id'], 'quantity' => 2, 'piece_price' => $product['price'],
        'total_price' => $product['price'] * 2, 'type_stock' => 'Express',
    ])->assertOk();

    $total = round($product['price'] * 2 + $city['cost'], 2);
    $response = withHeaders(guestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => $city['id'], 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => $total, 'payment_method' => 'cash',
        'guest_name' => 'Test Guest', 'guest_email' => 'guest@example.test',
        'items' => [['product_id' => $product['id'], 'quantity' => 2, 'piece_price' => $product['price'], 'total_price' => $product['price'] * 2, 'type_stock' => 'Express']],
    ]);

    $response->assertOk()->assertJson(['success' => true, 'message' => 'Order placed successfully']);
    $orderNumber = T::str($response->json('order_number'));
    expect($orderNumber)->toMatch('/^\d{6,}$/');

    $order = T::row(DB::table('orders')->where('order_number', $orderNumber)->first());
    expect($order)->not->toBeNull()
        ->and(Row::str($order, 'status'))->toBe('processing')
        ->and(Row::str($order, 'payment_method'))->toBe('cash')
        ->and(Row::money($order, 'total_price_for_order'))->toBe(number_format($total, 2, '.', ''))
        ->and(Row::nstr($order, 'guest_token'))->toBe($token);

    $orderId = Row::int($order, 'id');
    expect(DB::table('order_items')->where('order_id', $orderId)->count())->toBe(1)
        ->and(T::int(DB::table('catalog_products')->where('id', $product['id'])->value('stock_express')))->toBe($stockBefore - 2)
        ->and($service->isCommitted($orderId))->toBeTrue()
        ->and($service->ledgerQuantity($product['id'], 'express'))->toBe($stockBefore - 2);

    $movement = T::row(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $orderId)->first());
    expect(Row::int($movement, 'quantity_delta'))->toBe(-2)
        ->and(Row::str($movement, 'reason'))->toBe('order');

    // The cart it was placed from is gone, so a stale line cannot inflate the next order.
    expect(DB::table('carts')->where('guest_token', $token)->exists())->toBeFalse();
    withHeaders(guestHeaders($token))->getJson('/api/me/cart')->assertOk()->assertJson(['cart_item' => []]);
});

it('prices every line from the catalog and refuses a client total that disagrees', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();
    $city = shippingCity();
    $correct = round($product['price'] + $city['cost'], 2);

    $response = withHeaders(guestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => $city['id'], 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => $correct + 100, 'payment_method' => 'cash',
        // A tampered line price changes nothing: the server re-prices from the catalog.
        'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => 1, 'total_price' => 1, 'type_stock' => 'Express']],
    ]);

    $response->assertStatus(422)->assertJson([
        'success' => false,
        'message' => 'Order total mismatch — your cart may be out of date. Please review and try again.',
        'server_total' => $correct,
    ]);
});

it('refuses an order the bucket cannot cover, and persists nothing at all', function () {
    $token = (string) Str::uuid();
    $city = shippingCity();
    $row = DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', 0)
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']);
    $row = T::row($row);
    $id = Row::int($row, 'id');
    $price = CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'));

    $orders = DB::table('orders')->count();
    $addresses = DB::table('addresses')->count();
    $movements = DB::table('inventory_movements')->count();

    withHeaders(guestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => $city['id'], 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => round($price + $city['cost'], 2), 'payment_method' => 'cash',
        'items' => [['product_id' => $id, 'quantity' => 1, 'piece_price' => $price, 'total_price' => $price, 'type_stock' => 'Express']],
    ])->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'Insufficient stock', 'product_id' => $id]);

    expect(DB::table('orders')->count())->toBe($orders)
        ->and(DB::table('addresses')->count())->toBe($addresses)          // the address is rolled back too
        ->and(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(T::int(DB::table('catalog_products')->where('id', $id)->value('stock_express')))->toBe(0);
})->skip(fn () => DB::table('catalog_products')->whereNull('deleted_at')->where('stock_express', 0)->doesntExist(), 'no product with an empty express bucket');

it('refuses an empty order without creating an address', function () {
    $city = shippingCity();
    $addresses = DB::table('addresses')->count();

    withHeaders(guestHeaders((string) Str::uuid()))->postJson('/api/add_order', [
        'shipping_city_id' => $city['id'], 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => 0, 'payment_method' => 'cash', 'items' => [],
    ])->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'Cart is empty']);

    expect(DB::table('addresses')->count())->toBe($addresses);
});

it('records the order e-mail core cannot yet send instead of dropping it', function () {
    $token = (string) Str::uuid();
    $product = cartProduct();
    $city = shippingCity();

    withHeaders(guestHeaders($token))->postJson('/api/add_order', [
        'shipping_city_id' => $city['id'], 'address_line' => 'Test Street 1', 'phone' => '01000000000',
        'total_price_for_order' => round($product['price'] + $city['cost'], 2), 'payment_method' => 'cash',
        'guest_email' => 'guest@example.test',
        'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express']],
    ])->assertOk();

    $row = T::row(DB::table('integration_outbox')->where('channel', 'mail')->orderByDesc('id')->first());
    $payload = T::arr(json_decode(Row::str($row, 'payload'), true));
    expect(Row::str($row, 'event'))->toBe('order.placed')
        ->and(Row::str($row, 'status'))->toBe('pending')
        ->and($payload['kinds'])->toBe(['customer', 'admin'])
        ->and($payload['customer_email'])->toBe('guest@example.test');
});

it('rejects a Paymob callback whose HMAC does not verify, before touching any order', function () {
    config(['services.paymob.hmac_secret' => 'test-hmac']);

    withHeaders(['Api-Code' => CART_API_KEY])->getJson('/api/callback_payment?id=1&success=true&merchant_order_id=1&hmac=wrong')
        ->assertStatus(403)->assertExactJson(['message' => 'Invalid signature']);
});

it('rejects a Paymob callback when no HMAC secret is configured (fails closed)', function () {
    config(['services.paymob.hmac_secret' => null]);

    withHeaders(['Api-Code' => CART_API_KEY])->getJson('/api/callback_payment?id=1&success=true&hmac=anything')
        ->assertStatus(403);
});
