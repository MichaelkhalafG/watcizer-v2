<?php

use App\Domain\Customers\CustomerSocial;
use App\Domain\Customers\SocialNonce;
use App\Storefront\StorefrontUrls;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/*
 * Google sign-in PER STOREFRONT (backlog L3, developer decision 2026-10-19).
 *
 * Until this change one global `GOOGLE_REDIRECT_URI` was sent for every shop, so a Brand Fashion shopper went through
 * Watchizer's Google project and came back to Watchizer's callback — and landed on watchizereg.com (measured on
 * production, RUN_20261019_DAY.md). Now a storefront with its own pair signs in on its own project and its own
 * callback; the DEFAULT storefront without one is exactly what it was; any other without one is not configured.
 *
 * The first test is the one the change is judged by: Watchizer's Google URL, pinned character for character.
 */

const L3_API_KEY = 'social-per-storefront-test-key';
const L3_NONCE = 'L3PerStorefrontNonce20261019';

beforeEach(function () {
    config([
        'compat.api_key' => L3_API_KEY,
        'compat.jwt_secret' => 'social-per-storefront-test-secret',
        'compat.jwt_algo' => 'HS256',
        // Watchizer's global values, as production holds them (the shape, not the secret)
        'services.google.client_id' => 'wz-client.apps.googleusercontent.com',
        'services.google.client_secret' => 'wz-secret',
        'services.google.redirect' => 'https://api.watchizereg.com/api/auth/google/callback',
        'services.social_storefronts.brandfashion.google.client_id' => null,
        'services.social_storefronts.brandfashion.google.client_secret' => null,
    ]);
});

/** Brand Fashion's own pair, as Michael will set it in core's .env. */
function l3BrandFashionConfigured(): void
{
    config([
        'services.social_storefronts.brandfashion.google.client_id' => 'bf-client.apps.googleusercontent.com',
        'services.social_storefronts.brandfashion.google.client_secret' => 'bf-secret',
    ]);
}

/** The URL the redirect route answers on a host, or the failure body. */
function l3RedirectUrl(string $host): string
{
    $response = withHeaders(['Api-Code' => L3_API_KEY])->getJson("http://{$host}/api/auth/google/redirect?nonce=".L3_NONCE);

    return $response->status() === 200 ? T::str($response->json('url')) : 'HTTP '.$response->status().' '.$response->getContent();
}

/** Google's authorisation URL, built the way Socialite builds it (RFC 1738, `+` for spaces). */
function l3GoogleUrl(string $clientId, string $redirect): string
{
    return 'https://accounts.google.com/o/oauth2/auth?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'scope' => 'openid profile email',
        'response_type' => 'code',
        'state' => SocialNonce::state(L3_NONCE),
    ], '', '&', PHP_QUERY_RFC1738);
}

/**
 * v2 `auth/providers` on the shop's OWN API host — a relative URL would reuse the previous request's host, and a
 * host refuses another storefront's path.
 *
 * @return list<string>
 */
function l3Providers(string $code): array
{
    $host = $code === 'brandfashion' ? 'api.michaelkhalaf.com' : 'api.watchizereg.com';
    $response = getJson("http://{$host}/api/v2/{$code}/auth/providers")->assertOk()->assertHeader('Cache-Control', 'max-age=60, private');

    return array_values(array_map(fn (mixed $p): string => T::str($p), T::arr($response->json('providers'))));
}

it('sends Watchizer to Google on EXACTLY the URL it sent before — its global client and its global redirect URI', function () {
    $expected = l3GoogleUrl('wz-client.apps.googleusercontent.com', 'https://api.watchizereg.com/api/auth/google/callback');

    // its own host (storefront 1 by domain) and the local default host (the pin) — with Brand Fashion unconfigured …
    expect(l3RedirectUrl('api.watchizereg.com'))->toBe($expected)
        ->and(l3RedirectUrl('localhost'))->toBe($expected);

    // … and with Brand Fashion configured: another shop's project never changes Watchizer's
    l3BrandFashionConfigured();
    expect(l3RedirectUrl('api.watchizereg.com'))->toBe($expected)
        ->and(l3Providers('watchizer'))->toBe(['google']);
});

