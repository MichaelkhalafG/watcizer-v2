<?php

use App\Compat\Diff\HarnessJwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/*
 * `GET /api/v2/{storefront}/me/orders` (the night run of 2026-10-19, audit a1): a customer's orders on THE PATH'S
 * storefront only. Compat's `me/orders` lists every shop's — users are shared — and stays as it is; this is the
 * read Brand Fashion's account page uses. An order with no storefront_id belongs to the default storefront.
 */

beforeEach(function () {
    config(['compat.jwt_secret' => 'v2-account-test-secret', 'compat.jwt_algo' => 'HS256']);
});

/** @return array{user: int, address: int} a customer with an address (any; the orders below are this test's own) */
function v2AccountCustomer(): array
{
    $row = T::row(DB::table('addresses')->whereNotNull('user_id')->orderBy('id')->first(['id', 'user_id']));

    return ['user' => T::int($row->user_id), 'address' => T::int($row->id)];
}

function v2AccountOrder(int $userId, int $addressId, ?int $storefrontId, string $number): void
{
    DB::table('orders')->insert([
        'user_id' => $userId, 'address_id' => $addressId, 'storefront_id' => $storefrontId,
        'total_price_for_order' => '100.00', 'payment_method' => 'cash', 'order_number' => $number,
        'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<string> the order numbers a path lists for the customer */
function v2AccountNumbers(string $code, int $userId): array
{
    $body = withHeaders(['Authorization' => 'Bearer '.HarnessJwt::mint($userId)])
        ->getJson("/api/v2/{$code}/me/orders")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->json('data');

    return array_values(array_map(fn (mixed $o): string => T::str(T::arr($o)['order_number'] ?? null), T::arr($body)));
}

it('refuses a request with no customer token', function () {
    getJson('/api/v2/brandfashion/me/orders')->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
});

it('lists a customer\'s orders on the path\'s storefront only — untagged ones on the default storefront', function () {
    ['user' => $user, 'address' => $address] = v2AccountCustomer();
    v2AccountOrder($user, $address, 1, '970001');
    v2AccountOrder($user, $address, 2, '970002');
    v2AccountOrder($user, $address, null, '970003');

    $bf = v2AccountNumbers('brandfashion', $user);
    expect($bf)->toContain('970002')
        ->and($bf)->not->toContain('970001')
        ->and($bf)->not->toContain('970003');

    $wz = v2AccountNumbers('watchizer', $user);
    expect($wz)->toContain('970001')
        ->and($wz)->toContain('970003')
        ->and($wz)->not->toContain('970002');
});
