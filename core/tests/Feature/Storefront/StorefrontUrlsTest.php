<?php

use App\Http\Controllers\Payment\PaymentCallbackController;
use App\Mail\CustomerPasswordReset;
use App\Storefront\ImageUrl;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontHasNoHost;
use App\Storefront\StorefrontHost;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Shopper;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * ── Each storefront's shoppers land on THEIR site (L5, 2026-09-26 — functional) ──────────────
 *
 * The post-payment return, the password-reset link, the Google sign-in landing and v2's image host
 * were one global each: correct with one shop, broken with two — a Brand Fashion shopper who paid
 * by card came back to watchizereg.com. Watchizer's values must not move; the second shop's come
 * from its own domain.
 */

const URLS_API_KEY = 'storefront-urls-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => URLS_API_KEY,
        'compat.jwt_secret' => 'storefront-urls-test-secret',
        'auth.passwords.users.table' => 'core_password_resets',
        'auth.passwords.users.throttle' => 0,
        // Watchizer's values, as its .env sets them — they must come back unchanged.
        'customers.storefront_url' => 'https://watchizereg.test',
        'compat.payment_return_url' => 'https://watchizereg.test/',
        'storefront.asset_base' => 'https://api.watchizereg.test',
    ]);
    Mail::fake();
    // The second shop has a domain of its own — whichever it is today (step D changes it; Shops reads the row).
    expect(Shops::domain('brandfashion'))->not->toBe('watchizereg.com');
});

function targetOf(mixed $response): string
{
    expect($response)->toBeInstanceOf(RedirectResponse::class);

    return $response instanceof RedirectResponse ? $response->getTargetUrl() : '';
}

function shopId(string $code): int
{
    return T::int(DB::table('storefronts')->where('code', $code)->value('id'));
}

it('keeps Watchizer on its .env values and derives the second shop from its domain', function () {
    $watchizer = shopId('watchizer');
    $fashion = shopId('brandfashion');

    expect([StorefrontUrls::frontend($watchizer), StorefrontUrls::paymentReturn($watchizer), StorefrontUrls::assetBase($watchizer)])
        ->toBe(['https://watchizereg.test', 'https://watchizereg.test/', 'https://api.watchizereg.test'])
        ->and([StorefrontUrls::frontend($fashion), StorefrontUrls::paymentReturn($fashion), StorefrontUrls::assetBase($fashion)])
        ->toBe([Shops::site('brandfashion'), Shops::site('brandfashion').'/', Shops::api('brandfashion')]);
});

it('lets a storefront\'s settings override any of them, and forgets the cached values with the storefront', function () {
    $fashion = shopId('brandfashion');
    expect(StorefrontUrls::frontend($fashion))->toBe(Shops::site('brandfashion'));   // cached now

    DB::table('storefronts')->where('id', $fashion)->update(['settings' => json_encode(['urls' => ['frontend' => 'https://shop.example']])]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');

    expect(StorefrontUrls::frontend($fashion))->toBe('https://shop.example')
        ->and(StorefrontUrls::paymentReturn($fashion))->toBe('https://shop.example/');
});

it('sends a shopper back to the shop they PAID on', function () {
    $fashion = PaymentCallbackController::done(Request::create(Shops::api('brandfashion').'/api/pay/brandfashion/paymob/callback'), true);
    $failed = PaymentCallbackController::done(Request::create(Shops::api('brandfashion').'/api/pay/brandfashion/paymob/callback'), false);
    $watchizer = PaymentCallbackController::done(Request::create('https://api.watchizereg.com/api/callback_payment'), true);

    expect(targetOf($fashion))->toBe(Shops::site('brandfashion').'/')
        ->and(targetOf($failed))->toBe(Shops::site('brandfashion').'/?payment_error=1')
        ->and(targetOf($watchizer))->toBe('https://watchizereg.test/');
});

it('links a password reset to the shop it was asked for on', function () {
    $user = Shopper::register();

    withHeaders(['Api-Code' => URLS_API_KEY])->postJson(Shops::api('brandfashion').'/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Mail::assertSent(CustomerPasswordReset::class, function (CustomerPasswordReset $mail): bool {
        $url = T::str((new ReflectionProperty($mail, 'url'))->getValue($mail));

        return str_starts_with($url, Shops::site('brandfashion').'/reset-password?token=');
    });
});

it('lands a sign-in back on the shop it started from', function () {
    // An unsupported provider is refused before any OAuth exchange: the cheapest way to reach back().
    get(Shops::api('brandfashion').'/api/auth/facebook/callback?state=x')
        ->assertRedirect(Shops::site('brandfashion').'/auth/callback?error=unsupported_provider');
});

it('names each shop\'s images on its own API host in v2', function () {
    $fashion = (string) get(Shops::api('brandfashion').'/api/v2/brandfashion/products')->assertOk()->getContent();
    $watchizer = (string) get('https://api.watchizereg.com/api/v2/watchizer/products')->assertOk()->getContent();

    // Nothing after a v2 request inherits its shop's host: the dashboard and e-mails still read .env.
    get(Shops::api('brandfashion').'/api/v2/brandfashion/meta')->assertOk();
    expect(ImageUrl::src('Product/x.webp'))->toBe('https://api.watchizereg.test/Uploads_Images/Product/x.webp');

    expect($fashion)->toContain(Shops::api('brandfashion').'/Uploads_Images')
        ->and($fashion)->not->toContain('api.watchizereg')
        ->and($watchizer)->toContain('https://api.watchizereg.test/Uploads_Images');
});

