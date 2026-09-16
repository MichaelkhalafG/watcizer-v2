<?php

use App\Console\Commands\CoreChecksumCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

/*
 * Switch night IS a drop-and-rebuild (study §3.4 step 3b): `core:drop-clean`, then `migrate`, then
 * `core:transform`. `core:drop-clean` preserves the dashboard-authored tables — storefronts,
 * banners, role grants and, since wave 4C, the three payment tables — while clearing EVERY
 * `core_migrations` row, because the migrations must re-run to rebuild the 41 tables it dropped.
 *
 * That combination has a sharp edge: the `migrate` that follows runs the migrations again against
 * the preserved tables, which still exist. A migration whose `Schema::create` is unguarded dies at
 * "Base table or view already exists" — half-applied, on switch night, at the second command of
 * the runbook.
 *
 * Wave 4C's own closing rebuild died exactly there, on `storefront_payment_providers`. These tests
 * hold the invariant for every preserved table, present and future: a table that survives the drop
 * must have a creation that survives the migrate.
 */

it('guards the creation of every table on the never-dropped list', function () {
    /*
     * Structural on purpose. The behavioural version of this test would have to drop and rebuild a
     * database, and `DROP TABLE` commits implicitly in MariaDB — it would break out of
     * `DatabaseTransactions` and take the local clean side with it (the reasoning
     * `DropCleanBareDatabaseTest` records). So this reads the migrations and asserts the guard is
     * there, which is the property that actually prevents the failure.
     */
    $migrations = glob(database_path('migrations/*.php'));
    $files = $migrations === false ? [] : $migrations;
    expect($files)->not->toBe([], 'no migrations were found to read');

    $source = '';
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        $source .= is_string($contents) ? $contents : '';
    }

    $unguarded = [];
    foreach (CoreChecksumCommand::DASHBOARD_TABLES as $table) {
        // Either idiom counts: `Schema::hasTable('x') or Schema::create('x', …)` (the clean-core
        // migration's style) or an early `if (Schema::hasTable('x')) { return; }` (the roles
        // migration's). What must not appear is a bare `Schema::create` for a preserved table.
        $guardedInline = str_contains($source, "Schema::hasTable('".$table."') or Schema::create('".$table."'");
        $guardedEarly = str_contains($source, "if (Schema::hasTable('".$table."'))");
        $created = str_contains($source, "Schema::create('".$table."'");

        if ($created && ! $guardedInline && ! $guardedEarly) {
            $unguarded[] = $table;
        }
    }

    expect($unguarded)->toBe([], 'a preserved table is created unguarded, so the rebuild will die at "table already exists": '.implode(', ', $unguarded));
});

it('guards every ALTER of a preserved table, not only its creation', function () {
    /*
     * The third case, and the one nobody wrote down (found 2026-09-16 adding M1q).
     *
     * AGENTS §2.20 says adding a table to `DASHBOARD_TABLES` is two edits: the list, and the guard
     * on its CREATION. But `core:drop-clean` clears the WHOLE `core_migrations` ledger, so after a
     * rebuild `migrate` re-runs every migration — including one that only ALTERS a preserved table.
     * That table was never dropped, so it still carries the column, and an unguarded
     * `$table->string('x')` dies at "Duplicate column name" at the second command of the runbook.
     *
     * Same failure as the unguarded `Schema::create` above, same night, different verb. M1q
     * (`promotion_rule_rewards.amount`) is the first migration of this shape; this test is here so
     * it is not the last one to be checked by hand.
     */
    $migrations = glob(database_path('migrations/*.php'));
    $files = $migrations === false ? [] : $migrations;

    $unguarded = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (! is_string($contents)) {
            continue;
        }

        foreach (CoreChecksumCommand::DASHBOARD_TABLES as $table) {
            if (! str_contains($contents, "Schema::table('".$table."'")) {
                continue;
            }

            /*
             * `hasColumn` anywhere in the file is enough: the guard may sit around the
             * `Schema::table` call or inside the closure around the individual column, and both
             * make the re-run a no-op. Asserting the SHAPE any tighter would fail a correct
             * migration for writing its guard differently.
             */
            if (! str_contains($contents, 'Schema::hasColumn')) {
                $unguarded[] = basename($file).' → '.$table;
            }
        }
    }

    expect(array_values(array_unique($unguarded)))->toBe(
        [],
        "A migration ALTERS a preserved table without a `Schema::hasColumn` guard. The table survives\n"
        ."`core:drop-clean` but the migration ledger does not, so this re-runs on switch night against\n"
        ."a table that already has the column — and dies at \"Duplicate column name\".\n\n"
        .'Wrap the change: `if (! Schema::hasColumn($table, $column)) { Schema::table(…); }`'
    );
});

it('keeps the payment tables on the never-dropped list, with their translations', function () {
    // The list itself, asserted by name: a payment contract lost to a rebuild is a storefront that
    // silently stops taking money, and the credentials are not recoverable from the repo.
    expect(CoreChecksumCommand::DASHBOARD_TABLES)
        ->toContain('storefront_payment_providers')
        ->toContain('storefront_payment_methods')
        ->toContain('storefront_payment_method_translations');
});

it('is idempotent: re-running the payments migration against the built schema changes nothing', function () {
    /*
     * The behavioural half, made safe: if the migration is genuinely idempotent it executes NO DDL
     * on a database where everything already exists, so nothing escapes the transaction. If it is
     * not, this fails — which is the alarm, and the residue is the broken migration's fault rather
     * than this test's.
     */
    $before = paymentSchemaFingerprint();

    $migration = require database_path('migrations/2026_09_18_000000_payments_and_order_scope.php');

    // `require` is mixed, and the base `Migration` class declares no `up()` (the migrations are
    // anonymous subclasses). `Assert::fail()` returns `never`, so this both checks the premise and
    // narrows the type for what follows.
    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        Assert::fail('the migration file did not return a migration object with an up()');
    }

    $migration->up();

    expect(paymentSchemaFingerprint())->toBe($before, 'a second up() changed the schema');
});

it('leaves the provider-scoped unique key in place, not wave 3’s single-column one', function () {
    $names = [];
    foreach (DB::select('SHOW INDEX FROM `payment_statuses`') as $row) {
        if (is_object($row) && property_exists($row, 'Key_name') && is_string($row->Key_name)) {
            $names[$row->Key_name] = true;
        }
    }

    // Transaction ids are unique only WITHIN a provider: the single-column key would reject a
    // second provider's colliding id as a replay and lose a real payment.
    expect(array_keys($names))->toContain('ps_provider_transaction_unique')
        ->and(array_keys($names))->not->toContain('ps_pay_transaction_unique');
});

/**
 * The shape of everything this migration touches — tables, their columns and their index names.
 *
 * @return array<string, list<string>>
 */
function paymentSchemaFingerprint(): array
{
    /** @var array<string, list<string>> $out */
    $out = [];
    foreach ([
        'storefront_payment_providers',
        'storefront_payment_methods',
        'storefront_payment_method_translations',
        'payment_statuses',
        'orders',
    ] as $table) {
        if (! Schema::hasTable($table)) {
            $out[$table] = ['MISSING'];

            continue;
        }

        $columns = [];
        foreach (Schema::getColumnListing($table) as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }
        sort($columns);

        $indexes = [];
        foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
            if (is_object($row) && property_exists($row, 'Key_name') && is_string($row->Key_name)) {
                $indexes[$row->Key_name] = true;
            }
        }
        $indexNames = array_keys($indexes);
        sort($indexNames);

        $out[$table] = array_merge($columns, $indexNames);
    }

    return $out;
}
