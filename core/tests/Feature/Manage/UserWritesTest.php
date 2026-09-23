<?php

use App\Domain\Access\DashboardAccounts;
use App\Domain\Access\UserWrites;
use Tests\Support\Staff;

/*
 * The LOCK on the shared `users` table (AGENTS §2.18, widened 2026-09-21).
 *
 * `AccountWriteGuardTest` covers the dashboard's two operations. This file covers the mechanism
 * they and the customer-account operations both go through, and it exists because the mechanism is
 * the part that has to keep being true after somebody adds a third feature.
 *
 * The properties, in the order they matter:
 *
 *   1. a reason nobody declared cannot open the door — so `open()` is not a string a caller invents;
 *   2. the door shuts again, including when the work throws;
 *   3. nesting does not re-open it on the way out;
 *   4. every call site names a DECLARED reason, and every declared reason still has a call site —
 *      so the list cannot outlive the feature that needed it, and the feature cannot outrun
 *      the list.
 *
 * (4) is the one that keeps the constant honest. It is also the one a reviewer would otherwise have
 * to do by hand, every time, for ever.
 */

// ── the door ─────────────────────────────────────────────────────────────────────────────────

it('REFUSES to open for a reason nobody declared', function () {
    expect(fn () => UserWrites::open('customer.whatever', fn () => 'never runs'))
        ->toThrow(RuntimeException::class, 'is not a declared reason');

    expect(UserWrites::permitted())->toBeFalse();
});

it('names the declared reasons in the refusal, so the message answers the question it raises', function () {
    $user = Staff::admin();
    $user->first_name = 'Should Never Land';

    try {
        $user->save();
        expect(false)->toBeTrue('the guard did not refuse the write');
    } catch (RuntimeException $e) {
        foreach (array_keys(UserWrites::REASONS) as $reason) {
            expect($e->getMessage())->toContain($reason);
        }
    }
});

