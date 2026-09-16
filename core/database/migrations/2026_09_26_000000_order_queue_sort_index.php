<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1n — the index the order queue sorts and filters on.
 *
 * ── What it is for ───────────────────────────────────────────────────────────────────────────
 *
 * `/manage/orders` sorts by `created_at DESC` by default and offers a `from`/`to` date range. Both
 * read the same column, and that column had no index: every page load was a full scan of `orders`
 * plus a filesort, and every date filter was a full scan as well. At the 67 orders this database
 * holds today that is invisible; the screen is being built for a shop that will have thousands.
 *
 * ── Why this index ALONE would have made it slower ──────────────────────────────────────────
 *
 * Worth writing down, because the obvious version of this migration is a pessimisation.
 *
 * `TableQuery` appends a tiebreaker to every sort so page 2 cannot repeat a row from page 1. That
 * tiebreaker used to be hard-coded ASCENDING, so the queue's real order was
 * `created_at DESC, id ASC` — a MIXED-direction sort, which no index can serve. Measured on a
 * 20,000-row copy of this table's shape, `LIMIT 25`:
 *
 *   | order by                 | index            | plan                        | median  |
 *   |--------------------------|------------------|-----------------------------|---------|
 *   | created_at DESC, id ASC  | none             | filesort over 19,621 rows   | 4.71 ms |
 *   | created_at DESC, id ASC  | **this one**     | index scan AND filesort     | 6.62 ms |
 *   | created_at DESC, id DESC | **this one**     | rows=25, Using index        | 0.45 ms |
 *
 * The index made the mixed sort WORSE: the optimiser walked the whole index and then sorted anyway.
 * So this migration ships together with the one-word change in `TableQuery` that makes the
 * tiebreaker follow the sort's direction. Neither half is worth anything alone, and a future reader
 * who reverts one of them should revert both.
 *
 * ── Why it is safe on a legacy table ────────────────────────────────────────────────────────
 *
 * `orders` is a SHARED commerce table the legacy application still writes, so the rule (M1h, and
 * wave 3.5's `variant_id` before it) is: additive only, and nothing the other application must
 * satisfy. A secondary index is exactly that — it constrains no write, rejects no row, and changes
 * no value. It is not a UNIQUE index, deliberately: a uniqueness rule added here would be a rule
 * the legacy writer has never had to obey.
 *
 * It also leaves the switch-night digest alone. `CoreChecksumCommand` hashes ROW DATA — `CHECKSUM
 * TABLE`, or a primary-key-ordered row hash for the tables where that is unstable — and reads
 * `information_schema.STATISTICS` only to find the primary key's columns. A secondary index appears
 * in neither. This was verified, not assumed: the 65-table digest was taken before and after.
 *
 * ── Guarded, because `orders` is never dropped ──────────────────────────────────────────────
 *
 * `orders` is a preserved table, so switch night's `migrate` runs against a database where it and
 * its indexes already exist (§3.4 step 3b drops only `CLEAN_TABLES`). An unguarded `$table->index()`
 * dies at "duplicate key name" and takes the migrate step with it — the shape `RebuildSurvivesTest`
 * exists to catch, and the reason wave 4C's closing rebuild died once already.
 */
return new class extends Migration
{
    private const INDEX = 'orders_created_at_index';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * Does the index already exist?
     *
     * Asked of the schema rather than guessed from the migration ledger: this migration has to be
     * safe to run against a database that was rebuilt around a preserved `orders`, where the ledger
     * is new and the table is not.
     */
    private function indexExists(): bool
    {
        return Schema::getConnection()
            ->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', 'orders')
            ->where('INDEX_NAME', self::INDEX)
            ->exists();
    }
};
