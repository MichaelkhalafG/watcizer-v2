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

it('does NOT flip the writing direction, because the text is still Arabic', function () {
    /*
     * The one thing step 0 must not do. Mirroring the shell to `ltr` while all 1 422 labels in it
     * are Arabic would be a regression wearing the costume of a feature — so `dir` follows the
     * language the dashboard is WRITTEN in, and step 1 deletes that constant.
     */
    $user = Staff::admin();
    Preferences::setLocale($user, 'en');
    actingAs($user);

    expect(T::str(Props::of(get('/manage'))['dir'] ?? null))->toBe('rtl')
        ->and(Preferences::TEXT_LOCALE)->toBe('ar')
        ->and(Preferences::TEXT_DIR)->toBe('rtl');
});

it('is INERT: an English operator sees exactly what an Arabic one sees', function () {
    // The proof that nothing regressed. Same page, same rendered props, two locales — because the
    // dictionaries are empty and every string is still its inline literal.
    $user = Staff::admin();

    Preferences::setLocale($user, 'ar');
    $arabic = Props::of(actingAs($user)->get('/manage/storefronts/1/banners'));

    Preferences::setLocale($user, 'en');
    $english = Props::of(actingAs($user)->get('/manage/storefronts/1/banners'));

    // `locale` is the one prop allowed to differ — it is the thing that was set.
    foreach (['table', 'targets', 'media_type', 'dir'] as $key) {
        expect(json_encode($english[$key] ?? null))->toBe(json_encode($arabic[$key] ?? null), "[{$key}] differs between locales");
    }
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
