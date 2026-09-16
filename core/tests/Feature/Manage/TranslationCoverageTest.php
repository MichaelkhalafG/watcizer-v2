<?php

use Tests\Support\T;

/*
 * Translation coverage (wave 4D).
 *
 * ── It was a ratchet; it is now a cliff ─────────────────────────────────────────────────────
 *
 * 1,027 Arabic strings lived across 42 dashboard files. A test demanding zero would have failed
 * from the moment it was written until the last file was done — which means it would have been
 * skipped, ignored or deleted long before it ever caught anything. So it started as a RATCHET: it
 * counted what was left and refused to let the number grow, and every wired file lowered it.
 *
 * On 2026-09-16 the number reached ZERO, and a ratchet at zero is just a cliff with extra steps.
 * It is now written as what it is: no screen may carry Arabic the seam cannot reach. The ceiling
 * and its companion "is the ceiling stale?" test are gone; they were scaffolding for a migration
 * that is over, and leaving them would only invite somebody to raise the number again.
 *
 * ── What counts as WIRED ────────────────────────────────────────────────────────────────────
 *
 * An Arabic literal that is the second argument of `t(...)` — the seam's fallback. Everything else
 * is unwired: a bare literal, a JSX text node, an attribute string.
 *
 * The Arabic fallback stays in the source ON PURPOSE (see `lang/ar/manage.php`), so "wired" cannot
 * mean "no Arabic in the file". It means "every Arabic string can be translated".
 *
 * ── The four checks ─────────────────────────────────────────────────────────────────────────
 *
 * They are deliberately four, because the seam can fail in four independent ways and only the
 * first one is obvious:
 *
 *   1. a screen carries Arabic no locale can reach          → the operator never sees English
 *   2. `lang/ar` grows a second copy of the Arabic          → two sources that can disagree
 *   3. a key is asked for that English does not answer      → English silently renders ARABIC
 *   4. one key carries two different Arabic strings         → one screen gets the other's English
 *
 * 3 and 4 are the quiet ones. Neither throws, neither shows up in a screenshot of the Arabic
 * dashboard, and both were found here rather than by anyone reading the diffs.
 */

/** Arabic anywhere. */
const ARABIC = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

/**
 * Files still carrying Arabic that no locale can reach, as path => count.
 *
 * @return array<string, int>
 */
function unwiredArabic(): array
{
    $root = resource_path('js');
    $out = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));
        if (preg_match(ARABIC, $source) !== 1) {
            continue;
        }

        /*
         * Blank out everything the seam already covers, then look at what is left:
         *
         *   • `t('key', 'العربية')` and `translate(map, 'key', 'العربية')` — the fallback,
         *   • block and line COMMENTS, which are written for developers and are not UI text.
         *
         * Blanking rather than skipping the file keeps the count honest: a file can be half done.
         */
        /*
         * An explicit, REASONED opt-out, applied before the comments are stripped.
         *
         * A handful of Arabic literals are data rather than UI copy — `{ ar: 'العربية' }` naming
         * the Arabic input box is the clearest case, since a language is named in its own language
         * whatever locale the page is in, and translating it would be a bug. Those cannot be wired,
         * and without a way to say so the ratchet reports a permanent count it can never reach.
         *
         * The marker must carry a REASON, so it is a decision somebody wrote down rather than a
         * mute switch: `// i18n-exempt: language names are data, not UI copy`.
         */
        $stripped = preg_replace('#^.*\bi18n-exempt:\s*\S.*$#mu', '', $source) ?? $source;

        $stripped = preg_replace('#/\*.*?\*/#su', '', $stripped) ?? $stripped;
        $stripped = preg_replace('#^\s*//.*$#mu', '', $stripped) ?? $stripped;
        /*
         * BOTH quote styles, and across NEWLINES.
         *
         * Prettier reformats a long `t('key', 'العربية')` onto several lines and rewrites the
         * quotes to double — so a single-line, single-quote pattern reported a fully wired file as
         * untouched, and the ratchet sat at 4 while five files were actually done. The detector was
         * wrong, not the wiring; it is matched loosely here for exactly that reason.
         */
        $stripped = preg_replace('#\bt\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"][^\'"]*[\'"]#su', '', $stripped) ?? $stripped;
        $stripped = preg_replace('#\btranslate\(\s*[^,]+,\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"][^\'"]*[\'"]#su', '', $stripped) ?? $stripped;

        $remaining = preg_match_all(ARABIC, $stripped);
        if (is_int($remaining) && $remaining > 0) {
            $relative = str_replace('\\', '/', str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()));
            $out[$relative] = $remaining;
        }
    }

    ksort($out);

    return $out;
}

/**
 * Every `t('key', 'العربية')` in the dashboard, as key => arabic => [files].
 *
 * One extractor for both of the checks below, because they failed differently on the same blind
 * spot: the first detector written here matched single quotes on a single line, and Prettier writes
 * these calls with DOUBLE quotes across several lines as soon as they grow. The result was a test
 * that reported a fully wired file as untouched. Anything reading the seam reads it through here.
 *
 * `.ts` counts as well as `.tsx`: `lib/` holds label maps that are UI text just as much as a JSX
 * node is.
 *
 * @return array<string, array<string, list<string>>>
 */
