<?php

use App\Compat\CompatCart;
use App\Compat\CompatCheckout;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payment\PaymentInitiator;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * ── Paymob credentials never leave the encrypted column (checked 2026-09-24) ──────────────────
 *
 * Sentinel credentials go in through the REAL dashboard routes. Then every response that could carry
 * them is collected: the dashboard as admin and as data-entry, every GET screen and its CSV export,
 * every parameter-free API GET, the activity log, and a log file written while an intention and a
 * callback FAIL. Each is searched for any 8-character window of any sentinel, so a masked,
 * truncated or prefixed copy counts as a leak, not only the whole value.
 *
 * One exception is permitted and asserted: the public key in the unified-checkout URL a buyer is
 * redirected to. Paymob's hosted page is addressed by it (`?publicKey=`), so a browser must be sent
 * it. The secret key and the HMAC secret have no exception anywhere.
 *
 * The collected bodies are also written to storage/framework/testing/leak-evidence/ so the search
 * can be repeated from outside this file.
 */

// Split below 32 characters: the pre-commit hook scans this file and blocks a whole key-shaped literal.
const LEAK_SECRET_A = 'SkQ7zP3lW9xV2nC8rB'.'4tM6yH1jD5fG0kSntA';
const LEAK_PUBLIC_A = 'PkR4tY8uI2oP6aS0dF'.'3gH7jK1lZ5xC9vSntA';
const LEAK_HMAC_A = 'HmE1A7C3F9B5D2E8C4'.'A6F0B3D7E1C5A9SntA';
const LEAK_SECRET_B = 'SkW2eR6tY0uI4oP8aS'.'1dF5gH9jK3lZ7xSntB';
const LEAK_PUBLIC_B = 'PkM9nB5vC1xZ7lK3jH'.'8gF2dS6aP0oI4uSntB';
const LEAK_HMAC_B = 'HmB8D4F0A6C2E9B5D1'.'F7A3C8E4B0D6F2SntB';

/** @return list<string> */
function leakSecrets(bool $withPublic = true): array
{
    return $withPublic
        ? [LEAK_SECRET_A, LEAK_PUBLIC_A, LEAK_HMAC_A, LEAK_SECRET_B, LEAK_PUBLIC_B, LEAK_HMAC_B]
        : [LEAK_SECRET_A, LEAK_HMAC_A, LEAK_SECRET_B, LEAK_HMAC_B];
}

/**
 * Every 8-character window of every sentinel that appears in the text.
 *
 * @param  list<string>  $secrets
 * @return list<string>
 */
function leakedFragments(string $text, array $secrets): array
{
    $found = [];
    foreach ($secrets as $secret) {
        for ($i = 0; $i + 8 <= strlen($secret); $i++) {
            $window = substr($secret, $i, 8);
            if (str_contains($text, $window) || str_contains($text, urlencode($window))) {
                $found[] = $window;
            }
        }
    }

    return array_values(array_unique($found));
}

function leakEvidence(string $name, string $body): void
{
    $dir = storage_path('framework/testing/leak-evidence');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $body);
}

