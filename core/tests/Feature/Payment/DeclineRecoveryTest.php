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
 * ── Since 2026-11-05 the e-mail goes at the END OF THE WINDOW, not at the decline ───────────────
 *
 * A decline used to cancel the order on the spot, so the e-mail went seconds later — and a shopper
 * who retried on the same payment page and PAID two minutes afterwards was charged for an order
 * that was already cancelled (order 000092). A decline no longer cancels: the order keeps waiting,
 * a retry that succeeds simply pays it, and `orders:expire-unpaid` ends the wait. The e-mail is the
 * same one with the same reason — the expiry reads "this order saw a declined attempt" and says
 * `declined` — sent once the page that declined them can no longer succeed. The old wrinkle (a
 * shopper who paid still got the decline e-mail beside the confirmation) is gone with it.
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

/** What `orders:expire-unpaid` does for one order at the end of the window. */
function drExpire(int $orderId): bool
{
    return app(UnpaidOrders::class)->cancel($orderId, UnpaidOrders::EXPIRED);
}

it('e-mails a DECLINED shopper the recovery e-mail at the end of the window, and the admins a copy that says declined', function () {
    $orderId = drOrder();
    expect(drKinds($orderId))->toBe([]);                              // a card order says nothing at checkout

    // The decline: on record, and NOTHING else — the order keeps waiting, nobody is e-mailed yet.
    drCallback($orderId, success: false, transactionId: 770001);
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([])
        ->and(T::str(DB::table('payment_statuses')->where('order_id', $orderId)->value('outcome')))->toBe('failed');

    // The window ends with no payment: cancelled, and the e-mail says WHY.
    expect(drExpire($orderId))->toBeTrue()
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
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

it('still tells the admins "expired unpaid" when nobody tried to pay — the two reasons are two stories', function () {
    $orderId = drOrder();

    // What `orders:expire-unpaid` calls for each order it finds (the sweep itself only lists orders
    // core reserved stock for, which these fixture orders are not).
    expect(drExpire($orderId))->toBeTrue()
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
        ->and(drKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]);

    expect(drRendered(PaymentExpiredAdmin::class))->toContain('A card order expired unpaid')
        ->and(drRendered(PaymentExpired::class))->toContain("because the payment wasn't finished");
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => $m->hasSubject('⏱ لم يكتمل الدفع #'.PaymentFixture::orderNumber($orderId).' — payment-test — 100 EGP'));
});

it('sends nothing at the decline — neither on the provider\'s POST nor on the browser\'s GET — and exactly one pair at the expiry', function () {
    $orderId = drOrder();
    $number = PaymentFixture::orderNumber($orderId);

    $processed = PaymentFixture::processedCallback($number, 10000, success: false, secret: DR_SECRET, transactionId: 770002);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$processed['hmac'], $processed['body'])->assertOk();
    expect(drKinds($orderId))->toBe([]);

    // The shopper's browser follows on the same transaction: a replay, already on record.
    drCallback($orderId, success: false, transactionId: 770002);
    expect(drKinds($orderId))->toBe([])
        ->and(DB::table('payment_statuses')->where('order_id', $orderId)->count())->toBe(1);

    expect(drExpire($orderId))->toBeTrue()
        ->and(drKinds($orderId))->toHaveCount(2);

    // Already cancelled: the expiry's claim (`status = pending`) matches nothing, so a second sweep sends nothing.
    expect(drExpire($orderId))->toBeFalse()
        ->and(drKinds($orderId))->toHaveCount(2);
});

it('PENDING then declined: neither moves the order, and the expiry still knows it was declined', function () {
    $orderId = drOrder();
    $number = PaymentFixture::orderNumber($orderId);

    // The provider says "still pending": recorded, order untouched, nobody mailed.
    $pending = PaymentFixture::callback($number, 10000, success: false, secret: DR_SECRET, transactionId: 770003, overrides: ['pending' => 'true']);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($pending))->assertRedirect();
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([]);

    // Then the decline: still waiting, still nobody mailed.
    drCallback($orderId, success: false, transactionId: 770004);
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([]);

    expect(drExpire($orderId))->toBeTrue();
    expect(T::str(DB::table('integration_outbox')->where('aggregate_id', $orderId)->where('channel', 'mail')->orderBy('id')->value('payload')))
        ->toContain('"reason":"declined"');
});

it('keeps the weekly ceiling across reasons: an expiry e-mail this week silences a decline e-mail', function () {
    $expired = drOrder('sara@example.test');
    expect(drExpire($expired))->toBeTrue()
        ->and(drKinds($expired))->toHaveCount(2);

    $declined = drOrder('sara@example.test');
    drCallback($declined, success: false, transactionId: 770006);
    expect(drExpire($declined))->toBeTrue();

    expect(T::str(DB::table('orders')->where('id', $declined)->value('status')))->toBe('cancelled')
        ->and(drKinds($declined))->toBe([]);
});

it('sends nothing for a callback that was refused — the order was never touched', function () {
    $orderId = drOrder();
    $forged = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, success: false, secret: 'not-the-secret', transactionId: 770007);

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($forged))->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(drKinds($orderId))->toBe([])
        ->and(DB::table('payment_statuses')->where('order_id', $orderId)->count())->toBe(0);
});

it('a shopper who is declined and then PAYS on the same page gets the confirmation and never a decline e-mail (order 000092)', function () {
    /*
     * The case this file used to pin as an accepted wrinkle — "declined at 10:00, pays at 10:02,
     * still gets the decline e-mail beside the confirmation" — was the visible half of a worse one:
     * the decline had CANCELLED the order, so the payment at 10:02 landed on a cancelled order and
     * the customer was charged for nothing. Both halves are gone: the decline is only recorded, the
     * retry pays the SAME order, and the expiry then has nothing to cancel and nobody to e-mail.
     */
    $orderId = drOrder('sara@example.test');
    drCallback($orderId, success: false, transactionId: 770008);
    expect(drKinds($orderId))->toBe([]);

    // Two minutes later the retry on the same payment page goes through — a new transaction id.
    drCallback($orderId, success: true, transactionId: 770009);
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing')
        ->and(drKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_CUSTOMER, OrderMailer::KIND_ADMIN])
        ->and(DB::table('payment_reconciliation_findings')->where('order_id', $orderId)->count())->toBe(0);

    // The sweep finds a paid order: nothing to cancel, nothing more to send.
    expect(drExpire($orderId))->toBeFalse()
        ->and(drKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_CUSTOMER, OrderMailer::KIND_ADMIN]);
});
