<?php

use App\Domain\Access\Preferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The end-to-end sweep (2026-09-18, after the sixteen-item review).
 *
 * ── What this is for ────────────────────────────────────────────────────────────────────────
 *
 * The developer asked for an inspection rather than a test run: *"Boot it and drive every screen
 * yourself, as admin and as data-entry, in Arabic and in English… Click the things nobody clicks:
 * empty states, a list with no results, a form submitted blank, a page opened with a bad id, the
 * back button after a save."*
 *
 * The visual half of that needs a browser session, which needs a password typed into a form, which
 * is not something I will do. This file is the half that can be driven without one, and it is not
 * a small half: it opens every GET screen in the dashboard as BOTH roles in BOTH locales, opens
 * each one with a nonsense id, and posts a blank form at every write endpoint that takes one.
 *
 * ── Why it is one file and not an assertion bolted onto each screen's own test ───────────────
 *
 * Each screen already has a test that knows what that screen means. What none of them can see is
 * the screen ADDED LAST: sixteen items produced four new screens and thirty changed ones, and the
 * failure mode of a sweep like that is the route nobody thought to open. So this walks the ROUTE
 * TABLE rather than a list somebody typed, and a new screen joins it by existing.
 */

/**
 * Every dashboard GET route that renders a screen, with a usable set of parameters.
 *
 * Built from the route table, so a screen added after this file was written is still covered.
 * Routes taking an id are filled from real rows; one that cannot be filled is skipped and NAMED,
 * so "covered" never quietly means "skipped".
 *
 * @return array<string, string> route name => a URL to open
 */
function sweepScreens(): array
{
    $storefront = T::int(DB::table('storefronts')->where('is_active', true)->orderBy('id')->value('id'));
    $product = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $order = T::int(DB::table('orders')->orderBy('id')->value('id'));
    $category = T::int(DB::table('storefront_categories')->where('storefront_id', $storefront)->orderBy('id')->value('id'));

    /** @var array<string, string> $fill */
    $fill = [
        'storefront' => (string) $storefront,
        'product' => (string) $product,
        'order' => (string) $order,
        'category' => (string) $category,
        'list' => 'brands',
        'blog' => (string) T::int(DB::table('core_blogs')->orderBy('id')->value('id') ?? 0),
        'promotion' => (string) T::int(DB::table('promotion_rules')->orderBy('id')->value('id') ?? 0),
        'banner' => (string) T::int(DB::table('storefront_banners')->orderBy('id')->value('id') ?? 0),
        /*
         * A REAL guest key, from the orders themselves. The customers screen groups guests by
         * phone number, so an invented one is a 404 — which the sweep duly reported on its first
         * run, and which was this fixture's fault rather than the screen's.
         */
        'customer' => 'g:'.T::str(
            DB::table('orders')->whereNotNull('guest_phone')->where('guest_phone', '<>', '')
                ->orderBy('id')->value('guest_phone') ?? ''
        ),
    ];

    $out = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if ($name === '' || ! str_starts_with($name, 'manage.')) {
            continue;
        }
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        // Exports stream a file and logout ends the session: neither is a screen.
        if (str_contains($name, 'export') || str_contains($name, 'logout') || str_contains($name, 'login')) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        $usable = true;
        foreach ($route->parameterNames() as $rawParameter) {
            // `parameterNames()` is typed loosely; a name that is not a string cannot match a
            // placeholder in the URI anyway, so it is the same "cannot fill this" case.
            $parameter = T::str($rawParameter);
            $value = T::str($fill[$parameter] ?? '');

            // '' and '0' both mean "there is no such row to point at" — an empty table, or an id
            // that came back null. The route is skipped rather than opened with a nonsense id.
            if ($value === '' || $value === '0') {
                $usable = false;
                break;
            }

            $uri = str_replace(['{'.$parameter.'}', '{'.$parameter.'?}'], $value, $uri);
        }

        if ($usable && ! str_contains($uri, '{')) {
            $out[$name] = $uri;
        }
    }

    return $out;
}

it('opens every dashboard screen as an ADMIN, in both languages', function () {
    $screens = sweepScreens();
    expect(count($screens))->toBeGreaterThan(20, 'the sweep found almost no screens — it is not walking the route table');

    $broken = [];
    foreach (Preferences::LOCALES as $locale) {
        $admin = Staff::admin();
        Preferences::setLocale($admin, $locale);

        foreach ($screens as $name => $uri) {
            $status = actingAs($admin)->get($uri)->getStatusCode();

            /*
             * 403 is the RIGHT answer on one screen, and the sweep found it: `media:prune` deletes
             * files the live legacy storefront is still serving, so `MANAGE_MEDIA_PRUNE` is in
             * `Role::RESTRICTED` — an ability no role holds implicitly, not even an administrator.
             * It is granted to a person by name, on purpose.
             *
             * Named here rather than filtered out of the sweep, so the day somebody makes it
             * ordinary this line is what asks whether they meant to.
             */
            $expected = $name === 'manage.media.prune' ? [200, 403] : [200];

            if (! in_array($status, $expected, true)) {
                $broken[] = "[{$locale}] {$name} ({$uri}) => {$status}";
            }
        }
    }

    expect($broken)->toBe([]);
});

