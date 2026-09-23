<?php

use App\Compat\CompatCart;
use App\Compat\Diff\HarnessJwt;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * ── Anonymous PII disclosure and IDOR on add_order (security audit, Finding 2, 2026-09-23) ────
 *
 * `add_order` took the buyer (`user_id`) and the address (`address_id`) from the BODY and
 * dereferenced both into real PII with no ownership check: an anonymous caller named any customer
 * and any address, read the details back from the Paymob page or from `me/orders` (with the
 * address's guest token), and learned an unowned address's governorate from the `server_total`
 * 422. `add_address` had the twin hole — a body `user_id` with no `exists`, so an address could be
 * planted in any customer's book.
 *
 * Every refusal here is asserted to WRITE NOTHING and to DISCLOSE NOTHING, and the legitimate
 * checkout — JWT, matching `user_id`, own address — is asserted unchanged.
 */

const OWN_API_KEY = 'ownership-test-api-code';
const OWN_JWT_SECRET = 'ownership-test-secret-not-a-real-one';

beforeEach(function () {
    config(['compat.api_key' => OWN_API_KEY, 'compat.jwt_secret' => OWN_JWT_SECRET, 'compat.jwt_algo' => 'HS256']);
});

/** @return array{id: int, price: float} */
function ownershipProduct(): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', 3)
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/** A registered victim with a saved address in a governorate whose cost we know. */
function victimWithAddress(): array
{
    $victim = Shopper::register();
    // The MOST expensive governorate, so its cost is distinguishable from the caller's own.
    $city = T::row(DB::table('shipping_cities')->orderByDesc('shipping_cost')->first(['id', 'shipping_cost']));
    $addressId = (int) DB::table('addresses')->insertGetId([
        'user_id' => $victim->id, 'guest_token' => 'victim-guest-token-'.Str::random(8),
        'shipping_city_id' => Row::int($city, 'id'), 'address_line' => 'Victim Street 7',
        'phone_number_one' => '01099999999', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['user' => $victim, 'address_id' => $addressId, 'cost' => round((float) Row::money($city, 'shipping_cost'), 2)];
}

/** Everything add_order could have written. */
function ownershipWrites(): array
{
    return [
        'orders' => T::int(DB::table('orders')->count()),
        'order_items' => T::int(DB::table('order_items')->count()),
        'movements' => T::int(DB::table('inventory_movements')->count()),
        'addresses' => T::int(DB::table('addresses')->count()),
        'outbox' => T::int(DB::table('integration_outbox')->count()),
    ];
}

function orderLine(array $product): array
{
    return [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express']];
}

it('REFUSES an anonymous caller naming another customer: nothing back, nothing written', function () {
    $victim = victimWithAddress();
    $product = ownershipProduct();
    $before = ownershipWrites();

    $response = withHeaders(['Api-Code' => OWN_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'user_id' => $victim['user']->id,
            'address_id' => $victim['address_id'],
            'total_price_for_order' => $product['price'] + $victim['cost'],
            'payment_method' => 'card',
            'items' => orderLine($product),
        ]);

    // The same answer compat.auth gives any unauthenticated account call.
    $response->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('Victim Street')
        ->and($body)->not->toContain('01099999999')
        ->and($body)->not->toContain('redirect_url')
        ->and(ownershipWrites())->toBe($before);
});

it('REFUSES an anonymous guest using another customer ADDRESS, and the 422 leaks no shipping cost', function () {
    /*
     * No `user_id` at all this time — a plain guest checkout pointing at a stranger's saved
     * address, with a deliberately WRONG total. Before the fix the answer was a 422 carrying
     * `server_total`, which included that address's governorate shipping cost: the victim's
     * governorate, disclosed anonymously in one request.
     */
    $victim = victimWithAddress();
    $product = ownershipProduct();
    $before = ownershipWrites();

    $response = withHeaders(['Api-Code' => OWN_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'address_id' => $victim['address_id'],
            'total_price_for_order' => 1,
            'payment_method' => 'cash',
            'items' => orderLine($product),
        ]);

    $response->assertStatus(422);
    $json = T::arr($response->json());

    expect($json)->not->toHaveKey('server_total')
        ->and($json['errors'] ?? [])->toHaveKey('address_id')
        ->and((string) $response->getContent())->not->toContain((string) ($product['price'] + $victim['cost']))
        ->and(ownershipWrites())->toBe($before);
});

it('answers a foreign address and a NON-EXISTENT one identically, so ids cannot be enumerated', function () {
    $victim = victimWithAddress();
    $product = ownershipProduct();
    $missing = T::int(DB::table('addresses')->max('id')) + 1000;

    $ask = fn (int $addressId) => withHeaders(['Api-Code' => OWN_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'address_id' => $addressId, 'total_price_for_order' => 1,
            'payment_method' => 'cash', 'items' => orderLine($product),
        ]);

    $foreign = $ask($victim['address_id']);
    $absent = $ask($missing);

    expect($foreign->status())->toBe($absent->status())
        ->and($foreign->json())->toBe($absent->json());
});

it('REFUSES a signed-in customer naming a DIFFERENT customer in the body', function () {
    $victim = victimWithAddress();
    $caller = Shopper::register();
    $product = ownershipProduct();
    $before = ownershipWrites();

    withHeaders(['Api-Code' => OWN_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint((int) $caller->id)])
        ->postJson('/api/add_order', [
            'user_id' => $victim['user']->id, 'address_id' => $victim['address_id'],
            'total_price_for_order' => $product['price'] + $victim['cost'],
            'payment_method' => 'cash', 'items' => orderLine($product),
        ])->assertStatus(401);

    expect(ownershipWrites())->toBe($before);
});

it('REFUSES a signed-in customer using somebody else address even without naming them', function () {
    $victim = victimWithAddress();
    $caller = Shopper::register();
    $product = ownershipProduct();
    $before = ownershipWrites();

    withHeaders(['Api-Code' => OWN_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint((int) $caller->id)])
        ->postJson('/api/add_order', [
            'user_id' => $caller->id, 'address_id' => $victim['address_id'],
            'total_price_for_order' => $product['price'] + $victim['cost'],
            'payment_method' => 'cash', 'items' => orderLine($product),
        ])->assertStatus(422)->assertJsonValidationErrors('address_id');

    expect(ownershipWrites())->toBe($before);
});

it('still places the LEGITIMATE signed-in order: JWT, matching user_id, own address', function () {
    // Exactly what Checkout.jsx sends for a signed-in shopper choosing a saved address.
    $owner = victimWithAddress();
    $product = ownershipProduct();

    $response = withHeaders(['Api-Code' => OWN_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint((int) $owner['user']->id)])
        ->postJson('/api/add_order', [
            'user_id' => $owner['user']->id, 'address_id' => $owner['address_id'],
            'total_price_for_order' => round($product['price'] + $owner['cost'], 2),
            'payment_method' => 'cash', 'items' => orderLine($product),
        ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect(DB::table('orders')->where('user_id', $owner['user']->id)->where('address_id', $owner['address_id'])->exists())->toBeTrue();
});

it('still lets a GUEST reuse an address its OWN session created', function () {
    $token = (string) Str::uuid();
    $product = ownershipProduct();
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $addressId = (int) DB::table('addresses')->insertGetId([
        'user_id' => null, 'guest_token' => $token, 'shipping_city_id' => Row::int($city, 'id'),
        'address_line' => 'Guest Street 1', 'phone_number_one' => '01000000000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    withHeaders(['Api-Code' => OWN_API_KEY, 'X-Guest-Token' => $token])->postJson('/api/add_order', [
        'address_id' => $addressId,
        'total_price_for_order' => round($product['price'] + round((float) Row::money($city, 'shipping_cost'), 2), 2),
        'payment_method' => 'cash', 'guest_name' => 'G', 'guest_phone' => '01000000000',
        'items' => orderLine($product),
    ])->assertOk()->assertJson(['success' => true]);
});

it('REFUSES add_address planting an address in another customer book', function () {
    $victim = Shopper::register();
    $city = T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));

    withHeaders(['Api-Code' => OWN_API_KEY])->postJson('/api/add_address', [
        'user_id' => $victim->id, 'shipping_city_id' => $city,
        'address_line' => 'Attacker Drop Point 1', 'phone_number_one' => '01011111111',
    ])->assertOk();

    // Accepted as an anonymous address, never as the victim's: their book is unchanged.
    expect(DB::table('addresses')->where('user_id', $victim->id)->where('address_line', 'Attacker Drop Point 1')->exists())->toBeFalse()
        ->and(DB::table('addresses')->where('address_line', 'Attacker Drop Point 1')->value('user_id'))->toBeNull();
});

it('emits no guest_token in any ADDRESS row of me/addresses or me/orders', function () {
    $owner = victimWithAddress();
    $product = ownershipProduct();
    $auth = ['Api-Code' => OWN_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint((int) $owner['user']->id)];

    withHeaders($auth)->postJson('/api/add_order', [
        'user_id' => $owner['user']->id, 'address_id' => $owner['address_id'],
        'total_price_for_order' => round($product['price'] + $owner['cost'], 2),
        'payment_method' => 'cash', 'items' => orderLine($product),
    ])->assertOk();

    $addresses = T::rows(withHeaders($auth)->getJson('/api/me/addresses')->assertOk()->json());
    $orders = T::rows(withHeaders($auth)->getJson('/api/me/orders')->assertOk()->json());

    expect($addresses)->not->toBeEmpty()->and($orders)->not->toBeEmpty();
    foreach ($addresses as $address) {
        expect($address)->not->toHaveKey('guest_token');
    }
    foreach ($orders as $order) {
        expect(T::arr($order['address']))->not->toHaveKey('guest_token');
    }
});

it('throttles add_order per endpoint, because every accepted call reserves stock', function () {
    $statuses = [];
    for ($i = 0; $i < 12; $i++) {
        $statuses[] = withHeaders(['Api-Code' => OWN_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
            ->postJson('/api/add_order', [])->status();
    }

    expect($statuses)->toContain(429)
        ->and(array_slice($statuses, 0, 10))->not->toContain(429);
});
