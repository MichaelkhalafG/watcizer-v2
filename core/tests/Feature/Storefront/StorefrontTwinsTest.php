<?php

use PHPUnit\Framework\Assert;

/*
 * The second storefront app is a COPY of the first (A2-RULE: two folders, copied, not a shared
 * package). `Frontend-brandfashion/TWINS.txt` lists the files that are REQUIRED to stay byte-identical
 * to their Frontend-next twin — the logic layer and whatever presentation the identity sweep of
 * 2026-10-14 left untouched. A fix landing on one side only is a divergence nobody chose, and nothing
 * in a file copy says so.
 *
 * This test is the manifest's teeth (BF-TWINS, decided 2026-10-14: it BLOCKS). The suite is the deploy
 * gate, so a drifted twin stops the next deploy instead of waiting a month for someone to re-read the
 * manifest. To split a twin on purpose: remove its line from the manifest in the same commit and record
 * it under DIVERGED there, with the date and the reason.
 */

const TWINS_MANIFEST = 'Frontend-brandfashion/TWINS.txt';

/** @return list<string> the manifest's paths, comment and blank lines dropped */
function twinPaths(): array
{
    $file = dirname(base_path()).DIRECTORY_SEPARATOR.TWINS_MANIFEST;
    Assert::assertFileExists($file, TWINS_MANIFEST.' is missing');
    $paths = [];
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $paths[] = $line;
    }

    return $paths;
}

it('lists a sane manifest: relative paths, no duplicates, nothing outside the app', function () {
    $paths = twinPaths();

    expect(count($paths))->toBeGreaterThan(100, 'the manifest has lost most of its entries');
    expect(array_values(array_unique($paths)))->toBe($paths, 'a path is listed twice');
    foreach ($paths as $p) {
        expect(str_starts_with($p, '/') || str_contains($p, chr(92)) || str_contains($p, '..') || preg_match('/^[A-Za-z]:/', $p) === 1)
            ->toBeFalse("not a relative path inside the app: {$p}");
    }
});

it('keeps every listed twin byte-identical between Frontend-next and Frontend-brandfashion', function () {
    $root = dirname(base_path()).DIRECTORY_SEPARATOR;
    $failures = [];
    foreach (twinPaths() as $p) {
        $a = $root.'Frontend-next'.DIRECTORY_SEPARATOR.$p;
        $b = $root.'Frontend-brandfashion'.DIRECTORY_SEPARATOR.$p;
        if (! is_file($a)) {
            $failures[] = "missing in Frontend-next: {$p}";

            continue;
        }
        if (! is_file($b)) {
            $failures[] = "missing in Frontend-brandfashion: {$p}";

            continue;
        }
        if (hash_file('sha256', $a) !== hash_file('sha256', $b)) {
            $failures[] = sprintf('DIVERGED: %s (%d B in Frontend-next, %d B in Frontend-brandfashion) — change both sides, or take it off TWINS.txt with a dated DIVERGED line', $p, filesize($a), filesize($b));
        }
    }

    Assert::assertSame([], $failures, count($failures)." twin problem(s):\n".implode("\n", $failures));
});
