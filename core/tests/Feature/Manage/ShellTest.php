<?php

use Inertia\Testing\AssertableInertia;
use Tests\Support\Props;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The shell's contract with the server: what it is given, and what a role is allowed to see of it.
 */

it('renders the dashboard inside an Arabic RTL shell', function () {
    actingAs(Staff::admin())->get('/manage')
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manage/Home')
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            // Five since wave 4D: `orders_unseen`, the per-operator badge count.
            ->has('stats', 5)
            ->has('inventory')
            ->has('nav')
            ->has('auth.user')
            ->has('branding'));
});

it('shares branding from config, so no component knows a filename', function () {
    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branding.name', config('branding.name'))
        ->where('credit.text', config('branding.credit.text'))
        ->where('branding.logo', asset(config()->string('branding.logo.default')))
        ->where('branding.logo_light', asset(config()->string('branding.logo.light'))));
});

it('carries the developer credit in the shared props, which only the footer renders', function () {
    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('credit.text', 'Created by Michael Khalaf'));
});

it('keeps the developer credit out of the login screen ENTIRELY, payload included', function () {
    // A separate test on purpose: `actingAs()` lasts for the whole test, and a signed-in visit to
    // the login route redirects — which would have made this pass without looking.
    //
    // The first version of this failed, and correctly: the credit was part of the `branding` prop,
    // so the string was in the login page's `data-page` JSON even though nothing rendered it. It
    // is now its own prop, shared only for an authenticated session.
    $html = get('/manage/login')->assertOk()->getContent();

    expect($html)->toBeString();
    expect((string) $html)->not->toContain('Created by Michael Khalaf')
        ->and((string) $html)->not->toContain('&quot;credit&quot;:{');
});

it('gives an admin the settings group and a data-entry user none of it', function () {
    $adminKeys = Props::navKeys(actingAs(Staff::admin())->get('/manage'));
    $entryKeys = Props::navKeys(actingAs(Staff::dataEntry())->get('/manage'));

    expect($adminKeys)->toContain('storefronts')->toContain('users')->toContain('payments')
        ->and($entryKeys)->not->toContain('storefronts')
        ->and($entryKeys)->not->toContain('users')
        ->and($entryKeys)->not->toContain('payments')
        // …but the catalog group is their job, and so is the shop floor: orders and stock became
        // data-entry work on 2026-09-11 (cancelling and refunding did not).
        ->and($entryKeys)->toContain('products')
        ->and($entryKeys)->toContain('orders')
        ->and($entryKeys)->toContain('inventory');
});

it('parks the screens the team is not meant to open, instead of linking to them', function () {
    $byKey = Props::navItems(actingAs(Staff::admin())->get('/manage'));

    // The combined "offers, banners and articles" item is GONE (2026-09-14): offers became the
    // promotions engine and banners got their own screen.
    expect($byKey)->not->toHaveKey('legacy-content');

    /*
     * ONE parked item now, and it is parked for a reason worth stating — the sidebar draws a
     * not-built screen and a deliberately-closed one identically on purpose, because "not for you
     * today" is the whole message an operator needs:
     *
     * BLOGS left this list on 2026-09-18 (item 14): it is a real screen with real routes and
     * core-owned tables. The assertion below is what made that a deliberate edit rather than a
     * sidebar quietly still saying "later" over a screen that works.
     *
     *   • promotions — built, tested, and deliberately switched off (item 13, 2026-09-17). Its
     *                  ROUTES are still registered and still admin-only; what changed is that the
     *                  sidebar no longer offers it. `PromotionScreenTest` still proves the
     *                  server's side of that, which is the half that is actually the control.
     *
     * If somebody opens promotions back up, this assertion is what tells them to move the item out
     * of this list rather than leaving a live screen the sidebar calls "later".
     */
    foreach (['promotions'] as $parked) {
        expect($byKey[$parked]['later'])->toBeTrue("{$parked} must render parked")
            ->and($byKey[$parked]['href'])->toBeNull("{$parked} is parked and must not link anywhere");
    }

    // …and what IS live has a link and no badge.
    expect($byKey['storefronts']['href'])->not->toBeNull()
        ->and($byKey['storefronts']['later'])->toBeFalse()
        ->and($byKey['home']['active'])->toBeTrue();

    // Wave 4B turned four stubs into screens and wave 4C turned four more, so the assertion flips
    // for them: a built item MUST carry a link and MUST NOT be parked. This is the test that would
    // have caught a shipped screen the sidebar still calls "later".
    foreach (['products', 'categories', 'placement', 'lookups', 'orders', 'inventory', 'users', 'payments', 'banners', 'blogs'] as $built) {
        expect($byKey[$built]['later'])->toBeFalse("{$built} is built and must not be parked")
            ->and($byKey[$built]['href'])->not->toBeNull("{$built} must link somewhere");
    }

    // The shop-floor items are deliberately NOT storefront-scoped (one queue, filtered), while
    // payments is — "which account takes this money" is a per-storefront question, and a link that
    // dropped the segment would 404 on every click.
    // Compared on the PATH, not the absolute URL: the host comes from APP_URL and carries a port
    // in some environments, which is not what these assertions are about.
    expect($byKey['orders']['href'])->toEndWith('/manage/orders')
        ->and($byKey['orders']['href'])->not->toContain('/storefronts/')
        ->and($byKey['inventory']['href'])->toEndWith('/manage/inventory')
        ->and($byKey['inventory']['href'])->not->toContain('/storefronts/')
        ->and($byKey['users']['href'])->toEndWith('/manage/users')
        ->and($byKey['payments']['href'])->toContain('/manage/storefronts/')
        ->and($byKey['payments']['href'])->toEndWith('/payments');

    // The storefront-scoped links carry the storefront segment: a nav link that dropped it would
    // 404 on every click (the scope middleware needs the id to check the grant).
    expect($byKey['products']['href'])->toContain('/manage/storefronts/')
        ->and($byKey['products']['href'])->toContain('/products')
        ->and($byKey['categories']['href'])->toContain('/categories')
        ->and($byKey['placement']['href'])->toContain('/placement')
        ->and($byKey['lookups']['href'])->toContain('/manage/lookups/brands');

    // And there is deliberately no "variants" item: the panel lives inside the product form, so a
    // nav entry would promise a screen that does not exist.
    expect($byKey)->not->toHaveKey('variants');
});

it('lights the active nav item from the route name, not the URL', function () {
    expect(Props::activeNavKeys(actingAs(Staff::admin())->get('/manage/storefronts')))->toBe(['storefronts']);
});

it('keeps a nested route on its section: the edit screen lights the storefronts item', function () {
    // `manage.storefronts.edit` must light `storefronts` and nothing else — the reason the active
    // flag is computed from the route NAME rather than from the URL.
    expect(Props::activeNavKeys(actingAs(Staff::admin())->get('/manage/storefronts/1/edit')))->toBe(['storefronts']);
});

it('exposes an ability map that matches the role, for hiding affordances only', function () {
    actingAs(Staff::dataEntry())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('abilities.manage-catalog', true)
        ->where('abilities.manage-storefronts', false)
        ->where('abilities.manage-payments', false));
});

it('redirects the old walking-skeleton root to the dashboard', function () {
    actingAs(Staff::admin())->get('/')->assertRedirect('/manage');
});
