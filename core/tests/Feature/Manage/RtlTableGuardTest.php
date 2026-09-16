<?php

use Tests\Support\T;

/**
 * The dashboard is Arabic and right-to-left, and this is the mistake that broke every table in it.
 *
 * ── What went wrong (reported 2026-09-14) ────────────────────────────────────────────────────
 *
 * To keep a SKU, a price or an email in Latin order, screens wrote `dir="ltr"` on the element that
 * held it — a `<td>`, a `<div>`, a `<p>`. `text-align: start` resolves against the element's OWN
 * direction, so every one of those boxes quietly aligned itself to the LEFT while its header, which
 * inherits the page's RTL, stayed on the RIGHT. On the dashboard home the effect was that all five
 * numeric columns sat one column over from their heading: the operator could not tell which number
 * was which.
 *
 * ── Why a test and not a review note ─────────────────────────────────────────────────────────
 *
 * The fix is invisible in a diff — `dir="ltr"` looks obviously right, which is why it was written
 * nineteen times. So the two rules are asserted mechanically instead:
 *
 *   1. direction goes on an INLINE run (`<bdi>`, i.e. `<Ltr>`/`<Num>`), never on a block box;
 *   2. there is ONE table in this application — `components/ui/table.tsx`. A screen that hand-rolls
 *      `<table>` opts out of every alignment decision made there, which is how three screens came
 *      to have three different answers.
 *
 * These are source-text rules, so this is a source-text test. It has no database and no HTTP.
 */
$sources = static function (): array {
    $root = base_path('resources/js');
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            /*
             * COMMENTS ARE STRIPPED FIRST. Both rules below are about markup, and the files that
             * explain the rules quote the very markup they forbid — this test would otherwise fail
             * on its own documentation.
             */
            $source = T::str(file_get_contents($file->getPathname()));
            $code = T::str(preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^
]*#'], '', $source));
            $files[substr($path, strpos($path, 'resources/js') ?: 0)] = $code;
        }
    }
    ksort($files);

    return $files;
};

it('never puts a direction on a block element — that is what breaks header alignment', function () use ($sources) {
    // `<span dir>` is fine: an inline box changes no alignment. These are the block tags.
    $blocks = 'div|p|ul|ol|li|dd|dt|td|th|h1|h2|h3|h4|section|article';

    $offenders = [];
    foreach ($sources() as $path => $source) {
        if (preg_match_all('#<('.$blocks.')\b[^>]*\sdir="#i', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($source, 0, T::int($match[1])), "\n") + 1;
                $offenders[] = "{$path}:{$line}";
            }
        }
    }

    expect($offenders)->toBe([], 'use <Ltr>/<Num> from components/ui/bidi — direction belongs on the text, not the box');
});

it('keeps every table on the one primitive, so alignment is decided once', function () use ($sources) {
    $offenders = [];
    foreach ($sources() as $path => $source) {
        if (str_ends_with($path, 'components/ui/table.tsx')) {
            continue;
        }
        foreach (['<table', '<thead', '<tbody', '<td ', '<td>', '<th ', '<th>'] as $raw) {
            if (str_contains($source, $raw)) {
                $offenders[] = "{$path} ({$raw})";
            }
        }
    }

    expect($offenders)->toBe([], 'compose components/ui/table.tsx instead of hand-rolling a table');
});

it('has the inline primitives the other two rules point at', function () use ($sources) {
    $bidi = $sources()['resources/js/components/ui/bidi.tsx'] ?? '';

    // `<bdi>` and not a styled <span>: isolation is the browser's job, and it is the one element
    // that does it without becoming a block.
    expect($bidi)->toContain('export function Ltr')
        ->and($bidi)->toContain('export function Num')
        ->and($bidi)->toContain('<bdi dir="ltr"');
});
