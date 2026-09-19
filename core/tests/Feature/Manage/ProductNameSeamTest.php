<?php

use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Which of a product's two names the dashboard shows (item 1b, 2026-09-17).
 *
 * ── The defect ──────────────────────────────────────────────────────────────────────────────
 *
 * Every screen rendered `row.title.ar` — the ARABIC name — whatever language the operator had
 * chosen. An English operator got an English interface wrapped around Arabic product names, which
 * is the dashboard showing what the system stores rather than what the reader can use.
 *
 * The fix is one decision, `localisedTitle()` in `resources/js/lib/title.ts`, drawn by one
 * component, `ProductName`. Neither is reachable from PHP, and this tree has no JavaScript test
 * runner — so this file tests the two things PHP CAN see, and they are the two that matter:
 *
 *   1. the server actually SENDS both languages, because a perfect client rule fed one name is
 *      still one name on screen;
 *   2. no screen reads a title's language directly any more.
 *
 * ── Why (2) is a grep and not an apology for one ────────────────────────────────────────────
 *
 * `TranslationCoverageTest` is the same shape and found things no one found by reading diffs. The
 * regression here is not subtle to describe and is very easy to commit: the next person adding a
 * column that shows a product writes `row.title.ar`, in Arabic, on an Arabic-first dashboard, and
 * it looks completely correct to them — because in their locale it IS correct. It only fails for
 * the reader who is not in the room. A grep is the only thing that catches that at the moment it
 * is written.
 */

/**
 * The files ALLOWED to read a title's individual languages.
 *
 * The first two are the rule and its mark — one place that decides which name a READER gets, one
 * that draws the consequence.
 *
 * `lib/seo.ts` is a different case and was added on 2026-09-18 with item 8. The SEO generator does
 * not pick a language for anybody: it writes an Arabic meta description AND an English one in the
 * same click, so it needs both names at once. Routing it through `localisedTitle()` would give an
 * English operator an English sentence in the Arabic field — the exact defect item 1b existed to
 * remove, in the other direction.
 *
 * Listing it here rather than loosening the pattern, so the exemption is a decision with a name on
 * it and the next file that wants one has to argue for it too.
 */
const TITLE_SEAM_FILES = [
    'lib/title.ts',
    'components/manage/ProductName.tsx',
    'lib/seo.ts',
];

/**
 * A line that reads one language ON PURPOSE, marked so the exemption is a decision somebody wrote
 * down rather than a hole in the pattern.
 *
 * The legitimate case is an EDITOR: a form that offers an Arabic box and an English box has to read
 * each language separately, because editing them is the whole point. `Categories/Index.tsx`'s
 * `NodeForm` and `Lookups/Index.tsx`'s two name columns are that case.
 *
 * Reading is the case this test exists for, and no marker is offered for it.
 */
const NAME_SEAM_MARKER = 'name-seam-exempt:';

it('sends BOTH names to the products list, not just the Arabic one', function () {
    actingAs(Staff::admin());

    $rows = T::arr(T::arr(Props::of(get('/manage/storefronts/1/products'))['table'] ?? null)['data'] ?? null);

    expect($rows)->not->toBe([], 'the products list returned no rows — this test proves nothing');

    foreach ($rows as $row) {
        $title = T::arr(T::arr($row)['title'] ?? null);

        // Both keys PRESENT. Their values may well be empty — 750-odd products are missing one
        // name or the other, and that is the case the marked fallback exists for. What must never
        // happen is the key going missing, because then the client cannot tell "no English name"
        // from "the server did not send it", and it renders the Arabic either way.
        expect($title)->toHaveKey('ar')->toHaveKey('en');
    }
});

