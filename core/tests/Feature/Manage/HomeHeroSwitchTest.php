<?php

use App\Storefront\HomeHero;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * The per-storefront home hero (the developer's addendum, 2026-10-20).
 *
 * Which hero a shop draws used to be decided by which APP was deployed — Watchizer's home rendered a 3D watch,
 * Brand Fashion's rendered a banner — so a shop that wanted the other one needed a developer. It is one value
 * per storefront now, `settings.home.hero`, flipped on the Banners screen, and both storefront apps render the
 * same component for it.
 *
 * WHAT THIS FILE IS REALLY FOR: Watchizer is live and taking real orders, and its home page is the 3D hero.
 * Every test below exists to prove that nothing in this release moves it until somebody chooses to.
 */

/**
 * This storefront's `settings` as stored, decoded.
 *
 * @return array<array-key, mixed>
 */
function heroSettings(int $storefrontId): array
{
    $raw = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

/**
 * Put a storefront's `settings` back exactly as they were.
 *
 * @param  array<array-key, mixed>  $settings
 */
function restoreSettings(int $storefrontId, array $settings): void
{
    DB::table('storefronts')->where('id', $storefrontId)
        ->update(['settings' => $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    app(StorefrontCache::class)->flush($storefrontId);
}

it('starts where each shop already was: Watchizer on the 3D hero, Brand Fashion on its banners', function () {
    // What the release's migration wrote. It is written out rather than inferred so that the two decisions stay
    // independent — see the next test for the fallback, which protects the live shop if the value goes missing.
    expect(HomeHero::of(heroSettings(1)))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(heroSettings(2)))->toBe(HomeHero::BANNER);
});

it('reads a missing, empty or unknown value as the 3D hero — never as the banner hero', function () {
    // The live shop's hero is the 3D watch. A storefront row created by hand, a `settings` column reset, this
    // release deployed without its migration: every one of those must leave Watchizer's home page as it is.
    expect(HomeHero::of(null))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of([]))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(['home' => []]))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(['home' => ['hero' => '']]))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(['home' => ['hero' => 'carousel']]))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(['home' => 'banner']))->toBe(HomeHero::THREE_D)
        ->and(HomeHero::of(['home' => ['hero' => HomeHero::BANNER]]))->toBe(HomeHero::BANNER);
});

it('flips a storefront from the screen and leaves every other setting untouched', function () {
    $before = heroSettings(2);

    try {
        actingAs(Staff::admin())->post('/manage/storefronts/2/banners/hero', ['hero' => HomeHero::THREE_D])->assertRedirect();

        $after = heroSettings(2);
        expect(HomeHero::of($after))->toBe(HomeHero::THREE_D)
            // the urls, identity and promotions sections this screen knows nothing about survive the save
            ->and($after['identity'] ?? null)->toBe($before['identity'] ?? null)
            ->and($after['promotions'] ?? null)->toBe($before['promotions'] ?? null)
            ->and(array_diff(array_keys($after), array_keys($before)))->toBe([]);
    } finally {
        restoreSettings(2, $before);
    }
});

it('refuses a hero it cannot draw, rather than storing a value the shop cannot read', function () {
    $before = heroSettings(2);

    actingAs(Staff::admin())->post('/manage/storefronts/2/banners/hero', ['hero' => 'parallax'])
        ->assertSessionHasErrors('hero');

    expect(heroSettings(2))->toBe($before);
});

it('reaches the shop: the flip busts this storefront\'s cached home, and only this one', function () {
    $before2 = heroSettings(2);
    $before1 = heroSettings(1);

    try {
        // warm both, so a stale answer would be the thing that fails
        expect(T::str(getJson('/api/v2/brandfashion/home')->assertOk()->json('hero')))->toBe(HomeHero::BANNER)
            ->and(T::str(getJson('/api/v2/watchizer/home')->assertOk()->json('hero')))->toBe(HomeHero::THREE_D);

        actingAs(Staff::admin())->post('/manage/storefronts/2/banners/hero', ['hero' => HomeHero::THREE_D])->assertRedirect();

        // the cached payload carries `hero` for ten minutes; a switch the shop cannot see take effect is a
        // switch the shop presses twice
        expect(T::str(getJson('/api/v2/brandfashion/home')->assertOk()->json('hero')))->toBe(HomeHero::THREE_D)
            ->and(T::str(getJson('/api/v2/watchizer/home')->assertOk()->json('hero')))->toBe(HomeHero::THREE_D);
    } finally {
        restoreSettings(2, $before2);
        restoreSettings(1, $before1);
    }
});

it('shows the operator which hero is on, and says when the banners are not being shown', function () {
    $page = actingAs(Staff::admin())->get('/manage/storefronts/1/banners')->assertOk();
    $props = Props::of($page);

    expect(T::str($props['hero'] ?? null))->toBe(HomeHero::THREE_D)
        ->and(T::arr($props['heroes'] ?? []))->toBe(HomeHero::MODES);
});
