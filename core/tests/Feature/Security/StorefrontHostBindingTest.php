<?php

use App\Storefront\StorefrontHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;

/*
 * ── A storefront's API host serves that storefront and no other (brand separation, 2026-09-26) ──
 *
 * `https://api.watchizereg.com/api/v2/brandfashion/meta` answered 200 with Brand Fashion's
 * catalogue — measured on the live host — because v2 took its storefront from the URL path. The
 * payment callback took it from the path too, and answered 403 for a shop that exists and 404 for
 * one that does not. Both are now bound to the request host through StorefrontHost, and a mismatch
 * must be INDISTINGUISHABLE from an unknown storefront: same status, same body.
 */

beforeEach(function () {
    // The two live rows, stated rather than assumed: the whole test rests on these domains.
    expect(DB::table('storefronts')->where('code', 'watchizer')->value('domain'))->toBe('watchizereg.com')
        ->and(DB::table('storefronts')->where('code', 'brandfashion')->value('domain'))->toBe('brandfashionegy.com')
        ->and(DB::table('storefronts')->where('code', 'brandfashion')->value('is_active'))->toBeTruthy();
});

/**
 * @param  TestResponse<Response>  $response
 * @return array{0: int, 1: string}
 */
function answer(TestResponse $response): array
{
    return [$response->getStatusCode(), (string) $response->getContent()];
}

it('serves each storefront only on its own API host — v2', function () {
    get('https://api.watchizereg.com/api/v2/watchizer/meta')->assertOk();
    get('https://api.brandfashionegy.com/api/v2/brandfashion/meta')->assertOk();

    $crossed = get('https://api.watchizereg.com/api/v2/brandfashion/meta');
    $unknown = get('https://api.watchizereg.com/api/v2/no-such-shop/meta');

    expect($crossed->getStatusCode())->toBe(404)
        ->and(answer($crossed))->toBe(answer($unknown))                       // "not yours" == "does not exist"
        ->and(get('https://api.brandfashionegy.com/api/v2/watchizer/meta')->getStatusCode())->toBe(404);
});

it('answers another shop\'s payment callback exactly like an unknown shop', function () {
    // BOTH shops hold a Paymob contract, so the only difference left between the calls is the host.
    PaymentFixture::paymob(storefrontId: T::int(DB::table('storefronts')->where('code', 'watchizer')->value('id')));
    PaymentFixture::paymob(storefrontId: T::int(DB::table('storefronts')->where('code', 'brandfashion')->value('id')));

    $crossed = get('https://api.watchizereg.com/api/pay/brandfashion/paymob/callback?hmac=probe');
    $unknown = get('https://api.watchizereg.com/api/pay/no-such-shop/paymob/callback?hmac=probe');

    expect(answer($crossed))->toBe([404, '{"message":"Not found"}'])
        ->and(answer($crossed))->toBe(answer($unknown))
        // …while its OWN host still reaches the signature check (refused: the probe is unsigned).
        ->and(get('https://api.watchizereg.com/api/pay/watchizer/paymob/callback?hmac=probe')->getStatusCode())->toBe(403)
        ->and(get('https://api.brandfashionegy.com/api/pay/brandfashion/paymob/callback?hmac=probe')->getStatusCode())->toBe(403);
});

it('refuses a host that belongs to no storefront outside local and testing', function () {
    app()['env'] = 'production';

    expect(get('https://some-other-host.example/api/v2/watchizer/meta')->getStatusCode())->toBe(404)
        ->and(get('https://api.watchizereg.com/api/v2/watchizer/meta')->getStatusCode())->toBe(200);
});

it('matches a host exactly or by sub-domain, never by bare suffix', function () {
    $watchizer = T::int(DB::table('storefronts')->where('code', 'watchizer')->value('id'));
    $fashion = T::int(DB::table('storefronts')->where('code', 'brandfashion')->value('id'));

    expect(StorefrontHost::storefrontIdFor('api.watchizereg.com'))->toBe($watchizer)
        ->and(StorefrontHost::storefrontIdFor('WWW.watchizereg.com.'))->toBe($watchizer)
        ->and(StorefrontHost::storefrontIdFor('api.brandfashionegy.com'))->toBe($fashion)
        ->and(StorefrontHost::storefrontIdFor('notwatchizereg.com'))->toBeNull()
        ->and(StorefrontHost::storefrontIdFor('eleganceeg.com'))->toBeNull()
        ->and(StorefrontHost::storefrontIdFor(''))->toBeNull();
});