it('refuses to start Brand Fashion\'s flow while it has no project of its own — never through Watchizer\'s', function () {
    expect(l3RedirectUrl('api.michaelkhalaf.com'))->toBe('HTTP 500 {"error":"Could not start social login."}')
        ->and(l3Providers('brandfashion'))->toBe([])
        ->and(l3Providers('watchizer'))->toBe(['google']);

    // half a pair is none: an id without its secret shows no button rather than a flow Google refuses
    config(['services.social_storefronts.brandfashion.google.client_id' => 'bf-client.apps.googleusercontent.com']);
    expect(l3RedirectUrl('api.michaelkhalaf.com'))->toStartWith('HTTP 500')
        ->and(l3Providers('brandfashion'))->toBe([]);
});

it('sends Brand Fashion to ITS project with ITS callback once its pair is set — the URI its console must list', function () {
    $domain = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));
    expect($domain)->toBe('michaelkhalaf.com'); // storefront 2's domain on dev, as on production (BF-HOST-STEP-D)

    l3BrandFashionConfigured();

    expect(CustomerSocial::callbackUrl('google', 2))->toBe('https://api.michaelkhalaf.com/api/auth/google/callback')
        ->and(l3RedirectUrl('api.michaelkhalaf.com'))
        ->toBe(l3GoogleUrl('bf-client.apps.googleusercontent.com', 'https://api.michaelkhalaf.com/api/auth/google/callback'))
        ->and(l3Providers('brandfashion'))->toBe(['google'])
        // Microsoft has no per-storefront driver: never listed for a shop without the global one
        ->and(CustomerSocial::configured('microsoft', 2))->toBeFalse();
});

it('finishes Brand Fashion\'s callback on ITS project and lands the shopper on Brand Fashion', function () {
    l3BrandFashionConfigured();
    $email = Shopper::email();
    $existing = Shopper::register(['email' => $email]);

    $account = new SocialiteUser;
    $account->map(['id' => 'l3-bf-google', 'email' => $email, 'name' => 'Mona Said']);
    $account->setRaw(['sub' => 'l3-bf-google', 'email' => $email, 'email_verified' => true]);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('driver')->never();
    Socialite::shouldReceive('buildProvider')->once()
        ->with(GoogleProvider::class, [
            'client_id' => 'bf-client.apps.googleusercontent.com',
            'client_secret' => 'bf-secret',
            'redirect' => 'https://api.michaelkhalaf.com/api/auth/google/callback',
        ])
        ->andReturn($provider);

    $state = SocialNonce::state(L3_NONCE);
    $location = T::str(withHeaders([])->get("http://api.michaelkhalaf.com/api/auth/google/callback?state={$state}")->headers->get('Location'));

    expect($location)->toStartWith(StorefrontUrls::frontend(2).'/auth/callback?token=')
        ->and($location)->toEndWith('&nonce='.L3_NONCE)
        ->and(StorefrontUrls::frontend(2))->not->toBe(StorefrontUrls::frontend(1));
    expect(DB::table(CustomerSocial::TABLE)->where('provider_id', 'l3-bf-google')->value('user_id'))->toEqual((int) $existing->id);
});

it('never finishes a Brand Fashion callback through Watchizer\'s project while Brand Fashion has none', function () {
    Socialite::shouldReceive('driver')->never();
    Socialite::shouldReceive('buildProvider')->never();

    $state = SocialNonce::state(L3_NONCE);
    $location = T::str(withHeaders([])->get("http://api.michaelkhalaf.com/api/auth/google/callback?state={$state}")->headers->get('Location'));

    expect($location)->toBe(StorefrontUrls::frontend(2).'/auth/callback?error=social_failed&nonce='.L3_NONCE);
});