/** Put sentinel set A in, then rotate to set B — both through the dashboard's own routes. */
function leakContract(): int
{
    DB::table('storefront_payment_providers')->where('storefront_id', 1)->delete();
    $admin = Staff::admin();

    actingAs($admin)->post('/manage/storefronts/1/payments/providers', [
        'provider' => 'paymob', 'is_enabled' => true,
        'credentials' => ['secret_key' => LEAK_SECRET_A, 'public_key' => LEAK_PUBLIC_A, 'hmac_secret' => LEAK_HMAC_A],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $id = T::int(DB::table('storefront_payment_providers')->where('storefront_id', 1)->where('provider', 'paymob')->value('id'));

    actingAs($admin)->put("/manage/storefronts/1/payments/providers/{$id}", [
        'is_enabled' => true,
        'credentials' => ['secret_key' => LEAK_SECRET_B, 'public_key' => LEAK_PUBLIC_B, 'hmac_secret' => LEAK_HMAC_B],
    ])->assertRedirect()->assertSessionHasNoErrors();

    return $id;
}

it('stores the credentials ENCRYPTED: the raw column holds none of them', function () {
    $id = leakContract();

    $raw = T::str(DB::table('storefront_payment_providers')->where('id', $id)->value('credentials'));
    leakEvidence('01-raw-column.txt', $raw);

    // Readable through the model (so the rotation really stored set B)…
    $provider = StorefrontPaymentProvider::query()->findOrFail($id);
    expect($provider->getAttribute('credentials'))->toBe(['secret_key' => LEAK_SECRET_B, 'public_key' => LEAK_PUBLIC_B, 'hmac_secret' => LEAK_HMAC_B])
        // …and not in the bytes MySQL holds, which is what a dump copies.
        ->and(leakedFragments($raw, leakSecrets()))->toBe([])
        ->and(json_decode((string) base64_decode($raw, true), true))->toHaveKeys(['iv', 'value', 'mac']);
});

it('sends the payments screen NO part of a key, as admin; data-entry gets no screen at all', function () {
    leakContract();

    $admin = actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk();
    $page = json_encode($admin->viewData('page'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    leakEvidence('02-payments-props-admin.json', $page);

    $entry = actingAs(Staff::dataEntry())->get('/manage/storefronts/1/payments');
    leakEvidence('03-payments-dataentry.txt', $entry->status()."\n".$entry->getContent());

    expect(leakedFragments($page, leakSecrets()))->toBe([])
        ->and($page)->toContain('credentials_complete')   // the screen CAN say "set"…
        ->and($entry->status())->toBe(403)
        ->and(leakedFragments((string) $entry->getContent(), leakSecrets()))->toBe([]);
});

it('records THAT the keys changed in the activity log, never what they were or became', function () {
    $id = leakContract();

    $rows = DB::table('core_activity_log')->where('subject_type', 'storefront_payment_providers')
        ->where('subject_id', $id)->get();
    $dump = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    leakEvidence('04-activity-log.json', $dump);

    // The activity screen renders the same rows; it is searched too.
    $screen = (string) actingAs(Staff::admin())->get('/manage/activity')->getContent();

    expect($rows)->toHaveCount(2)                        // created, then the rotation
        ->and($dump)->toContain('credentials')
        ->and(leakedFragments($dump, leakSecrets()))->toBe([])
        ->and(leakedFragments($screen, leakSecrets()))->toBe([]);
});

it('carries no part of a key in ANY dashboard GET screen or its CSV export', function () {
    $contractId = leakContract();
    $admin = Staff::admin();

    // A PAID order with a real attempt row, settled by a callback signed with the dashboard's own
    // HMAC secret — so the order page and the settlement export have payment data to render.
    PaymentFixture::method(StorefrontPaymentProvider::query()->findOrFail($contractId), 'card', integrationId: '4001');
    $paidOrder = PaymentFixture::order(100.0);
    $code = T::str(DB::table('storefronts')->where('id', 1)->value('code'));
    get('/api/pay/'.$code.'/paymob/callback?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($paidOrder), 10000, secret: LEAK_HMAC_B)
    ));
    expect(DB::table('payment_statuses')->where('order_id', $paidOrder)->exists())->toBeTrue();

    $visited = [];
    $skipped = [];
    $hits = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDef $route */
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'manage') || str_contains($uri, 'logout')) {
            continue;
        }
        $path = str_replace('{storefront}', '1', $uri);
        // Screens that need an id get the first real one, so the ORDER page — which lists payment
        // attempts — is searched rather than skipped.
        $path = str_replace('{order}', (string) $paidOrder, $path);
        foreach (['{customer}' => 'users', '{product}' => 'catalog_products', '{blog}' => 'blogs', '{promotion}' => 'promotion_rules'] as $param => $table) {
            if (str_contains($path, $param)) {
                $first = DB::table($table)->orderBy('id')->value('id');
                if ($first !== null) {
                    $path = str_replace($param, (string) T::int($first), $path);
                }
            }
        }
        if (str_contains($path, '{')) {
            $skipped[] = $uri;

            continue;
        }
        foreach (['', '?export=csv'] as $suffix) {
            $response = actingAs($admin)->get('/'.$path.$suffix);
            $body = $response->baseResponse instanceof StreamedResponse
                ? (function () use ($response): string {
                    ob_start();
                    $response->baseResponse->sendContent();

                    return (string) ob_get_clean();
                })()
                : (string) $response->getContent();
            $visited[] = $response->baseResponse->getStatusCode().' /'.$path.$suffix.' ('.strlen($body).' bytes)';
            if ($suffix === '' && str_contains($path, 'orders/'.$paidOrder)) {
                leakEvidence('05b-paid-order-page.html', $body);
            }
            foreach (leakedFragments($body, leakSecrets()) as $fragment) {
                $hits[] = '/'.$path.$suffix.' → '.$fragment;
            }
        }
    }
    leakEvidence('05-manage-routes.txt', "VISITED\n".implode("\n", $visited)."\n\nSKIPPED (need an id)\n".implode("\n", $skipped)."\n\nHITS\n".implode("\n", $hits));

    expect(count($visited))->toBeGreaterThan(40)
        ->and($hits)->toBe([]);
});

