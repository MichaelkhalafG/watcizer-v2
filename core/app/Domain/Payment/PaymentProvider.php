<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * The thin contract every payment provider implements (study §3.9.3).
 *
 * ── What is deliberately NOT here ────────────────────────────────────────────────────────────
 *
 * No decisions. `verifyCallback()` returns DATA — a {@see CallbackVerdict} — and the controller
 * decides what it means: the amount comparison, the storefront ownership check, the idempotency
 * key, whether to release stock. Every one of those is a guard, and a guard implemented once per
 * provider is a guard implemented differently per provider. Paymob is the first implementation;
 * Fawry is expected to be the second, and an interface written against one implementation is a
 * guess until a second one uses it — so this is as small as it can be.
 *
 * Credentials arrive as an argument rather than being read from config, because they live per
 * `(storefront, provider)` in an encrypted column and a provider object must never know how to
 * find its own secrets: that is what would make a global gateway config possible again (§2.19).
 */
interface PaymentProvider
{
    /** The provider constant this implementation serves (`paymob`, `fawry`, `cod`, …). */
    public function key(): string;

    /**
     * The credential fields this provider needs, in the order the dashboard should render them.
     *
     * Names only. The dashboard renders one write-only input per name and never a value; the
     * providers screen uses this to show which keys are set and which are missing, so a half-done
     * rotation is visible without revealing anything.
     *
     * @return list<string>
     */
    public function credentialFields(): array;

    /**
     * Start a payment for ONE method of this contract.
     *
     * Returns a hosted-checkout URL or an inline token, plus the provider's own reference, so the
     * caller can record the attempt before the customer leaves the site (§3.9.4 rule 1: what the
     * customer chose, recorded at initiation, is the authoritative record of the method).
     */
    public function initiate(PaymentIntent $intent, ProviderCredentials $credentials): InitiationResult;

    /**
     * Verify a raw callback payload and report what it says.
     *
     * Signature validity, the provider's transaction id, its AUTHORITATIVE minor-unit amount, the
     * success flag and which method executed it. A provider that cannot report an authoritative
     * amount is not integrable — that is an acceptance criterion, not a preference, because the
     * amount check is the only thing standing between a tampered callback and a free order.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload, ProviderCredentials $credentials): CallbackVerdict;

    /**
     * Ask the provider what it thinks a reference's status is.
     *
     * For a reconciler that settles callbacks which never arrived, and for support answering "did
     * this money arrive?" without opening three merchant portals. Not called by any wave-4C path;
     * the seat is reserved so the first reconciler does not have to change the interface.
     */
    public function resolveStatus(string $providerReference, ProviderCredentials $credentials): PaymentOutcome;
}
