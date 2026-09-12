<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * REHEARSAL #3 (2026-09-12) — `core:drop-clean` is the FIRST command of the §3.4 step-3b runbook
 * and of the §2.9.4 rehearsal loop, and the loop begins by importing a fresh production dump: 65
 * legacy tables, no clean tables, no `core_migrations`. Against that database the command died
 * with `Base table or view not found: 1146 Table '…core_migrations' doesn't exist` and exit 1, so
 * the recipe could not be followed from its own first line. Nothing was wrong with the database.
 *
 * ── Why this test uses a database of its own ──────────────────────────────────────────────────
 *
 * `DropCleanCommandTest` deliberately never executes the drop: `DROP TABLE` commits implicitly in
 * MariaDB, so a real drop inside the suite would break out of `DatabaseTransactions` and take the
 * local clean side with it. The same reasoning points the other way here — the state under test is
 * "a database with ZERO core tables", which the working database is not and must not become. So
 * this test creates an EMPTY scratch database, points the default connection at it for the
 * duration, runs the command for real there, and drops the scratch database again.
 *
 * That makes it the honest version of the scenario: not a mocked `hasTable`, an actual database
 * with nothing of ours in it.
 */

/** An empty scratch database, the default connection pointed at it, and both undone afterwards. */
function onBareDatabase(Closure $body): void
{
    $name = 'wz_dropclean_probe_'.bin2hex(random_bytes(4));
    $original = T::str(config('database.default'));

    /** @var array<string, mixed> $config */
    $config = config('database.connections.'.$original);
    $config['database'] = $name;

    DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try {
        config(['database.connections.wz_bare' => $config, 'database.default' => 'wz_bare']);
        DB::purge('wz_bare');

        // The scratch database really is empty — otherwise this test proves nothing about a bare
        // dump, and the assertions below would be measuring the working database by accident.
        $tableCount = DB::connection('wz_bare')->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $name)->count();
        expect($tableCount)->toBe(0, 'the scratch database was not empty');

        $body();
    } finally {
        config(['database.default' => $original]);
        DB::purge('wz_bare');
        DB::connection($original)->statement('DROP DATABASE IF EXISTS `'.$name.'`');
    }
}

it('runs a DRY RUN against a database with no core tables, and says why there is nothing to clear', function () {
    onBareDatabase(function (): void {
        expect(Artisan::call('core:drop-clean', ['--dry-run' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('41 table(s) in the list, 0 present, 41 already absent')
            // The sentence an operator needs: the ledger's absence is a state, not a fault.
            ->and($output)->toContain('core_migrations: not present')
            ->and($output)->toContain('DRY RUN — nothing dropped. 0 core_migrations row(s)');
    });
});

it('completes for real against a bare database instead of dying on the missing ledger', function () {
    onBareDatabase(function (): void {
        expect(Artisan::call('core:drop-clean', ['--force' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('dropped 0 table(s)')
            ->and($output)->toContain('no core_migrations table to clear')
            // …and it must NOT cry catastrophe about dashboard tables that were never created.
            // That false alarm was the second half of the same bug.
            ->and($output)->not->toContain('A dashboard-authored table disappeared')
            ->and($output)->toContain('none present yet')
            ->and($output)->toContain('Next: php artisan migrate --force');
    });
});

it('still drops what it should and preserves a dashboard table that IS present', function () {
    /*
     * The guard was narrowed — an absent-before table is no longer an alarm — so the path it
     * exists for is driven here for real, on a database where SOME of the tables exist: one
     * transform-output table to destroy, one dashboard-authored table to keep. The command must
     * drop the first, keep the second, and say so.
     */
    onBareDatabase(function (): void {
        Schema::create('catalog_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
        Schema::create('storefronts', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        DB::table('storefronts')->insert(['code' => 'watchizer']);

        expect(Artisan::call('core:drop-clean', ['--force' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('41 table(s) in the list, 1 present, 40 already absent')
            ->and($output)->toContain('dropped 1 table(s)')
            ->and($output)->toContain('preserved tables verified present: storefronts')
            ->and($output)->not->toContain('A dashboard-authored table disappeared')
            // The drop took the transform-output table and nothing else.
            ->and(Schema::hasTable('catalog_brands'))->toBeFalse()
            ->and(Schema::hasTable('storefronts'))->toBeTrue()
            ->and(T::int(DB::table('storefronts')->count()))->toBe(1, 'a preserved table lost its rows');
    });
});
