<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Compat\CompatCheckout;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Paymob — the first implementation of {@see PaymentProvider} (study §3.9.3).
 *
 * ── What is reused, and why that matters ─────────────────────────────────────────────────────
 *
 * The HMAC field list and the field extraction are NOT rewritten here: they come from
 * {@see CompatCheckout::paymobField()}, which wave 3 built and the wave-3 review exercised. Paymob
 * signs twenty fields in a documented order, and the same callback arrives in two shapes — nested
 * (`obj.order.id`) from the server-to-server call and flattened (`order`, `source_data_pan`) from
 * the browser redirect. A second copy of that list would be a second thing to get wrong, and the
 * bug would be "signature invalid on the redirect only", which looks like a Paymob outage.
 *
 * What IS new here is where the secret comes from. Wave 3 read `config('services.paymob.*')` — one
 * global account. This reads the CONTRACT's own credentials, passed in, so two storefronts holding
 * two Paymob accounts cannot validate each other's callbacks (§2.19, §3.9.2 check 2).
 *
 * ── What this class deliberately does not decide ─────────────────────────────────────────────
 *
 * Nothing. `verifyCallback()` reports the signature, the transaction id, the authoritative amount,
 * the success flag, the integration id and the merchant reference. Whether the amount matches the
 * order, whether the order belongs to this storefront, whether this transaction was already
 * recorded — all of that is the controller's, once, for every provider (§3.9.3).
 */
final class PaymobProvider implements PaymentProvider
{
    public const KEY = 'paymob';

    /** The intention endpoint and the unified-checkout page, as Paymob documents them. */
    private const INTENTION_URL = 'https://accept.paymob.com/v1/intention/';

    private const CHECKOUT_URL = 'https://accept.paymob.com/unifiedcheckout/';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Three secrets per contract, and the dashboard renders them in this order.
     *
     * `secret_key` authenticates the intention call, `public_key` builds the checkout URL, and
     * `hmac_secret` verifies callbacks. The fourth wave-3 value — `payment_methods`, the integration
     * ids — is NOT a credential: it moves to `storefront_payment_methods.integration_id`, one row
     * per method, which is the whole point of the two-level shape.
     *
     * @return list<string>
     */
    public function credentialFields(): array
    {
        return ['secret_key', 'public_key', 'hmac_secret'];
    }

    /**
     * Create an intention for ONE method and return its unified-checkout URL.
     *
     * The method's `integration_id` is what routes the customer to card, valU or Tamara inside one
     * Paymob account — which is why the intent carries the method (§3.9.3) rather than the caller
     * passing a second argument that might disagree with what was recorded.
     */
    public function initiate(PaymentIntent $intent, ProviderCredentials $credentials): InitiationResult
    {
        try {
            $secret = $credentials->require('secret_key');
            $public = $credentials->require('public_key');
        } catch (Throwable $e) {
            // Names the missing key, never a value.
            return InitiationResult::failed($e->getMessage());
        }

        $integrationId = $intent->integrationId;
        if ($integrationId === null || trim($integrationId) === '') {
            return InitiationResult::failed(
                "The payment method [{$intent->method}] has no integration id, so Paymob cannot route it."
            );
        }

        $billing = $intent->billing;
        $payload = [
            'amount' => $intent->amountMinor,
            'currency' => $intent->currency,
            'payment_methods' => [is_numeric($integrationId) ? (int) $integrationId : $integrationId],
            // Paymob echoes this back in the signed callback; it is how the callback finds the order.
            'special_reference' => $intent->orderNumber,
            'items' => [],
            'billing_data' => [
                'first_name' => self::str($billing, 'first_name', 'Customer'),
                'last_name' => self::str($billing, 'last_name', '-'),
                'phone_number' => self::str($billing, 'phone', '-'),
                'email' => self::str($billing, 'email', 'no-reply@example.com'),
                'street' => self::str($billing, 'street', '-'),
                'city' => self::str($billing, 'city', '-'),
                'country' => self::str($billing, 'country', 'EG'),
            ],
            'extras' => ['order_id' => $intent->orderId, 'storefront_id' => $intent->storefrontId],
        ];
        if ($intent->returnUrl !== null) {
            $payload['redirection_url'] = $intent->returnUrl;
        }

        try {
            $response = Http::withToken($secret)->asJson()->timeout(30)->post(self::INTENTION_URL, $payload);
        } catch (Throwable $e) {
            return InitiationResult::failed('Paymob could not be reached: '.mb_substr($e->getMessage(), 0, 120));
        }

        if (! $response->successful()) {
            // The body may quote back what was sent; it is NOT logged here, and the caller records
            // only the status. A provider error body has no business in a log line next to a key.
            return InitiationResult::failed('Paymob refused the intention (HTTP '.$response->status().').');
        }

        $token = $response->json('client_secret');
        if (! is_string($token) || $token === '') {
            return InitiationResult::failed('Paymob returned no client secret.');
        }

        $reference = $response->json('id');

        return InitiationResult::redirect(
            self::CHECKOUT_URL.'?publicKey='.urlencode($public).'&clientSecret='.urlencode($token),
            is_scalar($reference) ? (string) $reference : null,
            ['status' => $response->status()],
        );
    }

