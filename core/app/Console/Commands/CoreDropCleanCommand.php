<?php

namespace App\Console\Commands;

use App\Transform\LegacySource;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * core:drop-clean — drop the transform-output tables so `migrate` + `core:transform` can rebuild
 * them (CLEAN_CORE_STUDY §3.4 step 3b).
 *
 * ── Why this is a command and not a line of SQL in a runbook (review 🟠-A) ───────────────────
 *
 * The rule "a dashboard-authored table is never dropped" (AGENTS §2.20) lived in a PHP constant,
 * while the procedure a human actually runs at 02:00 on switch night was hand-typed SQL with a
 * `storefront*` glob — which matches `storefronts` and `storefront_banners`, the two tables the
 * rule exists to protect. A guarantee written in one place and enforced nowhere near the hand on
 * the keyboard is the same defect class as the remember-token write this wave fixed: the safe
 * behaviour existed, and the path a person takes bypassed it.
 *
 * So the list has ONE home. This command drops exactly
 * {@see CoreChecksumCommand::CLEAN_TABLES} — never a dashboard table, never a legacy table — and
 * checks that claim per table, at the moment of dropping, rather than trusting the constant it
 * just read.
 *
 *   php artisan core:drop-clean --dry-run     # list what would go, change nothing
 *   php artisan core:drop-clean               # prompts in production (ConfirmableTrait)
 *   php artisan core:drop-clean --force       # switch night, inside the runbook
 *
 * Afterwards: `php artisan migrate --force` then `php artisan core:transform --force`.
 *
 * It also clears the `core_migrations` rows for the core migrations, because a dropped table whose
 * migration still claims to have run is a half-state `migrate` will not fix. Every core migration
 * is idempotent (M1 guards the two preserved tables with `hasTable`, M1b–M1g guard their columns
 * and indexes), so re-running the whole set over the preserved tables is safe by design.
 */
final class CoreDropCleanCommand extends Command
{
    use ConfirmableTrait;

    /*
     * ── there is no pattern, and that is the fix (2026-09-13) ────────────────────────────────
     *
     * This used to clear only rows matching `LIKE '2026_09_1%'`. Two migrations later the dates
     * reached `2026_09_20` and `2026_09_21` and the pattern stopped matching them — so a rebuild
     * recreated `integration_outbox` from the base migration while M1k's row still claimed to
     * have added `dedupe_key`. The UNIQUE index that makes an order e-mail exactly-once was gone
     * and `migrate` reported nothing to do. Found by running the rebuild, not by reading it.
     *
     * `core_migrations` is CORE's own migration repository (`config/database.php`); the legacy
     * application records its own in `migrations` and never touches this table. Every row here is
     * therefore a core migration, there is nothing to filter out, and the honest operation is
     * "clear the ledger". {@see self::ledgerRowsWithoutAFile()} checks that claim against the files
     * on disk at the moment of acting, which a date glob never did.
     */

    protected $signature = 'core:drop-clean
        {--dry-run : list the tables and the migration rows that would go, and change nothing}
        {--keep-migrations : drop the tables but leave core_migrations alone (rarely what you want)}
        {--force : skip the production confirmation prompt (ConfirmableTrait)}';

    protected $description = 'Drop the transform-output tables (never the dashboard-authored ones) so migrate + core:transform can rebuild';

