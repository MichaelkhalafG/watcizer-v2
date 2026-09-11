<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Console\Commands\MediaPruneCommand;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * 🟠-3 (review 2026-09-11): the old coverage test asserted that every table/column NAMED in
 * `MediaPruneCommand::REFERENCE_COLUMNS` exists. That is backwards — it catches a stale entry and
 * is blind to a MISSING one, and a missing entry is what makes `media:prune` delete a referenced
 * file.
 *
 * This derives the expected set from the LIVE schema instead: every string-typed column whose name
 * looks like a media reference must appear in the command's list, or be in the exclusion list below
 * with a reason. The first run of it found a real gap — `storefront_categories.image_path`, a
 * category image the command would have ignored.
 *
 * Note the shared schema: both connections point at ONE database (AGENTS §1), so the query below is
 * run once and each table is attributed to its owner by the existing table lists.
 *
 * There is deliberately NO test that each listed table sits under the right side (`clean` vs
 * `legacy`). PHPStan already proves it at analysis time — the constants are literal arrays, so it
 * reports "will always evaluate to true" for the correct membership and would report "always
 * false" for a wrong name. A runtime test of the same thing cannot fail, and a test that cannot
 * fail is worse than no test: it reports coverage it does not have.
 */

/** Columns whose NAME matches the media pattern but which hold no filename. Each needs a reason. */
const NOT_MEDIA = [
    // A materialised category path (`watches/automatic`), the taxonomy's own addressing.
    'storefront_categories.path',
    // URL paths for the A-17 twin redirects, not files on disk.
    'storefront_redirects.from_path',
    'storefront_redirects.to_path',
];

/**
 * Every media-shaped column in the shared schema: name matches, type is string-ish.
 *
 * @return array<string, list<string>> table => columns
 */
function mediaShapedColumns(): array
{
    $database = DB::connection()->getDatabaseName();
    $rows = DB::select(
        "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND DATA_TYPE IN ('varchar', 'char', 'text', 'mediumtext', 'longtext')
           AND COLUMN_NAME REGEXP 'image|photo|logo|file|cover|picture|avatar|thumb|_path$'
         ORDER BY t, c",
        [$database],
    );

    $out = [];
    foreach ($rows as $raw) {
        $row = T::row($raw);
        $table = Row::str($row, 't');
        $column = Row::str($row, 'c');
        if (in_array("{$table}.{$column}", NOT_MEDIA, true)) {
            continue;
        }
        $out[$table][] = $column;
    }

    return $out;
}

it('lists EVERY media-shaped column in the shared schema, on both sides', function () {
    $expected = mediaShapedColumns();
    expect($expected)->not->toBe([], 'the schema query found nothing — it is the test that is broken');

    $listed = [];
    foreach (MediaPruneCommand::REFERENCE_COLUMNS as $tables) {
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $listed["{$table}.{$column}"] = true;
            }
        }
    }
    // Long-text columns are covered by the inline scan rather than the column scan; either counts.
    foreach (MediaPruneCommand::INLINE_TEXT_COLUMNS as $tables) {
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $listed["{$table}.{$column}"] = true;
            }
        }
    }

    $missing = [];
    foreach ($expected as $table => $columns) {
        foreach ($columns as $column) {
            if (! isset($listed["{$table}.{$column}"])) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    expect($missing)->toBe([], 'media:prune would treat files referenced by these columns as orphans');
});

it('attributes every media column to a side, so nothing sits in an unowned table', function () {
    // The clean and legacy tables share one database, so "which side owns this table" comes from
    // the table lists, not from the connection. A table in neither list is a new table nobody
    // classified — which is exactly when a prune guard goes stale.
    $unclassified = [];
    foreach (array_keys(mediaShapedColumns()) as $table) {
        $isCore = in_array($table, CoreChecksumCommand::CORE_TABLES, true);
        $isLegacy = in_array($table, LegacySource::TABLES, true);
        if (! $isCore && ! $isLegacy) {
            $unclassified[] = $table;
        }
    }

    expect($unclassified)->toBe([]);
});

it('keeps the exclusion list honest: every excluded column still exists and is still not media', function () {
    foreach (NOT_MEDIA as $reference) {
        [$table, $column] = explode('.', $reference, 2);
        expect(Schema::hasColumn($table, $column))
            ->toBeTrue("{$reference} is excluded from the media scan but no longer exists — drop the exclusion");
    }
});
