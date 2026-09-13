<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What a payment is being started FOR (study §3.9.3).
 *
 * The chosen METHOD is part of the intent rather than a separate argument, for two reasons: for
 * most providers it is a routing parameter (Paymob's intention call takes the integration id), and
 * because the initiation is what records the customer's choice — which is the authoritative answer
 * to "which method executed this payment" for every provider, including ones that report nothing
 * back (§3.9.4 rule 1).
 */
final class PaymentIntent
{
    /**
     * @param  int  $amountMinor  the authoritative amount in minor units (piastres), never a float
     * @param  array<string, mixed>  $billing  name/phone/email/address as the provider needs them
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $storefrontId,
        public readonly int $methodId,
        public readonly string $method,
        public readonly ?string $integrationId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly array $billing = [],
        public readonly ?string $returnUrl = null,
    ) {}
}
