<?php

/*
 * Paymob intention `expiration` — measure the unit (2026-09-27). Run from the core directory:
 *     php ~/paymob-expiry-probe.php
 * Creates two 1-EGP card intentions on storefront 1's LIVE Paymob contract (one with
 * expiration=120, one without), prints Paymob's answer and the lifetime embedded in each payment
 * key. Prints no credential; the checkout links it prints carry each test intention's client secret,
 * which can do nothing but pay that 1 EGP. Nothing is paid; the intentions simply expire.
 */

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$contract = StorefrontPaymentProvider::query()->where('storefront_id', 1)->where('provider', 'paymob')->first();
$credentials = $contract?->getAttribute('credentials');
if (! is_array($credentials) || empty($credentials['secret_key'])) {
    exit("No live Paymob contract with a secret key on storefront 1.\n");
}
$card = DB::table('storefront_payment_methods')
    ->where('storefront_payment_provider_id', $contract->getKey())->where('method', 'card')->value('integration_id');
if (! is_numeric($card)) {
    exit("No card integration id on the contract.\n");
}

/** The payload of a Paymob payment key: a JWT, sometimes base64-wrapped once more. */
function keyClaims(string $key): ?array
{
    $jwt = str_starts_with($key, 'eyJ') ? $key : (string) base64_decode($key, true);
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }
    $json = base64_decode(strtr($parts[1], '-_', '+/').str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
    $claims = is_string($json) ? json_decode($json, true) : null;

    return is_array($claims) ? $claims : null;
}

foreach ([120, null] as $expiration) {
    $body = [
        'amount' => 100,
        'currency' => 'EGP',
        'payment_methods' => [(int) $card],
        'items' => [],
        'billing_data' => ['first_name' => 'Expiry', 'last_name' => 'Probe', 'phone_number' => '01000000000',
            'email' => 'probe@example.com', 'street' => '-', 'city' => 'Cairo', 'country' => 'EG'],
        'special_reference' => 'EXPIRY-PROBE-'.time().'-'.($expiration ?? 'none'),
    ];
    if ($expiration !== null) {
        $body['expiration'] = $expiration;
    }
    $sentAt = time();
    $response = Http::withToken($credentials['secret_key'])->asJson()->timeout(30)
        ->post('https://accept.paymob.com/v1/intention/', $body);

    echo "\n== expiration sent: ".($expiration ?? '(none)')." — HTTP {$response->status()}\n";
    if (! $response->successful()) {
        echo 'Refused: '.mb_substr((string) $response->body(), 0, 300)."\n";

        continue;
    }
    echo 'intention id: '.$response->json('id').'  status: '.$response->json('status').'  created: '.$response->json('created')."\n";
    foreach ((array) $response->json('payment_keys') as $pk) {
        $claims = is_array($pk) && is_string($pk['key'] ?? null) ? keyClaims($pk['key']) : null;
        $exp = is_array($claims) && is_numeric($claims['exp'] ?? null) ? (int) $claims['exp'] : null;
        echo '  payment key for integration '.($pk['integration'] ?? '?').': '
            .($exp === null ? 'no exp claim readable' : 'expires in '.($exp - $sentAt).' s ('.gmdate('Y-m-d H:i:s', $exp).' UTC)')."\n";
    }
    echo '  open within 1 min, and again after 3 min (DO NOT PAY): https://accept.paymob.com/unifiedcheckout/?publicKey='
        .urlencode((string) ($credentials['public_key'] ?? '')).'&clientSecret='.urlencode((string) $response->json('client_secret'))."\n";
}
