<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Props;
use Tests\Support\Routes;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Users & roles (wave 4C, AGENTS §2.7 and §2.18).
 *
 * The claim used to be wholly negative — that this screen could not touch the shared `users` table
 * at all. Since 2026-09-20 it can do exactly one thing to it: CREATE a dashboard account, through
 * `DashboardAccounts`, because on the standalone deployment nothing else writes that table and the
 * old "create it on the storefront or in the old dashboard" workflow had nowhere left to happen.
 *
 * So the claim is now bounded rather than absent, and it is proven the same three ways: a GRANT
 * still never conjures an account, the surface's writes are named exactly, and no write anywhere
 * takes an account id — which is the shape an edit or a delete would have. The creation flow
 * itself, and the guard that refuses every other write, live in `AccountWriteGuardTest`.
 */

it('grants a role to an EXISTING account without touching the users table', function () {
    $target = Staff::customer();            // a real account with no grant
    $before = User::query()->count();
    $email = T::str($target->getAttribute('email'));

    actingAs(Staff::admin())
        ->post('/manage/users/grants', ['email' => $email, 'role' => Role::DataEntry->value])
        ->assertRedirect();

    expect(app(Roles::class)->can($target->fresh() ?? $target, Role::MANAGE_CATALOG))->toBeTrue()
        // The one write is core's own table…
        ->and(DB::table('core_user_roles')->where('user_id', $target->getKey())->where('role', Role::DataEntry->value)->exists())->toBeTrue()
        // …and `users` is exactly as large as it was.
        ->and(User::query()->count())->toBe($before);
});

it('refuses a GRANT to an e-mail that has no account, and points at the form that makes one', function () {
    $before = User::query()->count();

    actingAs(Staff::admin())
        ->post('/manage/users/grants', ['email' => 'nobody-here@example.test', 'role' => Role::Admin->value])
        ->assertSessionHasErrors('email');

    /*
     * No account is conjured to satisfy a grant — `Rule::exists` on this route is unchanged, and
     * creating one is a different, deliberate request to `POST /manage/users`. What changed on
     * 2026-09-20 is the SENTENCE: it used to send the operator to "the storefront or the old
     * dashboard", which on the standalone deployment is a workflow with nowhere to happen. It now
     * names the form on the same screen.
     */
    expect(User::query()->count())->toBe($before)
        ->and(T::err('email'))->toContain('إضافة موظّف')
        ->and(T::err('email'))->not->toContain('الداشبورد القديم');
});

it('scopes a grant to one storefront when asked', function () {
    $target = Staff::customer();

    actingAs(Staff::admin())->post('/manage/users/grants', [
        'email' => T::str($target->getAttribute('email')),
        'role' => Role::DataEntry->value,
        'storefront_id' => 1,
    ])->assertRedirect();

    $row = T::one(DB::table('core_user_roles')->where('user_id', $target->getKey()));

    expect(Row::nint($row, 'storefront_id'))->toBe(1)
        // …and the grant records WHO gave it, which is the only audit this table needs.
        ->and(Row::nint($row, 'granted_by'))->not->toBeNull();
});

it('refuses to let an administrator revoke their OWN admin grant', function () {
    $admin = Staff::admin();
    // A second unscoped admin, so the "last admin" rule is not what refuses this.
    $second = Staff::customer();
    app(Roles::class)->assign($second, Role::Admin, null, $admin);

    $ownGrant = T::int(
        DB::table('core_user_roles')->where('user_id', $admin->getKey())->where('role', Role::Admin->value)->value('id')
    );

    actingAs($admin)->delete("/manage/users/grants/{$ownGrant}")->assertSessionHasErrors('grant');

    expect(DB::table('core_user_roles')->where('id', $ownGrant)->exists())->toBeTrue()
        ->and(T::err('grant'))->toContain('لا يمكنك سحب صلاحية المدير من نفسك');
});

it('refuses to revoke the LAST unscoped admin grant, which would lock the dashboard', function () {
    $admin = Staff::admin();

    // Someone else's grant, so self-demotion is not what refuses it — and it is the only unscoped
    // admin grant left once the acting admin's own is scoped away.
    $other = Staff::customer();
    app(Roles::class)->assign($other, Role::Admin, null, $admin);

    // Take the acting administrator's unscoped grant out of the picture by scoping it: they keep
    // admin over storefront 1 and can still open this screen.
    DB::table('core_user_roles')->where('user_id', $admin->getKey())->where('role', Role::Admin->value)
        ->update(['storefront_id' => 1]);
    app(Roles::class)->forget($admin);

    $lastGrant = T::int(
        DB::table('core_user_roles')->where('user_id', $other->getKey())->where('role', Role::Admin->value)
            ->whereNull('storefront_id')->value('id')
    );

    /*
     * Refused EARLIER than it used to be (security audit, Finding 1, 2026-09-23). The acting
     * admin is now storefront-scoped, and a scoped admin may not touch an UNSCOPED grant at all —
     * so this is a 403 from the actor-scope check, before the last-admin guard is reached. The
     * property the test exists for is unchanged: the last global admin grant survives.
     *
     * The last-admin guard stays as defence in depth. Through this screen it is now reachable only
     * by an unscoped actor, who is itself an unscoped admin and is refused revoking its own grant
     * first — which is the point: two independent refusals, either of which keeps the dashboard
     * administrable.
     */
    actingAs($admin)->delete("/manage/users/grants/{$lastGrant}")->assertForbidden();

    expect(DB::table('core_user_roles')->where('id', $lastGrant)->exists())->toBeTrue();
});