it('lets no screen pick a NAME language for the reader either', function () {
    /*
     * ── Widened 2026-10-05, after the same defect turned up a third time ──────────────
     *
     * The original guard covered `.title.ar` — a PRODUCT's name — and nothing else, so the
     * category tree printed both names on every node, the product form's picker was Arabic, and
     * the activity log and the inventory list showed Arabic to an English reader. Every one of
     * those is the same decision made about a differently-named field.
     *
     * The developer's words, and they are the reason this is a grep rather than a code review:
     * *"an Arabic developer working in Arabic sees nothing wrong. It only fails for the reader who
     * isn't in the room."*
     */
    $root = resource_path('js');
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (in_array($relative, TITLE_SEAM_FILES, true)) {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));

        // A marked line is an editor and is allowed to read one language. Blanked before the
        // match, exactly as `TranslationCoverageTest` blanks its own exemptions.
        /*
         * The marker covers its own line AND the one after it, because that is where a developer
         * actually writes the reason: above the code, not crammed into a JSX attribute list where
         * `//` is not even a comment. Learned from the `i18n-exempt` markers, which strip only
         * their own line and needed three passes to place correctly.
         */
        $lines = preg_split('/\R/', $source) ?: [];
        $skipNext = false;
        foreach ($lines as $index => $line) {
            $marked = str_contains($line, NAME_SEAM_MARKER);
            if ($marked || $skipNext) {
                $lines[$index] = '';
            }
            $skipNext = $marked;
        }
        $stripped = implode('
', $lines);

        // `something.name.ar` / `.name.en` — a read of ONE language of a category, brand or
        // lookup name. A TYPE declaration (`name: { ar: string; en: string }`) is a different
        // shape and does not match: carrying the pair is the point, choosing from it is not.
        if (preg_match_all('/\.name\.(?:ar|en)\b/', $stripped, $matches) > 0) {
            $offenders[$relative] = count($matches[0]);
        }
    }

    expect($offenders)->toBe([], "A screen is choosing which language of a NAME the reader gets.\n\n"
        ."Use `localisedTitle()` / `<ProductName>` with the pair, as the products list does. If the\n"
        .'line is an EDITOR — an Arabic box and an English box — mark it `'.NAME_SEAM_MARKER."` with a\n"
        ."reason.\n\n".implode("\n", array_map(
            static fn (string $path, int $count): string => "{$path}: {$count}",
            array_keys($offenders),
            $offenders,
        )));
});

it('lets no CONTROLLER pick a name language for the reader', function () {
    /*
     * The half the client guard could never see.
     *
     * `ProductController::categoryOptions()` resolved a label as `$ar !== '' ? $ar : $en` and
     * handed the client one string — so the picker, the primary-category select, the list filter
     * and the placement tree were all Arabic for an English operator, and no client rule could
     * have corrected it. `App\Support\LocalisedName` is now the one place that chooses.
     */
    $root = app_path('Http/Controllers/Manage');
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));

        /*
         * The shape: a ternary that prefers one language variable over the other.
         *
         * Narrow on purpose. A test that flagged every mention of `$ar` would flag the queries
         * that READ both columns, which is exactly what a controller should do; what must not
         * happen is the controller deciding between them.
         */
        if (preg_match_all('/\$ar\s*!==/', $source, $matches) > 0) {
            $offenders[$file->getFilename()] = count($matches[0]);
        }
    }

    expect($offenders)->toBe([], "A controller is choosing which language of a name to send.\n"
        ."Call `App\\Support\\LocalisedName::pick()` instead — it reads the ACTIVE locale.\n\n"
        .implode("\n", array_map(
            static fn (string $path, int $count): string => "{$path}: {$count}",
            array_keys($offenders),
            $offenders,
        )));
});

it('lets no screen pick a title language for the reader', function () {
    $root = resource_path('js');
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (in_array($relative, TITLE_SEAM_FILES, true)) {
            continue;
        }

        $source = T::str(file_get_contents($file->getPathname()));

        // `something.title.ar` / `.title.en` — a read of ONE language of a product's name.
        // A TYPE declaration (`title: { ar: string; en: string }`) is a different shape and does
        // not match, which is the point: the pair may be carried, it may just not be chosen from.
        if (preg_match_all('/\.title\.(?:ar|en)\b/', $source, $matches) > 0) {
            $offenders[$relative] = count($matches[0]);
        }
    }

    expect($offenders)->toBe([], implode("\n", array_map(
        static fn (string $path, int $count): string => "{$path}: {$count}",
        array_keys($offenders),
        $offenders,
    )));
});