it('adds the extra storefront origins to CORS, and nothing when the list is empty', function () {
    $evaluate = function (?string $value): array {
        putenv($value === null ? 'CORS_EXTRA_ORIGINS' : 'CORS_EXTRA_ORIGINS='.$value);
        $_ENV['CORS_EXTRA_ORIGINS'] = $_SERVER['CORS_EXTRA_ORIGINS'] = $value;
        if ($value === null) {
            unset($_ENV['CORS_EXTRA_ORIGINS'], $_SERVER['CORS_EXTRA_ORIGINS']);
        }
        try {
            $config = require config_path('cors.php');

            $origins = is_array($config) && is_array($config['allowed_origins'] ?? null) ? $config['allowed_origins'] : [];

            return array_values(array_filter($origins, 'is_string'));
        } finally {
            putenv('CORS_EXTRA_ORIGINS');
            unset($_ENV['CORS_EXTRA_ORIGINS'], $_SERVER['CORS_EXTRA_ORIGINS']);
        }
    };

    $with = $evaluate('https://brandfashionegy.com, https://www.brandfashionegy.com');
    $without = $evaluate(null);

    expect($with)->toContain('https://brandfashionegy.com', 'https://www.brandfashionegy.com')
        ->and($without)->not->toContain('')
        ->and(array_values(array_diff($with, $without)))->toBe(['https://brandfashionegy.com', 'https://www.brandfashionegy.com']);
});

/*
 * ── A2 (developer, 2026-10-12): the storefront's own API host, and no fallback anywhere ────────
 */

it('derives each shop\'s API host from its domain, and takes settings.urls.api_host over it', function () {
    $fashion = shopId('brandfashion');

    expect(StorefrontUrls::apiHost(shopId('watchizer')))->toBe('https://api.watchizereg.com')   // what the retired env keys held
        ->and(StorefrontUrls::apiHost($fashion))->toBe(Shops::api('brandfashion'));

    DB::table('storefronts')->where('id', $fashion)->update(['settings' => json_encode(['urls' => ['api_host' => 'https://api.shop.example/']])]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');

    expect(StorefrontUrls::apiHost($fashion))->toBe('https://api.shop.example');   // trailing slash dropped
});

it('gives a storefront with neither api_host nor domain NO host — never the default shop\'s', function () {
    $fashion = shopId('brandfashion');
    DB::table('storefronts')->where('id', $fashion)->update(['domain' => null, 'settings' => null]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');

    foreach (['apiHost', 'frontend', 'paymentReturn', 'assetBase'] as $method) {
        expect(fn () => StorefrontUrls::$method($fashion))->toThrow(StorefrontHasNoHost::class, (string) $fashion);
    }
    expect(StorefrontUrls::frontend(shopId('watchizer')))->toBe('https://watchizereg.test');   // Watchizer's env value, untouched
});

it('refuses a request host that names no storefront in production, and pins it in testing', function () {
    $unmatched = Request::create('https://unmatched-host.example/api/pay/brandfashion/paymob/callback');

    expect(StorefrontUrls::storefrontOf($unmatched))->toBe(shopId('watchizer'));   // testing: the suite's pin

    app()['env'] = 'production';
    expect(fn () => StorefrontUrls::storefrontOf($unmatched))->toThrow(NotFoundHttpException::class)
        ->and(StorefrontUrls::storefrontOf(Request::create(Shops::api('brandfashion').'/x')))->toBe(shopId('brandfashion'));
});

it('survives step D: the second shop moved to its temporary host, every URL follows, and the wordmark moves with it', function () {
    /*
     * Step D (BF handover §7.6, backlog SF-URL-SETTINGS) changes ONE value — `storefronts.domain` —
     * and this is what must follow from it with no other change: the site, the API host, the mail
     * wordmark's URL. Run here inside the test's transaction, so the dev row is untouched; the real
     * statement is the developer's to run.
     */
    $fashion = shopId('brandfashion');
    $before = StorefrontIdentity::for($fashion)->wordmarkUrl();
    expect($before)->toBe(Shops::api('brandfashion').'/assets/brand/brandfashion-mail-wordmark@2x.png');

    $moved = DB::update("UPDATE storefronts SET domain = 'bf.michaelkhalaf.com', updated_at = NOW() WHERE id = ? AND code = 'brandfashion' AND domain = ?", [$fashion, Shops::domain('brandfashion')]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');

    expect($moved)->toBe(1)
        ->and(StorefrontUrls::frontend($fashion))->toBe('https://bf.michaelkhalaf.com')
        ->and(StorefrontUrls::apiHost($fashion))->toBe('https://api.bf.michaelkhalaf.com')
        ->and(StorefrontUrls::paymentLanding($fashion, 'paid', '000001'))->toBe('https://bf.michaelkhalaf.com/order-confirmation?order=000001&status=paid')
        ->and(StorefrontIdentity::for($fashion)->wordmarkUrl())->toBe('https://api.bf.michaelkhalaf.com/assets/brand/brandfashion-mail-wordmark@2x.png')
        ->and(StorefrontHost::storefrontIdFor('api.bf.michaelkhalaf.com'))->toBe($fashion)
        // Run twice: the guard names the old domain, so the second run is a no-op.
        ->and(DB::update("UPDATE storefronts SET domain = 'bf.michaelkhalaf.com', updated_at = NOW() WHERE id = ? AND code = 'brandfashion' AND domain = 'brandfashionegy.com'", [$fashion]))->toBe(0);
});
