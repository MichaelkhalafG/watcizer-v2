<?php

use App\Domain\Payment\MethodList;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentMethod;
use App\Models\Storefront\StorefrontPaymentMethodTranslation;
use App\Models\Storefront\StorefrontPaymentProvider;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

/*
 * The collapse rule of study §3.9.5, with the wording the 4C brief settled: a duplicated method key
 * shows ONCE and the LOWEST `sort` wins.
 *
 * The study flagged "highest-sort provider" as ambiguous and asked for one sentence of
 * confirmation, because the two readings pick OPPOSITE providers — and picking the wrong one means
 * the money settles into the wrong merchant account, silently, on every card order. So the rule is
 * asserted here as data, in both directions, rather than described in a comment.
 */

it('shows one entry per method key, and the LOWEST sort wins', function () {
    $paymob = PaymentFixture::paymob(storefrontId: 1);
    // A second contract on the same storefront, also offering `card`.
    $fawry = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true,
        'credentials' => ['merchant_code' => 'x'], 'settings' => null,
    ]);

    PaymentFixture::method($paymob, 'card', '4001', sort: 10, labelEn: 'Card');
    PaymentFixture::method($fawry, 'card', 'F-1', sort: 20, labelEn: 'Card');
    PaymentFixture::method($paymob, 'valu', '4002', sort: 30, labelEn: 'valU');

    $list = MethodList::forCustomer(1, 'en');
    $methods = array_map(fn (array $r): string => $r['method'], $list);

    expect($methods)->toBe(['card', 'valu'], 'the customer never sees the same method twice');

    // The winner is Paymob's row: sort 10 comes before sort 20.
    $admin = MethodList::forAdmin(1, 'en');
    $cardRows = array_values(array_filter($admin, fn (array $r): bool => $r['method'] === 'card'));
    expect(count($cardRows))->toBe(2);
    foreach ($cardRows as $row) {
        expect($row['serves'])->toBe($row['provider'] === 'paymob',
            "expected paymob to serve `card` at sort 10, not {$row['provider']}");
    }

    // Flip the sorts and the winner flips with them — the rule is the ORDER, not the provider name.
    StorefrontPaymentMethod::query()
        ->where('storefront_payment_provider_id', $fawry->getAttribute('id'))
        ->where('method', 'card')->update(['sort' => 1]);

    $after = MethodList::forAdmin(1, 'en');
    $cardAfter = array_values(array_filter($after, fn (array $r): bool => $r['method'] === 'card'));
    foreach ($cardAfter as $row) {
        expect($row['serves'])->toBe($row['provider'] === 'fawry', 'the lowest sort must win after the flip');
    }
});

it('tells the admin which contract is NOT serving, and why it is still listed', function () {
    $paymob = PaymentFixture::paymob(storefrontId: 1);
    $fawry = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true,
        'credentials' => null, 'settings' => null,
    ]);
    PaymentFixture::method($paymob, 'card', '4001', sort: 5);
    PaymentFixture::method($fawry, 'card', 'F-1', sort: 9);

    $rows = array_values(array_filter(MethodList::forAdmin(1, 'en'), fn (array $r): bool => $r['method'] === 'card'));
    $losing = array_values(array_filter($rows, fn (array $r): bool => ! $r['serves']));

    expect(count($losing))->toBe(1)
        // "Card → Paymob (also configured on Fawry)": the loser stays listed and editable, and
        // says who took it. Once two contracts can serve one button, this must never be inferred.
        ->and($losing[0]['served_by'])->toBe('paymob');
});

it('warns when two enabled rows of one key carry DIFFERENT labels', function () {
    // The customer sees the WINNER's label, so re-ordering two differently-labelled rows would
    // silently change the storefront's wording.
    $paymob = PaymentFixture::paymob(storefrontId: 1);
    $fawry = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true, 'credentials' => null, 'settings' => null,
    ]);
    PaymentFixture::method($paymob, 'card', '4001', sort: 1, labelEn: 'Card');
    PaymentFixture::method($fawry, 'card', 'F-1', sort: 2, labelEn: 'Bank card');

    $rows = array_values(array_filter(MethodList::forAdmin(1, 'en'), fn (array $r): bool => $r['method'] === 'card'));

    foreach ($rows as $row) {
        expect($row['label_mismatch'])->toBeTrue();
    }

    // Identical labels: no warning.
    StorefrontPaymentMethodTranslation::query()
        ->where('locale', 'en')->where('label', 'Bank card')->update(['label' => 'Card']);

    foreach (array_filter(MethodList::forAdmin(1, 'en'), fn (array $r): bool => $r['method'] === 'card') as $row) {
        expect($row['label_mismatch'])->toBeFalse();
    }
});

it('hides a method whose PROVIDER is disabled, credentials intact', function () {
    // Disabling a contract switches off every method under it in one act (§3.9.1).
    $paymob = PaymentFixture::paymob(storefrontId: 1, enabled: false);
    PaymentFixture::method($paymob, 'card', '4001', sort: 1);

    expect(MethodList::forCustomer(1, 'en'))->toBe([]);

    // …and the admin still sees the row, so it can be switched back on.
    expect(count(MethodList::forAdmin(1, 'en')))->toBe(1)
        ->and($paymob->credentialsSet())->toBeTrue('disabling must not clear credentials');
});

it('never leaks another storefront’s methods', function () {
    $one = PaymentFixture::paymob(storefrontId: 1);
    PaymentFixture::method($one, 'card', '4001', sort: 1, labelEn: 'Card one');

    $two = PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID);
    PaymentFixture::method($two, 'valu', '9001', sort: 1, labelEn: 'valU two');

    expect(array_map(fn (array $r): string => $r['method'], MethodList::forCustomer(1, 'en')))->toBe(['card'])
        ->and(array_map(fn (array $r): string => $r['method'], MethodList::forCustomer(Storefront::BRAND_FASHION_ID, 'en')))->toBe(['valu']);
});

it('routes the legacy string and the v2 id to the SAME contract', function () {
    /*
     * A legacy client posting "card" and a v2 client posting the method id must reach the same
     * merchant account — otherwise the same order placed two ways settles in two places.
     */
    $paymob = PaymentFixture::paymob(storefrontId: 1);
    $fawry = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true, 'credentials' => null, 'settings' => null,
    ]);
    $winner = PaymentFixture::method($paymob, 'card', '4001', sort: 3);
    PaymentFixture::method($fawry, 'card', 'F-1', sort: 8);
    PaymentFixture::method($paymob, 'cod', null, sort: 9);

    $byId = MethodList::resolve(1, 'en', T::int($winner->getAttribute('id')));
    $byLegacyCard = MethodList::resolve(1, 'en', 'card');
    $byLegacyPaymob = MethodList::resolve(1, 'en', 'paymob');
    $byLegacyCash = MethodList::resolve(1, 'en', 'cash');

    expect($byId)->not->toBeNull()
        ->and($byLegacyCard)->toBe($byId, 'the legacy string must land on the winning row')
        ->and($byLegacyPaymob)->toBe($byId)
        ->and($byLegacyCash['method'] ?? null)->toBe('cod', 'cash maps to the cod method');

    // Unknown, disabled and foreign rows resolve to nothing — the caller answers 422.
    expect(MethodList::resolve(1, 'en', 'bitcoin'))->toBeNull()
        ->and(MethodList::resolve(1, 'en', 999999))->toBeNull();
});
