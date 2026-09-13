<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What a provider SAYS about a callback — data, never a decision (study §3.9.3).
 *
 * The controller compares `amountMinor` against the order, checks the storefront, applies the
 * idempotency key and decides whether to release stock. Keeping those out of here is what stops
 * two providers from implementing the same guard two different ways.
 */
final class CallbackVerdict
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $signatureValid,
        public readonly ?string $providerTransactionId,
        public readonly ?int $amountMinor,
        public readonly bool $isSuccess,
        public readonly ?string $integrationId,
        /** The provider's OWN order reference, from a field inside the signature. */
        public readonly ?string $orderReference,
        /**
         * The provider's own order id, taken from a SIGNED field.
         *
         * `orderReference` above is whichever reference the provider offers for finding our order,
         * and for Paymob that is `merchant_order_id` — which is NOT inside the HMAC. This one is
         * (`order`), so a resolver that can use it is not trusting an unsigned value (🟡-5).
         */
        public readonly ?string $signedOrderReference = null,
        public readonly array $raw = [],
    ) {}

    public static function invalidSignature(): self
    {
        return new self(false, null, null, false, null, null);
    }
}
