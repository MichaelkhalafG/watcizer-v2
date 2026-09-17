<?php

use App\Compat\CompatCart;
use App\Compat\CompatCheckout;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderFulfilment;
use App\Mail\AdminOrderNotification;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusUpdate;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * Every order-e-mail trigger the legacy app has, wired in core — prerequisite (a), 2026-09-13.
 *
 * ── What these tests are FOR ─────────────────────────────────────────────────────────────────
 *
 * The adversarial review of wave 4C measured the gap this closes: two status advances and a
 * cancel through the core dashboard sent **0 mailables and wrote 0 outbox rows**. Nothing errored.
 * T-0 disables the Blade dashboard, which is the only sender today, so from switch night a
 * customer who ordered would have received nothing and an order marked shipped would have
 * notified nobody — silently.
 *
 * So these tests are not "the mailer works". They are one per LEGACY TRIGGER, asserting the
 * mailable class AND the recipient, because the legacy rules are asymmetric in ways that are easy
 * to get subtly wrong: a card order tells the admins at checkout and the customer only after the
 * money lands; a WhatsApp order never tells the customer at all.
 *
 * Legacy sources, enumerated from the code and not from memory:
 *   `Api/OrderController::sendOrderEmails()` (line 842), called at line 811 (checkout) and line
 *   1032 (Paymob success, passing 'cash'); `Admin/OrderController::update()` line 72.
 */

const MAIL_API_KEY = 'test-api-code';

const MAIL_HMAC = 'order-mail-test-hmac';

beforeEach(function () {
    config([
        'compat.api_key' => MAIL_API_KEY,
        'compat.payment_return_url' => 'https://watchizereg.test/',
        // The wave-3 alias verifies against THIS key, not a contract's (that is the 4C route).
        'services.paymob.hmac_secret' => MAIL_HMAC,
        'notifications.admin_emails' => ['ops@watchizer.test', 'boss@watchizer.test'],
        'notifications.send.inline' => true,
        /*
         * `tests/Pest.php` wraps every feature test in a transaction that is never committed, and
         * `OrderMailer::flush()` refuses to send inside one — for good production reasons. Lifted
         * HERE so the triggers can be observed at all; the production default is held by
         * `OrderMailTransactionGuardTest`, which does not set this.
         */
        'notifications.send.inside_transaction' => true,
    ]);
});

/**
 * A product with stock and no variants, priced from its storefront row — the shape a compat
 * checkout can actually buy.
 *
 * @return array{id: int, price: float}
 */
function mailProduct(): array
{
    $row = T::row(
        DB::table('catalog_products as cp')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('cp.deleted_at')
            ->where('cp.stock_express', '>=', 2)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id')->selectRaw('1');
            })
            ->orderBy('cp.id')
            ->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price'])
    );

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/** @return array{id: int, cost: float} */
function mailCity(): array
{
    $row = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return ['id' => Row::int($row, 'id'), 'cost' => round((float) Row::money($row, 'shipping_cost'), 2)];
}

/** A real compat checkout, through HTTP, returning the order id. */
function mailCheckout(string $paymentMethod, string $guestEmail = 'shopper@example.test'): int
{
    $product = mailProduct();
    $city = mailCity();
    $total = round($product['price'] + $city['cost'], 2);

    $response = withHeaders(['Api-Code' => MAIL_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'shipping_city_id' => $city['id'],
            'address_line' => 'Mail Test Street 1',
            'phone' => '01000000000',
            'total_price_for_order' => $total,
            'payment_method' => $paymentMethod,
            'guest_name' => 'Mail Test',
            'guest_email' => $guestEmail,
            'items' => [[
                'product_id' => $product['id'], 'quantity' => 1,
                'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
            ]],
        ]);

    $response->assertOk();
    $number = T::str($response->json('order_number'));

    return T::int(DB::table('orders')->where('order_number', $number)->value('id'));
}

/**
 * An order sitting in `$status` with stock reserved, so a cancel has something to return.
 *
 * @return array{order: int, product: int}
 */
function mailOrder(string $status = 'processing', ?string $guestEmail = 'shopper@example.test'): array
{
    $product = mailProduct();
    $orderId = PaymentFixture::order(total: 100.0, status: $status);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => $guestEmail, 'guest_name' => 'Mail Test', 'payment_method' => 'cash',
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $product['id'], 'offer_id' => null,
        'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $product['id']];
}

/**
 * `artisan()` returns `PendingCommand|int`, so the narrowing is explicit rather than a chain the
 * analyser has to guess at -- the same helper shape `EnvParityCheckTest` uses.
 *
 * @param  array<string, mixed>  $args
 */
