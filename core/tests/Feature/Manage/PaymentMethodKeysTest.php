<?php

use App\Domain\Payment\MethodList;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * ── The payment-method key list, widened for Paymob's integration ids (2026-09-24) ─────────────
 *
 * A key is a label and a route name: the integration id on the row is what Paymob routes by. So
 * the risk worth testing is not the label, it is a row that LOOKS configured and cannot take a
 * payment — enabled with no integration id, or with one that is not a number.
 */

const NEW_PAYMOB_KEYS = [
    'cagg', 'bank_installment', 'apple_pay', 'kiosk', 'souhoola', 'aman', 'halan', 'sympl', 'forsa',
    'premium', 'contact',
];

/**
 * The storefront-1 Paymob contract, with none of the new keys under it yet.
 */
function keysContract(): StorefrontPaymentProvider
{
    $contract = PaymentFixture::paymob();
    DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))
        ->whereIn('method', [...NEW_PAYMOB_KEYS, 'valu'])
        ->delete();

    return $contract;
}

/**
 * The form the screen posts for one method.
 *
 * @return array<string, mixed>
 */
function methodForm(StorefrontPaymentProvider $contract, string $method, ?string $integrationId, bool $enabled): array
{
    return [
        'storefront_payment_provider_id' => $contract->getAttribute('id'),
        'method' => $method,
        'integration_id' => $integrationId,
        'is_enabled' => $enabled,
        'sort' => 50,
        'label' => ['ar' => 'طريقة '.$method, 'en' => 'Method '.$method],
    ];
}

it('offers every key asked of Paymob, and keeps the ones that were already there', function () {
    keysContract();

    $keys = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk())['method_keys'] ?? null;

    expect($keys)->toBe([
        'card', 'valu', 'tamara', 'wallet', 'cagg', 'bank_installment', 'apple_pay', 'kiosk',
        'souhoola', 'aman', 'halan', 'sympl', 'forsa', 'premium', 'contact', 'fawry_code', 'cod', 'whatsapp',
    ]);
});

it('stores each new key ENABLED once it has a numeric integration id', function () {
    $contract = keysContract();
    $admin = Staff::admin();

    foreach (NEW_PAYMOB_KEYS as $i => $key) {
        actingAs($admin)->post('/manage/storefronts/1/payments/methods', methodForm($contract, $key, (string) (5943060 + $i), true))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    expect(DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))
        ->whereIn('method', NEW_PAYMOB_KEYS)->where('is_enabled', true)->count())->toBe(count(NEW_PAYMOB_KEYS));
});

it('REFUSES to enable a method with no integration id, and writes nothing', function () {
    $contract = keysContract();

    actingAs(Staff::admin())->post('/manage/storefronts/1/payments/methods', methodForm($contract, 'valu', null, true))
        ->assertRedirect()->assertSessionHasErrors('integration_id');

    expect(DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))->where('method', 'valu')->exists())->toBeFalse();
});

it('REFUSES to enable a method whose integration id is not a number', function () {
    $contract = keysContract();

    foreach (['ID5943060', '5943060 wallet', '5943-060'] as $bad) {
        actingAs(Staff::admin())->post('/manage/storefronts/1/payments/methods', methodForm($contract, 'cagg', $bad, true))
            ->assertRedirect()->assertSessionHasErrors('integration_id');
    }

    expect(DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))->where('method', 'cagg')->exists())->toBeFalse();
});

it('lets a method be PREPARED suspended before its id arrives, then refuses to switch it on bare', function () {
    $contract = keysContract();
    $admin = Staff::admin();

    actingAs($admin)->post('/manage/storefronts/1/payments/methods', methodForm($contract, 'valu', null, false))
        ->assertRedirect()->assertSessionHasNoErrors();
    $id = T::int(DB::table('storefront_payment_methods')
        ->where('storefront_payment_provider_id', $contract->getAttribute('id'))->where('method', 'valu')->value('id'));

    $form = methodForm($contract, 'valu', null, true);
    unset($form['storefront_payment_provider_id']);
    actingAs($admin)->put("/manage/storefronts/1/payments/methods/{$id}", $form)
        ->assertRedirect()->assertSessionHasErrors('integration_id');
    expect(DB::table('storefront_payment_methods')->where('id', $id)->value('is_enabled'))->toBeFalsy();

    // …and the same update with the id Paymob sent goes through.
    $form['integration_id'] = '5943062';
    actingAs($admin)->put("/manage/storefronts/1/payments/methods/{$id}", $form)
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('storefront_payment_methods')->where('id', $id)->value('is_enabled'))->toBeTruthy();
});

