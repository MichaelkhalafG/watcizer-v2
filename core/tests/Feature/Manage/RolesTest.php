<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Staff;

/*
 * The roles mechanism (M1g, AGENTS §2.18) — and the two ways it could destroy access silently.
 */

it('keeps core_user_roles OUT of the rebuild drop list, or switch night locks everyone out', function () {
    // THE test this table exists to protect. `CLEAN_TABLES` is what §3.4 step 3b drops and the
    // transform rebuilds; a role grant is not transform output, it is a real decision a human
    // made. If someone adds this table to that list, switch night silently removes every
    // administrator's access at the worst possible moment.
    expect(CoreChecksumCommand::CLEAN_TABLES)->not->toContain('core_user_roles');

    // …and the table really exists, so the assertion above is about a live table and not a typo.
    expect(Schema::hasTable('core_user_roles'))->toBeTrue();
});

it('grants, re-grants idempotently and revokes', function () {
    $roles = app(Roles::class);
    $user = Staff::customer();

    expect($roles->hasAnyRole($user))->toBeFalse();

    $first = $roles->assign($user, Role::DataEntry);
    $again = $roles->assign($user, Role::DataEntry);
    expect($again->id)->toBe($first->id, 'the same grant twice is one row');

    expect($roles->rolesFor($user))->toBe([Role::DataEntry])
        ->and($roles->isAdmin($user))->toBeFalse();

    expect($roles->revoke($user, Role::DataEntry))->toBe(1)
        ->and($roles->hasAnyRole($user))->toBeFalse();
});

it('records who granted a role', function () {
    $admin = Staff::admin();
    $target = Staff::customer();

    $grant = app(Roles::class)->assign($target, Role::DataEntry, null, $admin);

    expect($grant->granted_by)->toBe($admin->id);
});

it('refuses a grant scoped to a storefront that does not exist', function () {
    expect(fn () => app(Roles::class)->assign(Staff::customer(), Role::DataEntry, 987654))
        ->toThrow(InvalidArgumentException::class);
});

it('splits orders into view, fulfil and cancel, so data-entry can work without refunding', function () {
    expect(Role::Admin->abilities())->toBe(Role::ABILITIES)
        ->and(Role::DataEntry->can(Role::MANAGE_CATALOG))->toBeTrue()
        ->and(Role::DataEntry->can(Role::MANAGE_LEGACY_CONTENT))->toBeTrue()
        // Settled 2026-09-11: they run the shop day to day…
        ->and(Role::DataEntry->can(Role::VIEW_ORDERS))->toBeTrue()
        ->and(Role::DataEntry->can(Role::MANAGE_ORDER_FULFILMENT))->toBeTrue()
        ->and(Role::DataEntry->can(Role::MANAGE_INVENTORY))->toBeTrue()
        // …but cancelling returns stock to the ledger and a refund moves money.
        ->and(Role::DataEntry->can(Role::CANCEL_ORDERS))->toBeFalse()
        ->and(Role::DataEntry->can(Role::MANAGE_PAYMENTS))->toBeFalse()
        ->and(Role::DataEntry->can(Role::MANAGE_USERS))->toBeFalse();
});

it('labels roles in Arabic and English', function () {
    expect(Role::Admin->label('ar'))->toBe('مدير النظام')
        ->and(Role::Admin->label('en'))->toBe('Administrator')
        ->and(Role::DataEntry->label('ar'))->toBe('إدخال بيانات');
});

it('never creates a user through the role command', function () {
    $exit = Artisan::call('manage:role', ['action' => 'grant', 'email' => 'ghost-'.uniqid().'@example.test', 'role' => 'admin']);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('never creates users');
});

it('grants and lists through the command', function () {
    $user = Staff::customer();

    Artisan::call('manage:role', ['action' => 'grant', 'email' => $user->email, 'role' => 'data_entry']);
    expect(Artisan::output())->toContain('granted data_entry');

    Artisan::call('manage:role', ['action' => 'list']);
    expect(Artisan::output())->toContain((string) $user->email);
});

it('warns when the last grant is revoked, because nobody can open the dashboard then', function () {
    // Clear every grant in the transaction, then revoke the last one and read the warning.
    DB::table('core_user_roles')->delete();
    $user = Staff::admin();

    Artisan::call('manage:role', ['action' => 'revoke', 'email' => $user->email, 'role' => 'admin', '--all-scopes' => true]);

    expect(Artisan::output())->toContain('No dashboard roles remain');
});

it('memoises grants per request but not across a change', function () {
    $roles = app(Roles::class);
    $user = Staff::customer();

    expect($roles->hasAnyRole($user))->toBeFalse();
    $roles->assign($user, Role::Admin);
    // assign() forgets the memo, so the very next question sees the new grant.
    expect($roles->isAdmin($user))->toBeTrue();
});
