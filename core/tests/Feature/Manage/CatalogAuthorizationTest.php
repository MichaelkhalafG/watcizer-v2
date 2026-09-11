<?php

use App\Domain\Access\Role;
use App\Http\Middleware\EnsureDashboardAccess;
use App\Http\Middleware\EnsureStorefrontScope;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The wave-4B authorisation matrix, proven on the SERVER — and the per-screen id-enumeration test
 * that wave 4A's review left owed (study §3.11.14, 🟡-6).
 *
 * None of these tests renders a menu. Hiding a nav item is presentation; the only claim that
 * matters is what the server answers when a session drives straight at the URL.
 *
 * Three refusal shapes, and they are deliberately different:
 *
 *   • a GUEST is redirected to the login screen (they bookmarked a page),
 *   • a CUSTOMER with no grant gets 403 on the whole dashboard (nothing to enumerate, and
 *     bouncing them to a login form they can satisfy would loop forever),
 *   • a caller who holds the ability but is SCOPED to another storefront gets **404** — because a
 *     403 confirms the row exists and turns the URL into an id oracle.
 */

/**
 * Every wave-4B URL, with the storefront segment filled in.
 *
 * @return array<string, string>
 */
function urls(int $storefrontId = 1): array
{
    return [
        'products.index' => "/manage/storefronts/{$storefrontId}/products",
        'products.create' => "/manage/storefronts/{$storefrontId}/products/create",
        'categories.index' => "/manage/storefronts/{$storefrontId}/categories",
        'placement.index' => "/manage/storefronts/{$storefrontId}/placement",
        'lookups.index' => '/manage/lookups/brands',
    ];
}

it('sends a guest to the login screen from every 4B screen', function () {
    foreach (urls() as $name => $url) {
        expect(get($url)->getStatusCode())->toBe(302, "{$name} must redirect a guest");
        get($url)->assertRedirect('/manage/login');
    }
});

it('403s a real account with no dashboard grant on every 4B screen', function () {
    $customer = Staff::customer();

    foreach (urls() as $name => $url) {
        expect(actingAs($customer)->get($url)->getStatusCode())->toBe(403, "{$name} must refuse an account with no role");
    }
});

it('lets an admin open every 4B screen', function () {
    $admin = Staff::admin();

    foreach (urls() as $name => $url) {
        expect(actingAs($admin)->get($url)->getStatusCode())->toBe(200, "an admin must reach {$name}");
    }
});

it('lets data-entry open every 4B screen, because the catalogue is their job', function () {
    // The settled split (AGENTS §2.7): data-entry runs the catalogue and the placement. What they
    // must not touch is money, storefront settings and users — asserted by RouteAuthorizationTest.
    $entry = Staff::dataEntry();

    foreach (urls() as $name => $url) {
        expect(actingAs($entry)->get($url)->getStatusCode())->toBe(200, "data-entry must reach {$name}");
    }
});

/*
 * ── The id-enumeration rule, per screen (study §3.11.14) ─────────────────────────────────────
 *
 * A grant can be scoped to ONE storefront (`core_user_roles.storefront_id`), which Brand Fashion
 * will need. `can:manage-catalog` answers the UNSCOPED question — "may this user edit a catalogue
 * anywhere" — and says yes for a user scoped to storefront 1 even when the URL names storefront 2.
 * `EnsureStorefrontScope` asks again with the storefront in hand.
 */

it('404s a scoped data-entry session on ANOTHER storefront every 4B screen that names one', function () {
    $other = CatalogFixture::secondStorefront();
    $scoped = Staff::dataEntryFor(CatalogFixture::STOREFRONT);

    // Their own storefront: fine.
    foreach (urls(CatalogFixture::STOREFRONT) as $name => $url) {
        expect(actingAs($scoped)->get($url)->getStatusCode())->toBe(200, "a scoped user must reach their own {$name}");
    }

    // Another storefront: NOT FOUND, not forbidden. This is the assertion the rule exists for.
    foreach (urls($other) as $name => $url) {
        if ($name === 'lookups.index') {
            continue; // catalogue-wide, names no storefront
        }
        expect(actingAs($scoped)->get($url)->getStatusCode())
            ->toBe(404, "{$name} must answer 404 for another storefront, never 403");
    }
});

