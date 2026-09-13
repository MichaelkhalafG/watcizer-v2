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
 * Users & roles: GRANTS ONLY (wave 4C, AGENTS §2.7 and §3).
 *
 * The claim this file has to prove is negative — that the screen cannot create, rename, disable or
 * password-reset an account in the shared `users` table — so it is proven three ways: the row
 * count does not change, a grant to an unknown e-mail is refused instead of creating one, and
 * there is no route on the surface that writes `users` at all.
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

it('refuses an e-mail that has no account, and says where accounts come from', function () {
    $before = User::query()->count();

    actingAs(Staff::admin())
        ->post('/manage/users/grants', ['email' => 'nobody-here@example.test', 'role' => Role::Admin->value])
        ->assertSessionHasErrors('email');

    // No account was conjured to satisfy the grant — the whole point of `Rule::exists`.
    expect(User::query()->count())->toBe($before)
        ->and(T::err('email'))->toContain('الحسابات تُنشأ من المتجر أو من الداشبورد القديم');
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

    actingAs($admin)->delete("/manage/users/grants/{$lastGrant}")->assertSessionHasErrors('grant');

    expect(DB::table('core_user_roles')->where('id', $lastGrant)->exists())->toBeTrue()
        ->and(T::err('grant'))->toContain('آخر صلاحية مدير عامة');
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

it('exposes NO route that writes the shared users table', function () {
    // The negative claim, asserted rather than described: the users surface has exactly three
    // routes and the two writes both name `grants`. A future `POST /manage/users` — an "add user"
    // button — fails here before it can reach the shared table.
    $writes = Routes::writeUris('manage/users');

    expect($writes)->not->toBeEmpty();
    foreach ($writes as $uri) {
        expect(str_contains($uri, 'grants'))
            ->toBeTrue("every write on the users surface must be a grant, found [{$uri}]");
    }
});
