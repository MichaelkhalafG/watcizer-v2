<?php

use App\Domain\Payment\MethodList;
use App\Models\Storefront\StorefrontPaymentMethod;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * ── Two methods on one integration id: warned, not refused (2026-09-28) ────────────────────────
 *
 * bank_installment was saved as 5943060 — valU's number, a digit away from its own 5943061 — and
 * the screen took it silently. With a shared id, a shopper who picks either method is charged
 * through the same Paymob integration. The developer's decision: the save goes through, and both
 * the save and the screen say which other method carries the number.
 */

/** Storefront 1's Paymob contract with none of this test's keys under it. */
function sharedIdContract(): StorefrontPaymentProvider
{
    $contract = PaymentFixture::paymob();
    DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))
        ->whereIn('method', ['aman', 'halan', 'sympl'])
        ->delete();

    return $contract;
}

/** @return array<string, mixed> */
function sharedIdForm(StorefrontPaymentProvider $contract, string $method, ?string $integrationId): array
{
    return [
        'storefront_payment_provider_id' => $contract->getAttribute('id'),
        'method' => $method,
        'integration_id' => $integrationId,
        'is_enabled' => true,
        'sort' => 50,
        'label' => ['ar' => 'طريقة '.$method, 'en' => 'Method '.$method],
    ];
}

/** @return array<string, mixed> the forAdmin row for this method under this contract */
function adminRow(StorefrontPaymentProvider $contract, string $method): array
{
    foreach (MethodList::forAdmin(1, 'en') as $row) {
        if ($row['method'] === $method && $row['provider_id'] === T::int($contract->getAttribute('id'))) {
            return $row;
        }
    }
    throw new RuntimeException("no {$method} row");
}

it('marks every method under one contract that shares an integration id, disabled ones included', function () {
    $paymob = sharedIdContract();
    PaymentFixture::method($paymob, 'aman', '9990001', sort: 60);
    PaymentFixture::method($paymob, 'halan', ' 9990001 ', sort: 61, enabled: false); // spaces: the same number
    PaymentFixture::method($paymob, 'sympl', '9990002', sort: 62);

    expect(adminRow($paymob, 'aman')['shares_integration_id_with'])->toBe(['halan'])
        ->and(adminRow($paymob, 'halan')['shares_integration_id_with'])->toBe(['aman'])
        ->and(adminRow($paymob, 'sympl')['shares_integration_id_with'])->toBe([]);
});

it('does not flag the same number under ANOTHER contract, or a blank id', function () {
    $paymob = sharedIdContract();
    $fawry = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true, 'credentials' => null, 'settings' => null,
    ]);
    PaymentFixture::method($paymob, 'aman', '9990001', sort: 60);
    PaymentFixture::method($fawry, 'aman', '9990001', sort: 61);
    PaymentFixture::method($paymob, 'halan', null, sort: 62, enabled: false);
    PaymentFixture::method($paymob, 'sympl', '  ', sort: 63, enabled: false);

    expect(adminRow($paymob, 'aman')['shares_integration_id_with'])->toBe([])
        ->and(adminRow($fawry, 'aman')['shares_integration_id_with'])->toBe([])
        ->and(adminRow($paymob, 'halan')['shares_integration_id_with'])->toBe([])
        ->and(adminRow($paymob, 'sympl')['shares_integration_id_with'])->toBe([]);
});

it('SAVES a method whose id is already taken, and warns naming the other method', function () {
    $paymob = sharedIdContract();
    PaymentFixture::method($paymob, 'aman', '9990001', sort: 60);

    actingAs(Staff::admin())
        ->post('/manage/storefronts/1/payments/methods', sharedIdForm($paymob, 'halan', '9990001'))
        ->assertRedirect()
        ->assertSessionHas('status')
        ->assertSessionHas('warning', fn (string $w): bool => str_contains($w, '9990001') && str_contains($w, 'aman'));

    $saved = StorefrontPaymentMethod::query()
        ->where('storefront_payment_provider_id', $paymob->getAttribute('id'))->where('method', 'halan')->first();
    expect($saved?->getAttribute('integration_id'))->toBe('9990001');
});

it('warns on an UPDATE onto a taken id, and not once the numbers are distinct', function () {
    $paymob = sharedIdContract();
    PaymentFixture::method($paymob, 'aman', '9990001', sort: 60);
    $halan = PaymentFixture::method($paymob, 'halan', '9990002', sort: 61);
    $admin = Staff::admin();
    $url = '/manage/storefronts/1/payments/methods/'.T::int($halan->getKey());

    actingAs($admin)->put($url, sharedIdForm($paymob, 'halan', '9990001'))
        ->assertSessionHas('warning');

    actingAs($admin)->put($url, sharedIdForm($paymob, 'halan', '9990003'))
        ->assertSessionHas('status')
        ->assertSessionMissing('warning');
});

it('puts the warning where the dashboard shell reads it', function () {
    $paymob = sharedIdContract();
    PaymentFixture::method($paymob, 'aman', '9990001', sort: 60);
    PaymentFixture::method($paymob, 'halan', '9990001', sort: 61);

    $props = Props::of(actingAs(Staff::admin())->withSession(['warning' => 'check the ids'])->get('/manage/storefronts/1/payments')->assertOk());
    $flash = T::arr($props['flash'] ?? null);
    $merged = T::arr($props['merged'] ?? null);
    $aman = array_values(array_filter($merged, fn (mixed $r): bool => T::arr($r)['method'] === 'aman' && T::arr($r)['provider_id'] === T::int($paymob->getAttribute('id'))));

    expect($flash['warning'] ?? null)->toBe('check the ids')
        ->and(T::arr($aman[0])['shares_integration_id_with'])->toBe(['halan']);
});
