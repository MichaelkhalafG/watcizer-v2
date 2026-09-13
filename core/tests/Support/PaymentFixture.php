<?php

namespace Tests\Support;

use App\Domain\Payment\PaymobProvider;
use App\Models\Storefront\StorefrontPaymentMethod;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;

/**
 * Contracts, methods and Paymob-signed payloads for the wave-4C payment tests.
 *
 * The HMAC is computed here the way Paymob computes it — twenty fields, in Paymob's documented
 * order, concatenated and signed with sha512 — so a test can produce a payload that is genuinely
 * valid for a given secret. Anything less would test the controller against its own verifier.
 */
final class PaymentFixture
{
    /** The twenty signed fields, in order. Kept here deliberately, so the test does not import the implementation's list. */
    private const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order', 'owner', 'pending',
        'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
    ];

    /** A Paymob contract with credentials, enabled, on one storefront. */
    public static function paymob(int $storefrontId = 1, string $hmacSecret = 'test-hmac-secret', bool $enabled = true): StorefrontPaymentProvider
    {
        $provider = StorefrontPaymentProvider::query()->updateOrCreate(
            ['storefront_id' => $storefrontId, 'provider' => PaymobProvider::KEY],
            [
                'is_enabled' => $enabled,
                'credentials' => [
                    'secret_key' => 'test-secret-key',
                    'public_key' => 'test-public-key',
                    'hmac_secret' => $hmacSecret,
                ],
                'settings' => null,
            ],
        );

        return $provider;
    }

    /** A contract with NO credentials — the "cannot verify anything" case. */
    public static function paymobWithoutCredentials(int $storefrontId = 1): StorefrontPaymentProvider
    {
        return StorefrontPaymentProvider::query()->updateOrCreate(
            ['storefront_id' => $storefrontId, 'provider' => PaymobProvider::KEY],
            ['is_enabled' => true, 'credentials' => null, 'settings' => null],
        );
    }

    /** A method under a contract, with a label in both locales. */
    public static function method(
        StorefrontPaymentProvider $provider,
        string $method = 'card',
        ?string $integrationId = '4001',
        int $sort = 0,
        bool $enabled = true,
        string $labelAr = 'بطاقة',
        string $labelEn = 'Card',
    ): StorefrontPaymentMethod {
        $row = StorefrontPaymentMethod::query()->updateOrCreate(
            ['storefront_payment_provider_id' => $provider->getAttribute('id'), 'method' => $method],
            ['integration_id' => $integrationId, 'icon' => null, 'is_enabled' => $enabled, 'sort' => $sort, 'settings' => null],
        );

        foreach (['ar' => $labelAr, 'en' => $labelEn] as $locale => $label) {
            DB::table('storefront_payment_method_translations')->updateOrInsert(
                ['storefront_payment_method_id' => $row->getAttribute('id'), 'locale' => $locale],
                ['label' => $label],
            );
        }

        return $row->fresh() ?? $row;
    }

    /** An order with a known total, on a known storefront. */
    public static function order(float $total = 100.0, ?int $storefrontId = 1, string $status = 'pending'): int
    {
        $addressId = T::int(DB::table('addresses')->orderBy('id')->value('id'));

        return (int) DB::table('orders')->insertGetId([
            'user_id' => null,
            'address_id' => $addressId,
            'storefront_id' => $storefrontId,
            'total_price_for_order' => number_format($total, 2, '.', ''),
            'payment_method' => 'card',
            'order_number' => 'ZZ'.random_int(1000000, 9999999),
            'status' => $status,
            'guest_name' => 'payment-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function orderNumber(int $orderId): string
    {
        return T::str(DB::table('orders')->where('id', $orderId)->value('order_number'));
    }

    /**
     * A Paymob callback payload, signed with `$secret` so it genuinely verifies.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function callback(
        string $orderReference,
        int $amountMinor,
        bool $success = true,
        string $secret = 'test-hmac-secret',
        int $transactionId = 987654,
        string $integrationId = '4001',
        array $overrides = [],
    ): array {
        $payload = array_merge([
            'amount_cents' => (string) $amountMinor,
            'created_at' => '2026-09-12T10:00:00.000000',
            'currency' => 'EGP',
            'error_occured' => 'false',
            'has_parent_transaction' => 'false',
            'id' => (string) $transactionId,
            'integration_id' => $integrationId,
            'is_3d_secure' => 'true',
            'is_auth' => 'false',
            'is_capture' => 'false',
            'is_refunded' => 'false',
            'is_standalone_payment' => 'true',
            'is_voided' => 'false',
            'order' => '55555',
            'owner' => '12345',
            'pending' => 'false',
            'source_data_pan' => '2346',
            'source_data_sub_type' => 'MasterCard',
            'source_data_type' => 'card',
            'success' => $success ? 'true' : 'false',
            // The UNSIGNED field that selects the order — which is exactly why the storefront
            // ownership check exists (study §3.9.2 check 3).
            'merchant_order_id' => $orderReference,
        ], $overrides);

        $concatenated = '';
        foreach (self::HMAC_FIELDS as $field) {
            $concatenated .= self::field($payload, $field);
        }
        $payload['hmac'] = hash_hmac('sha512', $concatenated, $secret);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function field(array $payload, string $key): string
    {
        $flat = str_starts_with($key, 'source_data.')
            ? 'source_data_'.substr($key, strlen('source_data.'))
            : $key;
        $value = $payload[$flat] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
