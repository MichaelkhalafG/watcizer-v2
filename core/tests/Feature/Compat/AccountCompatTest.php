<?php

use App\Compat\Diff\HarnessJwt;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `GET me/orders`, `GET me/addresses`, `POST add_address`, `DELETE me/addresses/{id}`.
 *
 * §3.3 calls these "move, trivial" and wave 2 left them proxied because they need the JWT
 * validation that arrives with the cart. They also cannot stay behind once the checkout moves:
 * `add_order` writes the very rows `me/orders` reads.
 *
 * The nesting (`order_item`, `address`, `shipping_city`, `translations`, the appended
 * `city_name`) is compared byte for byte against the legacy host by `compat:diff`. What is
 * pinned here is ownership — the property a differ cannot see, because it only ever sees one
 * caller's answer.
 */

const ACCOUNT_API_KEY = 'test-api-code';

beforeEach(function () {
    config(['compat.api_key' => ACCOUNT_API_KEY, 'compat.jwt_secret' => 'account-test-secret', 'compat.jwt_algo' => 'HS256']);
});

/** @return array<string, string> */
function accountHeaders(int $userId): array
{
    return ['Api-Code' => ACCOUNT_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint($userId)];
}

function userWithOrders(): int
{
    $id = DB::table('orders')->whereNotNull('user_id')->orderBy('user_id')->value('user_id');
    $id ??= DB::table('users')->orderBy('id')->value('id');

    return (int) (is_numeric($id) ? $id : 0);
}

it('requires a valid token on all three authenticated paths', function (string $method, string $path) {
    withHeaders(['Api-Code' => ACCOUNT_API_KEY])->json($method, $path)
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);

    withHeaders(['Api-Code' => ACCOUNT_API_KEY, 'Authorization' => 'Bearer nonsense'])->json($method, $path)
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/me/orders'],
    ['GET', '/api/me/addresses'],
    ['DELETE', '/api/me/addresses/1'],
]);

it('rejects a token whose account no longer exists', function () {
    withHeaders(['Api-Code' => ACCOUNT_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint(999999)])
        ->getJson('/api/me/orders')
        ->assertStatus(401);
});

it('returns only the caller\'s orders, newest first, with the legacy nesting', function () {
    $userId = userWithOrders();
    // The 2026-09-08 dump holds only GUEST orders, so the nesting would otherwise go unasserted.
    // Two orders are seeded for the caller inside the test transaction (rolled back with it), so
    // the shape, the ordering and the ownership filter are all actually exercised.
    seedUserOrders($userId, 2);
    $body = T::rows(withHeaders(accountHeaders($userId))->getJson('/api/me/orders')->assertOk()->json());

    expect(count($body))->toBeGreaterThanOrEqual(2);
    foreach ($body as $order) {
        expect($order['user_id'])->toBe($userId)
            ->and($order)->toHaveKeys(['id', 'user_id', 'guest_name', 'guest_email', 'guest_token', 'guest_phone', 'address_id', 'total_price_for_order', 'status', 'payment_method', 'order_number', 'note', 'created_at', 'updated_at', 'order_item', 'address']);
        foreach (T::rows($order['order_item']) as $item) {
            expect($item['order_id'])->toBe($order['id']);
        }
        if ($order['address'] !== null) {
            $address = T::arr($order['address']);
            expect($address['id'])->toBe($order['address_id'])
                ->and($address)->toHaveKey('shipping_city');
            if ($address['shipping_city'] !== null) {
                expect(T::arr($address['shipping_city']))->toHaveKeys(['id', 'shipping_cost', 'created_at', 'updated_at', 'city_name', 'translations']);
            }
        }
    }

    $ids = array_column($body, 'id');
    $sorted = $ids;
    rsort($sorted);
    expect($ids)->toBe($sorted);

    // …and an order belonging to somebody else is not in the answer.
    $foreign = DB::table('orders')->where(function (Builder $q) use ($userId): void {
        $q->where('user_id', '!=', $userId)->orWhereNull('user_id');
    })->orderBy('id')->value('id');
    if ($foreign !== null) {
        expect($ids)->not->toContain(T::int($foreign));
    }
});

