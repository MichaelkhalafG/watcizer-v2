import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

import type { SharedProps } from '@/types';

/**
 * The dashboard's translation seam.
 *
 * ── The whole idea is the fallback ──────────────────────────────────────────────────────────
 *
 * `t('banners.saved', 'تم حفظ البانر.')` renders the Arabic literal until somebody puts
 * `banners.saved` in `lang/<locale>/manage.php`. That is what made it safe to roll this out one
 * screen at a time across a dashboard of 711 distinct Arabic strings in 42 files: a screen that is
 * half-converted still reads perfectly in Arabic, the default language.
 *
 * `lang/ar/manage.php` is therefore a deliberate STUB. The Arabic in the component IS the Arabic,
 * so there is no second copy of a thousand strings to drift from the first. `lang/en/manage.php` is
 * the work, and `TranslationCoverageTest` holds both halves of that bargain: no screen may carry
 * Arabic the seam cannot reach, and no key may be asked for that English does not answer.
 *
 * ── Why not a library ───────────────────────────────────────────────────────────────────────
 *
 * i18next and friends bring a loader, a plural engine, an interpolation syntax and a bundle. The
 * server already has all of that in `lang/`, already shares the resolved map, and the dashboard
 * needs two languages. A forty-line helper that reads a prop is the honest size of this problem.
 *
 * The one thing it genuinely lacks is PLURALS: `t('media.orphans', ':count ملف')` reads "1 files"
 * in English at a count of one. Arabic takes the singular after a numeral, so the fallbacks are
 * right and only the English is wrong. That is the moment to reach for `trans_choice` or a library
 * — when somebody decides it matters, and not before.
 */

/**
 * One frozen empty map, not a fresh `{}` per call.
 *
 * Arabic is the default locale and ships NO dictionary at all — the fallbacks are the Arabic — so
 * the missing-translations case is the common one, not the edge one. A new object each render would
 * defeat the memo below on exactly the pages that matter most.
 */
const NO_TRANSLATIONS: Record<string, string> = {};

/**
 * Substitute Laravel's `:name` placeholders.
 *
 * LONGEST NAME FIRST, and that is not a tidiness choice. Replacing in insertion order turns
 * `':from–:to من :total'` with `{from, to, total}` into `'1–20 من 20tal'`: `:to` is a prefix of
 * `:total`, so it matches inside it and eats the rest of the name. Laravel's own `makeReplacements`
 * sorts by length for exactly this reason.
 */
function interpolate(text: string, values: Record<string, string | number>): string {
    return Object.entries(values)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((carry, [name, value]) => carry.split(`:${name}`).join(String(value)), text);
}

/** `{'banners.saved': '…'}` — the flat map the server shared for the ACTIVE locale. */
function dictionary(): Record<string, string> {
    // `usePage` is a hook, so this must only be called from a component or another hook.
    const { translations } = usePage<SharedProps>().props;

    return translations ?? NO_TRANSLATIONS;
}

/**
 * Translate `key`, falling back to `fallback` when the active locale has no entry for it.
 *
 * `values` interpolates `:name` placeholders, matching Laravel's own syntax so a key can move
 * between a PHP `__()` call and a React `t()` call without its string changing.
 */
export function useT(): (key: string, fallback: string, values?: Record<string, string | number>) => string {
    const map = dictionary();

    /*
     * Memoised so `t` keeps ONE identity for as long as the dictionary does. Several components
     * pass it to `useCallback`/`useEffect` dependency arrays — a fresh closure every render would
     * silently rebuild those callbacks on every keystroke, which is exactly the kind of cost that
     * never shows up in a test.
     */
    return useMemo(
        () => (key: string, fallback: string, values?: Record<string, string | number>) => {
            const raw = map[key] ?? fallback;

            return values === undefined ? raw : interpolate(raw, values);
        },
        [map],
    );
}

/**
 * The same thing outside a component — for a module-level constant map, where a hook cannot run.
 *
 * It takes the dictionary explicitly rather than reaching for one, because a helper that silently
 * returned the fallback when called outside a page would translate nothing and say nothing about
 * it. Pass `useT()`'s result down, or pass the map.
 */
export function translate(
    map: Record<string, string>,
    key: string,
    fallback: string,
    values?: Record<string, string | number>,
): string {
    const raw = map[key] ?? fallback;

    return values === undefined ? raw : interpolate(raw, values);
}
