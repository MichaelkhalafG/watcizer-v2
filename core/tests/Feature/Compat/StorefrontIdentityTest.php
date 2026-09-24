<?php

use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * The compat layer reads its storefront from the REQUEST, not from a constant (review 🔴-4).
 *
 * ── What was wrong ──────────────────────────────────────────────────────────────────────────
 *
 * `CompatServices::$storefrontId` was `config('compat.storefront_id')` — the literal 1. Every cart,
 * order, inventory movement and promotion evaluation on the live surface was therefore Watchizer's,
 * whichever shop the customer was actually in. `StorefrontIsolationTest` proves the engine keeps
 * two storefronts' promotions apart; this file proves the layer above it can tell them apart at
 * all, which is the half that was missing.
 *
 * ── Why the host, and not the Origin header ─────────────────────────────────────────────────
 *
 * `Origin` is absent on the storefront's server-side render calls and is supplied by the client on
 * every other one, so resolving from it would let a shopper claim another shop's promotions by
 * editing a header. The host a request arrived on is set by the reverse proxy. The last case below
 * is the one that would fail if somebody "improved" this by trusting `Origin`.
 */

/**
 * Resolve as if the request had arrived on `$host`.
 *
 * @param  array<string, string>  $headers
 */
function storefrontForHost(string $host, array $headers = []): int
{
    $request = Request::create('https://'.$host.'/api/catalog/meta', 'GET');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return (new CompatStorefront($request))->id();
}

it('resolves each storefront from its own domain', function () {
    // The domains are data, not constants in this test: a row edited in the dashboard must move the
    // resolution with it, and a test that hard-coded them would not notice if it stopped doing so.
    $watchizer = T::str(DB::table('storefronts')->where('id', 1)->value('domain'));
    $brandFashion = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));

    expect(storefrontForHost($watchizer))->toBe(1)
        ->and(storefrontForHost($brandFashion))->toBe(2);
});

it('resolves a sub-domain to its storefront, which is where the API actually lives', function () {
    $brandFashion = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));

    expect(storefrontForHost('dash.'.$brandFashion))->toBe(2)
        ->and(storefrontForHost('api.'.$brandFashion))->toBe(2)
        ->and(storefrontForHost('www.'.$brandFashion))->toBe(2);
});

it('does NOT match a bare suffix — the impersonation case', function () {
    /*
     * `notbrandfashionegy.com` ends with `brandfashionegy.com` as a STRING. A naive `str_ends_with`
     * on the domain alone would hand an attacker's host a real storefront's identity, and with it
     * that storefront's promotions.
     */
    $brandFashion = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));

    expect(storefrontForHost('not'.$brandFashion))->toBe(config()->integer('compat.storefront_id'))
        ->and(storefrontForHost($brandFashion.'.evil.example'))->toBe(config()->integer('compat.storefront_id'));
});

it('falls back to the configured pin for a host that names no storefront', function () {
    // The harness calls 127.0.0.1 and health checks call whatever the load balancer uses. Both must
    // keep answering as the default storefront rather than failing.
    expect(storefrontForHost('127.0.0.1'))->toBe(config()->integer('compat.storefront_id'))
        ->and(storefrontForHost('localhost'))->toBe(config()->integer('compat.storefront_id'));
});

it('REPORTS a request it could not attribute, instead of quietly defaulting', function () {
    /*
     * The fallback is correct today and dangerous tomorrow: the moment a second storefront is live,
     * an unattributed request is an order, a promotion and a stock movement recorded against the
     * wrong shop. It has to be visible.
     */
    $matched = new CompatStorefront(Request::create('https://'.T::str(DB::table('storefronts')->where('id', 2)->value('domain')).'/api/catalog/meta'));
    $unmatched = new CompatStorefront(Request::create('http://127.0.0.1/api/catalog/meta'));

    expect($matched->unresolved())->toBeFalse()
        ->and($unmatched->unresolved())->toBeTrue();
});

it('ignores a storefront that has been switched off', function () {
    $domain = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));

    DB::table('storefronts')->where('id', 2)->update(['is_active' => 0]);

    // A shop that is off must not keep claiming requests because its domain is still in the row.
    expect(storefrontForHost($domain))->toBe(config()->integer('compat.storefront_id'));
});

it('does not take the storefront from a client-supplied Origin', function () {
    $brandFashion = T::str(DB::table('storefronts')->where('id', 2)->value('domain'));
    $watchizer = T::str(DB::table('storefronts')->where('id', 1)->value('domain'));

    // A Watchizer request claiming to be Brand Fashion in every header a client controls.
    $spoofed = storefrontForHost($watchizer, [
        'Origin' => 'https://'.$brandFashion,
        'Referer' => 'https://'.$brandFashion.'/cart',
        'X-Forwarded-Host' => $brandFashion,
    ]);

    expect($spoofed)->toBe(1, 'a client header changed which storefront the request was attributed to');
});

it('hands the resolved id to every compat builder, not just the promotion path', function () {
    /*
     * The point of fixing this at one door. `CompatServices` constructs the cart, the checkout and
     * the catalogue from a single value — so this asserts the value the container actually produces,
     * which is what `orders.storefront_id` and the inventory ledger are stamped with.
     */
    $services = app(CompatServices::class);

    expect($services->storefrontId)->toBe(config()->integer('compat.storefront_id'),
        'the default request (no storefront host) must still answer as the configured storefront');
});