it('revokes an ordinary grant and leaves the account alone', function () {
    $admin = Staff::admin();
    $target = Staff::customer();
    app(Roles::class)->assign($target, Role::DataEntry, null, $admin);

    $grant = T::int(DB::table('core_user_roles')->where('user_id', $target->getKey())->value('id'));
    $before = User::query()->count();

    actingAs($admin)->delete("/manage/users/grants/{$grant}")->assertRedirect();

    expect(DB::table('core_user_roles')->where('id', $grant)->exists())->toBeFalse()
        // The ACCOUNT still exists and can still shop: a revoked grant is not a disabled user.
        ->and(User::query()->whereKey($target->getKey())->exists())->toBeTrue()
        ->and(User::query()->count())->toBe($before);
});

it('never lists the whole users table, and caps the search at twenty', function () {
    $admin = Staff::admin();

    // With no search term, the screen shows grants only — `users` holds every customer and a
    // dashboard has no business paging through them.
    $props = Props::of(actingAs($admin)->get('/manage/users')->assertOk());
    expect(T::arr($props['search'] ?? [])['results'] ?? null)->toBe([])
        ->and(T::arr($props['search'] ?? [])['searched'] ?? null)->toBeFalse();

    // With a term that matches broadly, the list is capped.
    $props = Props::of(actingAs($admin)->get('/manage/users?q=@')->assertOk());
    expect(count(T::arr(T::arr($props['search'] ?? [])['results'] ?? [])))->toBeLessThanOrEqual(20);
});

it('shows the legacy users.type flag for contrast, and does not read it as a grant', function () {
    $admin = Staff::admin();
    $props = Props::of(actingAs($admin)->get('/manage/users')->assertOk());

    $grants = T::arr($props['grants'] ?? []);
    expect($grants)->not->toBeEmpty();

    // The flag is PRESENT on the row (so nobody argues "but they are SuperAdmin")…
    expect(T::arr($grants[0] ?? []))->toHaveKey('legacy_type');

    // …and it grants nothing: a customer whose legacy type says Admin still has no abilities.
    $customer = Staff::customer();
    expect(app(Roles::class)->hasAnyRole($customer))->toBeFalse();
});

it('writes the shared users table for CREATE and nothing else', function () {
    /*
     * ── The rule this test encodes changed on 2026-09-20, and did not disappear ──────
     *
     * It used to assert that every write on this surface named `grants` — the negative claim that
     * core never touched `users` at all. AGENTS §2.18 now permits exactly two operations on that
     * table, one of which lives here: creating a dashboard account. So the assertion is no longer
     * "no writes", it is "these writes and no others", which is the stricter and more useful shape.
     *
     * What must stay impossible is an EDIT or a DELETE of an account. Revoking access is a deleted
     * GRANT — a `core_user_roles` row — and `manage/users/grants/{grant}` is that route. A future
     * `PUT /manage/users/{user}` or `DELETE /manage/users/{user}` fails here before it can reach a
     * table holding every customer account, which is the same protection the old test gave.
     */
    $writes = Routes::writeUris('manage/users');

    expect($writes)->not->toBeEmpty();

    foreach ($writes as $uri) {
        $isCreate = $uri === 'manage/users';
        $isGrant = str_contains($uri, 'grants');

        expect($isCreate || $isGrant)->toBeTrue(
            "a write on the users surface must be the account CREATE or a grant, found [{$uri}]"
        );

        // …and no write may carry an account id in its path. That is the shape an edit or a delete
        // would take, and `{grant}` is a grant id, not a person.
        if (! $isGrant) {
            expect(str_contains($uri, '{'))->toBeFalse(
                "no users write may take an account id — found [{$uri}]"
            );
        }
    }

    // Named exactly, so adding a route is a decision somebody makes here on purpose.
    expect($writes)->toBe(['manage/users', 'manage/users/grants', 'manage/users/grants/{grant}']);
});
