<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Support\Coerce;
use App\Support\DeadlockRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a payment callback is ALLOWED to do to an order — one decision table, two handlers
 * (🔴-1 of the wave-4C review, decided 2026-09-13).
 *
 * ── The defect this exists to close ──────────────────────────────────────────────────────────
 *
 * Both callback handlers moved the order unconditionally: success → `processing`, failure →
 * `cancelled` + release stock, whatever state the order was already in. The idempotency guard only
 * catches a REPLAY of the same transaction id, so a *different* transaction sailed through:
 *
 *  - **decline, then succeed.** The decline cancelled the order and RELEASED its stock. The retry
 *    then set `processing` again — an order marked paid whose units had already gone back on the
 *    shelf and may already have been sold to someone else.
 *  - **void or refund after a success.** Paymob sends `success=true` with `is_voided`/`is_refunded`,
 *    so the money coming BACK re-marked the order paid.
 *  - **decline after a success.** A failed second attempt cancelled a paid order and released the
 *    stock of goods that were already being packed.
 *
 * ── The rule ─────────────────────────────────────────────────────────────────────────────────
 *
 * **A callback may never transition an order out of a terminal state**, where terminal means
 * `cancelled` or anything beyond `processing` (`shipped`, `delivered`, `completed`). And it never
 * re-reserves stock: silently re-reserving is the wrong repair, because the units may be gone.
 *
 * So when the decision is not a clean one, the callback does three things and stops: **record the
 * attempt** (the money is real and must be on record), **leave the order alone**, and **raise a
 * finding a human clears**. The alternative — guessing — is how an oversell or a double refund
 * becomes invisible.
 *
 * `pending` is the one non-decision: a 3-D Secure callback that says "not yet" is normal traffic,
 * recorded with no finding and no transition.
 */
final class CallbackPolicy
{
    /** What actually happened, as far as the provider said. */
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_REFUNDED = 'refunded';

    public const OUTCOME_VOIDED = 'voided';

    public const OUTCOME_PENDING = 'pending';

    /**
     * The states a callback must not move an order out of.
     *
     * `cancelled` because the stock is already back and the customer has been told; the three
     * fulfilment states beyond `processing` because the goods are with a courier or a customer, and
     * a payment event cannot undo a physical fact.
     *
     * @var list<string>
     */
    public const TERMINAL = ['cancelled', 'shipped', 'delivered', 'completed'];

    /** What the caller must do with the order. */
    public const ACT_PAY = 'pay';

    public const ACT_CANCEL = 'cancel';

    public const ACT_RECORD_ONLY = 'record';

    /** Finding kinds — the vocabulary the command and the screen show. */
    public const KIND_TERMINAL = 'callback_on_terminal_order';

    public const KIND_FAILED_AFTER_PAID = 'decline_after_payment';

    public const KIND_REVERSAL = 'refund_or_void';

    public const KIND_AMOUNT = 'amount_mismatch';

    /**
     * Read Paymob's payload as ONE outcome.
     *
     * Order matters: a refund arrives as `success=true` **with** `is_refunded=true`, and a void the
     * same way, so the reversal flags are tested before success. `pending` outranks everything —
     * nothing has happened yet.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function outcomeFromPaymob(array $payload): string
    {
        $flag = static fn (string $key): bool => in_array(
            strtolower(Coerce::str($payload[$key] ?? '')), ['true', '1'], true
        );

        if ($flag('pending')) {
            return self::OUTCOME_PENDING;
        }
        if ($flag('is_refunded')) {
            return self::OUTCOME_REFUNDED;
        }
        if ($flag('is_voided')) {
            return self::OUTCOME_VOIDED;
        }

        return $flag('success') ? self::OUTCOME_SUCCESS : self::OUTCOME_FAILED;
    }

    /**
     * The decision: what to do with the order, and what finding to raise.
     *
     * @return array{action: string, finding: string|null, paid: bool}
     */
    public static function decide(string $outcome, string $orderStatus, bool $amountMatches): array
    {
        // The amount check comes first and fails closed, as it did before: an amount that
        // disagrees with the order is never a payment, whatever the provider called it.
        if (! $amountMatches) {
            return ['action' => self::ACT_RECORD_ONLY, 'finding' => self::KIND_AMOUNT, 'paid' => false];
        }

        if ($outcome === self::OUTCOME_PENDING) {
            // Normal traffic, not a decision. Recorded, no finding, order untouched.
            return ['action' => self::ACT_RECORD_ONLY, 'finding' => null, 'paid' => false];
        }

        if (in_array($orderStatus, self::TERMINAL, true)) {
            // THE rule. Whatever the provider says, this order does not move.
            return ['action' => self::ACT_RECORD_ONLY, 'finding' => self::KIND_TERMINAL, 'paid' => false];
        }

        if ($outcome === self::OUTCOME_REFUNDED || $outcome === self::OUTCOME_VOIDED) {
            /*
             * Money came back on an order that is still live. Reversing a sale is not a status
             * change: it decides whether the goods are coming back too, whether the customer keeps
             * them, and what the ledger should say. A human does that.
             */
            return ['action' => self::ACT_RECORD_ONLY, 'finding' => self::KIND_REVERSAL, 'paid' => false];
        }

        if ($outcome === self::OUTCOME_SUCCESS) {
            return ['action' => self::ACT_PAY, 'finding' => null, 'paid' => true];
        }

        // A decline. Only an order that was never paid may be cancelled by one: on a `processing`
        // order the money is already in, and cancelling would release the stock of a paid sale.
        if ($orderStatus === 'pending') {
            return ['action' => self::ACT_CANCEL, 'finding' => null, 'paid' => false];
        }

        return ['action' => self::ACT_RECORD_ONLY, 'finding' => self::KIND_FAILED_AFTER_PAID, 'paid' => false];
    }

