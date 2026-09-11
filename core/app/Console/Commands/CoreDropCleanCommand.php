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

    /** Core migrations are dated `2026_09_1x`; the legacy app's own rows never match. */
    public const MIGRATION_PATTERN = '2026_09_1%';

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
        $migrationRows = DB::table('core_migrations')->where('migration', 'like', self::MIGRATION_PATTERN)->count();

        $this->line(sprintf(
            'transform output: %d table(s) in the list, %d present, %d already absent',
            count($planned), count($existing), count($missing),
        ));
        $this->line('preserved (dashboard-authored, never dropped): '.implode(', ', $protected));

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
        } else {
            $removed = DB::table('core_migrations')->where('migration', 'like', self::MIGRATION_PATTERN)->delete();
            $this->info("cleared {$removed} core_migrations row(s)");
        }

        // Prove the preserved tables are still there, in the same breath as the drop.
        $lost = array_values(array_filter($protected, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($lost !== []) {
            $this->error('A dashboard-authored table disappeared: '.implode(', ', $lost).'. This is a bug — do NOT continue the switch.');

            return self::FAILURE;
        }
        $this->line('preserved tables verified present: '.implode(', ', $protected));

        $this->newLine();
        $this->info('Next: php artisan migrate --force && php artisan core:transform --force');

        return self::SUCCESS;
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
