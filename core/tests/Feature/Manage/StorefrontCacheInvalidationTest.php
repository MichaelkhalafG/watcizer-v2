<?php

use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * C-BUG-1 — a storefront setting saved in the dashboard did not reach the shop for ten minutes.
 *
 * ── What was measured, before the fix ───────────────────────────────────────────────────────
 *
 * `ResolveStorefront` caches the whole storefront row under `sf:code:{code}` for
 * `storefront.ttl.storefront` = 600 s, and `StorefrontController::update()` invalidated nothing at
 * all. End to end:
 *
 *   • prime the cache with a customer request → 200
 *   • deactivate the storefront through the screen → database `is_active = 0`, cache still says 1
 *   • customer request immediately after → **still 200**, a deactivated shop still trading
 *   • rename + currency + default locale → database right, shop serving the old values
 *
 * The screen said "saved" and was telling the truth about the row. What it could not say was that
 * the shop would disagree for another ten minutes.
 *
 * ── Why the test drives BOTH sides ──────────────────────────────────────────────────────────
 *
 * Asserting that the controller called a cache method would pass with the cache key spelled wrong.
 * The only assertion worth making is the one the review made: prime the shop, change the setting,
 * ask the shop again.
 */

/** The storefront the customer API answers for, primed into the resolver's cache. */
function primeStorefront(string $code): void
{
    getJson("/api/v2/{$code}/meta")->assertOk();
    expect(Cache::get(StorefrontCache::resolvedKey($code)))->toBeArray('the customer request should have primed the resolver cache');
}

it('takes a deactivated storefront down IMMEDIATELY, not in ten minutes', function () {
    $storefront = T::row(DB::table('storefronts')->where('id', 1)->first());
    $code = T::str($storefront->code ?? null);

    primeStorefront($code);

    // Deactivate through the real screen, exactly as an admin would.
    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => T::str($storefront->name ?? null),
        'domain' => $storefront->domain,
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'EGP',
        'is_active' => false,
        'money_rewards' => false,
    ])->assertRedirect();

    expect(T::int(DB::table('storefronts')->where('id', 1)->value('is_active')))->toBe(0);

    // THE assertion: the shop must already be closed. Before the fix this was a 200.
    getJson("/api/v2/{$code}/meta")->assertNotFound();
});

it('serves the new name, currency and locale at once after a settings change', function () {
    $storefront = T::row(DB::table('storefronts')->where('id', 1)->first());
    $code = T::str($storefront->code ?? null);

    primeStorefront($code);

    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => 'Renamed In A Test',
        'domain' => $storefront->domain,
        'locales' => ['ar', 'en'],
        'default_locale' => 'en',
        'currency' => 'USD',
        'is_active' => true,
        'money_rewards' => false,
    ])->assertRedirect();

    /*
     * `meta` is a VERSIONED payload, so this half proves the version bump rather than the row
     * forget — the two invalidations cover different caches and a fix that did only one of them
     * would pass one of these two tests.
     */
    $meta = getJson("/api/v2/{$code}/meta")->assertOk()->json();

    $body = T::str(json_encode($meta, JSON_UNESCAPED_UNICODE));
    expect($body)->toContain('Renamed In A Test')
        ->and($body)->toContain('USD');
});

it('forgets the resolver row and bumps the version — the two halves, named', function () {
    /*
     * The unit-level half, so a future refactor that keeps the behaviour but drops one of the two
     * invalidations fails HERE with a clear reason rather than in an end-to-end test that says only
     * "the shop is stale".
     */
    $cache = app(StorefrontCache::class);
    $code = T::str(DB::table('storefronts')->where('id', 1)->value('code'));

    Cache::put(StorefrontCache::resolvedKey($code), ['id' => 1, 'stale' => true], 600);
    $before = $cache->version(1);

    $cache->forgetStorefront(1, $code);

    expect(Cache::get(StorefrontCache::resolvedKey($code)))->toBeNull('the resolver row must be forgotten — it carries no version, so a bump cannot reach it')
        ->and($cache->version(1))->toBeGreaterThan($before, 'the version must move, or meta keeps serving the old name');
});

it('keeps ONE spelling of the resolver key, so the writer and the reader cannot drift', function () {
    /*
     * The bug was possible because only the middleware knew the key. The middleware now asks for it,
     * and this asserts the shape the cache actually holds after a real customer request — a rename
     * of the key that updated only one side would leave this failing.
     */
    $code = T::str(DB::table('storefronts')->where('id', 1)->value('code'));

    expect(StorefrontCache::resolvedKey($code))->toBe("sf:code:{$code}");

    Cache::forget(StorefrontCache::resolvedKey($code));
    primeStorefront($code);
});
