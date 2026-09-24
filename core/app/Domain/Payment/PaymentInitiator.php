<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Starting a payment against the STOREFRONT'S OWN provider contract (wave 4D, task C2).
 *
 * ── The half of §3.9 that was still missing ──────────────────────────────────────────────────
 *
 * Wave 4C moved callback VERIFICATION onto `storefront_payment_providers`: each storefront's
 * Paymob account, its own encrypted credentials, its own callback URL. Initiation stayed on
 * `config('services.paymob.*')` — one global account read from `core/.env` — and the study's
 * prerequisite (d) has carried "HALF-OPEN" ever since, because that is exactly what it was.
 *
 * The gap is not cosmetic. Until this class existed a customer could be sent to **account A** to
 * pay while the callback was verified against **account B's** HMAC secret: the signature would not
 * verify, the order would sit pending with the money taken, and the only symptom would be a
 * callback that "does not verify" — which reads like a Paymob outage rather than a configuration
 * split across two places.
 *
 * ── The cutover rule is the CALLBACK's rule, deliberately the same one ───────────────────────
 *
 * `PaymentCallbackController::aliasIsLive()` decides that the v2 path owns a callback when the
 * contract exists, is enabled, and HOLDS CREDENTIALS — data, not a flag, because a flag is a
 * second thing to get right on switch night and it can disagree with the table it describes.
 *
 * This class asks the identical question and answers `null` when it is not yet true, so the caller
 * keeps the proven wave-3 path. That means initiation and verification cannot disagree about which
 * account is live: **inserting the credentials moves both at once**, with no deploy, and disabling
 * the contract moves both back.
 */
final class PaymentInitiator
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * Start a payment through the contract that owns this method.
     *
     * @param  int|string|null  $postedMethod  what the customer posted — a v2 method id or the
     *                                         legacy string; {@see MethodList::resolve()} settles both
     *                                         on the SAME row
     * @param  array<string, mixed>  $billing
     * @return InitiationResult|null null when no table-driven contract is live yet, which is the
     *                               caller's signal to keep the wave-3 path
     */
    public function initiate(
        /*
         * The request the payment is being started from, passed rather than pulled off a facade.
         * It decides the callback destination (see below), so it is a real input to this method:
         * hiding it behind `request()` made a domain service depend on ambient state, and the way
         * that surfaced was three tests asserting `ok` on a host no provider could call back to.
         */
        Request $request,
        int $storefrontId,
        string $locale,
        int|string|null $postedMethod,
        int $orderId,
        string $orderNumber,
        float $amount,
        array $billing,
    ): ?InitiationResult {
        $method = MethodList::resolve($storefrontId, $locale, $postedMethod);
        if ($method === null) {
            return null;
        }

        $contract = StorefrontPaymentProvider::query()
            ->where('id', $method['provider_id'])
            ->where('storefront_id', $storefrontId)
            ->where('is_enabled', true)
            ->first();

        // COMPLETE, not merely set — the same gate `aliasIsLive()` asks, so initiation and
        // verification cannot disagree about whether a half-entered contract is live.
        if (! $contract instanceof StorefrontPaymentProvider
            || ! $contract->credentialsComplete($this->registry->credentialFields(Coerce::str($contract->getAttribute('provider'))))) {
            return null;                        // not cut over yet — see the class note
        }

        $implementation = $this->registry->for($contract);
        if ($implementation === null) {
            return null;                        // a row naming a provider nobody implements
        }

        $integrationId = Coerce::nstr(
            $contract->methods()->where('method', $method['method'])->where('is_enabled', true)->value('integration_id')
        );

        /*
         * ── The callback destination travels WITH the transaction (review 🔴-2) ─────────────
         *
         * Not sending these left the destination to Paymob's merchant portal, whose URL names the
         * legacy host — and `.htaccess` §4 closes `/api` there. A callback sent to it after the
         * flip 404s with the money already taken.
         *
         * A FAILED initiation is the right answer when the pair cannot be built, and is why this
         * returns a failure rather than an intent with nulls: omitting the URLs would silently
         * restore the portal's authority, which is the defect. A shopper told the payment could
         * not be started has lost nothing.
         */
        $storefront = Storefront::query()->whereKey($storefrontId)->first();
        if (! $storefront instanceof Storefront) {
            return InitiationResult::failed('The storefront starting this payment no longer exists.');
        }

        $callbacks = CallbackDestination::for($request, $storefront, $contract);
        if ($callbacks === null) {
            // Names the contract, never a credential. The host is diagnostic and not secret.
            Log::error('payment initiation refused: no usable https callback base.', [
                'storefront_id' => $storefrontId,
                'provider' => Coerce::str($contract->getAttribute('provider')),
                'observed_host' => $request->getSchemeAndHttpHost(),
            ]);

            return InitiationResult::failed(
                'This shop has no usable payment callback address, so the payment was not started.'
            );
        }

        $intent = new PaymentIntent(
            orderId: $orderId,
            orderNumber: $orderNumber,
            storefrontId: $storefrontId,
            methodId: $method['id'],
            method: $method['method'],
            integrationId: $integrationId,
            /*
             * MINOR UNITS, computed once, here. `(int) ($amount * 100)` on 1234.35 is 123434 in
             * binary floating point, and a piastre missing from an intention is a callback whose
             * amount check fails and an order nobody can settle — the same class of defect the
             * wave-4C review found on the callback side.
             */
            amountMinor: (int) round($amount * 100),
            currency: 'EGP',
            billing: $billing,
            redirectUrl: $callbacks['redirection_url'],
            notifyUrl: $callbacks['notification_url'],
        );

        return $implementation->initiate($intent, ProviderCredentials::fromArray(
            Coerce::arr($contract->getAttribute('credentials'))
        ));
    }
}
