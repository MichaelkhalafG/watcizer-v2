<?php

use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderRecovery;
use App\Domain\Orders\UnpaidOrders;
use App\Mail\PaymentExpired;
use App\Mail\PaymentExpiredAdmin;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PaymentFixture;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
 * ── The decline e-mail (developer, 2026-10-12) ───────────────────────────────────────────────────
 *
 * A shopper who abandons checkout is e-mailed an hour later; a shopper who tried and was declined
 * was told nothing. The one who got further was told less. The fix is wiring, not a new mail: the
 * callback's cancel branch sends the SAME recovery e-mail the expiry sends, with the reason set so
 * the admins' copy says "declined" rather than "didn't pay". The customer's copy was already true.
 *
 * The one wrinkle, pinned here so nobody re-derives it: the decision is made when the callback
 * lands and the mail goes seconds later — nothing re-checks the shopper at send time. A shopper
 * who is declined, retries at once and pays gets this e-mail beside their confirmation, and the
 * recovery link is then refused as `reordered`. Accepted rather than holding the row.
 */

const DR_API_KEY = 'decline-recovery-test-key';

const DR_SECRET = 'decline-secret';

beforeEach(function () {
    config([
        'compat.api_key' => DR_API_KEY,
        'compat.payment_return_url' => 'https://watchizereg.test/',
        'compat.unpaid.expire_after_minutes' => 60,
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,       // the suite never commits; see config
        'notifications.send.park' => false,
    ]);
    StorefrontMailboxFixture::recipients(1, ['ops@example.test']);
    DB::table('storefront_payment_providers')->delete();
    PaymentFixture::method(PaymentFixture::paymob(hmacSecret: DR_SECRET), 'card', '4001');
    Mail::fake();
});

/** A pending card order of a known shopper — what `add_order` writes for a card checkout. */
function drOrder(string $email = 'sara@example.test', string $phone = '01012345678'): int
{
    $orderId = PaymentFixture::order(total: 100.0);
    DB::table('orders')->where('id', $orderId)->update([
        'payment_method' => 'paymob', 'guest_email' => $email, 'guest_phone' => $phone,
    ]);

    return $orderId;
}

/** The provider's callback for this order: `success` true or false, each with its own transaction id. */
function drCallback(int $orderId, bool $success, int $transactionId): void
{
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, success: $success, secret: DR_SECRET, transactionId: $transactionId);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect();
}

/** @return list<string> the kinds of mail rows this order caused */
function drKinds(int $orderId): array
{
    return array_map(fn (array $row): string => T::str($row['kind']), OrderMailer::forOrder($orderId));
}

/** @param  class-string<Mailable>  $mailable */
function drRendered(string $mailable): string
{
    $html = '';
    Mail::assertSent($mailable, function (Mailable $m) use (&$html): bool {
        $html = $m->render();

        return true;
    });

    return $html;
}

it('e-mails a DECLINED shopper the recovery e-mail, and the admins a copy that says declined', function () {
    $orderId = drOrder();
    expect(drKinds($orderId))->toBe([]);                              // a card order says nothing at checkout

    drCallback($orderId, success: false, transactionId: 770001);

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
        ->and(drKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]);

    // The rows carry WHY, so a later send (mail:drain) renders the same words as an inline one.
    $payloads = DB::table('integration_outbox')->where('aggregate_id', $orderId)->where('channel', 'mail')->pluck('payload')->all();
    expect($payloads)->toHaveCount(2);
    foreach ($payloads as $payload) {
        expect(T::str($payload))->toContain('"reason":"declined"');
    }

    Mail::assertSent(PaymentExpired::class, fn (PaymentExpired $m) => $m->hasTo('sara@example.test'));
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => $m->hasTo('ops@example.test'));

    // The customer's copy: the one clause that names the reason, in both languages, and the link.
    $customer = drRendered(PaymentExpired::class);
    expect($customer)->toContain('because the card payment was declined')
        ->and($customer)->toContain('لأن عملية الدفع بالبطاقة رُفضت')
        ->and($customer)->not->toContain("wasn't finished")
        ->and(preg_match('#/cart/recover\?t=([0-9]+\.[0-9]+\.[a-f0-9]{32})#', $customer, $m))->toBe(1)
        ->and(OrderRecovery::verify($m[1] ?? ''))->toBe(['order' => $orderId]);

    // The admins' copy: the story the caller acts on must be the right one.
    $admin = drRendered(PaymentExpiredAdmin::class);
    expect($admin)->toContain('A card payment was declined')
        ->and($admin)->toContain('The customer tried to pay and the card was declined.')
        ->and($admin)->toContain('حاول العميل الدفع ورُفضت البطاقة.')
        ->and($admin)->not->toContain('expired unpaid')
        ->and($admin)->not->toContain("didn't pay");
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => $m->hasSubject('💳 رُفضت البطاقة #'.PaymentFixture::orderNumber($orderId).' — payment-test — 100 EGP'));

    // And the link WORKS for a declined order: cancelled card order of this storefront, nothing else asked.
    withHeaders(['Api-Code' => DR_API_KEY])
        ->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($orderId)])
        ->assertOk();
});

