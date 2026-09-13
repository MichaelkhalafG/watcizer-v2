<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Console\Commands\CoreDropCleanCommand;
use App\Transform\LegacySource;
use App\Transform\Row;
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

it('clears EVERY ledger row, so every core migration re-runs after the drop', function () {
    /*
     * ── the test this replaced, and why ─────────────────────────────────────────────────────
     *
     * The previous version asserted that `MIGRATION_PATTERN` was `'2026_09_1%'` and then that the
     * rows matching `'2026_09_1%'` started with `2026_09_1`. Both are true of any pattern and any
     * data — a tautology — and it passed for a week while `2026_09_20` and `2026_09_21` sat
     * outside the pattern and quietly stopped being re-run. The symptom was
     * `integration_outbox.dedupe_key` vanishing on a rebuild while `migrate` said there was
     * nothing to do: the UNIQUE index that makes an order e-mail exactly-once, gone in silence.
     *
     * So the property, not the implementation: a rebuild drops the 41 tables, therefore the
     * ledger clear must cover EVERY row, whatever its name or date.
     */
    $total = T::int(DB::table('core_migrations')->count());
    expect($total)->toBeGreaterThan(0, 'this test needs a populated ledger to say anything')
        ->and(CoreDropCleanCommand::rowsToClear())->toBe($total);
});

it('has a file on disk for every migration the ledger claims has run', function () {
    /*
     * The other half: a row whose file is gone cannot be re-run at all, so a rebuild would leave
     * whatever that migration created missing and report success. On a clean checkout the answer
     * is always none — this catches a branch switch, a deleted migration, and a renamed one.
     */
    expect(CoreDropCleanCommand::ledgerRowsWithoutAFile())->toBe([]);
});

it('really has the column and index the mail outbox needs, AFTER a rebuild', function () {
    /*
     * The consequence of the bug above, asserted against the live schema rather than the
     * migration's source: `dedupe_key` and its UNIQUE index are what make an order e-mail
     * exactly-once (M1k), and they are on a table `core:drop-clean` drops every rehearsal.
     */
    expect(Schema::hasColumn('integration_outbox', 'dedupe_key'))->toBeTrue();

    $unique = [];
    foreach (DB::select('SHOW INDEX FROM `integration_outbox`') as $row) {
        $index = Row::cast(T::row($row));
        if (Row::str($index, 'Column_name') === 'dedupe_key' && Row::str($index, 'Non_unique') === '0') {
            $unique[] = Row::str($index, 'Key_name');
        }
    }

    expect($unique)->toBe(['io_dedupe_uq'], 'the dedupe key must be UNIQUE, or a double click mails the customer twice');
});
