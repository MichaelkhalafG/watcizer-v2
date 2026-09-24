<?php

use App\Domain\Access\DashboardAccounts;
use App\Domain\Access\Role;
use App\Domain\Access\UserWrites;
use App\Domain\Activity\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * The DASHBOARD's two `users` operations (AGENTS §2.18): creating an account, and an operator
 * changing their own password.
 *
 * The rule used to be absolute, and the reason was concurrency: the legacy application serialised
 * every column of a `users` row into the proxied `login`/`register`/`me` responses the live
 * storefront consumed. On the standalone eleganceeg.com deployment those routes are closed, so the
 * permission was granted — first to this class alone, and since 2026-09-21 through the shared lock
 * in `UserWrites`, because Phase 1 moved customer accounts into core as well.
 *
 * The LOCK's own properties are tested in `UserWritesTest`. What is tested HERE is that the
 * dashboard's two operations still behave exactly as they did, and that the guard still refuses
 * everything else — a permission enforced by a docblock is one the next contributor breaks without
 * noticing, and the symptom would be a silently modified row in a table holding every customer
 * account.
 */

// ── the guard ────────────────────────────────────────────────────────────────────────────────

it('REFUSES a users write from anywhere that did not open the door', function () {
    $user = Staff::admin();
    $before = T::str(DB::table('users')->where('id', $user->id)->value('first_name'));

    $user->first_name = 'Should Never Land';

    expect(fn () => $user->save())->toThrow(RuntimeException::class, 'core may not write');

    // …and the row is untouched, which is the part that actually matters.
    expect(T::str(DB::table('users')->where('id', $user->id)->value('first_name')))->toBe($before);
});

it('REFUSES deleting an account outright, with no door at all', function () {
    $user = Staff::customer();

    expect(fn () => $user->delete())->toThrow(RuntimeException::class, 'core may not delete from');
    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});

it('lets a CLEAN save through, because the framework issues those and they write nothing', function () {
    /*
     * The guard checks `isDirty()`, and that is load-bearing rather than a nicety.
     *
     * Eloquent fires `saving` before it checks whether anything is dirty, and wave 4A's remember-me
     * overrides work precisely by leaving the model CLEAN — `setRememberToken()` is a no-op, so the
     * `save()` inside `updateRememberToken()` writes no columns. A guard on the CALL rather than on
     * the CHANGE turned that documented no-op into an exception and broke
     * `Auth::login($user, remember: true)`, which is the one path those overrides exist to render
     * harmless. `AuthTest` is what caught it.
     */
    $user = Staff::admin();

    expect(fn () => $user->save())->not->toThrow(RuntimeException::class);
});

// ── creating an account ──────────────────────────────────────────────────────────────────────

it('creates a dashboard account and grants its role in one operation', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $email = 'new.person.'.bin2hex(random_bytes(4)).'@example.com';

    post('/manage/users', [
        'first_name' => 'Nadia',
        'last_name' => 'Fahmy',
        'email' => $email,
        'password' => 'a-starting-password',
        'password_confirmation' => 'a-starting-password',
        'role' => Role::DataEntry->value,
        'storefront_id' => '',
    ])->assertSessionHasNoErrors();

    $row = T::one(DB::table('users')->where('email', $email));

    expect(T::str($row->first_name))->toBe('Nadia')
        // The LEGACY enum's least-privileged value, never an admin one: `type` grants power in the
        // legacy application, which is not this dashboard's to hand out.
        ->and(T::str($row->type))->toBe(DashboardAccounts::CREATED_TYPE)
        // A real bcrypt hash at the pinned cost, indistinguishable from one the storefront wrote.
        ->and(Hash::check('a-starting-password', T::str($row->password)))->toBeTrue()
        // …and the three columns core must never touch are untouched on a fresh row.
        ->and($row->remember_token)->toBeNull()
        ->and($row->last_login_at)->toBeNull();

    // The role is what actually lets them in, and it landed in the SAME operation.
    expect(DB::table('core_user_roles')->where('user_id', T::int($row->id))
        ->where('role', Role::DataEntry->value)->exists())->toBeTrue();

    $entry = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'users')->where('subject_id', T::int($row->id))
        ->where('action', ActivityLog::CREATED)->orderByDesc('id'));

    expect(T::str($entry->user_name))->toBe(Staff::nameOf($admin))
        ->and(T::str($entry->subject_label))->toBe($email);
});

it('REFUSES a duplicate e-mail with a sentence rather than a 500', function () {
    $admin = Staff::admin();
    actingAs($admin);

    post('/manage/users', [
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => T::str(DB::table('users')->orderBy('id')->value('email')),
        'password' => 'a-starting-password',
        'password_confirmation' => 'a-starting-password',
        'role' => Role::DataEntry->value,
    ])->assertSessionHasErrors('email');
});

