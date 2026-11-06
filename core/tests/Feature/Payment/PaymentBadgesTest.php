<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Payment\PaymentBadges;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * ── Payment badges (the twelve updates, Part 7, 2026-11-05) ────────────────────────────────────
 *
 * The rule under test is one sentence: a badge exists exactly when the checkout offers the method.
 * Nothing typed in the dashboard can add one, and switching a method off removes it. The picture
 * is the only thing the team owns.
 */

beforeEach(function () {
    DB::table('storefront_payment_providers')->delete();
    DB::table('storefront_payment_badges')->delete();
});

function pbCod(int $storefrontId = 1): StorefrontPaymentProvider
{
    return StorefrontPaymentProvider::query()->updateOrCreate(
        ['storefront_id' => $storefrontId, 'provider' => 'cod'],
        ['is_enabled' => true, 'credentials' => null, 'settings' => null],
    );
}

/**
 * The badge method keys a storefront's public endpoint serves, in order.
 *
 * @return list<string>
 */
function pbServed(string $code): array
{
    $data = T::arr(get("/api/v2/{$code}/payment-badges")->assertOk()->json('data'));

    return array_values(array_map(fn (mixed $badge): string => T::str(T::arr($badge)['method'] ?? null), $data));
}

function pbCode(int $storefrontId): string
{
    return T::str(DB::table('storefronts')->where('id', $storefrontId)->value('code'));
}

it('serves one badge per method the checkout offers, in the checkout order, and nothing else', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method(pbCod(), 'cod', null, sort: 0, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    PaymentFixture::method($paymob, 'card', '4001', sort: 1);
    PaymentFixture::method($paymob, 'valu', '4002', sort: 2, labelAr: 'ڤاليو', labelEn: 'valU');
    PaymentFixture::method($paymob, 'wallet', '4003', sort: 3, enabled: false);
    PaymentFixture::method($paymob, 'kiosk', null, sort: 4);            // enabled, but cannot take a payment

    $offered = array_map(
        fn (mixed $row): string => T::str(T::arr($row)['method'] ?? null),
        T::arr(get('/api/v2/'.pbCode(1).'/payment-methods')->assertOk()->json('data')),
    );

    expect(pbServed(pbCode(1)))->toBe(['cod', 'card', 'valu'])
        ->and(pbServed(pbCode(1)))->toBe(array_values(array_unique($offered)));
});

it('drops the badge the moment its method is switched off', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method(pbCod(), 'cod', null, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    $valu = PaymentFixture::method($paymob, 'valu', '4002', sort: 2, labelAr: 'ڤاليو', labelEn: 'valU');
    PaymentBadges::set(1, 'valu', 'Payment/valu.webp', null);

    expect(pbServed(pbCode(1)))->toBe(['cod', 'valu']);

    DB::table('storefront_payment_methods')->where('id', $valu->getAttribute('id'))->update(['is_enabled' => false]);

    expect(pbServed(pbCode(1)))->toBe(['cod'])
        // the picture is kept for the day the method comes back — it just cannot make a badge appear
        ->and(PaymentBadges::pictures(1))->toHaveKey('valu');
});

it('keeps each storefront to its own methods and its own pictures', function () {
    $other = T::int(DB::table('storefronts')->where('id', '!=', 1)->orderBy('id')->value('id'));
    PaymentFixture::method(PaymentFixture::paymob(), 'card', '4001');
    PaymentFixture::method(pbCod($other), 'cod', null, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    PaymentBadges::set(1, 'cod', 'Payment/watchizer-cod.webp', null);

    expect(pbServed(pbCode(1)))->toBe(['card'])
        ->and(pbServed(pbCode($other)))->toBe(['cod']);

    $badge = T::arr(T::arr(get('/api/v2/'.pbCode($other).'/payment-badges')->json('data'))[0] ?? null);
    expect($badge['image'])->toBeNull()                     // storefront 1's picture is not this shop's
        ->and($badge)->not->toHaveKey('image_path');
});

it('never makes a badge of ordering by WhatsApp', function () {
    $whatsapp = StorefrontPaymentProvider::query()->updateOrCreate(
        ['storefront_id' => 1, 'provider' => 'whatsapp'],
        ['is_enabled' => true, 'credentials' => null, 'settings' => null],
    );
    PaymentFixture::method($whatsapp, 'whatsapp', null, labelAr: 'اطلب عبر واتساب', labelEn: 'Order on WhatsApp');
    PaymentFixture::method(pbCod(), 'cod', null, sort: 1, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');

    expect(pbServed(pbCode(1)))->toBe(['cod']);
});

it('serves the uploaded picture with the address of the storefront and a private one-minute cache', function () {
    PaymentFixture::method(pbCod(), 'cod', null, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    PaymentBadges::set(1, 'cod', 'Payment/cod.webp', null);

    $response = get('/api/v2/'.pbCode(1).'/payment-badges')->assertOk();
    $badge = T::arr(T::arr($response->json('data'))[0] ?? null);

    expect(T::str(T::arr($badge['image'])['src'] ?? null))->toEndWith('/Uploads_Images/Payment/cod.webp')
        ->and(T::arr($badge['label']))->toBe(['ar' => 'الدفع عند الاستلام', 'en' => 'Cash on delivery'])
        ->and(T::str($response->headers->get('Cache-Control')))->toContain('private')->toContain('max-age=60');
});

it('lets the dashboard set, replace and remove a picture, and writes each change to the activity log', function () {
    PaymentFixture::method(pbCod(), 'cod', null, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    $admin = Staff::admin();
    $before = DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_payment_badges')->count();

    actingAs($admin)->put('/manage/storefronts/1/payments/badges/cod', ['image_path' => 'Payment/a.webp'])->assertSessionHasNoErrors();
    actingAs($admin)->put('/manage/storefronts/1/payments/badges/cod', ['image_path' => 'Payment/b.webp'])->assertSessionHasNoErrors();

    $props = Props::of(actingAs($admin)->get('/manage/storefronts/1/payments')->assertOk());
    $row = T::arr(T::arr($props['badges'] ?? null)[0] ?? null);

    expect(PaymentBadges::pictures(1))->toBe(['cod' => 'Payment/b.webp'])
        ->and(DB::table('storefront_payment_badges')->where('storefront_id', 1)->count())->toBe(1)
        ->and($row['method'] ?? null)->toBe('cod')
        ->and(T::str($row['image_url'] ?? null))->toEndWith('/Uploads_Images/Payment/b.webp');

    actingAs($admin)->put('/manage/storefronts/1/payments/badges/cod', ['image_path' => null])->assertSessionHasNoErrors();

    expect(PaymentBadges::pictures(1))->toBe([])
        ->and(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_payment_badges')->count())->toBe($before + 3);
});

it('refuses a picture for a method the storefront does not offer, and still lets a stale one be removed', function () {
    PaymentFixture::method(pbCod(), 'cod', null, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    PaymentBadges::set(1, 'valu', 'Payment/old-valu.webp', null);
    $admin = Staff::admin();

    actingAs($admin)->put('/manage/storefronts/1/payments/badges/valu', ['image_path' => 'Payment/valu.webp'])
        ->assertSessionHasErrors('badge');
    expect(PaymentBadges::pictures(1))->toBe(['valu' => 'Payment/old-valu.webp']);

    actingAs($admin)->put('/manage/storefronts/1/payments/badges/valu', ['image_path' => null])->assertSessionHasNoErrors();
    expect(PaymentBadges::pictures(1))->toBe([]);
});