it('marks an unusable row on the methods screen, enabled or not, and leaves a good one unmarked', function () {
    $contract = keysContract();
    // Straight into the table, as rows entered before the guard existed would be.
    $missing = T::int(PaymentFixture::method($contract, 'valu', integrationId: null, sort: 90)->getKey());
    $malformed = T::int(PaymentFixture::method($contract, 'kiosk', integrationId: 'kiosk-id', sort: 91, enabled: false)->getKey());
    $good = T::int(PaymentFixture::method($contract, 'cagg', integrationId: '5943060', sort: 92)->getKey());

    $byId = [];
    foreach (MethodList::forAdmin(1, 'ar') as $row) {
        $byId[$row['id']] = $row['unusable'];
    }

    expect($byId[$missing] ?? 'absent')->toBe('missing')
        ->and($byId[$malformed] ?? 'absent')->toBe('malformed')
        ->and(array_key_exists($good, $byId))->toBeTrue()
        ->and($byId[$good])->toBeNull();
});

it('does not move card traffic: the storefront "card" post still lands on the card row', function () {
    $contract = keysContract();
    $card = PaymentFixture::method($contract, 'card', integrationId: '4001', sort: 10);
    // A new key sorted ABOVE card must not take card's money — the collapse is per key.
    PaymentFixture::method($contract, 'cagg', integrationId: '5943060', sort: 0);

    $resolved = MethodList::resolve(1, 'ar', 'card');

    expect($resolved['id'] ?? null)->toBe($card->getKey())
        ->and($resolved['method'] ?? null)->toBe('card');
});

it('still refuses a key that is not on the list', function () {
    $contract = keysContract();

    actingAs(Staff::admin())->post('/manage/storefronts/1/payments/methods', methodForm($contract, 'tabby', '5943099', true))
        ->assertRedirect()->assertSessionHasErrors('method');
});

it('saves a method\'s order limits, clears a blank one, keeps other settings, and refuses max below min', function () {
    /*
     * Batch 1 (2026-09-26): the checkout greys a method out outside these limits and `add_order`
     * refuses it, so the numbers the screen saves are the numbers a shopper meets.
     */
    $contract = keysContract();
    $admin = Staff::admin();

    actingAs($admin)->post('/manage/storefronts/1/payments/methods', methodForm($contract, 'bank_installment', '5943061', true) + ['min_total' => '1000', 'max_total' => '50000'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $id = T::int(DB::table('storefront_payment_methods')->where('storefront_payment_provider_id', $contract->getAttribute('id'))->where('method', 'bank_installment')->value('id'));
    expect(json_decode(T::str(DB::table('storefront_payment_methods')->where('id', $id)->value('settings')), true))
        ->toBe(['min_total' => 1000, 'max_total' => 50000]);

    DB::table('storefront_payment_methods')->where('id', $id)->update(['settings' => json_encode(['min_total' => 1000, 'max_total' => 50000, 'other' => 'kept'])]);
    actingAs($admin)->put("/manage/storefronts/1/payments/methods/{$id}", methodForm($contract, 'bank_installment', '5943061', true) + ['min_total' => '', 'max_total' => '40000'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(json_decode(T::str(DB::table('storefront_payment_methods')->where('id', $id)->value('settings')), true))
        ->toBe(['max_total' => 40000, 'other' => 'kept']);

    actingAs($admin)->put("/manage/storefronts/1/payments/methods/{$id}", methodForm($contract, 'bank_installment', '5943061', true) + ['min_total' => '5000', 'max_total' => '100'])
        ->assertSessionHasErrors('max_total');
});
