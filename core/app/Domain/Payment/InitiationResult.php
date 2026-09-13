<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What a provider gives back when a payment is started: somewhere to send the customer, and the
 * provider's own reference so the attempt can be recorded before they leave the site.
 */
final class InitiationResult
{
    /** @param array<string, mixed> $raw the provider's response, for the attempt record */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $checkoutUrl,
        public readonly ?string $providerReference,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function redirect(string $url, ?string $reference, array $raw = []): self
    {
        return new self(true, $url, $reference, null, $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, null, null, $reason, $raw);
    }
}
