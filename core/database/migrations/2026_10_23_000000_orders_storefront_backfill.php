<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `orders.storefront_id`: backfill the untagged rows, then make the column NOT NULL
 * (Addition A, 2026-10-23 — closes the backlog item of the same name).
 *
 * ── Why these rows are Watchizer's, and not a guess ──────────────────────────────────────────
 *
 * Measured on a copy of production: 9 orders carry no storefront, numbers 000001–000009, the last of them
 * 2026-09-01 16:41:36. BOTH storefront rows were created on 2026-09-12. So every untagged order predates the
 * existence of any storefront record at all, at a time when Watchizer was the only shop there was. The first
 * order that does carry a storefront is 2026-09-24, three weeks later. There is no window in which one of
 * these nine could have been Brand Fashion's, and Brand Fashion has never had an order.
 *
 * ── Why NOT NULL, and why it is safe ────────────────────────────────────────────────────────
 *
 * Every writer already sets it. The column being nullable means a future writer that forgets cannot be
 * caught, and a per-storefront read (slice 5's `me/orders`) silently drops the row instead of failing. The
 * constraint is what makes "every order belongs to a shop" true rather than merely usual.
 *
 * ── Reversibility ───────────────────────────────────────────────────────────────────────────
 *
 * `down()` drops the constraint and restores the nine rows to NULL **by their ids**, which are recorded here
 * at migrate time, so it cannot touch an order written afterwards. It does NOT null every storefront 1 order,
 * which would be a data loss dressed as a rollback.
 */
return new class extends Migration
{
    /*
     * MariaDB cannot roll back DDL, so this migration does not pretend to be atomic: it is written so that
     * each step is safe to repeat. The backfill is a no-op once nothing is null, and the MODIFY only runs
     * while the column is still nullable.
     */
    public $withinTransaction = false;

    /** The storefront every untagged order belongs to (see the note above). */
    private const STOREFRONT = 1;

    /** Where `down()` finds the ids it must restore. */
    private const RECORD = 'storage/app/orders_storefront_backfill.json';

    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'storefront_id')) {
            return;
        }

        /** @var list<int> $ids */
        $ids = [];
        foreach (DB::table('orders')->whereNull('storefront_id')->orderBy('id')->pluck('id') as $raw) {
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }

        if ($ids !== []) {
            // recorded BEFORE the write, so a rollback knows exactly which rows this migration changed
            $path = base_path(self::RECORD);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            // If the record cannot be written, STOP before changing anything: a backfill whose rollback has
            // no list of ids is not reversible, and discovering that afterwards is discovering it too late.
            if (file_put_contents($path, json_encode(['ids' => $ids, 'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('could not write '.$path.'; refusing to backfill without a rollback record');
            }
            DB::table('orders')->whereIn('id', $ids)->update(['storefront_id' => self::STOREFRONT]);
        }

        // Nothing may remain null, or the column change below would fail anyway — fail here instead, where the
        // message says what is wrong.
        $left = (int) DB::table('orders')->whereNull('storefront_id')->count();
        if ($left > 0) {
            throw new RuntimeException("orders.storefront_id is still null on {$left} row(s); backfill did not complete");
        }

        if ($this->isNullable()) {
            // a plain column change: the type is unchanged, only the constraint
            DB::statement('ALTER TABLE `orders` MODIFY `storefront_id` BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'storefront_id')) {
            return;
        }

        if (! $this->isNullable()) {
            DB::statement('ALTER TABLE `orders` MODIFY `storefront_id` BIGINT UNSIGNED NULL');
        }

        $path = base_path(self::RECORD);
        if (! is_file($path)) {
            return;   // nothing was backfilled, so there is nothing to restore
        }
        $raw = json_decode((string) file_get_contents($path), true);
        $ids = is_array($raw) && isset($raw['ids']) && is_array($raw['ids']) ? $raw['ids'] : [];
        $clean = [];
        foreach ($ids as $raw) {
            if (is_numeric($raw) && (int) $raw > 0) {
                $clean[] = (int) $raw;
            }
        }
        $ids = $clean;
        if ($ids !== []) {
            // BY ID: only the rows this migration touched, never every storefront 1 order
            DB::table('orders')->whereIn('id', $ids)->update(['storefront_id' => null]);
        }
        @unlink($path);
    }

    private function isNullable(): bool
    {
        // information_schema rather than Schema::getColumns(): the builder's rows are untyped here, and the
        // only thing this needs is one well-known string.
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['orders', 'storefront_id'],
        );

        if (! is_object($row) || ! property_exists($row, 'n')) {
            return true;
        }
        $n = $row->n;

        return ! is_string($n) || strtoupper($n) !== 'NO';
    }

};
