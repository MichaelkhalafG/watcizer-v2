<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The dashboard's translation seam, SERVER side — the twin of `useT()` in `resources/js/lib/i18n.ts`.
 *
 * ── Why a helper and not plain `__()` ────────────────────────────────────────────────────────
 *
 * Two reasons, and the first one is a bug that has already been caught once on the client:
 *
 *  1. **`lang/ar/manage.php` is EMPTY by design.** The Arabic lives at the call site as this
 *     helper's second argument, so there is exactly one source for it and no thousand-string
 *     second copy to drift. But Laravel's translator FALLS BACK to `app.fallback_locale` when a
 *     file yields nothing — so a plain `__('manage.x')` would hand an ARABIC reader the ENGLISH
 *     string, and the dashboard's default language would flip silently. `LocaleSeamTest` caught
 *     exactly that on the client seam. This resolves with `fallback: false` and returns the Arabic
 *     literal instead.
 *  2. **A missing key must be invisible, not a `manage.products.foo` on screen.** `__()` returns
 *     the KEY when it cannot resolve one. An operator seeing `manage.products.slug_taken` in place
 *     of a refusal is worse than seeing it in the wrong language.
 *
 * ── The contract, identical to the client's ──────────────────────────────────────────────────
 *
 *     ManageText::t('products.slug_taken', 'الرابط مستخدم بالفعل.')
 *
 * The Arabic literal IS the fallback and STAYS IN THE SOURCE. English lives in
 * `lang/en/manage.php` — the same file the client reads, so a string can move between a PHP
 * refusal and a React label without changing its key or its English. Interpolation is Laravel's
 * own `:name`, for the same reason.
 *
 * `ServerTranslationCoverageTest` holds both halves: no new bare Arabic server-side, and every key
 * this helper asks for has an English entry.
 */
final class ManageText
{
    /**
     * Translate `manage.$key`, falling back to the Arabic literal at the call site.
     *
     * @param  string  $key  dotted, WITHOUT the `manage.` prefix — `products.slug_taken`
     * @param  string  $arabic  the Arabic text; the fallback, and the only copy of it
     * @param  array<string, string|int|float>  $replace  `:name` placeholders
     * @param  string|null  $locale  resolve in THIS locale instead of the operator's current one
     */
    public static function t(string $key, string $arabic, array $replace = [], ?string $locale = null): string
    {
        /*
         * `$locale` exists for the CONSOLE. `manage:role` prints an English report and has no
         * operator session to read a preference from, so it asks for 'en' explicitly. Without it
         * the console's English had to be a second copy of the strings in `lang/en/manage.php` —
         * which is the duplication this whole seam exists to prevent.
         */
        $line = Lang::get('manage.'.$key, $replace, $locale ?? app()->getLocale(), false);

        /*
         * `Lang::get()` answers with the KEY ITSELF when nothing resolves, and with an ARRAY when
         * the key names a group rather than a line. Both mean "no translation for this locale", and
         * both must produce the Arabic rather than leaking a key or an array to the screen.
         */
        if (! is_string($line) || $line === 'manage.'.$key) {
            return self::interpolate($arabic, $replace);
        }

        return $line;
    }

    /**
     * Substitute `:name` placeholders in the fallback.
     *
     * LONGEST NAME FIRST, matching `makeReplacements()` in Laravel's own translator and the client
     * helper: replacing in insertion order turns `':from–:to من :total'` into `'1–20 من 20tal'`,
     * because `:to` is a prefix of `:total` and matches inside it.
     *
     * @param  array<string, string|int|float>  $replace
     */
    private static function interpolate(string $text, array $replace): string
    {
        if ($replace === []) {
            return $text;
        }

        $names = array_keys($replace);
        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $text = str_replace(':'.$name, (string) $replace[$name], $text);
        }

        return $text;
    }
}