it('REFUSES account creation to a data-entry operator', function () {
    actingAs(Staff::dataEntry());

    post('/manage/users', [
        'first_name' => 'Nope',
        'last_name' => 'Nope',
        'email' => 'nope.'.bin2hex(random_bytes(3)).'@example.com',
        'password' => 'a-starting-password',
        'password_confirmation' => 'a-starting-password',
        'role' => Role::Admin->value,
    ])->assertForbidden();
});

// ── changing a password ──────────────────────────────────────────────────────────────────────

it('changes the operator OWN password, and leaves every other column alone', function () {
    $user = Staff::admin();

    // A known starting point, written through the door so the guard does not refuse the fixture.
    UserWrites::open(DashboardAccounts::PASSWORD, function () use ($user): void {
        $user->forceFill(['password' => 'the-old-password'])->save();
    });

    $before = T::one(DB::table('users')->where('id', $user->id));

    actingAs(User::query()->findOrFail($user->id));

    put('/manage/profile/password', [
        'current_password' => 'the-old-password',
        'password' => 'the-new-password',
        'password_confirmation' => 'the-new-password',
    ])->assertSessionHasNoErrors();

    $after = T::one(DB::table('users')->where('id', $user->id));

    expect(Hash::check('the-new-password', T::str($after->password)))->toBeTrue()
        ->and(Hash::check('the-old-password', T::str($after->password)))->toBeFalse()
        // Every other column, byte for byte. This is the assertion that makes the permission
        // narrow rather than merely intended to be.
        ->and(T::str($after->first_name))->toBe(T::str($before->first_name))
        ->and(T::str($after->email))->toBe(T::str($before->email))
        ->and(T::str($after->type))->toBe(T::str($before->type))
        ->and($after->remember_token)->toBe($before->remember_token)
        ->and($after->last_login_at)->toBe($before->last_login_at);
});

it('REFUSES a wrong current password, and does not change anything', function () {
    $user = Staff::admin();
    UserWrites::open(DashboardAccounts::PASSWORD, function () use ($user): void {
        $user->forceFill(['password' => 'the-real-password'])->save();
    });

    actingAs(User::query()->findOrFail($user->id));

    put('/manage/profile/password', [
        'current_password' => 'not-the-real-password',
        'password' => 'whatever-comes-next',
        'password_confirmation' => 'whatever-comes-next',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('the-real-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
});

it('records the change WITHOUT the password itself', function () {
    $user = Staff::admin();
    UserWrites::open(DashboardAccounts::PASSWORD, function () use ($user): void {
        $user->forceFill(['password' => 'before-the-change'])->save();
    });

    actingAs(User::query()->findOrFail($user->id));
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    put('/manage/profile/password', [
        'current_password' => 'before-the-change',
        'password' => 'after-the-change',
        'password_confirmation' => 'after-the-change',
    ])->assertSessionHasNoErrors();

    $entry = T::one(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)
        ->where('subject_type', 'users')->where('subject_id', T::int($user->id)));

    $changes = T::str($entry->changes);

    /*
     * The row says a password changed and by whom. It must not say WHAT to — `password` matches
     * `ActivityLog::REDACTED_FIELDS`, so both sides are the marker. Copying the secret into a
     * second, less-guarded table would be a way of leaking it rather than auditing it.
     */
    expect($changes)->toContain(ActivityLog::REDACTED)
        ->and($changes)->not->toContain('after-the-change')
        ->and($changes)->not->toContain('before-the-change')
        ->and(T::str($entry->user_name))->toBe(Staff::nameOf($user));
});

it('REFUSES a password shorter than the declared minimum', function () {
    $user = Staff::admin();
    UserWrites::open(DashboardAccounts::PASSWORD, function () use ($user): void {
        $user->forceFill(['password' => 'long-enough-password'])->save();
    });

    actingAs(User::query()->findOrFail($user->id));

    $short = str_repeat('a', DashboardAccounts::MIN_PASSWORD - 1);

    put('/manage/profile/password', [
        'current_password' => 'long-enough-password',
        'password' => $short,
        'password_confirmation' => $short,
    ])->assertSessionHasErrors('password');
});

it('offers no route to set somebody ELSE password', function () {
    /*
     * Deliberate, and worth pinning: an administrator resetting another person's credential is a
     * second and different power over a legacy table, and it was not granted. A locked-out operator
     * gets a new account, or a hand-written row — resetting somebody's password should stay awkward.
     */
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (str_contains($uri, 'password') && str_contains($uri, '{')) {
            $routes[] = $uri;
        }
    }

    expect($routes)->toBe([]);
});
