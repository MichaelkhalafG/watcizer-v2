<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;

/**
 * Cash on delivery and the WhatsApp flow, as PROVIDERS with no credentials (study §3.9.1).
 *
 * They are modelled this way on purpose: cash gets the same enable/disable, the same per-locale
 * label, the same icon and the same position in the merged list as a card method, instead of a
 * second mechanism running beside the first. Both are existing legacy `payment_method` values, so
 * nothing about the customer-facing contract changes.
 *
 * There is no money movement to start and no callback to verify — an order placed this way is
 * confirmed by a human, not by a gateway — so `initiate()` returns "no redirect" and
 * `verifyCallback()` refuses. A callback arriving for one of these is either a misconfigured
 * merchant portal or a probe; both deserve a 403 and a log line, not an attempt at verification.
 */
final class OfflineProvider implements PaymentProvider
{
    public const COD = 'cod';

    public const WHATSAPP = 'whatsapp';

    public function __construct(private readonly string $key)
    {
        if (! in_array($key, [self::COD, self::WHATSAPP], true)) {
            throw new RuntimeException("OfflineProvider serves cod and whatsapp, not [{$key}].");
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    /** None — and the dashboard says "no credentials needed" rather than "not set". @return list<string> */
    public function credentialFields(): array
    {
        return [];
    }

    public function initiate(PaymentIntent $intent, ProviderCredentials $credentials): InitiationResult
    {
        // Nothing to redirect to: the order is placed and settled offline.
        return new InitiationResult(true, null, null, null, ['offline' => true]);
    }

    /** @param array<string, mixed> $payload */
    public function verifyCallback(array $payload, ProviderCredentials $credentials): CallbackVerdict
    {
        return CallbackVerdict::invalidSignature();
    }

    public function resolveStatus(string $providerReference, ProviderCredentials $credentials): PaymentOutcome
    {
        return PaymentOutcome::Unknown;
    }
}
