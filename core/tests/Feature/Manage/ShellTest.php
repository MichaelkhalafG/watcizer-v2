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
            ->has('stats', 4)
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

it('marks unbuilt screens with their wave instead of linking to them', function () {
    $byKey = Props::navItems(actingAs(Staff::admin())->get('/manage'));

    expect($byKey['products']['wave'])->toBe('4B')
        ->and($byKey['products']['href'])->toBeNull('a stub must not link anywhere')
        ->and($byKey['orders']['wave'])->toBe('4C')
        ->and($byKey['payments']['wave'])->toBe('4D')
        ->and($byKey['users']['wave'])->toBe('4C', 'the users-and-roles screen moved to 4C on 2026-09-11')
        // …and what IS built has a link and no wave badge.
        ->and($byKey['storefronts']['href'])->not->toBeNull()
        ->and($byKey['storefronts']['wave'])->toBeNull()
        ->and($byKey['home']['active'])->toBeTrue();
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
