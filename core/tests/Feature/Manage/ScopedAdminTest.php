<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\Storefront\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * ── A storefront-scoped admin is NOT a global admin (security audit, Finding 1, 2026-09-23) ───
 *
 * The admin short-circuit in `Gate::before` asked `isAdmin()`, which ignores scope, so an admin
 * granted Brand Fashion only held every ability on every storefront. And the three grant writes
 * checked the ROLE being granted, never the ACTOR's reach: that admin could POST
 * `{role: admin, storefront_id: null}` and make itself a permanent global admin, or revoke any
 * grant by id — every other operator's, and the last global admin's.
 *
 * Each refusal below is also asserted to WRITE NOTHING, because a 403 that has already inserted
 * the grant is not a refusal.
 */

/** A second, real account to grant things to — never created, like every Staff fixture. */
function grantee(): User
{
    $user = User::query()->orderBy('id')->skip(3)->firstOrFail();
    foreach (Role::cases() as $role) {
        app(Roles::class)->revoke($user, $role, allScopes: true);
    }
    app(Roles::class)->forget($user);

    return $user;
}

function grantCount(): int
{
    return T::int(DB::table('core_user_roles')->count());
}

it('does NOT short-circuit a scoped admin through the Gate', function () {
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);

    // On its own storefront: yes. On Watchizer, asked with the storefront: no. Before the fix
    // `Gate::before` said yes to both without ever reaching the storefront.
    expect(Gate::forUser($scoped)->allows(Role::MANAGE_PAYMENTS, [Storefront::BRAND_FASHION_ID]))->toBeTrue()
        ->and(Gate::forUser($scoped)->allows(Role::MANAGE_PAYMENTS, [Storefront::WATCHIZER_ID]))->toBeFalse()
        ->and(app(Roles::class)->isUnscopedAdmin($scoped))->toBeFalse();
});

it('does not treat admin@one-storefront + an UNSCOPED data-entry grant as a global admin', function () {
    /*
     * The trap in the obvious fix. `storefrontScope()` answers null when ANY grant is unscoped,
     * so "isAdmin() && storefrontScope() === null" would have read this user as a global admin.
     */
    $user = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    app(Roles::class)->assign($user, Role::DataEntry, null);
    app(Roles::class)->forget($user);

    expect(app(Roles::class)->isUnscopedAdmin($user))->toBeFalse()
        ->and(app(Roles::class)->scopeForAbility($user, Role::MANAGE_USERS))->toBe([Storefront::BRAND_FASHION_ID])
        ->and(Gate::forUser($user)->allows(Role::MANAGE_PAYMENTS, [Storefront::WATCHIZER_ID]))->toBeFalse();
});

it('REFUSES a scoped admin minting an UNSCOPED admin — for itself or anybody', function () {
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    $target = grantee();
    $before = grantCount();

    foreach ([$scoped, $target] as $who) {
        actingAs($scoped)->post('/manage/users/grants', [
            'email' => $who->getAttribute('email'), 'role' => Role::Admin->value, 'storefront_id' => null,
        ])->assertForbidden();
    }

    expect(grantCount())->toBe($before)
        ->and(app(Roles::class)->isUnscopedAdmin($scoped->fresh() ?? $scoped))->toBeFalse();
});

it('REFUSES a scoped admin granting on ANOTHER storefront', function () {
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    $target = grantee();
    $before = grantCount();

    actingAs($scoped)->post('/manage/users/grants', [
        'email' => $target->getAttribute('email'), 'role' => Role::DataEntry->value,
        'storefront_id' => Storefront::WATCHIZER_ID,
    ])->assertForbidden();

    expect(grantCount())->toBe($before);
});

it('REFUSES the same through storeAccount, and creates NO account', function () {
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    $email = 'scoped-escalation-'.uniqid().'@example.test';

    actingAs($scoped)->post('/manage/users', [
        'first_name' => 'Minted', 'last_name' => 'Admin', 'email' => $email,
        'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password',
        'role' => Role::Admin->value, 'storefront_id' => null,
    ])->assertForbidden();

    // Checked BEFORE the account is written: a refused grant must not leave a role-less account.
    expect(DB::table('users')->where('email', $email)->exists())->toBeFalse();
});