function triggerArtisan(string $command, array $args = []): PendingCommand
{
    $pending = artisan($command, $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

/** @return list<array<string, mixed>> */
function mailRows(int $orderId): array
{
    return OrderMailer::forOrder($orderId);
}

// ── trigger 1: checkout ──────────────────────────────────────────────────────────────────────

it('mails the customer AND every admin when a COD order is placed', function () {
    Mail::fake();

    $orderId = mailCheckout('cash');

    // The customer's confirmation: exactly one, to the address on the order.
    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m): bool => $m->hasTo('shopper@example.test'));

    // One admin notification PER configured address, which is what the legacy `foreach` does.
    Mail::assertSent(AdminOrderNotification::class, 2);
    foreach (['ops@watchizer.test', 'boss@watchizer.test'] as $admin) {
        Mail::assertSent(AdminOrderNotification::class, fn (AdminOrderNotification $m): bool => $m->hasTo($admin));
    }

    // No status e-mail: placing an order is not a status CHANGE.
    Mail::assertNotSent(OrderStatusUpdate::class);

    // …and every one of them is on record as SENT against the order, which is what makes
    // "was he told?" answerable three weeks later.
    $rows = mailRows($orderId);
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['status'])->toBe('sent')
            ->and($row['attempts'])->toBe(1)
            ->and($row['error_kind'])->toBeNull();
    }
});

it('mails ONLY the admins for a WhatsApp order — the customer has not bought anything yet', function () {
    Mail::fake();

    $orderId = mailCheckout('whatsapp');

    Mail::assertNotSent(OrderConfirmation::class);
    Mail::assertSent(AdminOrderNotification::class, 2);

    // The legacy rule, held explicitly: `sendOrderEmails()` builds the customer's confirmation
    // only `if ($paymentMethod === 'cash')`, and a WhatsApp order is an enquiry.
    expect(array_column(mailRows($orderId), 'kind'))
        ->toBe([OrderMailer::KIND_ADMIN, OrderMailer::KIND_ADMIN]);
});

it('mails NOBODY when a card order is placed — the money has not arrived', function () {
    Mail::fake();
    // The paymob branch calls Paymob's intention API. Faked, because a test must not depend on
    // a payment provider being reachable — and because a real call would carry real keys.
    Http::fake(['accept.paymob.com/*' => Http::response(['client_secret' => 'cs_test'], 200)]);
    config(['services.paymob.secret_key' => 'test-secret', 'services.paymob.public_key' => 'test-public']);

    $mailRowsBefore = T::int(DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->count());

    $product = mailProduct();
    $city = mailCity();
    $total = round($product['price'] + $city['cost'], 2);

    // `paymob` redirects to the provider, so the response is not asserted — what matters is that
    // the pre-payment order told nobody. The legacy app is identical: line 811 only calls
    // `sendOrderEmails()` for cash and whatsapp.
    withHeaders(['Api-Code' => MAIL_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'shipping_city_id' => $city['id'], 'address_line' => 'Mail Test Street 1', 'phone' => '01000000000',
            'total_price_for_order' => $total, 'payment_method' => 'card',
            'guest_name' => 'Mail Test', 'guest_email' => 'shopper@example.test',
            'items' => [[
                'product_id' => $product['id'], 'quantity' => 1,
                'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
            ]],
        ]);

    Mail::assertNothingSent();

    /*
     * A DELTA, not a global count — and the original global count was my own bug (§4 law: an
     * assertion must be scoped to its subject).
     *
     * It read `->count())->toBe(0)` over the whole `mail` channel, which is only true of an empty
     * table. It passed on the day it was written because a closing rebuild had just emptied one,
     * and broke the moment a compat-harness COD checkout left five perfectly legitimate rows
     * behind — the test then called the mail system's correct behaviour a defect. The claim was
     * always "this checkout enqueued nothing", so that is what it now measures.
     */
    expect(DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->count())->toBe($mailRowsBefore);
});

// ── trigger 2: the Paymob callback ───────────────────────────────────────────────────────────

it('mails the customer and the admins when the card payment lands', function () {
    Mail::fake();

    $f = mailOrder('pending');
    DB::table('orders')->where('id', $f['order'])->update(['payment_method' => 'paymob']);

    $response = get('/api/callback_payment?'.http_build_query(PaymentFixture::callback(
        orderReference: (string) $f['order'],
        amountMinor: 10000,
        secret: MAIL_HMAC,
        transactionId: random_int(2_000_000, 9_000_000),
    )));
    $response->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('processing');

    // The legacy callback calls `sendOrderEmails($order, $isGuest, 'cash')` — customer AND admins.
    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertSent(AdminOrderNotification::class, 2);

    // …and the order did NOT also get a status e-mail for pending → processing. The callback is a
    // payment event, and sending both would tell the customer twice about one thing.
    Mail::assertNotSent(OrderStatusUpdate::class);
});

// ── trigger 3: every status transition ───────────────────────────────────────────────────────

