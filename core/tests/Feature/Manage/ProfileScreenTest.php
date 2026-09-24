<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Access\Preferences;
use App\Domain\Activity\ActivityLog;
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

it('touches core_user_preferences and the activity log, and NOTHING else on the clean side', function () {
    $user = Staff::admin();
    actingAs($user);

    /*
     * Every core table except the two this save may reach, checksummed before and after.
     *
     * `core_activity_log` joined `core_user_preferences` here on 2026-10-05, when this screen
     * started recording who changed the language. It is an EXCLUSION with a reason rather than a
     * hole: the log is append-only, every audited screen writes it, and the assertion that matters
     * — that it gained exactly one entry, about this user — is made below instead of being waved
     * through by a digest.
     */
    $others = [];
    foreach (CoreChecksumCommand::CORE_TABLES as $table) {
        if (! in_array($table, ['core_user_preferences', 'core_activity_log'], true)) {
            $others[] = $table;
        }
    }

    $before = CoreChecksumCommand::compute($others)['digest'];
    $entries = DB::table('core_activity_log')->count();

    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();

    expect(CoreChecksumCommand::compute($others)['digest'])->toBe($before)
        // …and the one table it DOES own actually changed, or the assertion above proves nothing.
        ->and(T::int(DB::table('core_user_preferences')->count()))->toBe(1)
        // One entry, not none and not two.
        ->and(DB::table('core_activity_log')->count())->toBe($entries + 1);
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

it('records the language change in the activity log, with the operator who made it', function () {
    /*
     * The gap, dated. On 2026-10-05 a `core_user_preferences` row carried a changed `locale` and
     * nothing said where it came from: the row has a `user_id` and an `updated_at`, and neither
     * answers whether a PERSON saved this screen or a test run wrote it. One table, one column,
     * and the incident still could not be closed — which is the whole argument for auditing a
     * screen that writes almost nothing.
     */
    $user = Staff::admin();
    actingAs($user);

    // The name `ActivityLog` captures at write time, computed the way it computes it.
    $actor = trim(T::str($user->getAttribute('first_name')).' '.T::str($user->getAttribute('last_name')));
    $actor = $actor !== '' ? $actor : T::str($user->getAttribute('email'));

    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();

    $entry = T::one(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)
        ->where('subject_type', 'core_user_preferences'));

    $changes = T::arr(json_decode(T::str($entry->changes), true));

    // The subject is the USER: the preferences row is keyed by `user_id` and has no id of its own.
    expect(T::int($entry->subject_id))->toBe(T::int($user->getAuthIdentifier()))
        ->and(T::str($entry->action))->toBe(ActivityLog::UPDATED)
        ->and(T::str($entry->user_name))->toBe($actor)
        ->and(T::str($entry->subject_label))->toBe($actor)
        /*
         * Before AND after. `from` is `ar` rather than empty although NO ROW existed yet, because
         * the snapshot is read through `Preferences::localeFor()` — the same door the screen reads
         * it through, so it reports the language the operator was actually using.
         */
        ->and(T::arr($changes['locale'] ?? null)['from'] ?? null)->toBe('ar')
        ->and(T::arr($changes['locale'] ?? null)['to'] ?? null)->toBe('en');

    // Pressing Save again on the same language records nothing: this screen has one button, and a
    // log of every press would answer "who changed the language" with entries where nobody did.
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    put('/manage/profile', ['locale' => 'en'])->assertSessionHasNoErrors();

    expect(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)->count())->toBe(0);
});
