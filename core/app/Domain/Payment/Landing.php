<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Where a shopper's BROWSER is sent after the payment callback, and what it is told (2026-10-12).
 *
 * ── The defect this closes ──────────────────────────────────────────────────────────────────
 *
 * Paymob delivers one payment twice: the processed POST from its server, and the shopper's browser
 * on a GET a moment later. The POST leaves Paymob's server immediately; the browser takes as long
 * as the shopper's connection does — so the POST wins, and the GET arrives on an order that is
 * already settled. Measured on the first successful live payment (order 000027, 2026-10-12): the
 * browser hit the idempotency check and was shown `{"message":"Already processed"}` on a blank
 * page. That is the NORMAL case, not a rare one. And every early exit did the same — a bad
 * signature, an unknown order, a foreign order — so a customer could meet a code fragment on any
 * of them; the one branch that did redirect appended `?payment_error=1`, which nothing in the
 * storefront reads. The shopper was never told what happened.
 *
 * The rule now: a browser gets a redirect to the storefront whatever the outcome; JSON is for the
 * server-to-server caller only. The landing is the storefront's existing `/order-confirmation`
 * page with the order number and one of the three statuses below in the query string.
 *
 * ── What the landing is NOT (option A, developer decision 2026-10-12) ───────────────────────
 *
 * The page renders whatever the query string says. It is a confirmation MESSAGE, not a receipt
 * and not proof of payment: nobody should treat it as evidence in a dispute, and support reads the
 * ORDER, never the page. A forged URL fools only its author — the storefront fetches nothing on
 * it, verifies nothing, and fires no Purchase event (a browser Purchase would need an event id
 * matching the one the callback already sends server-side, or card sales count twice; that is a
 * separate decision, not part of this).
 */
final class Landing
{
    /** The order is paid: the callback settled it, or an earlier delivery of the same transaction did. */
    public const PAID = 'paid';

    /** The payment did not go through: a decline, a refused callback, or an error before anything was recorded. */
    public const FAILED = 'failed';

    /**
     * Money may have moved but the order was not settled by this callback: the provider says the
     * payment is still pending, the amount disagreed with the order, the order was already in a
     * terminal state, or the attempt could not be written and is on record for reconciliation. A
     * human is looking, and the shopper is told the payment is being confirmed — not that it failed.
     */
    public const PENDING = 'pending';

    /**
     * The landing for a callback the policy has decided on.
     *
     * @param  array{action: string, finding: string|null, paid: bool}  $decision  {@see CallbackPolicy::decide()}
     * @param  string  $outcome  {@see CallbackPolicy::outcomeFromPaymob()}
     */
    public static function of(array $decision, string $outcome): string
    {
        if ($decision['paid']) {
            return self::PAID;
        }
        if ($outcome === CallbackPolicy::OUTCOME_PENDING || $decision['finding'] !== null) {
            return self::PENDING;
        }

        return self::FAILED;
    }

    /**
     * The landing for a REPLAY: an attempt on this transaction is already on record, so what the
     * shopper is told is what that record says.
     */
    public static function ofRecorded(?string $success, ?string $outcome): string
    {
        if ($success === 'true') {
            return self::PAID;
        }
        if ($outcome === CallbackPolicy::OUTCOME_PENDING) {
            return self::PENDING;
        }

        return self::FAILED;
    }
}
