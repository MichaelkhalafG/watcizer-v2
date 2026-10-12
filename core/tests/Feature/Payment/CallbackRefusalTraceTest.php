<?php

use App\Models\Storefront\Storefront;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Tests\Support\PaymentFixture;
use Tests\Support\Scratch;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * A REFUSED callback must leave a trace that survives the log level — the detection fix of
 * 2026-10-12.
 *
 * For eighteen days after the 2026-09-23 flip every real Paymob callback was refused at the
 * signature (the stored `hmac_secret` was not the one Paymob signed with). The controller DID log
 * each refusal — at `warning`, on the default channel — and production has run `LOG_LEVEL=error`
 * since 2026-09-28, so the line was filtered out and nothing distinguished "nobody tried to pay"
 * from "somebody paid and we refused them". The defect is not an absent trace: it is a trace that
 * one configuration change silently removes, while everyone believes it is there.
 *
 * So the refusal line also goes to the `payments` channel, whose level is a literal in
 * `config/logging.php`. The first test below is the one that stops this exact failure recurring:
 * it loads the config with a tightened LOG_LEVEL and proves the channel does not move.
 */

/** Point the `payments` channel at a scratch file so the line can be read back; returns its directory. */
function tracedPaymentsLog(): string
{
    $dir = Scratch::dir('payments-log');
    Config::set('logging.channels.payments.path', $dir.'/payments.log');
    Log::forgetChannel('payments');

    return $dir;
}

/**
 * Every line the `payments` channel wrote during the test.
 *
 * The stream is closed first: Monolog holds the file open until the logger is destroyed, which on
 * Windows is after `Scratch::cleanAll()` has tried to remove the directory — and left it behind.
 * The handler reopens on the next write, so closing costs nothing.
 */
function paymentsLogLines(string $dir): string
{
    $channel = Log::channel('payments');
    $monolog = $channel instanceof IlluminateLogger ? $channel->getLogger() : null;
    if ($monolog instanceof Logger) {
        $monolog->close();
    }
    $files = glob($dir.'/payments*.log') ?: [];

    return implode('', array_map(fn (string $f): string => (string) file_get_contents($f), $files));
}

it('fixes the payments channel level in config, independent of LOG_LEVEL', function () {
    /*
     * The `daily` channel reads env('LOG_LEVEL') — that is the mechanism that hid every refusal
     * from 2026-09-28. Load the config file with LOG_LEVEL tightened to `critical` and prove the
     * override reaches the daily channel (so the test is not vacuous) while `payments` stays put.
     */
    $previous = getenv('LOG_LEVEL');
    $previousEnv = $_ENV['LOG_LEVEL'] ?? null;
    $previousServer = $_SERVER['LOG_LEVEL'] ?? null;
    putenv('LOG_LEVEL=critical');
    $_ENV['LOG_LEVEL'] = 'critical';
    $_SERVER['LOG_LEVEL'] = 'critical';

    try {
        /** @var array{channels: array<string, array<string, mixed>>} $config */
        $config = require config_path('logging.php');
    } finally {
        putenv($previous === false ? 'LOG_LEVEL' : 'LOG_LEVEL='.$previous);
        if ($previousEnv === null) {
            unset($_ENV['LOG_LEVEL']);
        } else {
            $_ENV['LOG_LEVEL'] = $previousEnv;
        }
        if ($previousServer === null) {
            unset($_SERVER['LOG_LEVEL']);
        } else {
            $_SERVER['LOG_LEVEL'] = $previousServer;
        }
    }

    expect($config['channels']['daily']['level'])->toBe('critical')   // the override reached the env-driven channel, so the test is not vacuous
        ->and($config['channels']['payments']['level'])->toBe('info')
        ->and($config['channels']['payments']['driver'])->toBe('daily')
        ->and($config['channels']['payments']['days'])->toBe(30);
});

it('builds the payments channel as a daily rotating file that handles a warning', function () {
    $dir = tracedPaymentsLog();

    $channel = Log::channel('payments');
    expect($channel)->toBeInstanceOf(IlluminateLogger::class);
    $monolog = $channel instanceof IlluminateLogger ? $channel->getLogger() : null;
    expect($monolog)->toBeInstanceOf(Logger::class);
    $handler = $monolog instanceof Logger ? $monolog->getHandlers()[0] : null;
    expect($handler)->toBeInstanceOf(RotatingFileHandler::class);
    expect($handler instanceof RotatingFileHandler ? $handler->getLevel() : null)->toBe(Level::Info);

    Log::channel('payments')->warning('probe line');

    $files = glob($dir.'/payments-*.log') ?: [];
    expect($files)->toHaveCount(1)   // the daily driver names the file by date
        ->and(basename($files[0]))->toMatch('/^payments-\d{4}-\d{2}-\d{2}\.log$/')
        ->and(paymentsLogLines($dir))->toContain('probe line');
});

