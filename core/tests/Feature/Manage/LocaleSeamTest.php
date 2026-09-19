<?php

use App\Domain\Access\Preferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * i18n step 0 — the seam, and the proof that it is inert.
 *
 * The whole claim of this step is: "the plumbing exists, and NOTHING on screen changed". Both
 * halves need asserting, because each without the other is a different and worse thing:
 *
 *   • plumbing without inertness = a half-translated dashboard, which is the outcome the developer
 *     specifically said they would rather not have;
 *   • inertness without plumbing = nothing was built.
 *
 * docs/wave4d/I18N_BACKLOG_2026-09-14.md carries the costed order for steps 1-6.
 */

beforeEach(function () {
    DB::table('core_user_preferences')->delete();
});

it('ships a lang file for every language the dashboard offers', function () {
    foreach (Preferences::LOCALES as $locale) {
        $file = base_path("lang/{$locale}/manage.php");
        expect(is_file($file))->toBeTrue("lang/{$locale}/manage.php is missing");
        // The FILE, not `Lang::get('manage')`: Laravel answers a group it cannot resolve with the
        // key itself, and an EMPTY group is one it cannot resolve — which is exactly our state.
        expect(require $file)->toBeArray("lang/{$locale}/manage.php does not return an array");
    }
});

it('shares a dictionary on every dashboard page', function () {
    actingAs(Staff::admin());

    $props = Props::of(get('/manage'));

    expect($props)->toHaveKey('translations')
        ->and($props['translations'])->toBeArray();
});

it('applies the operator CHOSEN locale to the request, so __() resolves against it', function () {
    $user = Staff::admin();
    Preferences::setLocale($user, 'en');
    actingAs($user);

    $props = Props::of(get('/manage'));

    expect(T::str($props['locale'] ?? null))->toBe('en');
});

it('FLIPS the writing direction with the chosen locale (2026-09-17)', function () {
    /*
     * ── The assertion this replaced, and why the replacement is not a reversal ──────────────
     *
     * This used to assert the OPPOSITE: that `dir` stayed `rtl` even for an English operator. That
     * was correct while the shell was 1,422 inline Arabic literals — mirroring the layout around
     * Arabic text would have been a regression wearing the costume of a feature, and the test said
     * so.
     *
     * The condition it named has been met. English coverage is 99.8%, so an operator who picks
     * English reads English, and the pin became the defect: English words in a right-to-left shell.
     * The test now pins the behaviour the old one promised would come.
     */
    $user = Staff::admin();

    Preferences::setLocale($user, 'en');
    expect(T::str(Props::of(actingAs($user)->get('/manage'))['dir'] ?? null))->toBe('ltr');

    // …and Arabic still gets the layout it has always had, which is the half that must not break.
    Preferences::setLocale($user, 'ar');
    expect(T::str(Props::of(actingAs($user)->get('/manage'))['dir'] ?? null))->toBe('rtl');
});

it('derives the direction from the locale rather than storing a second decision', function () {
    // A third locale must not need anybody to remember to add a direction for it.
    expect(Preferences::directionFor('ar'))->toBe('rtl')
        ->and(Preferences::directionFor('en'))->toBe('ltr')
        ->and(Preferences::directionFor('fr'))->toBe('ltr');
});

it('changes only the LANGUAGE and the direction — the data is identical in both locales', function () {
    /*
     * ── What this test used to assert, and why the change is the point ──────────────────────
     *
     * It was called "is INERT" and it listed `dir` among the props that must be byte-identical
     * across locales. That was the correct assertion for step 0, when the dictionaries were empty
     * and the locale toggle was a seam that changed nothing anybody could see.
     *
     * Step 1 landed on 2026-09-17: the direction now follows the chosen locale, so `dir` is
     * deliberately DIFFERENT and belongs on the other side of this test. Everything else must still
     * match, and that is the half worth keeping — a locale switch must never change the DATA, only
     * how it is written and which way it runs.
     */
    $user = Staff::admin();

    Preferences::setLocale($user, 'ar');
    $arabic = Props::of(actingAs($user)->get('/manage/storefronts/1/banners'));

    Preferences::setLocale($user, 'en');
    $english = Props::of(actingAs($user)->get('/manage/storefronts/1/banners'));

    // The DATA the screen renders is the same in either language.
    foreach (['table', 'targets', 'media_type'] as $key) {
        expect(json_encode($english[$key] ?? null))->toBe(json_encode($arabic[$key] ?? null), "[{$key}] differs between locales");
    }

    // …and the two things that SHOULD differ, do.
    expect($english['locale'] ?? null)->toBe('en')
        ->and($arabic['locale'] ?? null)->toBe('ar')
        ->and($english['dir'] ?? null)->toBe('ltr')
        ->and($arabic['dir'] ?? null)->toBe('rtl');
});

it('carries the shell keys in English and nothing in Arabic', function () {
    /*
     * ── This assertion CHANGED on 2026-09-16, exactly as step 0 said it would ────────────────
     *
     * It used to require BOTH files to be empty — the proof that the seam was inert, which was the
     * whole safety argument for adding it before any translating. Its own comment said: "the day
     * these files fill up, this test changes to 'the shell keys exist' — and that change is step 1
     * announcing itself." This is that change.
     *
     * English now carries real translations. Arabic stays EMPTY and always will: every call site
     * is `t('key', 'العربية')`, so the Arabic literal is the fallback and lives in the component.
     * A populated `lang/ar/manage.php` would be a second source for a thousand strings that can
     * drift from the first — see that file's header, and `TranslationCoverageTest`.
     */
    expect(require base_path('lang/ar/manage.php'))
        ->toBe([], 'lang/ar/manage.php must stay a stub — the Arabic is the fallback in the component');

    $english = require base_path('lang/en/manage.php');
    expect($english)->not->toBe([], 'lang/en/manage.php is empty — the extraction has not started');
    expect($english)->toHaveKey('shell');
    expect($english)->toHaveKey('common');

    // A missing key resolves to its own name, which is what the JS helper treats as "not found"
    // — and what `HandleInertiaRequests::dictionary()` turns into an empty map.
    expect(Lang::get('manage.nothing.here', [], 'en'))->toBe('manage.nothing.here');

    /*
     * …and the shared dictionary is a FLAT map, not the string 'manage'.
     *
     * It was asserted empty while the seam was inert. Now it carries the English entries for
     * whoever is reading in English — flattened to `common.save` style keys, which is the shape
     * `useT()` looks up. The Arabic reader gets an empty map and the component's own literals,
     * which is the same rendering by a different route.
     */
    actingAs(Staff::admin());
    Preferences::setLocale(Staff::admin(), 'en');

    $shared = T::arr(Props::of(get('/manage'))['translations'] ?? null);

    expect($shared)->not->toBe([], 'an English reader got no dictionary');
    expect($shared)->toHaveKey('common.save');
    expect($shared['common.save'] ?? null)->toBe('Save');

    // Arabic reads from the components, so its map stays empty — by design, not by omission.
    Preferences::setLocale(Staff::admin(), 'ar');
    expect(T::arr(Props::of(get('/manage'))['translations'] ?? null))->toBe([]);
});

it('gives a signed-out visitor the default language and no user lookup', function () {
    // The login screen has no user, so the middleware must leave the locale alone rather than
    // reaching for a preference that cannot exist.
    get('/manage/login')->assertOk();

    expect(Preferences::localeFor(null))->toBe(Preferences::DEFAULT_LOCALE);
});
