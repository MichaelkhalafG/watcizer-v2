<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Http\Middleware\EnsureDashboardAccess;
use Illuminate\Support\Facades\Route;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The authorisation matrix, proven on the SERVER (wave 4A non-negotiable).
 *
 * The sidebar hides what a role cannot reach, but hiding is presentation. These tests never render
 * a menu: they drive a data-entry session and a customer session straight at the URLs and assert
 * the response code, which is the only claim that matters — a hidden link is not a locked door.
 */

it('sends a guest to the login screen instead of the dashboard', function () {
    get('/manage')->assertRedirect('/manage/login');
    get('/manage/storefronts')->assertRedirect('/manage/login');
});

it('403s a REAL account that holds no dashboard role', function () {
    // The case a redirect would break: valid credentials, a storefront account, no staff grant.
    // Bouncing them to a login form they can satisfy would loop forever, so it is a 403.
    actingAs(Staff::customer())->get('/manage')->assertForbidden();
});

it('never lets the legacy users.type enum grant dashboard access', function () {
    $customer = Staff::customer();
    // Whatever the legacy app thinks of this account — `SuperAdmin` included — core asks
    // core_user_roles and nothing else (AGENTS §2.18).
    expect(app(Roles::class)->hasAnyRole($customer))->toBeFalse();

    actingAs($customer)->get('/manage')->assertForbidden();
});

it('lets an admin reach every wave-4A route', function () {
    $admin = Staff::admin();

    actingAs($admin)->get('/manage')->assertOk();
    actingAs($admin)->get('/manage/storefronts')->assertOk();
    actingAs($admin)->get('/manage/storefronts/1/edit')->assertOk();
});

it('403s a DATA-ENTRY session on every admin-only route', function () {
    $entry = Staff::dataEntry();

    // The home screen is theirs…
    actingAs($entry)->get('/manage')->assertOk();

    // …and the storefront screens are not, at every verb.
    actingAs($entry)->get('/manage/storefronts')->assertForbidden();
    actingAs($entry)->get('/manage/storefronts/1/edit')->assertForbidden();
    actingAs($entry)->put('/manage/storefronts/1', ['name' => 'Hijacked'])->assertForbidden();
});

it('lets data-entry upload media, because 4B product forms need it', function () {
    // postJson, because that is how the uploader calls it (fetch + Accept: application/json) and
    // because a JSON request gets a 422 body instead of a redirect back to a form.
    actingAs(Staff::dataEntry())->postJson('/manage/media', [])
        // 422 (validation), NOT 403: the ability is granted, the payload is empty.
        ->assertStatus(422);
});

it('refuses media upload for an account without the media ability', function () {
    actingAs(Staff::customer())->postJson('/manage/media', [])->assertForbidden();
});

it('holds the ability matrix itself: data-entry has catalog, admin has everything', function () {
    $roles = app(Roles::class);
    $admin = Staff::admin();
    $entry = Staff::dataEntry();

    foreach (Role::ABILITIES as $ability) {
        expect($roles->can($admin, $ability))->toBeTrue("admin should hold {$ability}");
    }

    // The settled split (developer decision 2026-09-11, AGENTS §2.7): data-entry runs the shop —
    // catalog, placement, legacy content, media, viewing orders, moving fulfilment along, and
    // adjusting stock through InventoryService.
    foreach ([
        Role::VIEW_DASHBOARD, Role::MANAGE_CATALOG, Role::MANAGE_PLACEMENT, Role::MANAGE_LEGACY_CONTENT,
        Role::MANAGE_MEDIA, Role::VIEW_ORDERS, Role::MANAGE_ORDER_FULFILMENT, Role::MANAGE_INVENTORY,
    ] as $ability) {
        expect($roles->can($entry, $ability))->toBeTrue("data-entry should hold {$ability}");
    }

    // …and NOT the parts that move money or change who can do what. Cancelling an order returns
    // stock to the ledger and a refund is a financial act, which is why it is its own ability.
    foreach ([
        Role::CANCEL_ORDERS, Role::MANAGE_STOREFRONTS, Role::MANAGE_USERS, Role::MANAGE_SETTINGS, Role::MANAGE_PAYMENTS,
    ] as $ability) {
        expect($roles->can($entry, $ability))->toBeFalse("data-entry must NOT hold {$ability}");
    }
});

it('scopes a grant to one storefront when it names one', function () {
    $roles = app(Roles::class);
    $scoped = Staff::dataEntryFor(1);

    expect($roles->can($scoped, Role::MANAGE_CATALOG, 1))->toBeTrue()
        ->and($roles->can($scoped, Role::MANAGE_CATALOG, 999))->toBeFalse()
        // Unscoped question: "may they edit a catalog anywhere" — yes, on storefront 1.
        ->and($roles->can($scoped, Role::MANAGE_CATALOG))->toBeTrue()
        ->and($roles->storefrontScope($scoped))->toBe([1]);
});

it('guards EVERY /manage route with authentication and an ability', function () {
    // A structural test, so a route added in 4B cannot be published unguarded: the middleware list
    // of every manage route must contain the dashboard gate, and every route past login must
    // carry a `can:` ability.
    // EXACT names, not a prefix (review 🟡-4): `str_starts_with($name, 'manage.login')` would
    // have exempted anything someone later called `manage.login-as-user` or
    // `manage.loginhistory` — a whole unguarded screen hiding behind a prefix match.
    $openRoutes = ['manage.login', 'manage.login.store'];

    $unguarded = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'manage.') || in_array($name, $openRoutes, true)) {
            continue;
        }
        $middleware = array_map(fn (mixed $m): string => is_string($m) ? $m : '', $route->gatherMiddleware());

        $hasAuth = in_array('auth', $middleware, true);
        $hasGate = in_array(EnsureDashboardAccess::class, $middleware, true);
        $hasAbility = $name === 'manage.logout' || array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:')) !== [];

        if (! $hasAuth || ($name !== 'manage.logout' && ! $hasGate) || ! $hasAbility) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([], 'every /manage route needs auth + EnsureDashboardAccess + an ability');

    // …and the exemption list itself must still name real routes, or it is exempting nothing while
    // looking like it protects everything.
    foreach ($openRoutes as $name) {
        expect(Route::has($name))->toBeTrue("the exemption list names {$name}, which no longer exists");
    }
});
