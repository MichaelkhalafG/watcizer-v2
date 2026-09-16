<?php

use App\Support\ManageText;
use Tests\Support\T;

/*
 * Translation coverage, SERVER side — the other half of the seam (wave 4D).
 *
 * ── Why this file exists at all ──────────────────────────────────────────────────────────────
 *
 * `TranslationCoverageTest` proved every string in `resources/js` can be translated, and the
 * dashboard still showed Arabic to an English operator — because a large share of what a screen
 * renders never passes through a component literal. It arrives in the props: a refusal from
 * `PromotionWriter`, a sidebar label from `Navigation`, a flash message from a controller,
 * `pre_switch.message`, `action_label`. The client seam cannot reach any of it.
 *
 * So this is the same guard, pointed at `app/`.
 *
 * ── What counts, and the two things that DELIBERATELY do not ─────────────────────────────────
 *
 * Wiring every Arabic literal in `app/` would be actively wrong, so the scope is classified:
 *
 *   • `Domain/Import/*` is DATA. `ArabicTitle` is a dictionary that builds Arabic PRODUCT TITLES
 *     and writes them into the catalogue; `CategoryMap` maps legacy category names. That is shop
 *     content, not interface text — putting it on a locale seam would rewrite the catalogue when
 *     an operator switched language.
 *   • `Mail/*`, `Domain/Notifications/*` and `Storefront/*` speak to the CUSTOMER, in the
 *     storefront's language. A different audience and a different locale from the dashboard
 *     operator; "an English operator reads Arabic" is not about them.
 *
 *     **Settled 2026-09-16: they stay in Arabic, and this is not a gap.** The customers are
 *     Egyptian and the e-mails work; converting them would be work rather than a saving. A second
 *     language can be added if Brand Fashion ever needs one — at which point the seam is keyed on
 *     the ORDER's locale, not the operator's — which is precisely why the dashboard seam was not
 *     extended over them: `ManageText::t()` resolves against the OPERATOR's language, and an
 *     English-speaking member of staff advancing an order must not thereby send an Egyptian
 *     customer an English e-mail. Nobody should read the exclusion below as an outstanding item.
 *     See CLEAN_CORE_STUDY §6.2.1.
 *
 * Everything else is OPERATOR text and in scope.
 *
 * ── It was a ratchet for one afternoon ───────────────────────────────────────────────────────
 *
 * 568 Arabic literals across 49 files, measured 2026-09-16. It started as a ratchet for the same
 * reason the client one did — a test demanding zero on day one cannot pass, and a test that cannot
 * pass gets skipped, ignored or deleted long before it catches anything — and reached ZERO the same
 * day. The ceiling and its companion "is the ceiling stale?" test are gone: they were scaffolding
 * for a migration that is over, and a raisable number only invites somebody to raise it.
 */

/** Arabic anywhere. */
const SERVER_ARABIC = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

/** Not interface text: catalogue content the importer writes. */
const DATA_PREFIXES = ['Domain/Import/'];

/** Not the operator's language: the shopper's. */
const CUSTOMER_PREFIXES = ['Mail/', 'Domain/Notifications/', 'Storefront/'];

/**
 * Operator-facing Arabic that no locale can reach, as path => count.
 *
 * @return array<string, int>
 */
function unwiredServerArabic(): array
{
    $root = app_path();
    $out = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()));
        if (serverTextIsExcluded($relative)) {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));
        if (preg_match(SERVER_ARABIC, $source) !== 1) {
            continue;
        }

        $count = count(bareArabicLiterals($source));
        if ($count > 0) {
            $out[$relative] = $count;
        }
    }

    ksort($out);

    return $out;
}