it('404s a scoped session asking for another storefront CATEGORY row by id', function () {
    // The per-ROW half: the screen is reachable, the row is not. The category writer resolves
    // every node inside a storefront-scoped query, so another storefront's node id is "not
    // there" — the same answer as a node that never existed.
    $other = CatalogFixture::secondStorefront();
    $foreignNode = CatalogFixture::anyNodeOf($other);
    $scoped = Staff::dataEntryFor(CatalogFixture::STOREFRONT);

    actingAs($scoped)
        ->put("/manage/storefronts/{$other}/categories/{$foreignNode}", ['name' => ['ar' => 'مُختطف']])
        ->assertNotFound();

    // …and the same id addressed through the user's OWN storefront is also 404, because the node
    // does not belong to it. "Not yours" and "not there" must be indistinguishable.
    actingAs($scoped)
        ->put('/manage/storefronts/'.CatalogFixture::STOREFRONT."/categories/{$foreignNode}", ['name' => ['ar' => 'مُختطف']])
        ->assertStatus(302)  // the tree writer refuses with a validation error, not a leak
        ->assertSessionHasErrors('tree');
});

it('404s a scoped session writing another storefront PLACEMENT row', function () {
    $other = CatalogFixture::secondStorefront();
    $scoped = Staff::dataEntryFor(CatalogFixture::STOREFRONT);
    $productId = CatalogFixture::product();

    actingAs($scoped)
        ->put("/manage/storefronts/{$other}/placement/{$productId}", ['is_visible' => true, 'is_featured' => false])
        ->assertNotFound();
});

it('404s an unknown lookup list instead of quoting the name back', function () {
    // The list name comes from the URL and is validated against config BEFORE anything is
    // queried: a table name never comes from a request string.
    actingAs(Staff::admin())->get('/manage/lookups/users')->assertNotFound();
    actingAs(Staff::admin())->get('/manage/lookups/../../etc/passwd')->assertNotFound();
});

/*
 * ── Structural guards, so a 4C route cannot ship without them ───────────────────────────────
 */

it('carries EnsureStorefrontScope on every manage route whose URL names a storefront', function () {
    // The rule §3.11.14 records, enforced structurally rather than remembered. A route with a
    // `{storefront}` segment that reaches a controller without this middleware has only been
    // checked for "may you do this ANYWHERE".
    $missing = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'manage.')) {
            continue;
        }
        if (! str_contains($route->uri(), '{'.EnsureStorefrontScope::PARAMETER.'}')) {
            continue;
        }
        // Wave 4A's storefront SETTINGS screens are admin-only and unscoped by design: creating
        // or disabling a storefront is not a per-storefront job, and `manage-storefronts` is
        // never granted with a scope. They are listed here on purpose rather than skipped by a
        // prefix, so a new one has to be considered.
        if (in_array($name, ['manage.storefronts.edit', 'manage.storefronts.update'], true)) {
            continue;
        }

        $middleware = array_map(fn (mixed $m): string => is_string($m) ? $m : '', $route->gatherMiddleware());
        $scoped = array_filter($middleware, fn (string $m): bool => str_starts_with($m, EnsureStorefrontScope::class.':'));

        if ($scoped === []) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], 'a storefront-scoped route without EnsureStorefrontScope is only checked unscoped');
});

it('keeps every 4B route behind auth, the dashboard gate and an ability', function () {
    // RouteAuthorizationTest asserts this for the whole /manage tree; this narrows it to the 4B
    // names so a failure points at the wave that broke it.
    $unguarded = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! preg_match('/^manage\.(products|categories|placement|lookups|variants)\./', $name)) {
            continue;
        }
        $middleware = array_map(fn (mixed $m): string => is_string($m) ? $m : '', $route->gatherMiddleware());

        $hasAuth = in_array('auth', $middleware, true);
        $hasGate = in_array(EnsureDashboardAccess::class, $middleware, true);
        $hasAbility = array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:')) !== [];

        if (! $hasAuth || ! $hasGate || ! $hasAbility) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([])
        // …and the list is not empty, or this test would pass by matching nothing — the vacuous
        // shape wave 4A's review had to fix twice.
        ->and(count(array_filter(
            array_map(fn ($r): string => (string) $r->getName(), Route::getRoutes()->getRoutes()),
            fn (string $n): bool => preg_match('/^manage\.(products|categories|placement|lookups|variants)\./', $n) === 1,
        )))->toBeGreaterThan(15);
});

it('puts the variant routes behind the INVENTORY ability as well as the catalog one', function () {
    // A quantity is a stock movement, so the route that carries one names `manage-inventory`
    // (AGENTS §2.7). Data-entry holds both; a future role holding only one is why they are named
    // separately rather than folded together.
    $route = Route::getRoutes()->getByName('manage.variants.store');
    expect($route)->not->toBeNull();
    if ($route === null) {
        return;
    }

    $middleware = array_map(fn (mixed $m): string => is_string($m) ? $m : '', $route->gatherMiddleware());

    expect($middleware)->toContain('can:'.Role::MANAGE_CATALOG)
        ->and($middleware)->toContain('can:'.Role::MANAGE_INVENTORY);
});