it('carries no part of a key in any parameter-free API GET', function () {
    leakContract();
    config(['compat.api_key' => 'leak-test-api-code']);

    $visited = [];
    $hits = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/') || str_contains($uri, '{')) {
            continue;
        }
        $response = withHeaders(['Api-Code' => 'leak-test-api-code'])->get('/'.$uri);
        $body = (string) $response->getContent();
        $visited[] = $response->status().' /'.$uri;
        foreach (leakedFragments($body, leakSecrets()) as $fragment) {
            $hits[] = '/'.$uri.' → '.$fragment;
        }
    }
    leakEvidence('06-api-routes.txt', "VISITED\n".implode("\n", $visited)."\n\nHITS\n".implode("\n", $hits));

    expect(count($visited))->toBeGreaterThan(10)
        ->and($hits)->toBe([]);
});

it('sends a buyer ONLY the public key, and writes no key, token or Authorization header to the log when an intention or a callback FAILS', function () {
    $contractId = leakContract();
    // The contract the DASHBOARD created — `PaymentFixture::paymob()` would reset its keys.
    PaymentFixture::method(StorefrontPaymentProvider::query()->findOrFail($contractId), 'card', integrationId: '4001');

    $logFile = storage_path('framework/testing/leak-evidence/07-log.log');
    @unlink($logFile);
    config(['logging.channels.leak' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('leak');

    $apiHost = HttpRequest::create('https://api.watchizereg.com/api/add_order', 'POST');
    $initiator = app(PaymentInitiator::class);
    $billing = ['first_name' => 'A', 'last_name' => 'B', 'phone' => '01000000000'];

    // ONE scripted fake: `Http::fake()` called again APPENDS stubs, so the first would keep answering.
    $script = [
        fn () => Http::response(['client_secret' => 'cs_leak_probe', 'id' => 'intent_1'], 200),
        // A REFUSED intention whose error body even echoes the token back — the worst case.
        fn () => Http::response(['detail' => 'Invalid token Bearer '.LEAK_SECRET_B], 401),
        fn () => throw new ConnectionException('cURL error 28: timed out'),
    ];
    Http::fake(['*/intention/' => function () use (&$script) {
        $next = array_shift($script);

        return $next === null ? Http::response([], 500) : $next();
    }]);

    // 1. A SUCCESSFUL intention: what the buyer's browser is sent.
    $ok = $initiator->initiate($apiHost, 1, 'ar', 'card', 91, 'WZ-91', 100.0, $billing);
    $sentTo = [];
    Http::assertSent(function (ClientRequest $request) use (&$sentTo): bool {
        $sentTo[] = $request->url().' Authorization='.json_encode($request->header('Authorization'));

        return true;
    });

    // 2. Refused.
    $refused = $initiator->initiate($apiHost, 1, 'ar', 'card', 92, 'WZ-92', 100.0, $billing);

    // 3. Paymob unreachable.
    $unreachable = $initiator->initiate($apiHost, 1, 'ar', 'card', 93, 'WZ-93', 100.0, $billing);

    // 4. Callbacks that FAIL verification, by both methods Paymob uses.
    $order = PaymentFixture::order(100.0);
    $code = T::str(DB::table('storefronts')->where('id', 1)->value('code'));
    $cbGet = get('/api/pay/'.$code.'/paymob/callback?obj.order.merchant_order_id='.$order.'&success=true&amount_cents=10000&hmac=deadbeef');
    $cbPost = withHeaders(['Authorization' => 'Bearer '.LEAK_SECRET_B])
        ->postJson('/api/pay/'.$code.'/paymob/callback?hmac=deadbeef', ['type' => 'TRANSACTION', 'obj' => ['id' => 1, 'success' => true]]);

    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    $buyerUrl = (string) $ok?->checkoutUrl;
    leakEvidence('07-intentions-and-callbacks.txt', implode("\n", [
        'SENT TO PAYMOB (the only place the secret is meant to go):', ...$sentTo, '',
        'BUYER REDIRECT: '.$buyerUrl,
        'REFUSED: ok='.var_export($refused?->ok, true).' reason='.(string) $refused?->failureReason.' raw='.json_encode($refused?->raw),
        'UNREACHABLE: ok='.var_export($unreachable?->ok, true).' reason='.(string) $unreachable?->failureReason.' raw='.json_encode($unreachable?->raw),
        'CALLBACK GET: '.$cbGet->status().' '.$cbGet->headers->get('Location'),
        'CALLBACK POST: '.$cbPost->status().' '.$cbPost->getContent(),
        '', 'LOG ('.strlen($log).' bytes):', $log,
    ]));

    expect($ok?->ok)->toBeTrue()
        // the exception, stated: the public key is in the hosted-checkout address…
        ->and($buyerUrl)->toContain('publicKey='.LEAK_PUBLIC_B)
        // …and neither the secret nor the HMAC secret is
        ->and(leakedFragments($buyerUrl, leakSecrets(withPublic: false)))->toBe([])
        ->and(leakedFragments((string) $refused?->failureReason.' raw='.json_encode($refused?->raw), leakSecrets()))->toBe([])
        ->and(leakedFragments((string) $unreachable?->failureReason.' raw='.json_encode($unreachable?->raw), leakSecrets()))->toBe([])
        ->and(leakedFragments($cbGet->headers->get('Location').$cbGet->getContent(), leakSecrets()))->toBe([])
        ->and(leakedFragments((string) $cbPost->getContent(), leakSecrets()))->toBe([])
        ->and($log)->not->toBe('')                        // something WAS logged, so the search means something
        ->and(leakedFragments($log, leakSecrets()))->toBe([])
        ->and(stripos($log, 'Authorization'))->toBeFalse()
        ->and(stripos($log, 'Bearer'))->toBeFalse()
        ->and(str_contains($log, 'cs_leak_probe'))->toBeFalse();
});

it('shows what the WAVE-3 fallback (no contract yet — production today) sends a buyer and the log when Paymob fails', function () {
    DB::table('storefront_payment_providers')->where('storefront_id', 1)->delete();
    config([
        'services.paymob.secret_key' => LEAK_SECRET_A,
        'services.paymob.public_key' => LEAK_PUBLIC_A,
        'services.paymob.payment_methods' => [4001],
    ]);

    $logFile = storage_path('framework/testing/leak-evidence/08-log.log');
    @unlink($logFile);
    config(['logging.channels.leak3' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('leak3');

    $script = [
        // Refused, and the body quotes the token back: the worst case a provider can do.
        fn () => Http::response(['detail' => 'Invalid token: Token '.LEAK_SECRET_A], 401),
        fn () => throw new ConnectionException('cURL error 28: timed out'),
    ];
    Http::fake(['*/intention/' => function () use (&$script) {
        $next = array_shift($script);

        return $next === null ? Http::response([], 500) : $next();
    }]);

    $checkout = new CompatCheckout(new CompatCart(1), app(InventoryService::class), 1);
    $refused = $checkout->createPaymobIntention(100.0, ['first_name' => 'A'], 94);

    // What `add_order` does with a thrown exception, with argument capture ON as the worst case.
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $checkout->createPaymobIntention(100.0, ['first_name' => 'A'], 95);
    } catch (Throwable $e) {
        Log::error($e);
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }

    $toBuyer = json_encode(['paymob_error' => $refused['error'] ?? null], JSON_UNESCAPED_SLASHES) ?: '';
    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    leakEvidence('08-wave3-fallback.txt', "TO BUYER (add_order's paymob_error): {$toBuyer}\n\nLOG (".strlen($log)." bytes):\n{$log}");

    expect($log)->not->toBe('')
        ->and(leakedFragments($log, leakSecrets()))->toBe([])
        ->and(leakedFragments($toBuyer, leakSecrets()))->toBe([]);
});