it('traces a signature refusal with the check, storefront, provider and the claimed ids, and no secret', function () {
    $dir = tracedPaymentsLog();
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 100.0);
    $number = PaymentFixture::orderNumber($orderId);

    $payload = PaymentFixture::callback($number, 10000, secret: 'some-other-secret', integrationId: '4988969');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertForbidden();

    $lines = paymentsLogLines($dir);
    expect($lines)->toContain('payment callback refused: signature did not verify against this contract')
        ->and($lines)->toContain('"storefront_id":1')
        ->and($lines)->toContain('"provider":"paymob"')
        ->and($lines)->toContain('"status":403')
        ->and($lines)->toContain('"http_method":"GET"')
        ->and($lines)->toContain('"claimed_integration_id":"4988969"')
        ->and($lines)->toContain('"claimed_merchant_order_id":"'.$number.'"')
        // Never the signature, never a secret, never an amount.
        ->and($lines)->not->toContain(T::str($payload['hmac']))
        ->and($lines)->not->toContain('the-real-secret')
        ->and($lines)->not->toContain('some-other-secret')
        ->and($lines)->not->toContain('amount_cents')
        ->and(substr_count($lines, 'payment callback refused'))->toBe(1);
});

it('traces the provider server-to-server POST as a POST, from the nested payload shape', function () {
    $dir = tracedPaymentsLog();
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 40.0);
    $number = PaymentFixture::orderNumber($orderId);

    $payload = PaymentFixture::processedCallback($number, 4000, secret: 'some-other-secret', integrationId: 4988969);

    postJson('/api/pay/watchizer/paymob/callback?hmac='.T::str($payload['hmac']), $payload['body'])->assertForbidden();

    $lines = paymentsLogLines($dir);
    expect($lines)->toContain('signature did not verify')
        ->and($lines)->toContain('"http_method":"POST"')
        ->and($lines)->toContain('"claimed_integration_id":"4988969"')
        ->and($lines)->toContain('"claimed_merchant_order_id":"'.$number.'"');
});

it('traces every other refusal by the check that refused it', function () {
    $dir = tracedPaymentsLog();
    PaymentFixture::paymob(storefrontId: 1, hmacSecret: 'shared-test-secret');
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'shared-test-secret');
    $foreign = PaymentFixture::order(total: 100.0, storefrontId: Storefront::BRAND_FASHION_ID);
    $foreignNumber = PaymentFixture::orderNumber($foreign);

    // Check 1: unknown storefront — the code is what identifies the attempt, there is no id yet.
    get('/api/pay/nosuchshop/paymob/callback?id=1&integration_id=777')->assertNotFound();
    // Check 1: a provider the storefront holds no contract for.
    get('/api/pay/watchizer/fawry/callback?id=1')->assertNotFound();
    // Check 3: a perfectly signed callback for storefront 1, naming storefront 2's order.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($foreignNumber, 10000, secret: 'shared-test-secret')
    ))->assertForbidden();
    // Ownership: a reference that names no order at all.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback('WZ-NO-SUCH-ORDER', 10000, secret: 'shared-test-secret')
    ))->assertNotFound();

    $lines = paymentsLogLines($dir);
    expect($lines)->toContain('refused: unknown storefront')
        ->and($lines)->toContain('"storefront":"nosuchshop"')
        ->and($lines)->toContain('"claimed_integration_id":"777"')
        ->and($lines)->toContain('refused: no enabled contract for this storefront and provider')
        ->and($lines)->toContain('"provider":"fawry"')
        ->and($lines)->toContain('refused: order belongs to another storefront')
        ->and($lines)->toContain('"order_id":'.$foreign)
        ->and($lines)->toContain('"order_storefront_id":'.Storefront::BRAND_FASHION_ID)
        ->and($lines)->toContain('refused: the merchant reference names no order')
        ->and($lines)->toContain('"claimed_merchant_order_id":"WZ-NO-SUCH-ORDER"')
        ->and(substr_count($lines, 'payment callback refused'))->toBe(4);
});

it('writes nothing to the payments channel for an ACCEPTED callback', function () {
    // The channel is for refusals: an accepted payment is already on record as an attempt row.
    $dir = tracedPaymentsLog();
    PaymentFixture::paymob(hmacSecret: 'ok-secret');
    $orderId = PaymentFixture::order(total: 25.0);

    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 2500, secret: 'ok-secret')
    ))->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing')
        ->and(paymentsLogLines($dir))->toBe('');
});