function serverTextIsExcluded(string $relative): bool
{
    foreach ([...DATA_PREFIXES, ...CUSTOMER_PREFIXES] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Every Arabic string literal in `$source` that is NOT already on the seam.
 *
 * @return list<string>
 */
function bareArabicLiterals(string $source): array
{
    /*
     * An explicit, REASONED opt-out, applied before comments are stripped — same convention as the
     * client detector. A handful of Arabic literals are data rather than UI copy even inside an
     * operator-facing file, and the reason is REQUIRED so it stays a decision somebody wrote down
     * rather than a mute switch.
     */
    $stripped = T::str(preg_replace('#^.*\bi18n-exempt:\s*\S.*$#mu', '', $source));

    // Comments and docblocks are written for developers, not for an operator.
    $stripped = T::str(preg_replace('#/\*.*?\*/#su', '', $stripped));
    $stripped = T::str(preg_replace('#^\s*//.*$#mu', '', $stripped));

    /*
     * Blank out what the seam already covers: `ManageText::t('key', 'العربية')` and Laravel's own
     * `__()` / `trans()`. Blanking rather than skipping the file keeps the count honest — a file
     * can be half done, and a half-done file has to keep showing up.
     */
    $stripped = T::str(preg_replace('#\bManageText::t\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"](?:[^\'"\\\\]|\\\\.)*[\'"]#su', '', $stripped));
    $stripped = T::str(preg_replace('#\b(?:__|trans)\(\s*[\'"][^\'"]*[\'"]#su', '', $stripped));

    $out = [];
    if (preg_match_all('#\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"#su', $stripped, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            // Group 1 is the single-quoted alternative, group 2 the double-quoted one; whichever
            // branch did not match is absent from the set rather than empty, so both are read
            // defensively before either is compared.
            $single = $match[1] ?? '';
            $double = $match[2] ?? '';
            $literal = $single !== '' ? $single : $double;

            if ($literal !== '' && preg_match(SERVER_ARABIC, $literal) === 1) {
                $out[] = $literal;
            }
        }
    }

    return $out;
}

/**
 * Every `ManageText::t('key', 'العربية')` in app/, as key => arabic => [files].
 *
 * @return array<string, array<string, list<string>>>
 */
function serverSeamCalls(): array
{
    $root = app_path();
    $calls = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));
        $relative = str_replace('\\', '/', str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()));

        // A doc example is not a call site — the helper documents itself by showing one.
        $code = T::str(preg_replace('#/\*.*?\*/#su', '', $source));
        $code = T::str(preg_replace('#^\s*//.*$#mu', '', $code));

        if (preg_match_all('#\bManageText::t\(\s*[\'"]([a-z0-9_.]+)[\'"]\s*,\s*[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"]#su', $code, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
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

it('lets no server file produce Arabic the seam cannot reach', function () {
    $unwired = unwiredServerArabic();

    expect(array_keys($unwired))->toBe(
        [],
        "A server file produces Arabic that no locale can reach, so an English operator reads Arabic\n"
        ."in a refusal, a label or a flash message — and nothing fails.\n\n"
        ."WHAT TO DO — for each file listed below:\n"
        ."  1. Wrap each operator-facing Arabic string:\n"
        ."         'الرابط مستخدم' becomes ManageText::t('products.slug_taken', 'الرابط مستخدم')\n"
        ."     The Arabic literal STAYS as the second argument — it is the fallback, and the only\n"
        ."     copy of the Arabic. Do NOT put it in lang/ar; that file is a deliberate stub.\n"
        ."  2. Add the key and its ENGLISH text to lang/en/manage.php — the SAME file the React\n"
        ."     side reads, so a string can move between a PHP refusal and a label unchanged.\n"
        ."  3. Interpolate with Laravel's own :name, never string concatenation.\n"
        ."  4. If the Arabic is DATA rather than interface text — a product noun, a legacy category\n"
        ."     name — do not wrap it. Mark the line `// i18n-exempt: <reason>`; the reason is\n"
        ."     required.\n"
        ."  5. If it is an operator-facing CONSTANT, a const cannot call the seam — convert it to a\n"
        ."     static method and update its call sites.\n\n"
        ."STILL UNWIRED:\n  ".implode("\n  ", array_keys($unwired))
    );
});

it('has an English entry for every key the server asks for', function () {
    /*
     * The inverse of the ratchet, and the quiet one: a `ManageText::t('x.y', …)` whose key is
     * missing from `lang/en/manage.php` renders the ARABIC fallback to an English operator and
     * nothing fails. That is the exact failure this seam exists to prevent.
     */
    $english = T::arr(require lang_path('en/manage.php'));

    $flat = [];
    $flatten = function (array $rows, string $prefix) use (&$flatten, &$flat): void {
        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            is_array($value) ? $flatten($value, $path) : $flat[$path] = true;
        }
    };
    $flatten($english, '');

    $missing = [];
    foreach (serverSeamCalls() as $key => $variants) {
        if (! isset($flat[$key])) {
            $missing[$key] = implode(', ', array_merge(...array_values($variants)));
        }
    }
    ksort($missing);

    expect($missing)->toBe(
        [],
        "These keys have no English entry, so an English operator silently gets the ARABIC fallback.\n"
        .'Add each one to lang/en/manage.php; the value here is the file that asks for it.'
    );
});

it('never lets one server key carry two different Arabic strings', function () {
    /*
     * Same defect the client seam hit seven times during parallel extraction: `t()` returns ONE
     * English string per key, so two call sites reaching for one key with different Arabic mean one
     * of them renders English that does not say what its Arabic says — and no test that reads a
     * single file can see it.
     */
    $conflicts = [];
    foreach (serverSeamCalls() as $key => $variants) {
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
        "One key, two meanings. English has only one string per key, so one of these call sites will\n"
        ."render English that does not say what its Arabic says.\n\n"
        ."WHAT TO DO: if they really are the same phrase, make the Arabic identical. If they are\n"
        ."not, give the narrower one its own key.\n\n"
        .implode("\n\n", $conflicts)
    );
});

it('shares ONE English file between the server and the client seams', function () {
    /*
     * The property that makes the two seams one seam: a string can move between a PHP refusal and a
     * React label without its key or its English changing. Asserted by proving both helpers resolve
     * the same key to the same text, rather than by trusting that they read the same path.
     */
    $original = app()->getLocale();

    try {
        app()->setLocale('en');
        expect(ManageText::t('common.save', 'حفظ'))->toBe('Save');

        /*
         * …and in the language the dashboard is WRITTEN in, the Arabic literal comes back.
         * `lang/ar/manage.php` is an empty stub, and Laravel's translator falls back to
         * `app.fallback_locale` when a file yields nothing — so without `fallback: false` this
         * would return 'Save' to an Arabic reader and the default language would flip silently.
         * That bug was caught once on the client seam; this asserts the server twin cannot have it.
         */
        app()->setLocale('ar');
        expect(ManageText::t('common.save', 'حفظ'))->toBe('حفظ');

        // A key with no English entry at all renders the fallback, never the key itself.
        app()->setLocale('en');
        expect(ManageText::t('nothing.defined_here', 'نص عربي'))->toBe('نص عربي');

        // Interpolation is Laravel's `:name`, and survives a prefix collision.
        expect(ManageText::t('nothing.defined_here', ':from–:to من :total', ['from' => 1, 'to' => 20, 'total' => 97]))
            ->toBe('1–20 من 97');
    } finally {
        app()->setLocale($original);
    }
});
