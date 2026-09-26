<?php

use App\Domain\Notifications\OrderEmailData;
use App\Domain\Orders\OrderCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Props;
use Tests\Support\Shopper;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * ── Who is this order's customer? One answer (2026-09-26) ─────────────────────────────────────
 *
 * The dashboard's order detail showed "Name — · Phone — · Email —" for every REGISTERED buyer: it
 * read only the `guest_*` columns, which the storefront fills for guests alone. The team could not
 * phone a registered customer about their order. `OrderCustomer` is now the one definition, read by
 * the order list, the detail, the search, the e-mails and the Paymob billing.
 */

/** @return array{order: int, email: string} */
function registeredOrder(?string $accountPhone = '01055550000'): array
{
    $user = Shopper::register(['first_name' => 'Salma', 'last_name' => 'Registered', 'phone_number' => $accountPhone]);
    $city = T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
    $address = (int) DB::table('addresses')->insertGetId([
        'user_id' => $user->id, 'guest_token' => null, 'shipping_city_id' => $city,
        'address_line' => 'Registered Street 9', 'phone_number_one' => '01077771234', 'phone_number_two' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = (int) DB::table('orders')->insertGetId([
        'user_id' => $user->id, 'address_id' => $address, 'storefront_id' => 1,
        'total_price_for_order' => '100.00', 'payment_method' => 'cash',
        'order_number' => 'RG'.random_int(1000000, 9999999), 'status' => 'processing',
        // Exactly what the storefront sends for a signed-in shopper: no guest_* at all.
        'guest_name' => null, 'guest_email' => null, 'guest_phone' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['order' => $order, 'email' => T::str($user->email)];
}

it('shows a REGISTERED buyer\'s name, phone and e-mail on the order detail', function () {
    $f = registeredOrder();

    $props = Props::of(actingAs(Staff::admin())->get("/manage/orders/{$f['order']}")->assertOk());
    $customer = T::arr(T::arr($props['order'] ?? [])['customer'] ?? []);

    expect($customer['name'] ?? null)->toBe('Salma Registered')
        ->and($customer['phone'] ?? null)->toBe('01077771234')   // the order's address first
        ->and($customer['email'] ?? null)->toBe($f['email'])
        ->and($customer['user_id'] ?? null)->not->toBeNull();
});

it('shows them in the order LIST, and finds the order by the address phone', function () {
    $f = registeredOrder();

    $rows = Props::rows(Props::table(actingAs(Staff::admin())->get('/manage/orders?q=01077771234')->assertOk()));
    $row = collect($rows)->firstWhere('id', $f['order']);

    expect($row)->not->toBeNull()
        ->and(T::arr($row)['customer'] ?? null)->toBe('Salma Registered')
        ->and(T::arr($row)['phone'] ?? null)->toBe('01077771234');
});

it('keeps a GUEST order exactly as before', function () {
    $guestToken = (string) Str::uuid();
    // A guest's address carries the number they typed, which is also their guest_phone.
    $address = (int) DB::table('addresses')->insertGetId([
        'user_id' => null, 'guest_token' => $guestToken, 'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Guest Street 1', 'phone_number_one' => '01012340000', 'phone_number_two' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null, 'address_id' => $address, 'storefront_id' => 1, 'total_price_for_order' => '50.00',
        'payment_method' => 'cash', 'order_number' => 'GS'.random_int(1000000, 9999999), 'status' => 'processing',
        'guest_name' => 'Guest Buyer', 'guest_email' => 'guest-'.Str::random(6).'@example.test', 'guest_phone' => '01012340000',
        'guest_token' => $guestToken, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = OrderCustomer::of($orderId);

    expect($customer?->name)->toBe('Guest Buyer')
        ->and($customer?->phone)->toBe('01012340000')
        ->and($customer?->isGuest())->toBeTrue();
});

it('gives the order e-mails the same answer as the screen', function () {
    $f = registeredOrder();
    $data = T::arr(OrderEmailData::for($f['order']));

    expect([$data['customerName'] ?? null, $data['customerEmail'] ?? null, $data['customerPhone'] ?? null])
        ->toBe(['Salma Registered', $f['email'], '01077771234']);
});

it('falls back to the account\'s phone, then the second address phone, and never invents one', function () {
    $f = registeredOrder();
    DB::table('addresses')->where('id', T::int(DB::table('orders')->where('id', $f['order'])->value('address_id')))
        ->update(['phone_number_one' => '', 'phone_number_two' => '01099990000']);   // blank: the column is NOT NULL

    $customer = OrderCustomer::of($f['order']);
    expect([$customer?->phone, $customer?->phoneAlt])->toBe(['01055550000', '01099990000']);

    // No phone on the address and none on the account: null, never a placeholder.
    $bare = registeredOrder(null);
    DB::table('addresses')->where('id', T::int(DB::table('orders')->where('id', $bare['order'])->value('address_id')))
        ->update(['phone_number_one' => '']);

    expect(OrderCustomer::of($bare['order'])?->phone)->toBeNull();
});