/** Seed $count orders (one line each) for a user; rolled back with the test. */
function seedUserOrders(int $userId, int $count): void
{
    $addressId = DB::table('addresses')->insertGetId([
        'user_id' => $userId,
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Seeded Street 1', 'phone_number_one' => '01000000000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    for ($i = 0; $i < $count; $i++) {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId, 'address_id' => $addressId,
            'total_price_for_order' => '100.00', 'payment_method' => 'cash',
            'order_number' => '92'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
            'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00',
            'type_stock' => 'Express', 'color_band' => null, 'color_dial' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

it('returns only the caller\'s addresses', function () {
    $userId = userWithOrders();
    $body = T::rows(withHeaders(accountHeaders($userId))->getJson('/api/me/addresses')->assertOk()->json());

    foreach ($body as $address) {
        expect($address['user_id'])->toBe($userId)
            ->and($address)->toHaveKeys(['id', 'user_id', 'guest_token', 'shipping_city_id', 'address_line', 'phone_number_one', 'phone_number_two', 'created_at', 'updated_at', 'shipping_city']);
    }
    expect(count($body))->toBe(DB::table('addresses')->where('user_id', $userId)->count());
});

it('refuses to delete an address that is not the caller\'s, and leaves it in place', function () {
    $userId = userWithOrders();
    $foreign = T::int(DB::table('addresses')->where(function (Builder $q) use ($userId): void {
        $q->where('user_id', '!=', $userId)->orWhereNull('user_id');
    })->orderBy('id')->value('id'));

    withHeaders(accountHeaders($userId))->deleteJson('/api/me/addresses/'.$foreign)
        ->assertNotFound()->assertExactJson(['message' => 'Address not found']);

    expect(DB::table('addresses')->where('id', $foreign)->exists())->toBeTrue();
})->skip(fn () => DB::table('addresses')->whereNull('user_id')->doesntExist() && DB::table('addresses')->where('user_id', '!=', userWithOrders())->doesntExist(), 'no foreign address in this dump');

it('deletes the caller\'s own address', function () {
    $userId = userWithOrders();
    $id = DB::table('addresses')->insertGetId([
        'user_id' => $userId, 'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Owned Street 1', 'phone_number_one' => '01000000000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    withHeaders(accountHeaders($userId))->deleteJson('/api/me/addresses/'.$id)
        ->assertOk()->assertExactJson(['message' => 'Address deleted']);

    expect(DB::table('addresses')->where('id', $id)->exists())->toBeFalse();
});

it('answers a 404 for an id that does not exist at all', function () {
    withHeaders(accountHeaders(userWithOrders()))->deleteJson('/api/me/addresses/999999')
        ->assertNotFound()->assertExactJson(['message' => 'Address not found']);
});

it('ties a new address to the JWT caller, not to a user_id in the body', function () {
    $userId = userWithOrders();
    $other = DB::table('users')->where('id', '!=', $userId)->orderBy('id')->value('id');

    $response = withHeaders(accountHeaders($userId))->postJson('/api/add_address', [
        'user_id' => $other,                                   // ignored: the token wins
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => '  Spaced Street 1  ',
        'phone_number_one' => ' 01000000000 ',
    ]);

    $response->assertOk()->assertJson(['success' => true, 'message' => 'Address added successfully']);
    $id = T::int($response->json('id'));
    expect($response->json('address_id'))->toBe($id);

    $row = T::row(DB::table('addresses')->where('id', $id)->first());
    expect(Row::nint($row, 'user_id'))->toBe($userId)
        ->and(Row::str($row, 'address_line'))->toBe('Spaced Street 1')       // trimmed, as legacy trims
        ->and(Row::str($row, 'phone_number_one'))->toBe('01000000000');
})->skip(fn () => DB::table('users')->count() < 2, 'needs two users');

it('lets a guest create an address with no token at all', function () {
    $response = withHeaders(['Api-Code' => ACCOUNT_API_KEY])->postJson('/api/add_address', [
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Guest Street 1',
        'phone_number_one' => '01000000000',
    ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect(Row::nint(T::row(DB::table('addresses')->where('id', T::int($response->json('id')))->first()), 'user_id'))->toBeNull();
});

it('accepts the legacy phone_number_tow spelling as an alias', function () {
    $response = withHeaders(['Api-Code' => ACCOUNT_API_KEY])->postJson('/api/add_address', [
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Alias Street 1',
        'phone_number_one' => '01000000000',
        'phone_number_tow' => '01111111111',
    ]);

    $row = T::row(DB::table('addresses')->where('id', T::int($response->json('id')))->first());
    expect(Row::nstr($row, 'phone_number_two'))->toBe('01111111111');
});

it('answers a validation failure on add_address with 422 (unlike add_to_cart)', function () {
    withHeaders(['Api-Code' => ACCOUNT_API_KEY])->postJson('/api/add_address', [])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed'])
        ->assertJsonStructure(['errors' => ['shipping_city_id', 'address_line', 'phone_number_one']]);
});
