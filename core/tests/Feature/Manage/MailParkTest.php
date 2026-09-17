<?php

use App\Domain\Notifications\OrderMailer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

/*
 * A run whose orders are not real must never leave sendable mail behind (🟠-5, 2026-09-17).
 *
 * ── The finding ──────────────────────────────────────────────────────────────────────────────
 *
 * The harness places genuine COD checkouts, and a genuine checkout writes `pending` outbox rows
 * addressed to the real `ORDER_ADMIN_EMAILS`. `MAIL_MAILER=log` stops the send DURING the run and
 * does nothing about the rows: they wait in `integration_outbox` until somebody runs
 * `php artisan mail:drain` on a host with real SMTP — days later, with nothing on screen connecting
 * that command to a harness run — and four real administrators are told about harness order 3381.
 *
 * Real admin addresses must never be one command away from test mail.
 *
 * ── The 26 already parked as `failed` stay exactly as they are ───────────────────────────────
 *
 * Nothing here unparks anything. They are evidence of what those runs did, and a log that gets
 * rewritten is worth nothing.
 */

/**
 * An order with a RECIPIENT, which the payment fixture does not set.
 *
 * Without a `guest_email` every customer enqueue lands as `skipped` rather than `pending`, so a
 * test built on the bare fixture would assert nothing about parking at all — it would be measuring
 * the no-recipient path and passing for the wrong reason.
 */
function mailableOrder(): int
{
    $orderId = PaymentFixture::order(total: 100.0);

    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper+'.$orderId.'@example.test',
    ]);

    return $orderId;
}

it('parks every row a run writes when parking is on, and leaves a normal run alone', function () {
    $orderId = mailableOrder();
    $mailer = app(OrderMailer::class);

    // A NORMAL run: the row is pending and claimable, which is the behaviour that must not change.
    config(['notifications.send.park' => false]);
    $mailer->statusChanged($orderId, 'processing');

    $normal = T::str(DB::table('integration_outbox')->where('aggregate_id', $orderId)->orderByDesc('id')->value('status'));
    expect($normal)->toBe(OrderMailer::STATUS_PENDING);

    // A PARKING run: the same call on the same order writes a row nothing will ever claim.
    config(['notifications.send.park' => true]);
    $mailer->statusChanged($orderId, 'shipped');

    $parked = DB::table('integration_outbox')->where('aggregate_id', $orderId)->orderByDesc('id')->first(['status', 'last_error', 'processed_at']);
    $row = T::row($parked);

    expect(T::str($row->status))->toBe(OrderMailer::STATUS_PARKED)
        // It says WHY it is parked, so nobody reads it later as a mystery failure.
        ->and(T::str($row->last_error))->toContain('parked')
        // …and it is a resting state: processed, not waiting.
        ->and($row->processed_at)->not->toBeNull();
});

it('never claims a parked row for delivery', function () {
    $orderId = mailableOrder();

    config(['notifications.send.park' => true]);
    $ids = app(OrderMailer::class)->statusChanged($orderId, 'processing');

    expect($ids)->not->toBe([]);

    /*
     * `deliver()` claims with `status = pending` in the WHERE. A parked row loses that race by
     * construction — which is the property that makes parking safe rather than merely tidy.
     */
    expect(app(OrderMailer::class)->deliver($ids[0]))->toBeFalse();

    expect(T::str(DB::table('integration_outbox')->where('id', $ids[0])->value('status')))
        ->toBe(OrderMailer::STATUS_PARKED);
});

it('refuses to drain ANY row of an order that has a parked row', function () {
    /*
     * The second guard, and the one the `pending` filter cannot provide.
     *
     * A harness order can end up with a PENDING row written by a process that was not parking: an
     * inline send that failed and scheduled a retry, or a status change made from the dashboard
     * afterwards. That row is pending, and its recipient is a real administrator.
     *
     * An order is "not real" if ANY of its rows is parked — a fact the harness recorded itself, so
     * it needs no marker on the legacy `orders` table, which core does not own.
     */
    $harnessOrder = mailableOrder();
    $realOrder = mailableOrder();
    $mailer = app(OrderMailer::class);

    config(['notifications.send.park' => true]);
    $mailer->statusChanged($harnessOrder, 'processing');

    // …and now a PENDING row lands on that same harness order.
    config(['notifications.send.park' => false]);
    $mailer->statusChanged($harnessOrder, 'shipped');
    $mailer->statusChanged($realOrder, 'shipped');

    $pendingOnHarness = T::int(
        DB::table('integration_outbox')
            ->where('aggregate_id', $harnessOrder)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->count()
    );
    expect($pendingOnHarness)->toBeGreaterThan(0, 'the test has not created the situation it asserts on');

    Artisan::call('mail:drain', ['--limit' => 50]);

    /*
     * The real order's row was sent or attempted; the harness order's pending row was not touched
     * and is still pending. Asserted on the ROW rather than on the command's wording, because the
     * wording is not the contract.
     */
    expect(T::int(
        DB::table('integration_outbox')
            ->where('aggregate_id', $harnessOrder)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->count()
    ))->toBe($pendingOnHarness, 'a harness order’s mail was claimed by mail:drain');

    expect(T::int(
        DB::table('integration_outbox')
            ->where('aggregate_id', $realOrder)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->count()
    ))->toBe(0, 'a real order’s mail was skipped, so the guard is too wide');
});

it('is set by every tool that places orders which are not real', function () {
    /*
     * Structural, and deliberately so: the three tools share one rule and the failure this prevents
     * is a FOURTH tool being written without it. Same shape as `WriteTargetGuardTest`'s assertion
     * that all three share the remote-target refusal — and for the same reason, since that guard
     * was also added to two of three and forgotten on the last.
     *
     * The launcher sets `CORE_MAIL_PARK` as well; this asserts the in-process half, which is the
     * one no launcher can go around.
     */
    $missing = [];

    foreach ([
        'CompatDiffCommand',
        'InventoryProveReleaseRaceCommand',
        'PaymentProveCallbackRaceCommand',
    ] as $tool) {
        $source = T::str(file_get_contents(app_path('Console/Commands/'.$tool.'.php')));

        if (! str_contains($source, "config(['notifications.send.park' => true])")) {
            $missing[] = $tool;
        }
    }

    expect($missing)->toBe(
        [],
        "These tools place orders that are not real and do not park the mail those orders write.\n"
        ."Add `config(['notifications.send.park' => true]);` beside the tool's other write guards."
    );
});

it('keeps the launcher setting the flag too', function () {
    // Belt and braces: the harness runs core in a SEPARATE process (`artisan serve`), so the
    // in-process setting above cannot reach the checkout the harness drives over HTTP. The launcher
    // is what parks those, and it is asserted here so a future edit cannot quietly drop it.
    $script = T::str(file_get_contents(base_path('../scripts/run-compat-harness.ps1')));

    expect($script)->toContain('CORE_MAIL_PARK')
        ->and($script)->toContain('MAIL_MAILER');
});
