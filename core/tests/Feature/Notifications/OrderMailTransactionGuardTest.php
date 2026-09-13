<?php

use App\Domain\Notifications\OrderMailer;
use App\Mail\OrderStatusUpdate;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

/*
 * The production rule, at its PRODUCTION VALUE: an inline send never happens while a transaction
 * is open.
 *
 * ── Why this file exists separately ──────────────────────────────────────────────────────────
 *
 * `tests/Pest.php` wraps every feature test in a transaction that is never committed, so the
 * other three files in this directory set `notifications.send.inside_transaction = true` to be
 * able to observe a send at all. This one deliberately does NOT, which makes the ambient
 * transaction stand in for a caller's — and it is the only file where the shipped default is the
 * one under test.
 *
 * The rule matters for two reasons, and both are failure modes a customer would notice:
 *
 *   - a row marked `sent` inside a transaction that then rolls back is a message the retry sends
 *     AGAIN, so the customer is told twice about one thing;
 *   - an SMTP round trip inside a transaction holds its row locks for the length of the round
 *     trip — on the cancel path, the locks on the order AND its product rows.
 *
 * Nothing is lost by the refusal: the obligation row is already written, so the cron sends it.
 */

beforeEach(function () {
    config([
        'notifications.admin_emails' => ['ops@watchizer.test'],
        'notifications.send.inline' => true,
        // NOT set: `notifications.send.inside_transaction` stays at its shipped default (false).
    ]);
});

/** @return array{order: int, product: int} */
function guardOrder(): array
{
    $row = T::row(
        DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')->first(['id'])
    );
    $productId = Row::int($row, 'id');

    $orderId = PaymentFixture::order(total: 100.0, status: 'processing');
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper@example.test', 'guest_name' => 'Guard Test', 'payment_method' => 'cash',
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['order' => $orderId, 'product' => $productId];
}

it('refuses to send while a transaction is open, and loses nothing by refusing', function () {
    Mail::fake();
    $f = guardOrder();

    expect(DB::transactionLevel())->toBeGreaterThan(0, 'this test depends on the ambient test transaction');

    app(OrderMailer::class)->statusChangedNow($f['order'], 'shipped');

    // Nothing went out…
    Mail::assertNothingSent();

    // …and the obligation is on record, claimable, with no attempt spent on it.
    $rows = OrderMailer::forOrder($f['order']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('pending')
        ->and($rows[0]['attempts'])->toBe(0)
        ->and($rows[0]['processed_at'])->toBeNull();
});

it('lets the cron send exactly what the in-request refusal left behind', function () {
    Mail::fake();
    $f = guardOrder();

    app(OrderMailer::class)->statusChangedNow($f['order'], 'shipped');
    Mail::assertNothingSent();

    /*
     * `mail:drain` is a console command, so it is not itself wrapped — but the ambient test
     * transaction is still open around it, which is exactly the shape the guard is written for.
     * `deliver()` is called directly for that reason: it is the drain's own per-row path, and
     * what is under test here is that the ROW survived in a claimable state, not the command's
     * argument parsing (which `OrderMailFailureTest` covers).
     */
    $id = OrderMailer::forOrder($f['order'])[0]['id'];
    expect(app(OrderMailer::class)->deliver(T::int($id)))->toBeTrue();

    Mail::assertSent(OrderStatusUpdate::class, 1);
    expect(OrderMailer::forOrder($f['order'])[0]['status'])->toBe('sent');
});

it('does not enqueue twice when the refusal is followed by a retry of the same event', function () {
    Mail::fake();
    $f = guardOrder();

    app(OrderMailer::class)->statusChangedNow($f['order'], 'shipped');
    app(OrderMailer::class)->statusChangedNow($f['order'], 'shipped');

    // The dedupe key holds whether or not anything was sent: the obligation is the unique thing,
    // not the delivery.
    expect(OrderMailer::forOrder($f['order']))->toHaveCount(1);
});
