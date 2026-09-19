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
