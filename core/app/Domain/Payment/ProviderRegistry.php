<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use RuntimeException;

/**
 * The provider constants this application knows how to serve, and how to reach one.
 *
 * A registry rather than a `match` scattered through the controllers: the dashboard renders the
 * add-a-provider list FROM here, the credential fields come from the implementation itself, and a
 * provider row whose constant nobody implements is a configuration mistake the screens can show
 * instead of a 500 a customer discovers.
 *
 * `cod` and `whatsapp` are registered with no credentials on purpose (study §3.9.1): cash and the
 * WhatsApp flow get the same enable/disable, label, icon and position as a card method rather than
 * a second mechanism running beside the first.
 */
final class ProviderRegistry
{
    /** @param array<string, PaymentProvider> $providers */
    public function __construct(private readonly array $providers) {}

    public static function default(): self
    {
        return new self([
            PaymobProvider::KEY => new PaymobProvider,
            OfflineProvider::COD => new OfflineProvider(OfflineProvider::COD),
            OfflineProvider::WHATSAPP => new OfflineProvider(OfflineProvider::WHATSAPP),
        ]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (int|string $k): string => (string) $k, array_keys($this->providers));
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @throws RuntimeException when no implementation serves that constant */
    public function get(string $key): PaymentProvider
    {
        return $this->providers[$key]
            ?? throw new RuntimeException("No payment provider implementation is registered for [{$key}].");
    }

    /** The provider serving a contract row, or null when the row names something unimplemented. */
    public function for(StorefrontPaymentProvider $row): ?PaymentProvider
    {
        $key = Coerce::str($row->getAttribute('provider'));

        return $this->has($key) ? $this->get($key) : null;
    }

    /**
     * The credential field names for a constant — for the dashboard, which renders one write-only
     * input per name and never a value.
     *
     * @return list<string>
     */
    public function credentialFields(string $key): array
    {
        return $this->has($key) ? $this->get($key)->credentialFields() : [];
    }
}