    /**
     * Verify the twenty-field HMAC with THIS contract's secret and report what the payload says.
     *
     * Fails closed: no secret, no signature, or a mismatch is `signatureValid: false` and the
     * controller answers 403 without writing anything.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload, ProviderCredentials $credentials): CallbackVerdict
    {
        if (! $credentials->has('hmac_secret')) {
            return CallbackVerdict::invalidSignature();
        }
        $received = CompatCheckout::paymobField($payload, 'hmac');
        if ($received === '') {
            /** @var mixed $raw */
            $raw = data_get($payload, 'hmac');
            $received = is_scalar($raw) ? (string) $raw : '';
        }
        if ($received === '') {
            return CallbackVerdict::invalidSignature();
        }

        $concatenated = '';
        foreach (self::HMAC_FIELDS as $field) {
            $concatenated .= CompatCheckout::paymobField($payload, $field);
        }
        $valid = hash_equals(
            hash_hmac('sha512', $concatenated, $credentials->require('hmac_secret')),
            $received,
        );

        if (! $valid) {
            return CallbackVerdict::invalidSignature();
        }

        $successField = CompatCheckout::paymobField($payload, 'success');
        $amount = CompatCheckout::paymobField($payload, 'amount_cents');
        $transaction = CompatCheckout::paymobField($payload, 'id');
        $integration = CompatCheckout::paymobField($payload, 'integration_id');

        // The merchant reference: `special_reference` on the intention API, `merchant_order_id` on
        // the older order API. Both are checked because a contract may still be on either.
        $reference = '';
        foreach (['obj.order.merchant_order_id', 'order.merchant_order_id', 'merchant_order_id', 'obj.order.id', 'order'] as $candidate) {
            /** @var mixed $value */
            $value = data_get($payload, $candidate);
            if (is_scalar($value) && (string) $value !== '') {
                $reference = (string) $value;
                break;
            }
        }

        // `order` is one of the twenty SIGNED fields, unlike `merchant_order_id` (🟡-5).
        $signedOrder = CompatCheckout::paymobField($payload, 'order');

        return new CallbackVerdict(
            signatureValid: true,
            providerTransactionId: $transaction === '' ? null : $transaction,
            amountMinor: is_numeric($amount) ? (int) $amount : null,
            isSuccess: $successField === 'true' || $successField === '1',
            integrationId: $integration === '' ? null : $integration,
            orderReference: $reference === '' ? null : $reference,
            signedOrderReference: $signedOrder === '' ? null : $signedOrder,
            /*
             * The WHOLE payload, not one field. `CallbackPolicy::outcomeFromPaymob()` needs
             * `is_refunded`, `is_voided` and `pending` to tell a refund from a sale — with only
             * `status` in here it would have read every reversal as a success, which is the
             * defect 🟡-4 names.
             */
            raw: $payload,
        );
    }

    /**
     * Ask Paymob about a reference.
     *
     * Implemented against the transaction endpoint and UNPROVEN locally, for the same reason wave 3
     * recorded of the intention call: the keys are developer-handled and never in this repository,
     * so this branch cannot be exercised here. It fails to `Unknown` rather than guessing.
     */
    public function resolveStatus(string $providerReference, ProviderCredentials $credentials): PaymentOutcome
    {
        if (! $credentials->has('secret_key')) {
            return PaymentOutcome::Unknown;
        }

        try {
            $response = Http::withToken($credentials->require('secret_key'))->timeout(20)
                ->get('https://accept.paymob.com/api/acceptance/transactions/'.urlencode($providerReference));
        } catch (Throwable) {
            return PaymentOutcome::Unknown;
        }

        if (! $response->successful()) {
            return PaymentOutcome::Unknown;
        }

        $success = $response->json('success');
        $pending = $response->json('pending');
        if ($pending === true) {
            return PaymentOutcome::Pending;
        }

        return $success === true ? PaymentOutcome::Paid : PaymentOutcome::Failed;
    }

    /**
     * The twenty fields Paymob signs, in Paymob's documented order.
     *
     * Duplicated nowhere: {@see CompatCheckout::isValidPaymobHmac()} holds the same list for the
     * wave-3 global path, and both call the same extractor. When the alias route retires
     * (§3.9.2) that path goes and this becomes the only copy.
     *
     * @var list<string>
     */
    private const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order', 'owner', 'pending',
        'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
    ];

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
