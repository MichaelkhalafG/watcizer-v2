<?php

use App\Domain\Access\Preferences;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/*
 * The sign-in screen (item 16, developer 2026-09-18).
 *
 * ── The defect this file exists because of ──────────────────────────────────────────────────
 *
 * `LoginController` refuses a bad sign-in with `trans('auth.failed')`, and `lang/ar/auth.php` did
 * not exist. So the translator fell through to `vendor/.../lang/en/auth.php` and an Arabic operator,
 * on a right-to-left page where every other word was Arabic, was told:
 *
 *     These credentials do not match our records.
 *
 * It was found by LOOKING at the rendered page, not by reading the source — which is the whole
 * lesson. The sentence lives in the one place a reader reaches only when something has already gone
 * wrong, so a full i18n pass walked straight past it, twice.
 *
 * ── The three cases, and the one that cannot be separated ───────────────────────────────────
 *
 * The developer asked that an operator know "whether the email is unknown, the password is wrong,
 * or they have no dashboard access, without us leaking which accounts exist". Two of those three
 * can be told apart honestly. The first two cannot be told apart from each other WITHOUT the leak
 * they named in the same sentence: a form that says "no such address" is a form that answers "does
 * this address have an account here?" for anybody who asks.
 *
 * So they share one message, the screen prints what to check underneath it, and it says plainly
 * that it will not name which half is wrong and why. That is the honest maximum, and these tests
 * pin all three states.
 */

it('refuses an unknown account in the operator’s own language', function () {
    // Both files, so the pair cannot be half-published.
    foreach (Preferences::LOCALES as $locale) {
        expect(is_file(base_path("lang/{$locale}/auth.php")))
            ->toBeTrue("lang/{$locale}/auth.php is missing — the framework's English would show through");
    }

    // Arabic is the default locale, and this is what a signed-out visitor gets.
    $arabic = T::str(Lang::get('auth.failed', [], 'ar'));
    expect($arabic)->toMatch('/\p{Arabic}/u', 'auth.failed is not Arabic for an Arabic operator')
        ->and($arabic)->not->toBe('auth.failed', 'the key resolved to itself — the file is not loading');

    // …and the throttle message too, which is the other one a person sees under stress.
    expect(T::str(Lang::get('auth.throttle', ['seconds' => 30], 'ar')))->toMatch('/\p{Arabic}/u');
});

it('gives ONE message for an unknown address and for a wrong password', function () {
    /*
     * The anti-enumeration property, asserted directly. If these two ever diverge, this form
     * becomes a way to discover which e-mail addresses are real — and it would diverge quietly,
     * because each message on its own would look like an improvement.
     */
    $real = T::str(DB::table('users')->orderBy('id')->value('email'));
    expect($real)->not->toBe('', 'no users at all — this test proves nothing');

    // The exact sentence, asserted for BOTH — not "each produced an error", which two different
    // messages would also satisfy. Arabic, because that is the locale a signed-out visitor gets.
    $expected = T::str(Lang::get('auth.failed', [], 'ar'));

    post('/manage/login', [
        'email' => 'definitely-not-registered@example.invalid',
        'password' => 'deliberately-wrong-not-a-credential',
    ])->assertSessionHasErrors(['email' => $expected]);

    post('/manage/login', [
        'email' => $real,
        'password' => 'deliberately-wrong-not-a-credential',
    ])->assertSessionHasErrors(['email' => $expected]);
});

it('says something DIFFERENT when the account exists but cannot use the dashboard', function () {
    /*
     * This one is safe to distinguish, and that is the point: by the time it is reached the password
     * was already correct, so nothing about it helps an attacker enumerate anything.
     */
    /*
     * Read through `ManageText`, not through `Lang` — and the difference matters.
     *
     * `lang/ar/manage.php` is a deliberate EMPTY stub: every dashboard string is written
     * `ManageText::t('key', 'العربية')` and the Arabic lives at the call site, so asking `Lang`
     * for a `manage.*` key in Arabic correctly returns the key's own name. `auth.failed` is the
     * opposite shape — a FRAMEWORK string with no call site of ours — which is exactly why it needed
     * a published file and this one does not.
     */
    $message = T::str(ManageText::t('auth.no_dashboard_access', 'هذا الحساب لا يملك صلاحية الدخول إلى لوحة التحكم.'));

    expect($message)->toMatch('/\p{Arabic}/u')
        ->and($message)->not->toBe(T::str(Lang::get('auth.failed', [], 'ar')), 'the two refusals are the same sentence');

    // …and English answers it too, or an English operator reads the Arabic fallback.
    $english = require base_path('lang/en/manage.php');
    expect(T::arr($english)['auth'] ?? null)->toBeArray()
        ->and(T::arr(T::arr($english)['auth'])['no_dashboard_access'] ?? null)->toBeString();
});

it('sends a signed-in operator away from the login screen rather than showing it again', function () {
    actingAs(Staff::admin())->get('/manage/login')->assertRedirect();
});

it('renders for a signed-out visitor, in the default language, with no user lookup', function () {
    // The one screen with no session, so it must not reach for a preference that cannot exist.
    \Pest\Laravel\get('/manage/login')->assertOk();

    expect(Preferences::localeFor(null))->toBe(Preferences::DEFAULT_LOCALE);
});
