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
 *
 * DIVERGED lines are checked too (2026-10-17, V2-READ-HALF — the first split made on purpose for a feature,
 * not for identity): `DIVERGED <YYYY-MM-DD> <path> — <reason>` must name a file that exists on both sides
 * and actually differs — a split that healed is re-twinned, not left on record — with its date and reason.
 * So the divergence list is a measured number that shrinks back to zero when Watchizer follows.
 */

const TWINS_MANIFEST = 'Frontend-brandfashion/TWINS.txt';

/**
 * The manifest's lines, split on line breaks as CHARACTERS (`/u`). Without the flag `\R` also matches the
 * byte 0x85, which sits inside Arabic letters (م is D9 85): on 2026-10-18 a DIVERGED reason quoting `3,900 ج.م`
 * was cut in two, and the gate read half a reason and a phantom twin path. A manifest that is not valid UTF-8
 * fails here, loudly, instead of reading as empty.
 *
 * @return list<string>
 */
function manifestLines(string $text): array
{
    $lines = preg_split('/\R/u', $text);
    if ($lines === false) {
        Assert::fail(TWINS_MANIFEST.' is not valid UTF-8 - it cannot be read line by line');
    }

    return $lines;
}

/** @return list<string> the manifest's paths, comment and blank lines dropped */
function twinPaths(): array
{
    $file = dirname(base_path()).DIRECTORY_SEPARATOR.TWINS_MANIFEST;
    Assert::assertFileExists($file, TWINS_MANIFEST.' is missing');
    $paths = [];
    foreach (manifestLines((string) file_get_contents($file)) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'DIVERGED ')) {
            continue;
        }
        $paths[] = $line;
    }

    return $paths;
}

/** @return list<array{date: string, path: string, reason: string, line: string}> the manifest's DIVERGED entries */
function divergedEntries(): array
{
    $file = dirname(base_path()).DIRECTORY_SEPARATOR.TWINS_MANIFEST;
    $out = [];
    foreach (manifestLines((string) file_get_contents($file)) as $line) {
        $line = trim($line);
        if (! str_starts_with($line, 'DIVERGED ')) {
            continue;
        }
        if (preg_match('/^DIVERGED (\d{4}-\d{2}-\d{2}) (\S+) — (.+)$/u', $line, $m) !== 1) {
            $out[] = ['date' => '', 'path' => '', 'reason' => '', 'line' => $line];

            continue;
        }
        $out[] = ['date' => $m[1], 'path' => $m[2], 'reason' => trim($m[3]), 'line' => $line];
    }

    return $out;
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

it('splits the manifest on line breaks only, never inside an Arabic letter', function () {
    // The shape of the 2026-10-18 line: the price in Arabic carries the byte 0x85 (م is D9 85).
    $text = "DIVERGED 2026-10-18 a/b.jsx — prints `3,900 ج.م`, Western digits in both\r\nsrc/next.js\nsrc/last.js";
    expect(manifestLines($text))->toBe([
        'DIVERGED 2026-10-18 a/b.jsx — prints `3,900 ج.م`, Western digits in both',
        'src/next.js',
        'src/last.js',
    ]);
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

it('keeps every DIVERGED entry honest: dated, reasoned, present on both sides, and actually different', function () {
    $root = dirname(base_path()).DIRECTORY_SEPARATOR;
    $entries = divergedEntries();
    $twins = twinPaths();
    $failures = [];
    foreach ($entries as $e) {
        if ($e['path'] === '') {
            $failures[] = 'malformed (want `DIVERGED <YYYY-MM-DD> <path> — <reason>`): '.$e['line'];

            continue;
        }
        if (in_array($e['path'], $twins, true)) {
            $failures[] = "listed as a twin AND as diverged: {$e['path']}";
        }
        $a = $root.'Frontend-next'.DIRECTORY_SEPARATOR.$e['path'];
        $b = $root.'Frontend-brandfashion'.DIRECTORY_SEPARATOR.$e['path'];
        if (! is_file($a) || ! is_file($b)) {
            $failures[] = "DIVERGED {$e['path']} must exist on both sides (a file deleted on one side is a comment, not a DIVERGED line)";

            continue;
        }
        if (hash_file('sha256', $a) === hash_file('sha256', $b)) {
            $failures[] = "DIVERGED {$e['path']} is byte-identical again — re-twin it: move it back to the list";
        }
        if (strlen($e['reason']) < 12) {
            $failures[] = "DIVERGED {$e['path']}: the reason is too short to be one";
        }
    }
    fwrite(STDERR, "\ntwins diverged on purpose: ".count($entries)."\n");

    Assert::assertSame([], $failures, count($failures).' DIVERGED problem(s):'."\n".implode("\n", $failures));
    expect(count($entries))->toBeGreaterThan(0, 'the DIVERGED section is empty — the 2026-10-14 splits should be on it');
});