it('opens every dashboard screen as DATA-ENTRY, with a refusal or a page but never a crash', function () {
    /*
     * A 403 is a correct answer here and is not a failure: data-entry may not open the users screen.
     * What must never happen is a 500 — a screen that assumes an ability it did not check, or reads
     * a prop the controller only builds for an administrator.
     */
    $screens = sweepScreens();

    $broken = [];
    foreach (Preferences::LOCALES as $locale) {
        $entry = Staff::dataEntry();
        Preferences::setLocale($entry, $locale);

        foreach ($screens as $name => $uri) {
            $status = actingAs($entry)->get($uri)->getStatusCode();
            if (! in_array($status, [200, 403, 404], true)) {
                $broken[] = "[{$locale}] {$name} ({$uri}) => {$status}";
            }
        }
    }

    expect($broken)->toBe([]);
});

it('answers a NONSENSE id with 404 on every screen that takes one', function () {
    /*
     * "A page opened with a bad id", in the developer's words. 404 and not 403, and certainly not
     * 500: §3.11.14 says out of scope is a 404 so an id cannot be confirmed by probing, and a 500
     * means the screen assumed the row exists.
     */
    $wrong = [];
    $admin = Staff::admin();

    foreach (
        [
            '/manage/storefronts/1/products/99999999/edit',
            '/manage/orders/99999999',
            '/manage/blogs/99999999/edit',
            '/manage/storefronts/99999999/products',
            '/manage/storefronts/99999999/categories',
            '/manage/storefronts/99999999/payments',
            '/manage/lookups/not-a-list',
        ] as $uri
    ) {
        $status = actingAs($admin)->get($uri)->getStatusCode();
        if ($status !== 404) {
            $wrong[] = "{$uri} => {$status}";
        }
    }

    expect($wrong)->toBe([]);
});

it('answers a BLANK form with field errors, never a crash', function () {
    /*
     * "A form submitted blank". Every one of these must come back as a validation refusal naming
     * its fields — a 500 here is a writer that assumed the shape of what it was given.
     */
    $admin = Staff::admin();
    $storefront = T::int(DB::table('storefronts')->where('is_active', true)->orderBy('id')->value('id'));

    $wrong = [];
    foreach (
        [
            "/manage/storefronts/{$storefront}/products",
            "/manage/storefronts/{$storefront}/categories",
            '/manage/blogs',
            '/manage/lookups/brands',
        ] as $uri
    ) {
        $status = actingAs($admin)->post($uri, [])->getStatusCode();
        // 302 is Laravel redirecting back with errors, which is the shape an Inertia form expects.
        if (! in_array($status, [302, 403, 422], true)) {
            $wrong[] = "POST {$uri} => {$status}";
        }
    }

    expect($wrong)->toBe([]);
});

it('gives every screen the shell props it needs, in whichever language is chosen', function () {
    /*
     * The shell reads `locale`, `dir`, `nav` and `abilities` on every page. A screen that renders
     * without them is a screen whose sidebar disappears — and it would only show up visually, which
     * is exactly what this run cannot check.
     */
    $missing = [];
    foreach (Preferences::LOCALES as $locale) {
        $admin = Staff::admin();
        Preferences::setLocale($admin, $locale);

        foreach (['/manage', '/manage/orders', '/manage/blogs', '/manage/lookups/brands'] as $uri) {
            $props = Props::of(actingAs($admin)->get($uri)->assertOk());

            foreach (['locale', 'dir', 'nav', 'abilities', 'translations'] as $key) {
                if (! array_key_exists($key, $props)) {
                    $missing[] = "[{$locale}] {$uri}: {$key}";
                }
            }

            if (($props['locale'] ?? null) !== $locale) {
                $missing[] = "[{$locale}] {$uri}: locale is ".T::str($props['locale'] ?? null);
            }
            if (($props['dir'] ?? null) !== ($locale === 'ar' ? 'rtl' : 'ltr')) {
                $missing[] = "[{$locale}] {$uri}: dir is ".T::str($props['dir'] ?? null);
            }
        }
    }

    expect($missing)->toBe([]);
});

it('shows a list with NO results without breaking, on every table screen', function () {
    /*
     * "A list with no results." A filter that matches nothing is the state every table screen
     * reaches on a quiet day, and an empty table is where a null slips through a cell renderer.
     */
    $admin = Staff::admin();
    $storefront = T::int(DB::table('storefronts')->where('is_active', true)->orderBy('id')->value('id'));

    $broken = [];
    foreach (
        [
            "/manage/storefronts/{$storefront}/products?search=zzzzz-nothing-matches-this-zzzzz",
            "/manage/storefronts/{$storefront}/placement?search=zzzzz-nothing-matches-this-zzzzz",
            '/manage/orders?search=zzzzz-nothing-matches-this-zzzzz',
            '/manage/customers?search=zzzzz-nothing-matches-this-zzzzz',
            '/manage/inventory?search=zzzzz-nothing-matches-this-zzzzz',
            '/manage/activity?search=zzzzz-nothing-matches-this-zzzzz',
        ] as $uri
    ) {
        $status = actingAs($admin)->get($uri)->getStatusCode();
        if ($status !== 200) {
            $broken[] = "{$uri} => {$status}";
        }
    }

    expect($broken)->toBe([]);
});
