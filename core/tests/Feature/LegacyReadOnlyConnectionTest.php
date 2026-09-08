<?php

use App\Support\LegacyReadOnly;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Milestone audit — the raw-SQL vector.
 *
 * `DB::connection('legacy')->statement('UPDATE …')` bypasses both the model guard and the
 * transform's own reader. AppServiceProvider now arms App\Support\LegacyReadOnly on every
 * `legacy` connection as it is established, so the SERVER refuses the write.
 *
 * The suite's own `legacy` connection is inside a DatabaseTransactions transaction and cannot be
 * switched to read-only mid-flight (MariaDB refuses, and the audit proofs need TEMPORARY tables),
 * so the mechanism is proven here on a SEPARATE connection to the same database — the same object
 * the listener receives at runtime.
 */

function probeConnection(): Connection
{
    /** @var array<string, mixed> $config */
    $config = config('database.connections.legacy');
    config(['database.connections.legacy_readonly_probe' => $config]);

    return DB::connection('legacy_readonly_probe');
}

afterEach(function () {
    DB::purge('legacy_readonly_probe');
});

it('refuses every write on a legacy session once the guard is armed, and keeps reads working', function () {
    $probe = probeConnection();
    expect(LegacyReadOnly::isEnforced($probe))->toBeFalse();      // a plain connection is writable

    LegacyReadOnly::enforce($probe);

    expect(LegacyReadOnly::isEnforced($probe))->toBeTrue()
        ->and($probe->table('sub_types')->count())->toBeGreaterThan(0);    // reads still work

    foreach ([
        fn () => $probe->statement('UPDATE sub_types SET id = id WHERE id = -1'),
        fn () => $probe->statement('INSERT INTO sub_types (id, created_at, updated_at) VALUES (999999, NOW(), NOW())'),
        fn () => $probe->statement('DELETE FROM sub_types WHERE id = -1'),
        fn () => $probe->statement('CREATE TEMPORARY TABLE zz_guard_probe LIKE sub_types'),
    ] as $write) {
        expect($write)->toThrow(QueryException::class);
    }
});

it('is armed by the application for the legacy connection outside the test suite', function () {
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($provider)->toContain('ConnectionEstablished')
        ->and($provider)->toContain("'legacy'")
        ->and($provider)->toContain('LegacyReadOnly::enforce')
        ->and($provider)->toContain('runningUnitTests');          // the one bounded exception, documented in the class
});