function seamCalls(): array
{
    $root = resource_path('js');
    $calls = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));
        $relative = str_replace('\\', '/', str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()));

        /*
         * Comments first. `lib/i18n.ts` documents the seam by SHOWING a call —
         * `t('banners.saved', 'تم حفظ البانر.')` — and an extractor that reads docblocks counts
         * that example as a real call site, then demands an English entry for a key no screen
         * asks for. A doc example is not a call site.
         */
        $code = T::str(preg_replace('#/\*.*?\*/#su', '', $source));
        $code = T::str(preg_replace('#^\s*//.*$#mu', '', $code));

        if (preg_match_all('#\bt\(\s*[\'"]([a-z0-9_.]+)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]#su', $code, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                /*
                 * Prettier breaks a long fallback across lines and indents the continuation, so the
                 * same sentence can arrive with different whitespace in two files. Compare on the
                 * words, not on the wrapping, or every wrapped string reads as a conflict.
                 */
                $arabic = T::str(preg_replace('#\s+#u', ' ', trim($match[2])));
                $calls[$match[1]][$arabic][] = $relative;
            }
        }
    }

    foreach ($calls as $key => $variants) {
        foreach ($variants as $arabic => $files) {
            $calls[$key][$arabic] = array_values(array_unique($files));
        }
    }
    ksort($calls);

    return $calls;
}

it('lets no screen carry Arabic the seam cannot reach', function () {
    $unwired = unwiredArabic();

    expect(array_keys($unwired))->toBe(
        [],
        "A screen carries Arabic that no locale can reach, so an English operator sees Arabic there\n"
        ."and nothing fails.\n\n"
        ."WHAT TO DO — for each file listed below:\n"
        ."  1. `const t = useT();` as the FIRST LINE OF THE COMPONENT BODY. Not in a default\n"
        ."     parameter, and not in a module-level const map: both are evaluated before the\n"
        ."     component runs, where a hook cannot be called. Use `label ?? t('key', 'العربية')`\n"
        ."     inside the body, or turn the map into a function that takes `t`.\n"
        ."  2. Wrap each Arabic string — 'نص' becomes t('screen.thing', 'نص'), and JSX text\n"
        ."     >نص< becomes >{t('screen.thing', 'نص')}<. One contextual edit each; a blind\n"
        ."     find-and-replace has already corrupted a docblock on this codebase.\n"
        ."  3. Add the key and its ENGLISH text to lang/en/manage.php. Leave lang/ar EMPTY: the\n"
        ."     Arabic literal above IS the Arabic, and a second copy would drift from it.\n"
        ."  4. Before reusing a `common.*` key, check its Arabic matches YOURS EXACTLY. If it\n"
        ."     differs at all, it is a different key — see the conflict test below.\n"
        ."  5. Exercise the screen in a test. Extracted but unexercised counts as unwired.\n\n"
        ."If the Arabic is genuinely DATA rather than UI copy — a language name, a currency symbol —\n"
        ."mark that line `// i18n-exempt: <reason>`. The reason is required, so it stays a decision\n"
        ."somebody wrote down rather than a mute switch.\n\n"
        ."STILL UNWIRED:\n  ".implode("\n  ", array_keys($unwired))
    );
});

it('keeps lang/ar EMPTY, so Arabic has exactly one source', function () {
    /*
     * The Arabic lives in the components as `t()`'s fallback. Filling `lang/ar/manage.php` would
     * duplicate a thousand strings into a second place that can drift from the first — and on the
     * day they disagree nobody could tell which one the screen is showing.
     */
    $arabic = require lang_path('ar/manage.php');

    expect($arabic)->toBe([], 'lang/ar/manage.php must stay a stub — see its header for why');
});

it('has an English entry for every key the components ask for', function () {
    /*
     * The inverse of the ratchet: a `t('shell.main_menu', …)` whose key is missing from
     * `lang/en/manage.php` renders the ARABIC fallback to an English-speaking operator, silently.
     * That is the failure mode this whole seam is built to avoid, so it is asserted rather than
     * trusted.
     */
    $english = T::arr(require lang_path('en/manage.php'));

    $flat = [];
    $flatten = function (array $rows, string $prefix) use (&$flatten, &$flat): void {
        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flatten($value, $path);
            } else {
                $flat[$path] = true;
            }
        }
    };
    $flatten($english, '');

    $missing = [];
    foreach (seamCalls() as $key => $variants) {
        if (! isset($flat[$key])) {
            $missing[$key] = implode(', ', array_merge(...array_values($variants)));
        }
    }
    ksort($missing);

    expect($missing)->toBe(
        [],
        "These keys have no English entry, so an English operator silently gets the ARABIC fallback —\n"
        ."the exact failure this seam exists to prevent. Add each one to lang/en/manage.php with its\n"
        .'English text; the file lists the key it belongs under.'
    );
});

it('never lets one key carry two different Arabic strings', function () {
    /*
     * Two screens reaching for the same key with DIFFERENT Arabic is not a style problem: `t()`
     * returns one English string for one key, so whichever screen loses gets English that does not
     * match the Arabic beside it, and no test that looks at a single file can see it.
     *
     * It is also not hypothetical. Parallel extraction produced two on its first batch —
     * `products.pre_switch_blocked_title` and `common.out_of_stock` each carried two different
     * Arabic strings written by two different authors, and neither author could have noticed.
     */
    $conflicts = [];
    foreach (seamCalls() as $key => $variants) {
        if (count($variants) > 1) {
            $lines = [];
            foreach ($variants as $arabic => $files) {
                $lines[] = '      "'.$arabic.'"  ← '.implode(', ', $files);
            }
            $conflicts[] = "  {$key}\n".implode("\n", $lines);
        }
    }

    expect($conflicts)->toBe(
        [],
        "One key, two meanings. English has only one string per key, so one of these screens will\n"
        ."render English that does not say what its Arabic says.\n\n"
        ."WHAT TO DO: decide whether the two really are the same phrase. If they are, make the Arabic\n"
        ."identical. If they are not — a short badge and a full sentence usually are not — give the\n"
        ."narrower one its own key on its own screen rather than widening the shared one.\n\n"
        .implode("\n\n", $conflicts)
    );
});
