<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Support\Coerce;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MyFatoorah — the second implementation of {@see PaymentProvider} (Brand Fashion, 2026-11-12, Block B).
 *
 * ── The flow: MyFatoorah's hosted payment page, as the old site and as Paymob ──────────────────
 *
 *   initiate   InitiatePayment (the account's ENABLED methods, read now — never a list in code) → the method whose
 *              `PaymentMethodCode` is this dashboard row's integration id (`mz` Meeza, `mw` wallets, … — the codes the
 *              account answers with), or whose `PaymentMethodId` it is when the account lists a code twice (pick())
 *              → ExecutePayment → its `PaymentURL`: the shopper is redirected there;
 *   return     MyFatoorah sends the shopper back to `CallBackUrl` / `ErrorUrl` with `?paymentId=…` — an UNSIGNED
 *              GET: nothing in it is trusted;
 *   webhook    a POST with `Data` (the event) and the `MyFatoorah-Signature` header, configured in the portal;
 *   verify     BOTH arrive at {@see verifyCallback()}, which takes only the payment's id from the request and asks
 *              GetPaymentStatus with THIS contract's key. That answer — the invoice's status, its value, our
 *              `CustomerReference` — is the authoritative one; a forged or replayed request can name a payment but
 *              cannot change what MyFatoorah says about it. A webhook is ALSO refused when the contract holds a
 *              webhook secret and the signature does not match it (MyFatoorah's rule: the event's fields sorted by
 *              name, case-insensitively, `key=value` joined by commas, HMAC-SHA256 with the secret, base64).
 *
 * ── The amount ─────────────────────────────────────────────────────────────────────────────────
 *
 * `InvoiceValue` is sent as the order's total and read back from GetPaymentStatus, which reports it in the ACCOUNT's
 * base currency (measured on MyFatoorah's Kuwaiti test account: an EGP 2,745 order became an invoice of 2,745 KD). The
 * shop's account must therefore be an EGP account — an Egyptian MyFatoorah account is; InitiatePayment cannot tell
 * (it repeats the currency asked about), so the switch-day runbook confirms it on the first live test payment
 * (`payments:myfatoorah-status`, `InvoiceDisplayValue`). No display currency is sent: a converted amount would
 * never equal the order's total, and the amount check would refuse every payment.
 *
 * ── Where it talks to ──────────────────────────────────────────────────────────────────────────
 *
 * `services.myfatoorah.base_url` (`MYFATOORAH_BASE_URL`), and NO default: a live key sent to the test host, or a
 * test key to the live one, fails in ways that look like an outage, so an unset host refuses to start a payment and
 * says why. Test: `https://apitest.myfatoorah.com`. Live, Egypt: the base URL MyFatoorah's portal gives the account
 * (switch-day CHECK in the runbook).
 */
final class MyFatoorahProvider implements PaymentProvider
{
    public const KEY = 'myfatoorah';

    /** The header MyFatoorah signs a webhook with; the callback controller hands it in as {@see self::HEADER_KEY}. */
    public const SIGNATURE_HEADER = 'MyFatoorah-Signature';

    /** Where the controller puts the signature header inside the payload (a key no provider field can collide with). */
    public const HEADER_KEY = '__myfatoorah_signature';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * `api_key` — the account's API token (Bearer); `webhook_secret` — the portal's webhook "secure key" (the portal's
     * webhook page, "secure key" ON). Both write-only on the dashboard and BOTH required: a contract counts as live only
     * with every field set ({@see CheckoutMethods}), and with the secret set every webhook must carry a matching
     * signature. The status call stays the authority either way.
     *
     * @return list<string>
     */
    public function credentialFields(): array
    {
        return ['api_key', 'webhook_secret'];
    }

    /**
     * The row's integration id is a MyFatoorah `PaymentMethodCode` (`vm`, `mz`, `mw`, …: letters, digits, `_` or `-`),
     * or — when the account lists a code twice — the method's `PaymentMethodId` (digits only; see pick()). Whether the
     * ACCOUNT offers it is only known by asking the account — at initiation, or from payments:myfatoorah-methods — so a
     * well-formed value is accepted here.
     */
    public function integrationIdProblem(?string $integrationId): ?string
    {
        $code = trim((string) $integrationId);
        if ($code === '') {
            return 'missing';
        }

        return preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code) === 1 ? null : 'malformed';
    }

    public function initiate(PaymentIntent $intent, ProviderCredentials $credentials): InitiationResult
    {
        try {
            $key = $credentials->require('api_key');
        } catch (Throwable $e) {
            return InitiationResult::failed($e->getMessage());
        }
        if (self::base() === '') {
            return InitiationResult::failed('MYFATOORAH_BASE_URL is not set: core does not know which MyFatoorah host this key belongs to.');
        }
        $code = trim((string) $intent->integrationId);
        if ($code === '') {
            return InitiationResult::failed("The payment method [{$intent->method}] has no MyFatoorah method code, so it cannot be routed.");
        }
        $value = round($intent->amountMinor / 100, 2);

        // 1. the account's enabled methods, now
        $methods = $this->methods($credentials, $value, $intent->currency);
        if ($methods === null) {
            return InitiationResult::failed('MyFatoorah could not be reached, or refused the methods request.');
        }
        $picked = self::pick($methods, $code);
        $method = $picked['method'];
        if ($method === null) {
            return InitiationResult::failed($picked['problem'] ?? "This MyFatoorah account does not offer the method [{$code}] now.");
        }

        // 2. the invoice for that method, and its hosted page
        $billing = $intent->billing;
        $name = trim(self::str($billing, 'first_name', '').' '.self::str($billing, 'last_name', ''));
        $mobile = preg_replace('/\D+/', '', self::str($billing, 'phone_number', self::str($billing, 'phone', ''))) ?? '';
        if (str_starts_with($mobile, '20') && strlen($mobile) === 12) {
            $mobile = substr($mobile, 2);                          // MobileCountryCode carries +20
        }
        $payload = [
            'PaymentMethodId' => Coerce::int($method['PaymentMethodId'] ?? null),
            'InvoiceValue' => $value,
            'CustomerName' => $name !== '' ? mb_substr($name, 0, 100) : 'Customer',
            'CustomerReference' => $intent->orderNumber,
            // our method's code rides on the invoice: GetPaymentStatus hands it back, and the callback maps it to the
            // method row (as Paymob's integration id) — MyFatoorah itself names only a gateway ("VISA/MASTER")
            'UserDefinedField' => (string) json_encode(['order_id' => $intent->orderId, 'storefront_id' => $intent->storefrontId, 'method' => $code]),
            'Language' => 'AR',
        ];
        $email = self::str($billing, 'email', '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $payload['CustomerEmail'] = $email;
        }
        if ($mobile !== '' && strlen($mobile) <= 11) {
            $payload['MobileCountryCode'] = '+20';
            $payload['CustomerMobile'] = $mobile;
        }
        // The shopper comes back to ONE address either way (success or error); it is not trusted (verifyCallback).
        if ($intent->redirectUrl !== null) {
            $payload['CallBackUrl'] = $intent->redirectUrl;
            $payload['ErrorUrl'] = $intent->redirectUrl;
        }

        $response = $this->post('/v2/ExecutePayment', $payload, $key);
        if ($response === null) {
            return InitiationResult::failed('MyFatoorah could not be reached.');
        }
        if (! $response->successful() || $response->json('IsSuccess') !== true) {
            // Never the body: it may quote back the customer's details.
            return InitiationResult::failed('MyFatoorah refused the payment (HTTP '.$response->status().').');
        }
        $url = $response->json('Data.PaymentURL');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return InitiationResult::failed('MyFatoorah returned no payment page.');
        }
        $invoice = $response->json('Data.InvoiceId');

        return InitiationResult::redirect($url, is_scalar($invoice) ? (string) $invoice : null, [
            'status' => $response->status(),
            'method_code' => $code,
            'method_id' => Coerce::int($method['PaymentMethodId'] ?? null),
        ]);
    }

    /**
     * Take the payment's id from the request — the shopper's return (`paymentId`) or the webhook (`Data.PaymentId`,
     * else `Data.InvoiceId`) — and report what GetPaymentStatus says about it. Fails closed: no id, no key, a webhook
     * whose signature does not match the contract's secret, or no answer is `signatureValid: false`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload, ProviderCredentials $credentials): CallbackVerdict
    {
        if (! $credentials->has('api_key')) {
            return CallbackVerdict::invalidSignature();
        }

        $data = data_get($payload, 'Data');
        $isWebhook = is_array($data);
        if ($isWebhook && $credentials->has('webhook_secret')) {
            $signature = Coerce::str($payload[self::HEADER_KEY] ?? null);
            if ($signature === '' || ! self::signatureMatches(Coerce::arr($data), $credentials->require('webhook_secret'), $signature)) {
                return CallbackVerdict::invalidSignature();
            }
        }

        [$key, $type] = self::keyOf($payload);
        if ($key === null) {
            return CallbackVerdict::invalidSignature();
        }
        $status = $this->status($credentials, $key, $type);
        if ($status === null) {
            return CallbackVerdict::invalidSignature();
        }

        $transactions = array_values(array_filter(Coerce::arr($status['InvoiceTransactions'] ?? null), 'is_array'));
        $paymentId = $type === 'PaymentId' ? $key : null;
        $mine = null;
        foreach ($transactions as $t) {
            if ($paymentId !== null && Coerce::str($t['PaymentId'] ?? null) === $paymentId) {
                $mine = $t;
            }
        }
        $mine ??= $transactions === [] ? null : $transactions[count($transactions) - 1];
        $invoiceStatus = Coerce::str($status['InvoiceStatus'] ?? null);
        $value = $status['InvoiceValue'] ?? null;
        $invoiceId = Coerce::str($status['InvoiceId'] ?? null);
        $reference = Coerce::str($status['CustomerReference'] ?? null);

        return new CallbackVerdict(
            signatureValid: true,
            // one row per PAYMENT attempt (the idempotency key): the attempt's id, else the invoice's
            providerTransactionId: $paymentId ?? (Coerce::str($mine['PaymentId'] ?? null) ?: ($invoiceId !== '' ? 'invoice:'.$invoiceId : null)),
            amountMinor: is_numeric($value) ? (int) round((float) $value * 100) : null,
            isSuccess: $invoiceStatus === 'Paid',
            integrationId: self::methodCodeOf(Coerce::str($status['UserDefinedField'] ?? null)),
            orderReference: $reference === '' ? null : $reference,
            // what MyFatoorah's own answer says, fetched with our key: as good as a signed field
            signedOrderReference: $invoiceId === '' ? null : $invoiceId,
            raw: ['status' => $status, 'webhook' => $isWebhook],
            outcome: self::outcome($invoiceStatus, $mine === null ? null : Coerce::str($mine['TransactionStatus'] ?? null)),
        );
    }

    public function resolveStatus(string $providerReference, ProviderCredentials $credentials): PaymentOutcome
    {
        if (! $credentials->has('api_key')) {
            return PaymentOutcome::Unknown;
        }
        $status = $this->status($credentials, $providerReference, 'InvoiceId');
        if ($status === null) {
            return PaymentOutcome::Unknown;
        }

        return match (Coerce::str($status['InvoiceStatus'] ?? null)) {
            'Paid' => PaymentOutcome::Paid,
            'Pending' => PaymentOutcome::Pending,
            default => PaymentOutcome::Failed,
        };
    }

    /**
     * The account's enabled methods for an amount (InitiatePayment), or null when MyFatoorah did not answer. For
     * initiation and for the dashboard's "which methods does this account have" check.
     *
     * @return list<array<string, mixed>>|null
     */
    public function methods(ProviderCredentials $credentials, float $amount, string $currency): ?array
    {
        if (! $credentials->has('api_key')) {
            return null;
        }
        $response = $this->post('/v2/InitiatePayment', ['InvoiceAmount' => $amount, 'CurrencyIso' => $currency], $credentials->require('api_key'));
        if ($response === null || ! $response->successful() || $response->json('IsSuccess') !== true) {
            return null;
        }

        $methods = [];
        foreach (Coerce::arr($response->json('Data.PaymentMethods')) as $m) {
            if (is_array($m)) {
                $methods[] = Coerce::arr($m);
            }
        }

        return $methods;
    }

    /**
     * GetPaymentStatus's `Data` for a payment id — the switch-day check of the first live test payment (its
     * `InvoiceDisplayValue` names the account's currency). Null when MyFatoorah did not answer.
     *
     * @return array<string, mixed>|null
     */
    public function statusOf(ProviderCredentials $credentials, string $paymentId): ?array
    {
        return $credentials->has('api_key') ? $this->status($credentials, $paymentId, 'PaymentId') : null;
    }

    /**
     * MyFatoorah's webhook signature: the event's fields sorted by name (case-insensitive), `key=value` joined by
     * commas (null → empty), HMAC-SHA256 with the secret, base64 — as MyFatoorah's own PHP library computes it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function signatureMatches(array $data, string $secret, string $signature): bool
    {
        uksort($data, 'strcasecmp');
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k.'='.(is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : '');
        }

        // A VERIFIER only: the received signature is DECODED and the raw HMAC compared — never an encoded signature
        // made here (TokenIssuanceTest: a file that HMACs and base64-ENCODES is a token minter, 2026-11-12)
        $given = base64_decode(trim($signature), true);

        return $given !== false && hash_equals(hash_hmac('sha256', implode(',', $pairs), $secret, true), $given);
    }

    /**
     * The account's method for a dashboard row's integration id (2026-11-12). DIGITS = a MyFatoorah `PaymentMethodId`,
     * matched exactly. Otherwise a `PaymentMethodCode`, case-insensitive — and when the account lists that code MORE
     * than once (Brand Fashion's live account: `vm` is both "Ahli Installment" id 14 and "VISA/MASTER" id 2) it FAILS
     * CLOSED and names the ids, rather than taking the first (which routed every card payment to installments).
     *
     * @param  list<array<string, mixed>>  $methods  InitiatePayment's PaymentMethods
     * @return array{method: array<string, mixed>|null, problem: string|null}
     */
    public static function pick(array $methods, string $integrationId): array
    {
        $wanted = trim($integrationId);
        $byId = $wanted !== '' && ctype_digit($wanted);
        $matches = [];
        foreach ($methods as $m) {
            $hit = $byId
                ? Coerce::int($m['PaymentMethodId'] ?? null) === (int) $wanted
                : strcasecmp(Coerce::str($m['PaymentMethodCode'] ?? null), $wanted) === 0;
            if ($hit) {
                $matches[] = $m;
            }
        }
        if ($matches === []) {
            return ['method' => null, 'problem' => $byId
                ? "This MyFatoorah account does not offer the method id [{$wanted}] now."
                : "This MyFatoorah account does not offer the method [{$wanted}] now."];
        }
        if (count($matches) > 1) {
            $ids = implode(', ', array_map(fn (array $m): string => Coerce::str($m['PaymentMethodId'] ?? null), $matches));

            return ['method' => null, 'problem' => "The MyFatoorah method code {$wanted} is ambiguous on this account: ids {$ids} — set the method id instead."];
        }

        return ['method' => $matches[0], 'problem' => null];
    }

    /** Our method code from the invoice's `UserDefinedField` (set at initiation), or null. */
    public static function methodCodeOf(string $userDefined): ?string
    {
        $decoded = json_decode($userDefined, true);
        $code = is_array($decoded) ? ($decoded['method'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code) === 1 ? $code : null;
    }

    /** What the invoice's state means for the order (the controller's decision table reads this). */
    public static function outcome(string $invoiceStatus, ?string $transactionStatus): string
    {
        if ($invoiceStatus === 'Paid') {
            return CallbackPolicy::OUTCOME_SUCCESS;
        }
        if (in_array($transactionStatus, ['Failed', 'Canceled'], true) || $invoiceStatus === 'Canceled') {
            return CallbackPolicy::OUTCOME_FAILED;
        }

        return CallbackPolicy::OUTCOME_PENDING;
    }

    /**
     * The id to ask about, and its kind: the return's `paymentId` (MyFatoorah's recommended key), a webhook's
     * `Data.PaymentId`, else a webhook's `Data.InvoiceId`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string|null, 1: string}
     */
    private static function keyOf(array $payload): array
    {
        foreach (['paymentId', 'PaymentId', 'Data.PaymentId', 'Data.Transaction.PaymentId'] as $field) {
            $v = data_get($payload, $field);
            if (is_scalar($v) && preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $v) === 1) {
                return [(string) $v, 'PaymentId'];
            }
        }
        foreach (['Data.InvoiceId', 'Data.Invoice.Id'] as $field) {
            $v = data_get($payload, $field);
            if (is_scalar($v) && ctype_digit((string) $v)) {
                return [(string) $v, 'InvoiceId'];
            }
        }

        return [null, 'PaymentId'];
    }

    /** @return array<string, mixed>|null GetPaymentStatus's `Data`, or null when MyFatoorah did not answer it */
    private function status(ProviderCredentials $credentials, string $key, string $type): ?array
    {
        $response = $this->post('/v2/GetPaymentStatus', ['Key' => $key, 'KeyType' => $type], $credentials->require('api_key'));
        if ($response === null || ! $response->successful() || $response->json('IsSuccess') !== true) {
            return null;
        }
        $data = $response->json('Data');

        return is_array($data) ? Coerce::arr($data) : null;
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, string $key): ?Response
    {
        try {
            $base = self::base();

            return $base === '' ? null : Http::withToken($key)->asJson()->acceptJson()->timeout(30)->post($base.$path, $body);
        } catch (Throwable) {
            return null;
        }
    }

    /** The API host (`MYFATOORAH_BASE_URL`), '' when unset. */
    public static function base(): string
    {
        $base = config('services.myfatoorah.base_url');

        return is_string($base) ? rtrim(trim($base), '/') : '';
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