it('shuts the door again after the work, and after the work THROWS', function () {
    $reason = array_key_first(UserWrites::REASONS);

    expect(UserWrites::permitted())->toBeFalse()
        ->and(UserWrites::reason())->toBeNull();

    UserWrites::open($reason, function () use ($reason): void {
        expect(UserWrites::permitted())->toBeTrue()
            ->and(UserWrites::reason())->toBe($reason);
    });

    expect(UserWrites::permitted())->toBeFalse();

    try {
        UserWrites::open($reason, function (): void {
            throw new RuntimeException('something inside went wrong');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(UserWrites::permitted())->toBeFalse()
        ->and(UserWrites::reason())->toBeNull();
});

it('does not re-open the door when a NESTED call returns', function () {
    /*
     * The reason this is a stack and not a boolean. An outer operation that opens the door and then
     * calls an inner one must still be open when the inner one returns — and CLOSED when it itself
     * returns. A boolean set to false by the inner `finally` would leave the outer half-way through
     * a write with the guard armed against it.
     */
    // Two named reasons rather than two array offsets: the revert is only visible if they differ,
    // and naming them means removing one breaks this test loudly instead of silently weakening it.
    $outer = DashboardAccounts::CREATE;
    $inner = DashboardAccounts::PASSWORD;

    UserWrites::open($outer, function () use ($inner, $outer): void {
        UserWrites::open($inner, function () use ($inner): void {
            expect(UserWrites::reason())->toBe($inner);
        });

        // Still open, and back to the OUTER reason.
        expect(UserWrites::permitted())->toBeTrue()
            ->and(UserWrites::reason())->toBe($outer);
    });

    expect(UserWrites::permitted())->toBeFalse();
});

// ── the declared list, measured against the code ─────────────────────────────────────────────

/**
 * Every PHP file of the application, minus the class that defines the door itself.
 *
 * @return array<string, string> path => source
 */
function userWriteSources(): array
{
    $sources = [];
    foreach (['app', 'database', 'routes', 'bootstrap'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'UserWrites.php')) {
                continue;
            }
            /*
             * COMMENTS BLANKED, line count preserved.
             *
             * This census looks for `UserWrites::open(` in source TEXT, so it counted every
             * docblock that MENTIONS the door as a call site with an undeclared reason. It started
             * failing the moment `UserWriteGuard` was written — a file whose whole job is to
             * explain that door — and the failure read as "a users write was opened with an
             * undeclared reason", which is the opposite of what was happening.
             *
             * Prose about the rule must not be readable as a use of it.
             */
            $sources[$file->getPathname()] = userWriteCodeOnly(
                (string) file_get_contents($file->getPathname())
            );
        }
    }

    return $sources;
}

/**
 * A file's code with every comment blanked out, newlines preserved so line numbers still hold.
 */
function userWriteCodeOnly(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * Every `UserWrites::open(` call site, as (file, reason-expression) pairs.
 *
 * The reason is captured as it is WRITTEN — `self::CREATE`, `'customer.register'` — because that is
 * what a reviewer reads and what a typo would break.
 *
 * @return list<array{file: string, expression: string}>
 */
function userWriteCallSites(): array
{
    $sites = [];
    foreach (userWriteSources() as $path => $source) {
        if (preg_match_all('/UserWrites::open\(\s*([^,]+),/', $source, $matches) > 0) {
            foreach ($matches[1] as $expression) {
                $sites[] = ['file' => $path, 'expression' => trim($expression)];
            }
        }
    }

    return $sites;
}

/**
 * Every `const NAME = 'value';` string constant, kept PER FILE.
 *
 * Per file rather than by name, and that is not fussiness — a flat name => value map is how the
 * first version of this test was wrong. `PASSWORD` is declared in BOTH doors
 * (`DashboardAccounts::PASSWORD = 'dashboard.password'`, `CustomerAccounts::PASSWORD =
 * 'customer.password'`), so the second silently overwrote the first: the "unused" test then
 * reported `dashboard.password` as dead, and — worse, because it was quiet — the "declared" test
 * was resolving `DashboardAccounts::PASSWORD` to the OTHER class's value and passing because that
 * value happened to be declared too. A collision could have hidden an undeclared reason entirely.
 *
 * @return array<string, array<string, string>> file => NAME => value
 */
function userWriteConstants(): array
{
    $constants = [];
    foreach (userWriteSources() as $path => $source) {
        $constants[$path] = [];
        if (preg_match_all("/const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'\s*;/", $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $constants[$path][$match[1]] = $match[2];
            }
        }
    }

    return $constants;
}

/**
 * Short class name => the file that declares it, so `Foo::BAR` resolves in Foo's own file.
 *
 * @return array<string, string>
 */
function userWriteClassFiles(): array
{
    $files = [];
    foreach (userWriteSources() as $path => $source) {
        if (preg_match('/^(?:final\s+)?(?:abstract\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $m) === 1) {
            $files[$m[1]] = $path;
        }
    }

    return $files;
}

/**
 * What one call site's reason expression actually resolves to, or null when it cannot be read.
 *
 * Null is never "assume it is fine": an expression this cannot resolve is a COMPUTED reason, and a
 * computed reason defeats the point of declaring them at all.
 */
function userWriteReasonOf(string $file, string $expression): ?string
{
    // A literal: 'customer.register'
    if (preg_match("/^'([^']*)'$/", $expression, $m) === 1) {
        return $m[1];
    }

    if (preg_match('/^(self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)$/', $expression, $m) !== 1) {
        return null;
    }

    $constants = userWriteConstants();
    $owner = in_array($m[1], ['self', 'static'], true) ? $file : (userWriteClassFiles()[$m[1]] ?? null);

    return $owner === null ? null : ($constants[$owner][$m[2]] ?? null);
}

it('finds call sites at all, so the two assertions below are not vacuous', function () {
    // The trap this avoids: a regex that stops matching turns both tests below green for ever.
    expect(userWriteCallSites())->not->toBe([]);
});

it('resolves a constant in the class that DECLARES it, not one that shares the name', function () {
    /*
     * The collision, pinned as its own case so the fix cannot quietly regress. Both doors declare a
     * `PASSWORD` constant; each must resolve to its own value.
     */
    $files = userWriteClassFiles();

    expect(userWriteReasonOf($files['DashboardAccounts'], 'self::PASSWORD'))->toBe('dashboard.password')
        ->and(userWriteReasonOf($files['CustomerAccounts'], 'self::PASSWORD'))->toBe('customer.password')
        ->and(userWriteReasonOf($files['CustomerAccounts'], 'DashboardAccounts::PASSWORD'))->toBe('dashboard.password')
        // …and something it genuinely cannot read stays null rather than becoming a pass.
        ->and(userWriteReasonOf($files['CustomerAccounts'], '$whateverThisIs'))->toBeNull();
});

it('opens the door ONLY with a reason that is declared', function () {
    $declared = array_keys(UserWrites::REASONS);
    $offenders = [];

    foreach (userWriteCallSites() as $site) {
        $value = userWriteReasonOf($site['file'], $site['expression']);

        if ($value === null || ! in_array($value, $declared, true)) {
            $offenders[] = $site['file'].' → '.$site['expression'];
        }
    }

    expect($offenders)->toBe([], 'a users write was opened with a reason UserWrites::REASONS does not declare');
});

it('declares no reason that has stopped being used', function () {
    /*
     * The other direction, and the one that rots silently. A feature is removed, its reason stays in
     * the constant, and the declared permission is now wider than the code — precisely the state the
     * constant exists to make impossible to be in without noticing.
     */
    $used = [];
    foreach (userWriteCallSites() as $site) {
        $value = userWriteReasonOf($site['file'], $site['expression']);
        if ($value !== null) {
            $used[] = $value;
        }
    }

    $unused = array_values(array_diff(array_keys(UserWrites::REASONS), $used));

    expect($unused)->toBe([], 'UserWrites::REASONS declares a permission no code uses any more');
});
