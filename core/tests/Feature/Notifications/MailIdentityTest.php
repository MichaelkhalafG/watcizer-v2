<?php

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\OrderEmailData;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Notifications\StockAlerts;
use App\Mail\CustomerPasswordReset;
use App\Mail\OrderConfirmation;
use App\Mail\PaymentExpired;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PaymentFixture;
use Tests\Support\StorefrontIdentityFixture;
use Tests\Support\T;

/*
 * ── Every mail carries its STOREFRONT's identity, or is parked (W2, developer 2026-10-13) ───────
 *
 * The measurement that shows W2 is right: a Brand Fashion order mail renders Brand Fashion's
 * wordmark, number and copyright and the string "Watchizer" ZERO times; a Watchizer mail is the
 * same document with its own values; a storefront with no identity sends nothing and the row says
 * why. Also closed here: the two host fallbacks batch 1 did not reach — the order-list link and
 * the recovery link both used to fall back to `watchizereg.com`.
 */

beforeEach(function () {
    config([
        'notifications.admin_emails' => ['ops@example.test'],
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,
        'notifications.send.park' => false,
        'storefront.asset_base' => 'https://api.watchizereg.test',
        'customers.storefront_url' => 'https://watchizereg.test',
    ]);
    Mail::fake();
});

/** A paid-looking order on a storefront, with a customer address, so every order mail renders. */
function identityOrder(int $storefrontId): int
{
    $orderId = PaymentFixture::order(total: 100.0, storefrontId: $storefrontId);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper@example.test', 'guest_name' => 'Identity Test', 'guest_phone' => '01000000000', 'payment_method' => 'cash',
    ]);

    return $orderId;
}

it('renders a Brand Fashion order mail with NOTHING of Watchizer in it, and vice versa', function () {
    $fashionOrder = identityOrder(2);
    $watchizerOrder = identityOrder(1);

    app(OrderMailer::class)->placedNow($fashionOrder, notifyCustomer: true);
    app(OrderMailer::class)->placedNow($watchizerOrder, notifyCustomer: true);

    $rendered = [];
    Mail::assertSent(OrderConfirmation::class, function (OrderConfirmation $m) use (&$rendered): bool {
        $rendered[] = $m->render();

        return true;
    });
    expect($rendered)->toHaveCount(2);
    [$fashion, $watchizer] = str_contains($rendered[0], 'brandfashion-mail-wordmark') ? [$rendered[0], $rendered[1]] : [$rendered[1], $rendered[0]];

    expect(substr_count($fashion, 'Watchizer'))->toBe(0)
        ->and($fashion)->toContain('https://api.brandfashionegy.com/assets/brand/brandfashion-mail-wordmark@2x.png', 'wa.me/201283888490', 'Brand Fashion', 'https://brandfashionegy.com/order-list')
        ->and($fashion)->not->toContain('watchizereg', '201551096234')
        ->and($watchizer)->toContain('https://api.watchizereg.test/assets/brand/watchizer-mail-wordmark@2x.png', 'wa.me/201551096234', 'https://watchizereg.test/order-list')
        ->and($watchizer)->not->toContain('brandfashion', '201283888490');

    // The subject follows the row too.
    Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m) => $m->hasSubject('✅ تم استلام طلبك — Brand Fashion | Order #'.PaymentFixture::orderNumber($fashionOrder).' Confirmed'));
});

it('PARKS the order mail of a storefront with no identity, saying what is missing — never sends it as the other shop', function () {
    $orderId = identityOrder(2);
    StorefrontIdentityFixture::clear(2);

    app(OrderMailer::class)->placedNow($orderId, notifyCustomer: true);

    // OrderMailer's park() is terminal: the row reads `failed`, never retried, with the reason.
    Mail::assertNothingSent();
    $rows = DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->where('aggregate_id', $orderId)->get(['status', 'last_error']);
    expect($rows->count())->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect(T::str($row->status))->toBe(OrderMailer::STATUS_FAILED)
            ->and(T::str($row->last_error))->toContain('storefront 2 has no identity', 'mail_wordmark is not set');
    }
});

it('builds the recovery link on the order\'s OWN site, and parks when that storefront has no host', function () {
    $orderId = identityOrder(2);
    DB::table('orders')->where('id', $orderId)->update(['payment_method' => 'paymob', 'status' => 'cancelled']);

    $html = (new PaymentExpired(OrderEmailData::for($orderId) ?? []))->render();
    expect($html)->toContain('https://brandfashionegy.com/cart/recover?t=')
        ->and($html)->not->toContain('watchizereg');

    // Now the storefront has no domain: the recovery link cannot be built, and the mail is parked
    // with the reason instead of pointing at Watchizer (the old fallback, PAYMENT_MAP F8).
    DB::table('storefronts')->where('id', 2)->update(['domain' => null]);
    app(StorefrontCache::class)->forgetStorefront(2, 'brandfashion');
    DB::table('orders')->where('id', $orderId)->update(['status' => 'pending']);

    $ids = app(OrderMailer::class)->paymentExpired($orderId);
    app(OrderMailer::class)->flush($ids);

    Mail::assertNothingSent();
    foreach (DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->where('aggregate_id', $orderId)->get(['status', 'last_error']) as $row) {
        expect(T::str($row->status))->toBe(OrderMailer::STATUS_FAILED)   // park() is terminal: failed, with the reason
            ->and(T::str($row->last_error))->toContain('has no host');
    }
});

it('parks a BULK mail of a storefront with no identity, with the reason', function () {
    // A notified alert on a product that is visible and in stock on Brand Fashion, written directly:
    // `subscribe()` refuses a product that is in stock, and the send path needs it in stock.
    $product = T::int(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 2)->where('sp.is_visible', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>', 0)
        ->orderBy('p.id')->value('p.id'));
    $alertId = (int) DB::table('core_stock_alerts')->insertGetId([
        'storefront_id' => 2, 'product_id' => $product, 'email' => 'bulk@example.test', 'user_id' => null, 'locale' => 'en',
        'token' => str_repeat('b', 40), 'status' => StockAlerts::NOTIFIED, 'created_at' => now(), 'notified_at' => now(),
    ]);
    $rowId = (int) DB::table('integration_outbox')->insertGetId([
        'channel' => BulkMailer::CHANNEL, 'event' => 'stock_alert', 'dedupe_key' => 'identity-test:'.$alertId,
        'aggregate_type' => 'core_stock_alerts', 'aggregate_id' => $alertId,
        'payload' => json_encode(['kind' => StockAlerts::KIND, 'recipient' => 'bulk@example.test', 'alert_id' => $alertId]),
        'status' => OrderMailer::STATUS_PENDING, 'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
    ]);
    StorefrontIdentityFixture::clear(2);

    app(BulkMailer::class)->drain();

    Mail::assertNothingSent();
    $row = T::row(DB::table('integration_outbox')->where('id', $rowId)->first(['status', 'last_error']));
    expect(T::str($row->status))->toBe(OrderMailer::STATUS_PARKED)
        ->and(T::str($row->last_error))->toContain('has no identity');
});

it('gives the password-reset mail the identity of the shop it was asked for on', function () {
    $identity = StorefrontIdentity::for(2);
    $html = (new CustomerPasswordReset('https://brandfashionegy.com/reset-password?token=t&email=e', 'Sara', 60, $identity))->render();

    expect($html)->toContain('brandfashion-mail-wordmark@2x.png', 'wa.me/201283888490', 'reset your Brand Fashion password')
        ->and(substr_count($html, 'Watchizer'))->toBe(0);
});
