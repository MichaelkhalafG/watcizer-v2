<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderFulfilment;
use App\Mail\OrderStatusUpdate;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command as Cmd;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
 * What happens when the relay is not there — the half of prerequisite (a) that is not about
 * sending.
 *
 * The brief's requirement, verbatim: "an SMTP failure must never lose the order, block a status
 * change, or show a 500. Log it, record it against the order, and make it visible to the operator
 * — a failed notification is an operational fact, not an exception."
 *
 * ── Why the failure here is REAL ─────────────────────────────────────────────────────────────
 *
 * These tests do not mock the mailer. They point the SMTP transport at `127.0.0.1:1` with a
 * one-second timeout, so the connection is genuinely refused and Symfony genuinely throws a
 * `TransportException` from inside `Mail::send()`. A mocked throw would prove that the catch block
 * catches what the test threw; this proves it catches what a dead relay throws.
 */

beforeEach(function () {
    config([
        'notifications.admin_emails' => ['ops@watchizer.test'],
        'notifications.send.inline' => true,
        // See OrderMailTriggersTest: the suite wraps every test in a transaction.
        'notifications.send.inside_transaction' => true,
        'notifications.send.max_attempts' => 3,
    ]);
});

/** Point the default mailer at a port nothing listens on. */
function breakTheRelay(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            // Port 1 is refused immediately on every OS, so the failure is fast AND real.
            'port' => 1,
            'timeout' => 1,
        ],
    ]);

    // The manager caches resolved mailers; without this the previous test's mailer is reused.
    Mail::purge('smtp');
}

/** @return array{order: int, product: int} */
function failureOrder(string $status = 'processing'): array
{
    $row = T::row(
        DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where('stock_express', '>=', 1)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')
            ->first(['id'])
    );
    $productId = Row::int($row, 'id');

    $orderId = PaymentFixture::order(total: 100.0, status: $status);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper@example.test', 'guest_name' => 'Failure Test', 'payment_method' => 'cash',
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $productId];
}

/**
 * `artisan()` returns `PendingCommand|int`, so the narrowing is explicit rather than a chain the
 * analyser has to guess at -- the same helper shape `EnvParityCheckTest` uses.
 *
 * @param  array<string, mixed>  $args
 */
function drain(string $command, array $args = []): PendingCommand
{
    $pending = artisan($command, $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

/** @return array<string, mixed> */
function mailRow(int $orderId): array
{
    $rows = OrderMailer::forOrder($orderId);
    expect($rows)->not->toBeEmpty();

    return $rows[0];
}

// ── the status change stands ──────────────────────────────────────────────────────────────────

it('advances the order and answers a redirect even though the relay is dead', function () {
    breakTheRelay();
    $entry = Staff::dataEntry();
    $f = failureOrder('processing');

    // No 500, no validation error: the operator's click did what it said it would.
    actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => 'shipped'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('shipped');

    // …and the failure is RECORDED against the order, with the exception class in it, rather than
    // living only in a log line nobody greps.
    $row = mailRow($f['order']);
    expect($row['status'])->toBe('pending')
        ->and($row['attempts'])->toBe(1)
        ->and($row['event'])->toBe('order.status.shipped')
        ->and($row['last_error'])->toContain('TransportException');
});

it('keeps the order and its stock when the checkout e-mail cannot be sent', function () {
    breakTheRelay();
    $f = failureOrder('pending');

    $before = T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express'));
    app(OrderMailer::class)->placedNow($f['order'], notifyCustomer: true);

    // The order is untouched and the stock was never given back: an e-mail failure is not a
    // reason to unwind a sale.
    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('pending')
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($before);

    // Two owed messages (customer + one admin), both on record as not yet sent.
    $rows = OrderMailer::forOrder($f['order']);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['status'])->toBe('pending')->and($row['last_error'])->toContain('TransportException');
    }
});

