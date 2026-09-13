<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payment\CallbackPolicy;
use App\Domain\Payment\CallbackVerdict;
use App\Domain\Payment\PaymobProvider;
use App\Domain\Payment\ProviderCredentials;
use App\Domain\Payment\ProviderRegistry;
use App\Http\Controllers\Compat\CheckoutCompatController;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use App\Support\DeadlockRetry;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /api/pay/{storefront}/{provider}/callback` — the per-storefront, per-provider callback
 * (study §3.9.2), and the only unauthenticated route on the core host.
 *
 * There is no `api.code` middleware and there cannot be: a payment provider cannot send a header
 * this application invented. **The signature IS the authentication**, which is why check 2 runs
 * before anything is read out of the payload and why every failure below writes NOTHING.
 *
 * ── The four checks, in this order, before any state changes ─────────────────────────────────
 *
 *  1. **Resolve** `(storefront, provider)` to its contract row. Missing, disabled or
 *     credential-less → 404 and a log line. A callback for a contract this storefront does not
 *     hold is not a payment event, and answering 403 would confirm the storefront exists.
 *  2. **Verify the signature with THAT row's secret.** Not a global one: two storefronts holding
 *     two Paymob accounts must not be able to validate each other's callbacks, and that is the
 *     difference between this route and the wave-3 global one.
 *  3. **Ownership.** Load the order the provider's merchant reference names and assert it belongs
 *     to the route's storefront. Paymob's HMAC covers twenty fields and `merchant_order_id` is
 *     NOT among them — the field that selects the order is UNSIGNED — so the storefront match is
 *     what makes an unsigned selector safe: a replay can change the order id, but it cannot move
 *     an order into another storefront. Mismatch → 403, both ids logged, nothing written.
 *  4. **Amount.** The provider's authoritative minor-unit amount must equal
 *     `orders.total_price_for_order × 100`. Mismatch → fail closed: the attempt is RECORDED as a
 *     failure, the order is left untouched (neither paid nor cancelled), no mail, stock still
 *     reserved. A human decides what a mismatched amount meant (deviation D-23).
 *
 * Idempotency is the `UNIQUE (provider, pay_transaction_id)` index this wave installs, checked
 * before the insert and relied on underneath it: two simultaneous deliveries of one callback end
 * with one row, and the second gets 200 "already processed" rather than a duplicate release.
 *
 * The whole verdict-to-state transition is one `DB::transaction` — record, order status and stock
 * release roll back together, as the wave-3 review proved they must.
 */
final class PaymentCallbackController
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * The scoped route. `{storefront}` is a storefront CODE and `{provider}` a provider constant,
     * both constrained in the route definition.
     */
    public function handle(Request $request, string $storefront, string $provider): JsonResponse|RedirectResponse
    {
        $storefrontRow = Storefront::query()->where('code', $storefront)->first();
        if ($storefrontRow === null) {
            // Unknown storefront: 404, and nothing about whether the provider exists.
            return $this->fail(404, 'unknown storefront', ['storefront' => $storefront, 'provider' => $provider]);
        }

        return $this->process($request, (int) $storefrontRow->id, $provider);
    }

    /**
     * The permanent Watchizer-storefront-1 Paymob alias for `GET /api/callback_payment`
     * (study §3.9.2).
     *
     * It exists because the callback URL lives in Paymob's merchant dashboard against the
     * integration id: changing it is a manual merchant action with no atomic cutover, and any
     * transaction in flight at that moment would call back to the old URL — money taken, order
     * stuck pending, nobody to confirm it. So the alias keeps the URL and resolves
     * `(storefront 1, paymob)`, running **the same four checks in the same controller**.
     *
     * ── It FALLS THROUGH until the credentials are in the table ──────────────────────────────
     *
     * Wave 4C writes the `.env`-to-table credential migration as a RUNBOOK STEP and deliberately
     * does not perform it (the keys are the developer's, never the repo's). So until someone
     * performs that step there is no Watchizer Paymob contract row, and a version of this alias
     * that simply answered "no enabled contract" would have 404'd every real callback: money
     * taken, order left pending, stock left reserved. That is what the first cut of this route
     * did, and fourteen wave-3 tests said so.
     *
     * So: no contract, or a contract with no credentials, means the proven wave-3 handler serves
     * the request exactly as it does today — byte-compatible and harness-covered. Inserting the
     * credentials IS the cutover, with no URL change and no deploy, and it is reversible by
     * disabling the contract. The scoped route is unconditional: a NEW storefront or provider has
     * no legacy handler to fall back to and is registered with its own URL only.
     */
    public function alias(Request $request): JsonResponse|RedirectResponse
    {
        if (! self::aliasIsLive()) {
            return app(CheckoutCompatController::class)->callbackPayment($request);
        }

        return $this->process($request, Storefront::WATCHIZER_ID, 'paymob', alias: true);
    }

    /**
     * Whether the v2 path owns Watchizer's Paymob callback yet — i.e. whether the runbook's
     * credential migration has been performed.
     *
     * Deliberately DATA and not a config flag: a flag is a second thing to get right on switch
     * night, and it can disagree with the table it describes. The credentials being present is the
     * only condition under which the new path can verify a signature at all, so it is the
     * condition itself.
     */
    private static function aliasIsLive(): bool
    {
        $contract = StorefrontPaymentProvider::query()
            ->where('storefront_id', Storefront::WATCHIZER_ID)
            ->where('provider', PaymobProvider::KEY)
            ->where('is_enabled', true)
            ->first();

        return $contract !== null && $contract->credentialsSet();
    }

    private function process(Request $request, int $storefrontId, string $provider, bool $alias = false): JsonResponse|RedirectResponse
    {
        $context = ['storefront_id' => $storefrontId, 'provider' => $provider, 'alias' => $alias];

        try {
            // ── 1. resolve the contract ──────────────────────────────────────────────────────
            $contract = StorefrontPaymentProvider::query()
                ->where('storefront_id', $storefrontId)->where('provider', $provider)->first();

            if ($contract === null || ! (bool) $contract->getAttribute('is_enabled')) {
                return $this->fail(404, 'no enabled contract for this storefront and provider', $context);
            }

            $implementation = $this->registry->for($contract);
            if ($implementation === null) {
                return $this->fail(404, 'no implementation registered for this provider', $context);
            }

            $credentials = ProviderCredentials::fromArray(Coerce::arr($contract->getAttribute('credentials')));
            if ($credentials->isEmpty() && $implementation->credentialFields() !== []) {
                // A contract that needs credentials and has none cannot verify anything, and
                // "verify with an empty secret" is how a forged callback gets accepted.
                return $this->fail(404, 'contract holds no credentials', $context);
            }

            // ── 2. verify the signature with THIS contract's secret ─────────────────────────
            /** @var array<string, mixed> $payload */
            $payload = $request->all();
            $verdict = $implementation->verifyCallback($payload, $credentials);
            if (! $verdict->signatureValid) {
                return $this->fail(403, 'signature did not verify against this contract', $context);
            }

            // ── 3. ownership ────────────────────────────────────────────────────────────────
            $order = $this->resolveOrder($verdict);
            if ($order === null) {
                return $this->fail(404, 'the merchant reference names no order', $context + [
                    'reference' => $verdict->orderReference,
                    'transaction' => $verdict->providerTransactionId,
                ]);
            }
            $orderId = Row::int($order, 'id');
            $orderStorefront = Row::nint($order, 'storefront_id');

            /*
             * NULL means the order was written before core recorded a storefront on it — every
             * such order belongs to Watchizer, the only storefront the legacy app ever served. It
             * is accepted for the PRIMARY storefront only, and logged, so the exception is visible
             * while it lasts; once every order carries the column this branch is dead and goes.
             */
            $belongs = $orderStorefront === $storefrontId
                || ($orderStorefront === null && $storefrontId === Storefront::WATCHIZER_ID);

            if (! $belongs) {
                return $this->fail(403, 'order belongs to another storefront', $context + [
                    'order_id' => $orderId,
                    'order_storefront_id' => $orderStorefront,
                    'transaction' => $verdict->providerTransactionId,
                ]);
            }
            if ($orderStorefront === null) {
                Log::info('payment callback: order carries no storefront_id; accepted as the primary storefront.', $context + ['order_id' => $orderId]);
            }

            // Idempotency, before the work: one row per (provider, transaction id).
            $transactionId = $verdict->providerTransactionId;
            if ($transactionId !== null && DB::table('payment_statuses')
                ->where('provider', $provider)->where('pay_transaction_id', $transactionId)->exists()) {
                return response()->json(['message' => 'Already processed'], 200);
            }

            // ── 4. amount ───────────────────────────────────────────────────────────────────
            $expectedMinor = (int) round(Coerce::float(Row::str($order, 'total_price_for_order')) * 100);
            $amountMatches = $verdict->amountMinor !== null && $verdict->amountMinor === $expectedMinor;

            $methodId = $this->resolveMethodId($contract, $verdict, $orderId);

            /*
             * ── 5. what this callback is ALLOWED to do (🔴-1, 🟡-4) ─────────────────────────
             *
             * The outcome is read from the payload as one value — a refund arrives as
             * `success=true` WITH `is_refunded=true`, and counting that as a sale is how a refund
             * becomes revenue in a settlement report. The decision table then says whether the
             * order may move at all: it never moves out of a terminal state, and a decline never
             * cancels an order that was already paid.
             */
            $outcome = CallbackPolicy::outcomeFromPaymob($verdict->raw);
            $orderStatus = Row::str($order, 'status');
            $decision = CallbackPolicy::decide($outcome, $orderStatus, $amountMatches);

            /*
             * The attempt row is built BEFORE the transaction, so the fallback below can still
             * write it when the transaction cannot commit.
             */
            $attemptRow = [
                'order_id' => $orderId,
                'provider' => $provider,
                'method' => $methodId['method'],
                'storefront_payment_method_id' => $methodId['id'],
                'pay_order_id' => is_numeric($verdict->orderReference) ? (int) $verdict->orderReference : null,
                'pay_transaction_id' => $verdict->providerTransactionId,
                'amount_cents' => $verdict->amountMinor,
                // An amount that disagrees with the order is NEVER recorded as a success, whatever
                // the provider said.
                'success' => $decision['paid'] ? 'true' : 'false',
                // …and WHAT it was, which `success` cannot express: a refund, a void and a decline
                // are three different things that all used to read `false` (or, worse, `true` for a
                // refund).
                'outcome' => $outcome,
            ];

            try {
                /*
                 * ── bounded deadlock retry (2026-09-13) ────────────────────────────────────────
                 *
                 * This transaction locks the order row and then its product rows — the same rows in
                 * the same order as `InventoryService`, which is exactly why the two deadlock
                 * against each other under concurrency. Wave 3.5 solved that for stock, and the
                 * SAME policy is used here rather than a second copy: deadlocks only, three
                 * attempts, jitter scaled by attempt, and never a retry inside a caller's
                 * transaction (`App\Support\DeadlockRetry`).
                 *
                 * Without it a deadlocked callback answered 500 and VANISHED: the provider had
                 * taken the money and the rollback removed every trace of the attempt.
                 */
                DeadlockRetry::run(function () use ($provider, $verdict, $orderId, $amountMatches, $methodId, $storefrontId, $outcome, $orderStatus, $decision, $attemptRow): void {
                    $now = now();
                    $attemptId = (int) DB::table('payment_statuses')->insertGetId($attemptRow + [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($decision['finding'] !== null) {
                        CallbackPolicy::record(
                            $decision['finding'],
                            $provider,
                            $orderId,
                            $attemptId,
                            $outcome,
                            $orderStatus,
                            $verdict->amountMinor,
                            [
                                'transaction' => $verdict->providerTransactionId,
                                'amount_matches' => $amountMatches,
                                'route' => 'scoped',
                            ],
                        );
                    }

                    if ($decision['action'] === CallbackPolicy::ACT_PAY) {
                        DB::table('orders')->where('id', $orderId)->update([
                            'status' => 'processing',
                            // Written by the attempt that succeeds. A SECOND success would
                            // overwrite it, which is why the policy above is what stops a second
                            // one arriving on an order that has already moved on — the column is
                            // not the guard.
                            'paid_via_provider' => $provider,
                            'paid_via_method' => $methodId['method'],
                            'updated_at' => now(),
                        ]);
                    } elseif ($decision['action'] === CallbackPolicy::ACT_CANCEL) {
                        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled', 'updated_at' => now()]);
                        // The ONE door for stock, even here (D-21): a failed payment returns the
                        // units it reserved, through the service, ledgered, exactly once.
                        $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $storefrontId);
                    }
                });
            } catch (Throwable $e) {
                /*
                 * ONLY CONTENTION lands here — a deadlock that outlived the retries, or a
                 * lock-wait timeout (which is never retried, because hammering the holder makes it
                 * worse). Anything else is
                 * deterministic — a foreign key, a bug, a broken config — and it is re-thrown
                 * untouched so that:
                 *
                 *   - the transaction's all-or-nothing property stays exactly as wave 3 proved it
                 *     (`PaymobCallbackTest`: a failing release rolls the payment row back with it), and
                 *   - a callback naming an order that does not exist is still refused the way the
                 *     LEGACY app refuses it, which the compat layer reproduces on purpose.
                 *
                 * Those failures repeat on the provider's next retry, which is what should happen:
                 * recording them as "reconciliation" would file a bug under money.
                 */
                if (! ($e instanceof QueryException) || ! DeadlockRetry::isContention($e)) {
                    throw $e;
                }

                /*
                 * The retries are spent. The transaction rolled back and took the attempt with it,
                 * so the record is written HERE — outside any transaction, because a record written
                 * inside the one that just failed is the one thing that cannot survive.
                 *
                 * The order is deliberately NOT touched: whatever it was before this callback, it
                 * still is. A human gets a finding naming the transaction id and the exception
                 * class, which is what separates "contention, find this id in the portal" from
                 * "a bug that will repeat".
                 */
                CallbackPolicy::recordLostCallback(
                    $provider,
                    $orderId,
                    $orderStatus,
                    $outcome,
                    $attemptRow,
                    $e,
                    ['route' => 'scoped', 'amount_matches' => $amountMatches, 'storefront_id' => $storefrontId],
                );

                // 200, not 500: the provider must not retry into the same contention, and the money
                // is now on record with a finding against it. A 500 here is how a provider's retry
                // storm turns one deadlock into ten.
                return response()->json(['message' => 'Recorded for reconciliation'], 200);
            }

            if (! $amountMatches) {
                Log::error('payment callback: amount does not match the order total; order left untouched.', $context + [
                    'order_id' => $orderId,
                    'expected_minor' => $expectedMinor,
                    'reported_minor' => $verdict->amountMinor,
                    'transaction' => $verdict->providerTransactionId,
                ]);

                return $this->done($request, false);
            }

            return $this->done($request, $verdict->isSuccess);
        } catch (Throwable $e) {
            // The message may quote the payload; the payload may quote the contract. Log the class
            // and the place, never the exception's own text next to payment context.
            Log::error('payment callback failed: '.$e::class.' at '.basename($e->getFile()).':'.$e->getLine(), $context);

            return $this->done($request, false);
        }
    }

    /**
     * The order a merchant reference names — by `order_number` first, then by id.
     *
     * `special_reference` on the intention API carries the order NUMBER, while the older order API
     * echoes the numeric id; a contract may still be on either, so both are tried and the number
     * wins. Nothing about the storefront is applied here: that is check 3's job, and doing it in
     * one place is what makes the check provable.
     */
    private function resolveOrder(CallbackVerdict $verdict): ?\stdClass
    {
        /*
         * 🟡-5: prefer the SIGNED field. `order` is inside Paymob's HMAC; `merchant_order_id` is
         * not, so resolving by it alone made the storefront-ownership check the only thing standing
         * between a forged reference and someone else's order. When a previous attempt on the same
         * provider order exists, its `pay_order_id` names the order that was actually paid and is
         * used instead — the unsigned reference then only has to agree, not to be trusted.
         */
        $signed = $verdict->signedOrderReference;
        if ($signed !== null && $signed !== '') {
            $bySigned = DB::table('payment_statuses')
                ->whereNotNull('order_id')
                ->where('pay_order_id', $signed)
                ->orderByDesc('id')
                ->value('order_id');

            if (is_numeric($bySigned)) {
                $row = DB::table('orders')->where('id', (int) $bySigned)
                    ->first(['id', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);
                if ($row !== null) {
                    return Row::cast($row);
                }
            }
        }

        $reference = $verdict->orderReference;
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        // Falling back to the UNSIGNED reference. Logged, because it is the weaker path and a
        // sudden flood of it means the signed field stopped arriving.
        Log::info('payment callback: resolving by the unsigned merchant reference.', [
            'reference' => $reference,
            'signed_reference' => $signed,
        ]);

        $row = DB::table('orders')->where('order_number', $reference)
            ->first(['id', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);
        if ($row !== null) {
            return Row::cast($row);
        }

        if (! is_numeric($reference)) {
            return null;
        }

        $byId = DB::table('orders')->where('id', Coerce::int($reference))
            ->first(['id', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);

        return $byId === null ? null : Row::cast($byId);
    }

    /**
     * Which METHOD executed this payment (study §3.9.4), in precedence order:
     *
     *  1. what the customer CHOSE, recorded at initiation on the pending attempt — authoritative,
     *     and it works for providers that report nothing back;
     *  2. what the provider says (`integration_id`, one of Paymob's signed fields), as a
     *     CROSS-CHECK. A mismatch is logged as a reconciliation finding, never a rejection: the
     *     money moved regardless, and refusing the callback would strand a paid order;
     *  3. neither — NULL, and the reconciliation report lists it.
     *
     * @return array{id: int|null, method: string|null}
     */
    private function resolveMethodId(StorefrontPaymentProvider $contract, CallbackVerdict $verdict, int $orderId): array
    {
        $chosen = DB::table('payment_statuses')
            ->where('order_id', $orderId)
            ->whereNotNull('storefront_payment_method_id')
            ->orderByDesc('id')
            ->first(['storefront_payment_method_id', 'method']);

        $fromProvider = null;
        if ($verdict->integrationId !== null && $verdict->integrationId !== '') {
            $fromProvider = DB::table('storefront_payment_methods')
                ->where('storefront_payment_provider_id', $contract->getAttribute('id'))
                ->where('integration_id', $verdict->integrationId)
                ->first(['id', 'method']);
        }

        if ($chosen !== null) {
            $chosenId = Row::nint(Row::cast($chosen), 'storefront_payment_method_id');
            $providerMethodId = $fromProvider === null ? null : Row::int(Row::cast($fromProvider), 'id');
            if ($providerMethodId !== null && $chosenId !== null && $providerMethodId !== $chosenId) {
                Log::warning('payment callback: the provider reports a different method than the customer chose.', [
                    'order_id' => $orderId,
                    'chosen_method_id' => $chosenId,
                    'provider_method_id' => $providerMethodId,
                ]);
            }

            return ['id' => $chosenId, 'method' => Row::nstr(Row::cast($chosen), 'method')];
        }

        if ($fromProvider !== null) {
            $row = Row::cast($fromProvider);

            return ['id' => Row::int($row, 'id'), 'method' => Row::str($row, 'method')];
        }

        return ['id' => null, 'method' => null];
    }

    /**
     * A refusal: one log line with the ids that make it diagnosable, and a body that says nothing
     * a prober could use.
     *
     * @param  array<string, mixed>  $context
     */
    private function fail(int $status, string $why, array $context): JsonResponse
    {
        Log::warning('payment callback refused: '.$why, $context);

        return response()->json(['message' => $status === 404 ? 'Not found' : 'Forbidden'], $status);
    }

    /** Where the customer lands. A provider's server-to-server call gets JSON instead. */
    private function done(Request $request, bool $ok): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->acceptsHtml()) {
            return response()->json(['message' => $ok ? 'ok' : 'failed'], 200);
        }

        $base = config()->string('compat.payment_return_url');

        return redirect($ok ? $base : $base.'?payment_error=1');
    }
}