    /**
     * Record a finding, loudly. Returns its id.
     *
     * Deliberately NOT inside the caller's transaction decision: a finding is the record that
     * something needs a human, and it must survive whatever the caller does or does not commit
     * around it. The callers insert the attempt and the finding in the same transaction so the pair
     * is consistent; nothing else writes this table.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function record(
        string $kind,
        string $provider,
        ?int $orderId,
        ?int $paymentStatusId,
        ?string $outcome,
        ?string $orderStatus,
        ?int $amountCents,
        array $detail = [],
    ): int {
        $id = (int) DB::table('payment_reconciliation_findings')->insertGetId([
            'order_id' => $orderId,
            'payment_status_id' => $paymentStatusId,
            'provider' => $provider,
            'kind' => $kind,
            'outcome' => $outcome,
            'order_status' => $orderStatus,
            'amount_cents' => $amountCents,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        // `error`, not `warning`: every kind here means money and an order disagree, and the log is
        // the only place an operator who is not looking at the dashboard will see it.
        Log::error('payment reconciliation finding: '.$kind, [
            'finding_id' => $id,
            'provider' => $provider,
            'order_id' => $orderId,
            'outcome' => $outcome,
            'order_status' => $orderStatus,
            'amount_cents' => $amountCents,
        ] + $detail);

        return $id;
    }

    /**
     * The stored outcome of an attempt row, falling back for rows written before `outcome` existed.
     *
     * Those rows genuinely do not know whether a `success = true` was a sale or a refund — the
     * column did not exist — so the fallback says what the row DOES claim rather than inventing a
     * distinction it never recorded.
     */
    public static function outcomeOf(?string $stored, ?string $success): string
    {
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return $success === 'true' ? self::OUTCOME_SUCCESS : self::OUTCOME_FAILED;
    }

    /** A deadlock that outlived the retries — the callback the database refused to let us write. */
    public const KIND_DEADLOCK = 'callback_lost_to_deadlock';

    /**
     * Record a callback whose transaction could not commit, OUTSIDE that transaction.
     *
     * The rolled-back transaction took the attempt row with it, so writing the record inside it is
     * the one thing that cannot work: everything we know about a real payment would vanish with the
     * rollback, the provider would get a 500, and the money would exist with nothing pointing at it.
     * So this runs afterwards, on its own, with no transaction of its own to lose.
     *
     * The log context carries the transaction id and the EXCEPTION CLASS deliberately: an operator
     * needs to tell a deadlock (retry-able contention, the payment is findable in the provider's
     * portal by that id) from a bug (a deterministic failure that will repeat), and those two need
     * different responses.
     *
     * @param  array<string, mixed>  $attempt  the `payment_statuses` row that was lost
     * @param  array<string, mixed>  $detail
     */
    public static function recordLostCallback(
        string $provider,
        ?int $orderId,
        ?string $orderStatus,
        string $outcome,
        array $attempt,
        \Throwable $e,
        array $detail = [],
    ): void {
        $attemptId = null;

        try {
            // The attempt FIRST: the money is the thing that must not be lost. If even this fails
            // the catch below still writes the finding, so the event is never silent.
            $attemptId = (int) DB::table('payment_statuses')->insertGetId($attempt + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $inner) {
            Log::error('payment callback: could not record the lost attempt either.', [
                'provider' => $provider,
                'order_id' => $orderId,
                'exception' => $inner::class,
                'message' => $inner->getMessage(),
            ]);
        }

        self::record(
            self::KIND_DEADLOCK,
            $provider,
            $orderId,
            $attemptId,
            $outcome,
            $orderStatus,
            is_numeric($attempt['amount_cents'] ?? null) ? (int) $attempt['amount_cents'] : null,
            $detail + [
                // The two things that separate contention from a bug.
                'transaction' => $attempt['pay_transaction_id'] ?? null,
                'exception' => $e::class,
                'deadlock' => DeadlockRetry::isDeadlock($e instanceof QueryException ? $e : new QueryException('', '', [], $e)),
                'message' => $e->getMessage(),
            ],
        );
    }

    /** Whether an outcome should count as REVENUE in a settlement report. */
    public static function isRevenue(string $outcome): bool
    {
        return $outcome === self::OUTCOME_SUCCESS;
    }
}