it('cancels the order and returns the stock even though the relay is dead', function () {
    breakTheRelay();
    $admin = Staff::admin();
    $f = failureOrder('processing');

    actingAs($admin)->post("/manage/orders/{$f['order']}/cancel", ['note' => 'relay down'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The two things that must not depend on an e-mail: the state, and the ledger.
    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('cancelled')
        ->and(DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $f['order'])
            ->where('reason', 'order_cancel')->exists())->toBeTrue()
        ->and(mailRow($f['order'])['status'])->toBe('pending');
});

// ── the retry path ───────────────────────────────────────────────────────────────────────────

it('retries a deferred message and sends it once the relay comes back', function () {
    breakTheRelay();
    $f = failureOrder('processing');

    app(OrderFulfilment::class)->advance($f['order'], 'shipped', Actor::system());
    expect(mailRow($f['order'])['status'])->toBe('pending');

    // The backoff is a minute; the test stands in for the minute passing rather than sleeping
    // through it, because what is under test is the DRAIN, not the clock.
    DB::table('integration_outbox')->where('aggregate_id', $f['order'])->update(['available_at' => now()]);

    // The relay comes back.
    Mail::fake();

    drain('mail:drain', ['--order' => $f['order']])->assertExitCode(Cmd::SUCCESS);

    Mail::assertSent(OrderStatusUpdate::class, 1);
    $row = mailRow($f['order']);
    expect($row['status'])->toBe('sent')
        // TWO attempts: the failed inline one and the successful drained one. The count is the
        // evidence that this was a retry and not a fresh message.
        ->and($row['attempts'])->toBe(2)
        ->and($row['last_error'])->toBeNull()
        ->and($row['processed_at'])->not->toBeNull();
});

it('parks a message after max_attempts and refuses to keep pretending', function () {
    breakTheRelay();
    $f = failureOrder('processing');

    app(OrderFulfilment::class)->advance($f['order'], 'shipped', Actor::system());   // attempt 1

    for ($i = 0; $i < 5; $i++) {
        DB::table('integration_outbox')->where('aggregate_id', $f['order'])->update(['available_at' => now()]);
        drain('mail:drain', ['--order' => $f['order']])->assertExitCode(Cmd::SUCCESS);
    }

    $row = mailRow($f['order']);
    expect($row['status'])->toBe('failed')
        ->and($row['attempts'])->toBe(config()->integer('notifications.send.max_attempts'))
        ->and($row['last_error'])->toContain('TransportException');

    // A failed row is never retried again — that is what `failed` means, and a queue that retries
    // forever is a queue nobody reads.
    drain('mail:drain', ['--order' => $f['order']])->expectsOutputToContain('nothing due')->assertExitCode(Cmd::SUCCESS);
});

// ── visible to the operator ──────────────────────────────────────────────────────────────────

it('is LOUD: mail:drain --report exits non-zero while a message is failed', function () {
    breakTheRelay();
    $f = failureOrder('processing');

    app(OrderFulfilment::class)->advance($f['order'], 'shipped', Actor::system());

    // Pending is not a failure: the message is still coming.
    drain('mail:drain', ['--report' => true, '--order' => $f['order']])->assertExitCode(Cmd::SUCCESS);

    DB::table('integration_outbox')->where('aggregate_id', $f['order'])
        ->update(['status' => 'failed', 'last_error' => 'parked for the test']);

    // Failed is. Same contract as `payments:findings`: a non-zero exit a cron or a deploy check
    // can act on, not a row in a table somebody has to remember to open.
    drain('mail:drain', ['--report' => true, '--order' => $f['order']])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(Cmd::FAILURE);
});

it('shows the operator what was sent for an order, on the order screen', function () {
    config(['notifications.send.inline' => true]);
    Mail::fake();
    $admin = Staff::admin();
    $f = failureOrder('processing');

    app(OrderFulfilment::class)->advance($f['order'], 'shipped', Actor::system());

    actingAs($admin);
    $response = get("/manage/orders/{$f['order']}");
    $response->assertOk();

    $page = Props::of($response);
    $notifications = $page['notifications'] ?? null;
    expect($notifications)->toBeArray()->toHaveCount(1);

    /** @var array<string, mixed> $first */
    $first = is_array($notifications) ? $notifications[0] : [];
    expect($first['kind'])->toBe(OrderMailer::KIND_STATUS)
        ->and($first['status'])->toBe('sent')
        // The label the screen renders, so the panel cannot show a raw slug to an operator.
        ->and($first['kind_label'])->toBe('تحديث حالة الطلب (للعميل)');
});
