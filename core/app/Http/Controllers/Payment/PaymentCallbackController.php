<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Compat\CompatCheckout;
use App\Domain\Analytics\MetaConversions;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderRecovery;
use App\Domain\Payment\CallbackPolicy;
use App\Domain\Payment\CallbackVerdict;
use App\Domain\Payment\Landing;
use App\Domain\Payment\PaymobProvider;
use App\Domain\Payment\ProviderCredentials;
use App\Domain\Payment\ProviderRegistry;
use App\Http\Controllers\Compat\CheckoutCompatController;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Storefront\StorefrontHost;
use App\Storefront\StorefrontUrls;
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
        private readonly OrderMailer $mailer,
        private readonly MetaConversions $meta,
    ) {}

    /**
     * The scoped route. `{storefront}` is a storefront CODE and `{provider}` a provider constant,
     * both constrained in the route definition.
     */
    public function handle(Request $request, string $storefront, string $provider): JsonResponse|RedirectResponse
    {
        $storefrontRow = Storefront::query()->where('code', $storefront)->first();
        // Unknown storefront: 404, and nothing about whether the provider exists. A storefront the
        // request's HOST does not belong to answers identically (brand separation, 2026-09-26):
        // a 403-vs-404 difference on another shop's code confirmed that shop exists. The host goes
        // to the log only, so the operator can tell the two apart and nobody else can.
        if ($storefrontRow === null || ! StorefrontHost::serves($request, (int) $storefrontRow->id)) {
            return $this->fail($request, 404, 'unknown storefront', ['storefront' => $storefront, 'provider' => $provider, 'host' => $request->getHost()]);
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

        /*
         * COMPLETE, not merely set (2026-09-23). `credentialsSet()` is true for a contract holding
         * `public_key` alone — and that contract cannot verify a signature, so going live on it
         * would answer every callback 403 with the money taken. A half-entered contract keeps the
         * proven wave-3 path until the last key is in.
         */
        return $contract !== null && $contract->credentialsComplete(
            app(ProviderRegistry::class)->credentialFields(PaymobProvider::KEY)
        );
    }

    private function process(Request $request, int $storefrontId, string $provider, bool $alias = false): JsonResponse|RedirectResponse
    {
        $context = ['storefront_id' => $storefrontId, 'provider' => $provider, 'alias' => $alias];

        try {
            // ── 1. resolve the contract ──────────────────────────────────────────────────────
            $contract = StorefrontPaymentProvider::query()
                ->where('storefront_id', $storefrontId)->where('provider', $provider)->first();

            if ($contract === null || ! (bool) $contract->getAttribute('is_enabled')) {
                return $this->fail($request, 404, 'no enabled contract for this storefront and provider', $context, landing: $storefrontId);
            }

            $implementation = $this->registry->for($contract);
            if ($implementation === null) {
                return $this->fail($request, 404, 'no implementation registered for this provider', $context, landing: $storefrontId);
            }

            $credentials = ProviderCredentials::fromArray(Coerce::arr($contract->getAttribute('credentials')));
            if ($credentials->isEmpty() && $implementation->credentialFields() !== []) {
                // A contract that needs credentials and has none cannot verify anything, and
                // "verify with an empty secret" is how a forged callback gets accepted.
                return $this->fail($request, 404, 'contract holds no credentials', $context, landing: $storefrontId);
            }

            // ── 2. verify the signature with THIS contract's secret ─────────────────────────
            /** @var array<string, mixed> $payload */
            $payload = $request->all();
            $verdict = $implementation->verifyCallback($payload, $credentials);
            if (! $verdict->signatureValid) {
                return $this->fail($request, 403, 'signature did not verify against this contract', $context, landing: $storefrontId);
            }

            // ── 3. ownership ────────────────────────────────────────────────────────────────
            $order = $this->resolveOrder($verdict);
            if ($order === null) {
                return $this->fail($request, 404, 'the merchant reference names no order', $context + [
                    'reference' => $verdict->orderReference,
                    'transaction' => $verdict->providerTransactionId,
                ], landing: $storefrontId);
            }
            $orderId = Row::int($order, 'id');
            $orderNumber = Row::str($order, 'order_number');
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
                return $this->fail($request, 403, 'order belongs to another storefront', $context + [
                    'order_id' => $orderId,
                    'order_storefront_id' => $orderStorefront,
                    'transaction' => $verdict->providerTransactionId,
                ], landing: $storefrontId);
            }
            if ($orderStorefront === null) {
                Log::info('payment callback: order carries no storefront_id; accepted as the primary storefront.', $context + ['order_id' => $orderId]);
            }

            /*
             * Idempotency, before the work: one row per (provider, transaction id).
             *
             * This is the branch the shopper's browser NORMALLY lands on (see Landing): the
             * processed POST has already settled the order by the time the GET arrives. The
             * provider still gets `Already processed` -- "stop retrying, this is on record" -- and
             * the browser is sent to the storefront with what the record SAYS, not a fresh verdict.
             */
            $transactionId = $verdict->providerTransactionId;
            $recorded = $transactionId === null ? null : DB::table('payment_statuses')
                ->where('provider', $provider)->where('pay_transaction_id', $transactionId)
                ->first(['success', 'outcome']);
            if ($recorded !== null) {
                return self::land($request, $storefrontId, Landing::ofRecorded(
                    Row::nstr($recorded, 'success'), Row::nstr($recorded, 'outcome'),
                ), $orderNumber, 'Already processed');
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
            /*
             * The mail rows the transaction below enqueues, sent after it commits.
             *
             * This handler sent NO order e-mail at all until 2026-09-13, and it is the one the
             * switch makes live (`aliasIsLive()`): a card customer would have paid, had their
             * order moved to `processing`, and been told nothing by anybody.
             *
             * @var list<int> $mailIds
             */
            $mailIds = [];
            /** @var list<int> $metaIds the card Purchase (B2): enqueued inside, sent after, like the mail */
            $metaIds = [];

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
                DeadlockRetry::run(function () use ($provider, $verdict, $orderId, $amountMatches, $methodId, $storefrontId, $outcome, $orderStatus, $decision, $attemptRow, &$mailIds, &$metaIds): void {
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

                        /*
                         * The money arrived: the customer's confirmation and the admins'
                         * notification, the same pair the legacy callback sends
                         * (`sendOrderEmails($order, $isGuest, 'cash')`). Enqueued inside the
                         * transaction so the obligation commits with the payment; sent after it,
                         * because an SMTP round trip inside a transaction holds row locks.
                         */
                        $mailIds = $this->mailer->placed($orderId, notifyCustomer: true);
                        // The card Purchase for Meta (B2): the only place that knows the money arrived.
                        $metaIds = $this->meta->purchase($orderId);
                    } elseif ($decision['action'] === CallbackPolicy::ACT_CANCEL) {
                        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled', 'updated_at' => now()]);
                        // The ONE door for stock, even here (D-21): a failed payment returns the
                        // units it reserved, through the service, ledgered, exactly once.
                        $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $storefrontId);

                        /*
                         * The decline e-mail (developer, 2026-10-12): the SAME recovery e-mail an
                         * expiry sends (`UnpaidOrders::cancel`), with the reason set so the admins'
                         * copy says "declined" rather than "didn't pay". Until now a shopper who
                         * abandoned checkout was e-mailed an hour later and a shopper who tried and
                         * was declined was told nothing — the one who got further was told less.
                         * Same gate as the expiry (one per shopper per week, never after they
                         * ordered again), same dedupe (once per order, whichever reason comes
                         * first), flushed after this transaction commits like the paid branch.
                         *
                         * Decided HERE, when the callback lands, and sent seconds later: nothing
                         * re-checks the shopper at send time. So a shopper declined at 10:00 who
                         * retries and pays at 10:02 still gets this e-mail beside their
                         * confirmation; the recovery link is then refused as `reordered`. Accepted
                         * (developer, 2026-10-12) rather than holding the row — DeclineRecoveryTest
                         * pins the behaviour so nobody re-derives it.
                         */
                        if (OrderRecovery::shouldRemind($orderId)) {
                            $mailIds = $this->mailer->paymentExpired($orderId, OrderMailer::REASON_DECLINED);
                        }
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
                // storm turns one deadlock into ten. The shopper is told the payment is being
                // confirmed, which is the truth of a finding.
                return self::land($request, $storefrontId, Landing::PENDING, $orderNumber, 'Recorded for reconciliation');
            }

            /*
             * Committed, so the outcome is real -- now the e-mail: the order confirmation on the
             * paid branch, the recovery e-mail on the cancel branch (2026-10-12; until then the
             * cancel branch sent nothing, on the grounds that the legacy app did not either — an
             * explanation, not a justification). A relay failure here changes nothing about the
             * payment or the order: the row stays pending and `mail:drain` retries it on the next
             * minute's tick.
             */
            $this->mailer->flush($mailIds);
            // Never fails the callback: a send Meta did not accept stays pending for `meta:drain`.
            $this->meta->flush($metaIds);

            if (! $amountMatches) {
                Log::error('payment callback: amount does not match the order total; order left untouched.', $context + [
                    'order_id' => $orderId,
                    'expected_minor' => $expectedMinor,
                    'reported_minor' => $verdict->amountMinor,
                    'transaction' => $verdict->providerTransactionId,
                ]);
            }

            // What the POLICY decided, not what the provider claimed: a success on an amount that
            // disagrees, or on an order already in a terminal state, is a finding -- `pending` to
            // the shopper -- never `paid`.
            return self::land($request, $storefrontId, Landing::of($decision, $outcome), $orderNumber);
        } catch (Throwable $e) {
            // The message may quote the payload; the payload may quote the contract. Log the class
            // and the place, never the exception's own text next to payment context.
            Log::error('payment callback failed: '.$e::class.' at '.basename($e->getFile()).':'.$e->getLine(), $context);

            return self::land($request, $storefrontId, Landing::FAILED, null, 'failed');
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
                    ->first(['id', 'order_number', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);
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
            ->first(['id', 'order_number', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);
        if ($row !== null) {
            return Row::cast($row);
        }

        if (! is_numeric($reference)) {
            return null;
        }

        $byId = DB::table('orders')->where('id', Coerce::int($reference))
            ->first(['id', 'order_number', 'storefront_id', 'total_price_for_order', 'status', 'payment_method']);

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
     * ── Two lines, two channels (2026-10-12) ─────────────────────────────────────────────────
     *
     * This line is a `warning`, and production runs `LOG_LEVEL=error`: from 2026-09-28 the default
     * channel dropped every refusal this method wrote, so the eighteen days in which EVERY real
     * callback was refused at the signature (the stored `hmac_secret` was not the one Paymob signed
     * with) left no record anywhere — "nobody tried to pay" and "somebody paid and we refused them"
     * looked identical: a pending order cancelled an hour later. A warning on the default channel
     * is a trace that vanishes whenever someone tightens the level, so the same line also goes to
     * the `payments` channel, whose level is fixed in `config/logging.php` and never read from
     * `LOG_LEVEL` (the `scheduled` channel's precedent). The default-channel line stays for whoever
     * tails the main log at a level that shows it.
     *
     * ── What the line carries, and what it never carries ─────────────────────────────────────
     *
     * Which check refused, the storefront, the provider, the HTTP method (POST is the provider's
     * server, GET is the shopper's browser), and the integration id and merchant order id — read
     * from the payload AS SENT, through the helper the verifier uses. At this point the signature
     * has not been checked, or has failed, so they are labelled `claimed_` and are trusted only as
     * far as a log line needs to be: enough to find the attempt in the provider's portal. Never the
     * `hmac`, never a secret, never the payload, never an amount.
     *
     * ── What the CALLER gets (Landing, 2026-10-12) ───────────────────────────────────────────
     *
     * The provider's server (a POST) gets the JSON refusal and its status. A shopper's browser (a
     * GET) is sent to the storefront's failed landing instead: a code fragment on a blank page told
     * a customer nothing. `$landing` is the storefront the callback was SCOPED to -- passed, never
     * derived from the request's host, so the landing cannot resolve through the default-storefront
     * fallback. It is null for exactly one refusal, the unknown storefront in `handle()`, which
     * stays a plain 404 for everyone: redirecting it would have to pick a shop from the host, and
     * `StorefrontUrls::storefrontOf()` falls back to Watchizer when the host matches nothing -- the
     * very fallback W6/W7 removes. Nobody legitimately lands on a storefront code that does not
     * exist, so there is nobody to redirect.
     *
     * @param  array<string, mixed>  $context
     */
    private function fail(Request $request, int $status, string $why, array $context, ?int $landing = null): JsonResponse|RedirectResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $integration = CompatCheckout::paymobField($payload, 'integration_id');
        $reference = CompatCheckout::paymobField($payload, 'merchant_order_id');

        $line = $context + [
            'check' => $why,
            'status' => $status,
            'http_method' => $request->getMethod(),
            'claimed_integration_id' => $integration === '' ? null : $integration,
            'claimed_merchant_order_id' => $reference === '' ? null : $reference,
        ];

        Log::warning('payment callback refused: '.$why, $line);
        Log::channel('payments')->warning('payment callback refused: '.$why, $line);

        if ($landing !== null && ! $request->isMethod('POST')) {
            // No order number: before ownership is proven the reference is a claim, and after a
            // failed ownership check it is another shop's order.
            return redirect(StorefrontUrls::paymentLanding($landing, Landing::FAILED, null));
        }

        return response()->json(['message' => $status === 404 ? 'Not found' : 'Forbidden'], $status);
    }

    /**
     * Where the customer lands. A provider's server-to-server call gets JSON instead.
     *
     * ── Why the METHOD decides this, and not the Accept header (review G2b) ─────────────────
     *
     * The processed callback is a POST; the shopper's return is a GET. That distinction is a fact
     * about the protocol. `expectsJson()` is a guess about a header: Paymob's POST sends an
     * any-type `Accept` and no `X-Requested-With`, so it read as a BROWSER and was answered with a
     * 302 to the storefront — a redirect sent to a machine that will not follow it, and a
     * response that says nothing about whether the callback was accepted.
     *
     * ── A GET ALWAYS redirects, whatever its Accept header — legacy parity (2026-09-23) ─────
     *
     * There used to be a second arm: a GET sending `Accept: application/json` got JSON. The legacy
     * handler redirects every GET unconditionally, so that arm was a divergence the compat harness
     * could see — and it served nobody. Paymob's GET is the shopper's BROWSER returning, which
     * never sends that header, and a monitoring probe that did would lose nothing by getting a
     * 302. Developer decision: byte-parity is worth more than the improvement, so the METHOD is
     * the only thing that decides, in both directions.
     *
     * ── PUBLIC and STATIC, because the wave-3 handler needs the same answer (review 🔴-2) ────
     *
     * {@see CheckoutCompatController::callbackPayment()} serves the
     * alias whenever the Paymob contract is not live, and it was answering a **302 to every
     * caller** — including the processed POST. A 302 is not the 200 a provider stops retrying on,
     * so each retry ran the handler again; with the identifier bug that was in it at the time,
     * each retry also wrote another orphan row. Two handlers on one URL must not disagree about
     * what a POST means, so this is one method rather than a second copy of the same three lines
     * (AGENTS §3: share the CODE, not the description).
     *
     * ── Now the WAVE-3 handler's answer only (Landing, 2026-10-12) ───────────────────────────
     *
     * The premise this method was written on -- "a browser arriving on a replay is a rare case" --
     * was false: the processed POST wins the race to the server almost every time, so the browser
     * normally arrives on the idempotency branch, and it was being shown `Already processed` as
     * raw JSON on a blank page (measured on the first successful live payment, 2026-10-12). The
     * scoped handler (`process()`) therefore sends EVERY non-POST exit through {@see self::land()}:
     * refusals, the replay, the deadlock record and the terminal branches all redirect a browser
     * to the storefront's `/order-confirmation` with the order number and a status, and answer the
     * provider with the JSON it always got.
     *
     * This method keeps the OLD landing -- the storefront root, `?payment_error=1` on failure, no
     * order number -- because its only remaining caller is the wave-3 alias fall-through in
     * {@see CheckoutCompatController::callbackPayment()}, which the developer chose to leave
     * untouched (2026-10-12): it serves the alias only while Watchizer's Paymob contract is not
     * live, and on production the contract is live, so the path is dead there. Its own early exits
     * (`Invalid signature`, `Already processed`) still answer a browser with JSON for the same
     * reason. The legacy app did the same; that is why the behaviour exists, not a justification.
     *
     * `$message` is the POST body's sentence; `null` gives the legacy `ok`/`failed`.
     */
    public static function done(Request $request, bool $ok, ?string $message = null): JsonResponse|RedirectResponse
    {
        if ($request->isMethod('POST')) {
            return self::providerAnswer($message ?? ($ok ? 'ok' : 'failed'));
        }

        // The shop the shopper paid ON (L5, 2026-09-26): the return GET arrives on that shop's
        // own API host, because the redirection URL is built from it. One global sent every
        // storefront's shoppers back to Watchizer.
        $base = StorefrontUrls::paymentReturn(StorefrontUrls::storefrontOf($request));

        return redirect($ok ? $base : $base.'?payment_error=1');
    }

    /**
     * The scoped handler's answer: the provider's server gets JSON 200 and stops retrying; the
     * shopper's browser is sent to the storefront's landing with the order number and the status
     * {@see Landing} decided. The storefront is the one the callback was SCOPED to, never derived
     * from the request's host (no fallback to the default shop).
     *
     * @param  string  $status  one of the {@see Landing} constants
     * @param  string|null  $message  the POST body's sentence; null gives `ok` for a paid landing and `failed` otherwise
     */
    public static function land(Request $request, int $storefrontId, string $status, ?string $orderNumber, ?string $message = null): JsonResponse|RedirectResponse
    {
        if ($request->isMethod('POST')) {
            return self::providerAnswer($message ?? ($status === Landing::PAID ? 'ok' : 'failed'));
        }

        return redirect(StorefrontUrls::paymentLanding($storefrontId, $status, $orderNumber));
    }

    /** A 200 with one sentence: all a provider's server needs in order to stop retrying. */
    private static function providerAnswer(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 200);
    }
}