    public function handle(): int
    {
        $planned = self::plan();
        $protected = CoreChecksumCommand::DASHBOARD_TABLES;

        // Defence in depth, at the moment of acting rather than when the constant was written.
        self::assertDroppable($planned);

        $existing = array_values(array_filter($planned, fn (string $table): bool => Schema::hasTable($table)));
        $missing = array_values(array_diff($planned, $existing));

        /*
         * The migration ledger may not exist yet, and that is a NORMAL state for this command.
         *
         * Rehearsal #3 (2026-09-12) found it the hard way: the §2.9.4 loop starts by importing a
         * fresh production dump, which has the 65 legacy tables and nothing of ours — no clean
         * tables and no `core_migrations`. The first command of the runbook then died with
         * "Base table or view not found: core_migrations" and exit 1, so the documented recipe
         * could not be followed from its own first line. Nothing was wrong with the database.
         *
         * A missing ledger means zero rows to clear, which is exactly what a bare dump should
         * report — so it is read through `Schema::hasTable()` and said out loud.
         */
        $hasLedger = Schema::hasTable('core_migrations');
        $migrationRows = $hasLedger ? self::rowsToClear() : 0;

        $this->line(sprintf(
            'transform output: %d table(s) in the list, %d present, %d already absent',
            count($planned), count($existing), count($missing),
        ));
        if (! $hasLedger) {
            $this->line('core_migrations: not present — this database has never had the clean schema, so there is no ledger to clear.');
        }
        $this->line('preserved (dashboard-authored, never dropped): '.implode(', ', $protected));

        // Which dashboard tables are there BEFORE anything is dropped. The guard below asks whether
        // the drop took one, not whether they exist at all — on a bare dump none of them do yet.
        $protectedBefore = array_values(array_filter($protected, fn (string $table): bool => Schema::hasTable($table)));

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->table(
                ['#', 'table', 'rows'],
                array_map(
                    fn (int $index, string $table): array => [$index + 1, $table, DB::table($table)->count()],
                    array_keys($existing),
                    $existing,
                ),
            );
            foreach ($protected as $table) {
                $this->line(sprintf('  KEEP  %-24s %d row(s)', $table, Schema::hasTable($table) ? DB::table($table)->count() : 0));
            }
            $this->newLine();
            $this->info("DRY RUN — nothing dropped. {$migrationRows} core_migrations row(s) would also be cleared.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('This DROPS '.count($existing).' clean tables in the PRODUCTION database.')) {
            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($existing as $table) {
                DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
            }
        } finally {
            // Always back on, even if a drop threw: leaving a session with checks disabled is how
            // an orphan row gets written later.
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->info('dropped '.count($existing).' table(s)');

        if ((bool) $this->option('keep-migrations')) {
            $this->warn('core_migrations left alone (--keep-migrations): `migrate` will believe these tables still exist.');
        } elseif (! $hasLedger) {
            $this->line('no core_migrations table to clear.');
        } else {
            $unknown = self::ledgerRowsWithoutAFile();
            if ($unknown !== []) {
                /*
                 * A recorded migration with no file on disk cannot be re-run, so leaving its row
                 * would be no safer than clearing it — but it IS drift worth saying out loud,
                 * because it usually means a branch switch mid-rebuild.
                 */
                $this->warn('core_migrations names '.count($unknown).' migration(s) with no file on disk: '.implode(', ', $unknown));
            }

            $removed = DB::table('core_migrations')->delete();
            $this->info("cleared {$removed} core_migrations row(s) — the whole ledger, so every core migration re-runs");
        }

        /*
         * Prove the preserved tables are still there, in the same breath as the drop — but only
         * the ones that WERE there. A table that never existed cannot have been dropped, and
         * treating its absence as a catastrophe is what made this command unusable on a fresh
         * import (rehearsal #3). The alarm still fires for its real case: a dashboard table that
         * existed a moment ago and does not now.
         */
        $lost = array_values(array_filter($protectedBefore, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($lost !== []) {
            $this->error('A dashboard-authored table disappeared: '.implode(', ', $lost).'. This is a bug — do NOT continue the switch.');

            return self::FAILURE;
        }

        if ($protectedBefore === []) {
            $this->line('dashboard-authored tables: none present yet — nothing to preserve on a database without the clean schema.');
        } else {
            $this->line('preserved tables verified present: '.implode(', ', $protectedBefore));
        }

        $this->newLine();
        $this->info('Next: php artisan migrate --force && php artisan core:transform --force');

        return self::SUCCESS;
    }

    /**
     * How many ledger rows a run would clear: all of them.
     *
     * A method rather than an inline `count()` so the rebuild test can assert that this equals the
     * table's own total — which is what "no pattern" means, expressed as something checkable.
     */
    public static function rowsToClear(): int
    {
        return Schema::hasTable('core_migrations') ? DB::table('core_migrations')->count() : 0;
    }

    /**
     * Ledger rows naming a migration this checkout does not have.
     *
     * @return list<string>
     */
    public static function ledgerRowsWithoutAFile(): array
    {
        if (! Schema::hasTable('core_migrations')) {
            return [];
        }

        $files = glob(database_path('migrations/*.php'));
        $known = [];
        foreach ($files === false ? [] : $files as $file) {
            $known[basename($file, '.php')] = true;
        }

        $out = [];
        foreach (DB::table('core_migrations')->orderBy('id')->pluck('migration') as $name) {
            $name = is_scalar($name) ? (string) $name : '';
            if ($name !== '' && ! isset($known[$name])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Refuse a list that contains anything but transform output.
     *
     * Separate and static so the guard can be PROVEN with a bad list: the command is final, and a
     * test that cannot feed it a wrong plan cannot show the guard works.
     *
     * @param  list<string>  $tables
     *
     * @throws RuntimeException
     */
    public static function assertDroppable(array $tables): void
    {
        foreach ($tables as $table) {
            if (in_array($table, CoreChecksumCommand::DASHBOARD_TABLES, true)) {
                throw new RuntimeException("REFUSING: [{$table}] is dashboard-authored (AGENTS §2.20) and must never be dropped.");
            }
            if (in_array($table, LegacySource::TABLES, true)) {
                throw new RuntimeException("REFUSING: [{$table}] is a LEGACY table (AGENTS §3).");
            }
            if (! in_array($table, CoreChecksumCommand::CLEAN_TABLES, true)) {
                throw new RuntimeException("REFUSING: [{$table}] is not in CoreChecksumCommand::CLEAN_TABLES, so this command has no business dropping it.");
            }
        }
    }

    /**
     * The tables this command drops — transform output only.
     *
     * A method rather than inline use of the constant, so a test can assert the PLAN without
     * dropping anything.
     *
     * @return list<string>
     */
    public static function plan(): array
    {
        return CoreChecksumCommand::CLEAN_TABLES;
    }
}
