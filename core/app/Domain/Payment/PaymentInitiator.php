<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;

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
        int $storefrontId,
        string $locale,
        int|string|null $postedMethod,
        int $orderId,
        string $orderNumber,
        float $amount,
        array $billing,
        ?string $returnUrl = null,
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

        if (! $contract instanceof StorefrontPaymentProvider || ! $contract->credentialsSet()) {
            return null;                        // not cut over yet — see the class note
        }

        $implementation = $this->registry->for($contract);
        if ($implementation === null) {
            return null;                        // a row naming a provider nobody implements
        }

        $integrationId = Coerce::nstr(
            $contract->methods()->where('method', $method['method'])->where('is_enabled', true)->value('integration_id')
        );

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
            returnUrl: $returnUrl,
        );

        return $implementation->initiate($intent, ProviderCredentials::fromArray(
            Coerce::arr($contract->getAttribute('credentials'))
        ));
    }
}
