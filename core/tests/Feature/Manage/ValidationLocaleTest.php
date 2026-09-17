<?php

use App\Domain\Access\Preferences;
use Illuminate\Support\Facades\Lang;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\put;

/*
 * Every locale the dashboard offers must refuse in that locale (🟠-3, 2026-09-17).
 *
 * ── The finding ──────────────────────────────────────────────────────────────────────────────
 *
 * Wave 4D put all 42 screens and all 49 server files on the translation seam, and validation was
 * the one path where the language still came from Laravel rather than from the operator. With no
 * `lang/ar/validation.php`, `__()` fell through to `vendor/.../lang/en/validation.php` and an
 * ARABIC operator was refused in ENGLISH — "The selling price field must be a number." — on a
 * right-to-left screen where every other word is Arabic.
 *
 * The ratchets could not see it: they scan `resources/js` and `app/`, and this was a file that did
 * not exist.
 */

it('has validation messages for every locale the dashboard offers', function () {
    /*
     * Driven from `Preferences::LOCALES` rather than a hard-coded pair, so adding a third language
     * fails here until its validation file exists — which is the whole point. `fallback: false`
     * because the fallback is exactly what hid this: with it on, every locale "has" messages.
     */
    $missing = [];

    foreach (Preferences::LOCALES as $locale) {
        foreach (['required', 'numeric', 'email'] as $rule) {
            $line = Lang::get('validation.'.$rule, [], $locale, false);

            if (! is_string($line) || $line === 'validation.'.$rule) {
                $missing[] = "{$locale}: validation.{$rule}";
            }
        }
    }

    expect($missing)->toBe(
        [],
        "A locale has no validation messages, so its operators are refused in another language.\n"
        .'Add lang/<locale>/validation.php — Laravel ships an English one in vendor to start from.'
    );
});

it('names the FIELD in the operator’s language, not the database column', function () {
    /*
     * The half that actually matters. Arabic messages with English column names produce
     * «حقل selling_price مطلوب» — a refusal that names a column rather than the box on screen.
     */
    $missing = [];

    foreach (Preferences::LOCALES as $locale) {
        $attributes = Lang::get('validation.attributes', [], $locale, false);

        if (! is_array($attributes)) {
            $missing[] = "{$locale}: no attributes map at all";

            continue;
        }

        // A sample across the areas that refuse most: catalogue, money, promotions, access.
        foreach (['selling_price', 'primary_category_id', 'storefronts', 'ends_at', 'email'] as $field) {
            if (! isset($attributes[$field]) || ! is_string($attributes[$field]) || $attributes[$field] === '') {
                $missing[] = "{$locale}: attributes.{$field}";
            }
        }
    }

    expect($missing)->toBe([], 'these fields would be named by their column in a refusal');
});

it('refuses an Arabic operator in Arabic, through a real request', function () {
    /*
     * The structural checks above prove the FILE is there. This drives a real `validate()` with the
     * operator's locale set, because that is the only thing that proves the wiring — a lang file
     * nothing resolves against would pass both tests above and change nothing on screen.
     */
    $admin = Staff::admin();
    Preferences::setLocale($admin, 'ar');
    actingAs($admin);

    $response = put('/manage/storefronts/1', []);
    $response->assertSessionHasErrors('name');

    $message = T::err('name');

    // Arabic message AND the Arabic field name — «حقل الاسم مطلوب.»
    expect($message)->toContain('مطلوب')
        ->and($message)->toContain('الاسم')
        // …and nothing of Laravel's English survives.
        ->and($message)->not->toContain('field')
        ->and($message)->not->toContain('required');
});

it('refuses an English operator in English', function () {
    // The other direction, so the fix cannot have simply pinned everything to Arabic.
    $admin = Staff::admin();
    Preferences::setLocale($admin, 'en');
    actingAs($admin);

    put('/manage/storefronts/1', [])->assertSessionHasErrors('name');

    $message = T::err('name');

    expect($message)->toContain('required')
        ->and($message)->not->toContain('مطلوب');
});
