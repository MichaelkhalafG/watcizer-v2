<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Console\Commands\CoreDropCleanCommand;
use App\Transform\LegacySource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * 🟠-A (review 2026-09-11): the drop list had ONE home (a PHP constant) and the procedure a human
 * runs at 02:00 on switch night was hand-typed SQL with a `storefront*` glob — which matches
 * `storefronts` and `storefront_banners`, the two tables the rule exists to protect.
 *
 * `core:drop-clean` is now that home. These tests assert its PLAN and its refusals.
 *
 * They deliberately do NOT execute the drop: `DROP TABLE` commits implicitly in MariaDB, so a real
 * drop inside the suite would break out of `DatabaseTransactions` and take the local clean side
 * with it, leaving every later test to fail against empty tables. The real end-to-end — drop →
 * migrate → transform, with a dashboard edit surviving — is run out of suite and recorded in
 * `new branding/docs/wave4a/DROP_CLEAN_2026-09-11.md`.
 */

it('plans exactly the transform-output tables — 41, and not one more', function () {
    $plan = CoreDropCleanCommand::plan();

    expect($plan)->toBe(CoreChecksumCommand::CLEAN_TABLES)
        ->and($plan)->toHaveCount(41);
});

it('never plans a dashboard-authored table', function () {
    foreach (CoreChecksumCommand::DASHBOARD_TABLES as $table) {
        expect(CoreDropCleanCommand::plan())->not->toContain($table);
    }
});

it('never plans a legacy table', function () {
    $legacyInPlan = array_values(array_intersect(CoreDropCleanCommand::plan(), LegacySource::TABLES));

    expect($legacyInPlan)->toBe([]);
});

it('lists the preserved tables next to the doomed ones, so the operator sees both', function () {
    expect(Artisan::call('core:drop-clean', ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('41 table(s) in the list')
        ->and($output)->toContain('preserved (dashboard-authored, never dropped): storefronts, storefront_banners, core_user_roles')
        ->and($output)->toContain('KEEP  storefronts')
        ->and($output)->toContain('DRY RUN — nothing dropped');

    // A dry run changes nothing: every table is still there.
    foreach (array_merge(CoreDropCleanCommand::plan(), CoreChecksumCommand::DASHBOARD_TABLES) as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} vanished during a dry run");
    }
});

it('shows the row counts, because an operator should see what they are about to destroy', function () {
    Artisan::call('core:drop-clean', ['--dry-run' => true]);
    $output = Artisan::output();
    $products = DB::table('catalog_products')->count();

    expect($output)->toContain('catalog_products')
        ->and($output)->toContain((string) $products)
        ->and($output)->toContain('core_migrations row(s) would also be cleared');
});

it('refuses, at the moment of acting, a list containing anything but transform output', function () {
    // The constant could be edited by a future hand, so the command re-checks each name as it
    // acts. Proving that needs a BAD list, which is why the guard is a static method: the command
    // itself is final and cannot be subclassed to lie to it.
    expect(fn () => CoreDropCleanCommand::assertDroppable(['catalog_brands', 'storefronts']))
        ->toThrow(RuntimeException::class, 'dashboard-authored');

    expect(fn () => CoreDropCleanCommand::assertDroppable(['catalog_brands', 'products']))
        ->toThrow(RuntimeException::class, 'LEGACY table');

    expect(fn () => CoreDropCleanCommand::assertDroppable(['catalog_brands', 'some_new_table']))
        ->toThrow(RuntimeException::class, 'not in CoreChecksumCommand::CLEAN_TABLES');

    // …and the real plan passes it.
    CoreDropCleanCommand::assertDroppable(CoreDropCleanCommand::plan());
    expect(true)->toBeTrue();
});

it('names the migration pattern it clears, and the pattern matches only core migrations', function () {
    expect(CoreDropCleanCommand::MIGRATION_PATTERN)->toBe('2026_09_1%');

    $matched = DB::table('core_migrations')->where('migration', 'like', CoreDropCleanCommand::MIGRATION_PATTERN)->pluck('migration');
    expect($matched)->not->toBeEmpty();
    foreach ($matched as $migration) {
        expect(T::str($migration))->toStartWith('2026_09_1');
    }
});