it('REFUSES a scoped admin revoking a grant outside its scope — the last global admin included', function () {
    $global = Staff::admin();
    $globalGrant = T::int(DB::table('core_user_roles')->where('user_id', $global->id)
        ->where('role', Role::Admin->value)->whereNull('storefront_id')->value('id'));

    // The actor: a DIFFERENT account from the global admin, admin on Brand Fashion only.
    $scoped = grantee();
    app(Roles::class)->assign($scoped, Role::Admin, Storefront::BRAND_FASHION_ID);
    app(Roles::class)->forget($scoped);

    // And a grant on ANOTHER storefront, which the scoped admin must not be able to touch either.
    app(Roles::class)->assign($global, Role::DataEntry, Storefront::WATCHIZER_ID);
    $watchizerGrant = T::int(DB::table('core_user_roles')->where('user_id', $global->id)
        ->where('role', Role::DataEntry->value)->where('storefront_id', Storefront::WATCHIZER_ID)->value('id'));
    $before = grantCount();

    actingAs($scoped)->delete("/manage/users/grants/{$globalGrant}")->assertForbidden();
    actingAs($scoped)->delete("/manage/users/grants/{$watchizerGrant}")->assertForbidden();

    expect(grantCount())->toBe($before)
        ->and(DB::table('core_user_roles')->where('id', $globalGrant)->exists())->toBeTrue();
});

it('lets a scoped admin grant and revoke INSIDE its own storefront', function () {
    // The refusals must not have turned into a wall: this is the job a scoped admin is for.
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    $target = grantee();

    actingAs($scoped)->post('/manage/users/grants', [
        'email' => $target->getAttribute('email'), 'role' => Role::DataEntry->value,
        'storefront_id' => Storefront::BRAND_FASHION_ID,
    ])->assertRedirect();

    $grant = T::int(DB::table('core_user_roles')->where('user_id', $target->id)
        ->where('storefront_id', Storefront::BRAND_FASHION_ID)->value('id'));

    actingAs($scoped)->delete("/manage/users/grants/{$grant}")->assertRedirect();

    expect(DB::table('core_user_roles')->where('id', $grant)->exists())->toBeFalse();
});

it('offers a scoped admin only its own storefronts, and only the grants inside them', function () {
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);

    $props = actingAs($scoped)->get('/manage/users')->assertOk()
        ->viewData('page')['props'];

    $offered = array_map(fn (array $o): string => (string) $o['value'], $props['storefronts']);
    $grantScopes = array_unique(array_map(fn (array $g) => $g['storefront_id'], $props['grants']));

    expect($offered)->toBe([(string) Storefront::BRAND_FASHION_ID])   // no "all storefronts" entry
        ->and(array_values($grantScopes))->toBe([Storefront::BRAND_FASHION_ID]);
});

it('leaves an UNSCOPED admin exactly as it was', function () {
    $global = Staff::admin();
    $target = grantee();

    expect(app(Roles::class)->isUnscopedAdmin($global))->toBeTrue()
        ->and(Gate::forUser($global)->allows(Role::MANAGE_PAYMENTS, [Storefront::WATCHIZER_ID]))->toBeTrue()
        ->and(Gate::forUser($global)->allows(Role::MANAGE_PAYMENTS, [Storefront::BRAND_FASHION_ID]))->toBeTrue();

    actingAs($global)->post('/manage/users/grants', [
        'email' => $target->getAttribute('email'), 'role' => Role::Admin->value, 'storefront_id' => null,
    ])->assertRedirect();

    expect(DB::table('core_user_roles')->where('user_id', $target->id)
        ->where('role', Role::Admin->value)->whereNull('storefront_id')->exists())->toBeTrue();

    $props = actingAs($global)->get('/manage/users')->assertOk()->viewData('page')['props'];
    expect($props['storefronts'][0]['value'])->toBe('');                 // "all storefronts" still offered
});

it('REFUSES a scoped admin opening or editing ANOTHER storefront settings', function () {
    // The audit's step 4, reached directly rather than through the escalation: rename or disable
    // the live Watchizer storefront while holding Brand Fashion only. 404, not 403 — the
    // EnsureStorefrontScope convention, so "not yours" and "not there" are one answer.
    $scoped = Staff::adminFor(Storefront::BRAND_FASHION_ID);
    $name = DB::table('storefronts')->where('id', Storefront::WATCHIZER_ID)->value('name');

    actingAs($scoped)->get('/manage/storefronts/'.Storefront::WATCHIZER_ID.'/edit')->assertNotFound();
    actingAs($scoped)->put('/manage/storefronts/'.Storefront::WATCHIZER_ID, ['name' => 'Taken over', 'is_active' => false])
        ->assertNotFound();

    expect(DB::table('storefronts')->where('id', Storefront::WATCHIZER_ID)->value('name'))->toBe($name);

    // And its own storefront still opens.
    actingAs($scoped)->get('/manage/storefronts/'.Storefront::BRAND_FASHION_ID.'/edit')->assertOk();
});
