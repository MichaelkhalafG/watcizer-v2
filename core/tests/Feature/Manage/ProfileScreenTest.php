<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Access\Preferences;
use App\Transform\LegacySource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

/*
 * The operator's own profile (wave 4D).
 *
 * ── The claim this file exists to defend ────────────────────────────────────────────────────
 *
 * A profile screen is the single most likely place for a legacy write to appear by accident,
 * because a profile screen is SUPPOSED to edit a name, an e-mail and a password, and all three of
 * those live in `users` — a shared table core may not write (AGENTS §3).
 *
 * So the assertions are not "the form renders". They are: the save touches exactly one table, that
 * table is core-owned, and the 65-table legacy digest is byte-identical afterwards. That last one
 * is the acceptance test AGENTS names for anything in this area, and it is the one that would fail
 * if a future edit quietly added `$user->update(...)` to the controller.
 */

beforeEach(function () {
    DB::table('core_user_preferences')->delete();
});

it('renders identity READ-ONLY, with the reason and the grants', function () {
    $user = Staff::admin();
    actingAs($user);

    $props = Props::of(get('/manage/profile'));
    $identity = T::arr($props['identity'] ?? null);

    expect(T::str($identity['email'] ?? null))->toBe(T::str($user->getAttribute('email')))
        // The sentence is a prop, not a comment: the operator has to be able to READ why there is
        // no edit control, or they go looking for one that cannot exist.
        ->and(T::str($props['identity_notice'] ?? null))->not->toBe('')
        /*
         * The sentence no longer names the TABLE (D-18). It used to read "…يكتب في جدول واحد
         * يملكه النظام الجديد (core_user_preferences)…", and the table name is not something a
         * reader can check, act on, or care about — what the sentence is FOR is the promise in its
         * second half, which is what this now asserts.
         */
        ->and(T::str($props['saves_notice'] ?? null))->not->toContain('core_user_preferences')
        ->and(T::str($props['saves_notice'] ?? null))->toContain('حسابك')
        ->and($props['grants'] ?? null)->toBeArray();

    // …and what it may save is offered, with today's value.
    expect(T::str($props['locale'] ?? null))->toBe(Preferences::DEFAULT_LOCALE)
        ->and($props['locales'] ?? null)->toBeArray();
});

it('saves the language, and it comes back', function () {
    $user = Staff::admin();
    actingAs($user);

    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();

    expect(Preferences::localeFor($user))->toBe('en')
        ->and(T::str(Props::of(get('/manage/profile'))['locale'] ?? null))->toBe('en');

    // Saving twice updates the one row rather than growing a second.
    put('/manage/profile', ['locale' => 'ar'])->assertSessionHasNoErrors();

    expect(T::int(DB::table('core_user_preferences')->where('user_id', $user->getAuthIdentifier())->count()))->toBe(1)
        ->and(Preferences::localeFor($user))->toBe('ar');
});

it('refuses a language this dashboard does not have', function () {
    actingAs(Staff::admin());

    put('/manage/profile', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    put('/manage/profile', ['locale' => ''])->assertSessionHasErrors('locale');

    // …and the writer refuses too, so a console caller meets the same door as the screen.
    expect(fn () => Preferences::setLocale(Staff::admin(), 'fr'))->toThrow(ValidationException::class);

    expect(DB::table('core_user_preferences')->exists())->toBeFalse();
});

it('falls back to Arabic for somebody who has never chosen, with no row at all', function () {
    $user = Staff::admin();

    // The default costs no row: an operator who never opens this screen has no record anywhere.
    expect(DB::table('core_user_preferences')->where('user_id', $user->getAuthIdentifier())->exists())->toBeFalse()
        ->and(Preferences::localeFor($user))->toBe('ar')
        ->and(Preferences::localeFor(null))->toBe('ar');
});

it('leaves the 65-table legacy digest IDENTICAL across a full profile save', function () {
    $user = Staff::admin();
    actingAs($user);

    $before = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    get('/manage/profile')->assertOk();
    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();
    get('/manage/profile')->assertOk();

    // The acceptance test AGENTS §3 names. If a future edit adds a name or password field that
    // writes `users`, this is the line that goes red.
    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($before);
});

it('touches core_user_preferences and NOTHING else on the clean side', function () {
    $user = Staff::admin();
    actingAs($user);

    // Every core table except the one this screen owns, checksummed before and after.
    $others = [];
    foreach (CoreChecksumCommand::CORE_TABLES as $table) {
        if ($table !== 'core_user_preferences') {
            $others[] = $table;
        }
    }

    $before = CoreChecksumCommand::compute($others)['digest'];

    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();

    expect(CoreChecksumCommand::compute($others)['digest'])->toBe($before)
        // …and the one table it DOES own actually changed, or the assertion above proves nothing.
        ->and(T::int(DB::table('core_user_preferences')->count()))->toBe(1);
});

it('offers no route that could write the accounts table', function () {
    // Two routes, both on `profile`, and the write one validates exactly one key. This asserts the
    // shape from the OUTSIDE: a name or a password sent to it is ignored, not accepted.
    actingAs(Staff::admin());

    put('/manage/profile', [
        'locale' => 'en',
        'first_name' => 'Injected',
        'last_name' => 'Injected',
        'email' => 'injected@example.com',
        'password' => 'injected-password',
    ])->assertSessionHasNoErrors();

    $user = Staff::admin();
    expect(T::str($user->getAttribute('first_name')))->not->toBe('Injected')
        ->and(T::str($user->getAttribute('email')))->not->toBe('injected@example.com');
});

it('is the OWN profile: there is no id to pass and no other profile to reach', function () {
    actingAs(Staff::dataEntry());

    // Data-entry has no `manage-users` ability and still has a profile — it is theirs.
    $props = Props::of(get('/manage/profile'));
    expect(T::str(T::arr($props['identity'] ?? null)['email'] ?? null))->not->toBe('');

    // No parameterised variant exists.
    get('/manage/profile/1')->assertNotFound();
});

it('refuses an account with no dashboard grant', function () {
    actingAs(Staff::customer());

    get('/manage/profile')->assertForbidden();
    put('/manage/profile', ['locale' => 'en'])->assertForbidden();
});