it('mails the customer on every forward move, with that status’s own copy', function () {
    Mail::fake();
    $entry = Staff::dataEntry();
    $f = mailOrder('pending');

    $walk = [['pending', 'processing'], ['processing', 'shipped'], ['shipped', 'delivered'], ['delivered', 'completed']];

    foreach ($walk as $index => [$from, $to]) {
        actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => $to])->assertRedirect();

        // ONE per move, cumulative — so a move that mailed twice, or not at all, fails here.
        Mail::assertSent(OrderStatusUpdate::class, $index + 1);
    }

    // Every one carried the status it was about, in the data the template renders. A single
    // count would pass even if all four said "processing".
    $sent = [];
    Mail::assertSent(OrderStatusUpdate::class, function (OrderStatusUpdate $mail) use (&$sent): bool {
        $rendered = $mail->render();
        foreach (['Processing', 'Shipped', 'Delivered', 'Completed'] as $label) {
            if (str_contains($rendered, $label.' &middot;') || str_contains($rendered, '>'.$label.'<')) {
                $sent[] = $label;
            }
        }

        return true;
    });

    expect(array_values(array_unique($sent)))->toEqualCanonicalizing(['Processing', 'Shipped', 'Delivered', 'Completed']);

    // Four rows, one per transition, each naming its own event — the audit trail an operator reads.
    $events = array_column(mailRows($f['order']), 'event');
    expect($events)->toEqualCanonicalizing([
        'order.status.processing', 'order.status.shipped', 'order.status.delivered', 'order.status.completed',
    ]);
});

it('mails the cancellation copy when an order is cancelled', function () {
    Mail::fake();
    $admin = Staff::admin();
    $f = mailOrder('processing');

    actingAs($admin)->post("/manage/orders/{$f['order']}/cancel", ['note' => 'test cancel'])->assertRedirect();

    Mail::assertSent(OrderStatusUpdate::class, 1);
    Mail::assertSent(OrderStatusUpdate::class, function (OrderStatusUpdate $mail): bool {
        // The cancelled copy, in both languages, from the ported template.
        return str_contains($mail->render(), 'Your order has been cancelled')
            && str_contains($mail->render(), 'تم إلغاء طلبك');
    });

    // The stock came back AND the customer was told — one act, both halves.
    expect(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $f['order'])->where('reason', 'order_cancel')->exists())->toBeTrue()
        ->and(array_column(mailRows($f['order']), 'event'))->toBe(['order.status.cancelled']);
});

// ── exactly once, and never on a no-op ───────────────────────────────────────────────────────

it('sends nothing when a transition is refused', function () {
    Mail::fake();
    $entry = Staff::dataEntry();
    $f = mailOrder('processing');

    // A skip, the same status again, and a backward move: three no-ops, three refusals.
    foreach (['delivered', 'processing', 'pending'] as $refused) {
        actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => $refused]);
    }

    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('processing');
    Mail::assertNothingSent();
    expect(mailRows($f['order']))->toBe([]);
});

it('sends ONE message when the same transition is applied twice', function () {
    Mail::fake();
    $f = mailOrder('processing');
    $fulfilment = app(OrderFulfilment::class);

    $fulfilment->advance($f['order'], 'shipped', Actor::system());

    // Force the second attempt past the transition guard by putting the order back, which is the
    // only way to reach the DEDUPE guard — and the dedupe guard is what holds when two operators
    // click at the same instant, or when a retry replays an event.
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'processing']);
    $fulfilment->advance($f['order'], 'shipped', Actor::system());

    Mail::assertSent(OrderStatusUpdate::class, 1);
    expect(DB::table('integration_outbox')
        ->where('channel', OrderMailer::CHANNEL)
        ->where('aggregate_id', $f['order'])
        ->count())->toBe(1);
});

it('refuses a duplicate at the DATABASE, not only in PHP', function () {
    $f = mailOrder('processing');
    $key = 'order:'.$f['order'].':status:shipped';

    $insert = fn (): int => (int) DB::table('integration_outbox')->insertGetId([
        'channel' => OrderMailer::CHANNEL, 'event' => 'order.status.shipped', 'dedupe_key' => $key,
        'aggregate_type' => 'orders', 'aggregate_id' => $f['order'], 'payload' => '{}',
        'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
    ]);

    $insert();

    // The claim is about the unique INDEX, so it is proved by making the database refuse the row —
    // not by calling the application code that avoids it.
    expect($insert)->toThrow(QueryException::class);
});

// ── the channel constant the study's prose names ─────────────────────────────────────────────

it('keeps the wave-3 channel name so nothing that reads it drifts', function () {
    expect(CompatCheckout::MAIL_CHANNEL)->toBe(OrderMailer::CHANNEL)
        ->and(OrderMailer::CHANNEL)->toBe('mail');
});

it('refuses to let integration:drain touch the mail channel', function () {
    $f = mailOrder('processing');
    DB::table('integration_outbox')->insert([
        'channel' => OrderMailer::CHANNEL, 'event' => 'order.placed', 'dedupe_key' => 'drain-guard-'.$f['order'],
        'aggregate_type' => 'orders', 'aggregate_id' => $f['order'], 'payload' => '{}',
        'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now()->subDay(),
    ]);

    triggerArtisan('integration:drain', ['--channel' => 'mail', '--older-than' => 0])
        ->assertExitCode(Command::INVALID);

    // The row is untouched: a `skipped` mail row is a message somebody never got.
    expect(T::str(DB::table('integration_outbox')->where('dedupe_key', 'drain-guard-'.$f['order'])->value('status')))->toBe('pending');
});
