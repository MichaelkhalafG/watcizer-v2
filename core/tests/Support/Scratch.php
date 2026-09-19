<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A scratch run directory that belongs to exactly ONE test.
 *
 * ── Why this exists (2026-09-19) ──────────────────────────────────────────────────────────────
 *
 * Seven transform-touching files used to build their own directory as
 * `storage_path('framework/testing/<label>-'.getmypid())` and guard the creation with
 * `if (! is_dir($dir))`. A PID is not unique over time — the OS hands the same number out again
 * within a day on this machine — so a later run could find a directory an earlier run had already
 * filled, keep it, and read it back. Two of those files (`OnePrimaryPlacementTest`,
 * `TransformCommandTest`) parse `summary.json` OUT of that directory and assert on it: a stale
 * summary left by a previous run would have been read as this run's output and passed. A false
 * pass in the reconciliation assertions is the one failure nobody would have looked at twice.
 *
 * So the key is `uniqid('', true)` — microtime plus entropy, never repeated — and the directory is
 * deleted after the test that made it, which also stops `storage/framework/testing` growing by a
 * directory per assertion-bearing run. The path is memoised PER LABEL for the duration of one test,
 * because several callers ask for "the directory this test's transform wrote into" twice: once to
 * run the command and once to read `summary.json` back out of it.
 *
 * {@see Scratch::cleanAll()} is registered once in `tests/Pest.php`, for the same
 * reason `LegacyShadow::closeAll()` is: a per-file `afterEach` is a line the eighth file to use
 * this helper would forget, and the cost of a forgotten line is a directory that outlives the run.
 */
final class Scratch
{
    /** @var array<string, string> label => absolute path, for the CURRENT test only */
    private static array $dirs = [];

    /**
     * The directory this test writes `<label>` output into — created, empty, and its own.
     *
     * Asking twice with the same label inside one test returns the SAME path; the next test gets a
     * fresh one.
     */
    public static function dir(string $label): string
    {
        if (! isset(self::$dirs[$label])) {
            $dir = storage_path('framework/testing/'.$label.'-'.str_replace('.', '', uniqid('', true)));
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("cannot create scratch directory {$dir}");
            }
            self::$dirs[$label] = $dir;
        }

        return self::$dirs[$label];
    }

    /** Remove every directory this test asked for. A no-op when it asked for none. */
    public static function cleanAll(): void
    {
        foreach (self::$dirs as $dir) {
            File::deleteDirectory($dir);
        }
        self::$dirs = [];
    }
}