it('still tells the admins "expired unpaid" when nobody paid — the two reasons are two stories', function () {
    $orderId = drOrder();

    // What `orders:expire-unpaid` calls for each order it finds (the sweep itself only lists orders
    // core reserved stock for, which these fixture orders are not).
    expect(app(UnpaidOrders::class)->cancel($orderId, UnpaidOrders::EXPIRED))->toBeTrue()
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
        ->and(drKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]);

    expect(drRendered(PaymentExpiredAdmin::class))->toContain('A card order expired unpaid')
        ->and(drRendered(PaymentExpired::class))->toContain("because the payment wasn't finished");
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => $m->hasSubject('⏱ لم يكتمل الدفع #'.PaymentFixture::orderNumber($orderId).' — payment-test — 100 EGP'));
});

it('sends it on the provider\'s POST too, and once: the browser\'s GET on the same decline adds nothing', function () {
    $orderId = drOrder();
    $number = PaymentFixture::orderNumber($orderId);

    $processed = PaymentFixture::processedCallback($number, 10000, success: false, secret: DR_SECRET, transactionId: 770002);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$processed['hmac'], $processed['body'])->assertOk();
    expect(drKinds($orderId))->toHaveCount(2);

    // The shopper's browser follows on the same transaction: a replay, already on record.
    drCallback($orderId, success: false, transactionId: 770002);
    expect(drKinds($orderId))->toHaveCount(2);
});

it('PENDING then declined: the provider\'s final word is what sends it, once', function () {
    $orderId = drOrder();
    $number = PaymentFixture::orderNumber($orderId);

    // The provider says "still pending": recorded, order untouched, nobody mailed.
    $pending = PaymentFixture::callback($number, 10000, success: false, secret: DR_SECRET, transactionId: 770003, overrides: ['pending' => 'true']);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($pending))->assertRedirect();
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([]);

    // Then the decline.
    drCallback($orderId, success: false, transactionId: 770004);
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
        ->and(drKinds($orderId))->toHaveCount(2);
});

it('does not mail twice when the expiry sweep runs after a decline', function () {
    $orderId = drOrder();
    drCallback($orderId, success: false, transactionId: 770005);
    expect(drKinds($orderId))->toHaveCount(2);

    // Already cancelled: the expiry's claim (`status = pending`) matches nothing, so it sends nothing.
    expect(app(UnpaidOrders::class)->cancel($orderId, UnpaidOrders::EXPIRED))->toBeFalse()
        ->and(drKinds($orderId))->toHaveCount(2);
});

it('keeps the weekly ceiling across reasons: an expiry e-mail this week silences a decline e-mail', function () {
    $expired = drOrder('sara@example.test');
    expect(app(UnpaidOrders::class)->cancel($expired, UnpaidOrders::EXPIRED))->toBeTrue()
        ->and(drKinds($expired))->toHaveCount(2);

    $declined = drOrder('sara@example.test');
    drCallback($declined, success: false, transactionId: 770006);

    expect(T::str(DB::table('orders')->where('id', $declined)->value('status')))->toBe('cancelled')
        ->and(drKinds($declined))->toBe([]);
});

it('sends nothing for a callback that was refused — the order was never cancelled', function () {
    $orderId = drOrder();
    $forged = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, success: false, secret: 'not-the-secret', transactionId: 770007);

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($forged))->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([]);
});

it('is decided when the callback lands: a shopper who retries and PAYS still has the e-mail, and the link is then refused', function () {
    /*
     * The wrinkle, pinned. `OrderRecovery::shouldRemind()` runs inside the cancel transaction and
     * the row is sent seconds after it commits; `OrderMailer::deliver()` re-checks the row's claim,
     * its recipient and that the order still exists — never the shopper. So the only thing that
     * protects a shopper who paid two minutes later is the LINK's refusal (`reordered`), not the
     * e-mail's absence. Accepted (developer, 2026-10-12) instead of holding the row.
     */
    $declined = drOrder('sara@example.test');
    drCallback($declined, success: false, transactionId: 770008);
    expect(drKinds($declined))->toHaveCount(2)
        ->and(DB::table('integration_outbox')->where('aggregate_id', $declined)->where('channel', 'mail')->where('status', OrderMailer::STATUS_SENT)->count())->toBe(2);

    // Two minutes later the same shopper orders again and the card goes through.
    $paid = drOrder('sara@example.test');
    drCallback($paid, success: true, transactionId: 770009);
    expect(T::str(DB::table('orders')->where('id', $paid)->value('status')))->toBe('processing');

    // The decline e-mail is still on record as sent — nothing retracts it…
    expect(DB::table('integration_outbox')->where('aggregate_id', $declined)->where('channel', 'mail')->where('status', OrderMailer::STATUS_SENT)->count())->toBe(2)
        // …and its link is now refused, so the order cannot be bought twice.
        ->and(OrderRecovery::reorderedSince($declined))->toBeTrue();
    withHeaders(['Api-Code' => DR_API_KEY])
        ->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($declined)])
        ->assertStatus(409)->assertJsonPath('refused', 'reordered');
});
