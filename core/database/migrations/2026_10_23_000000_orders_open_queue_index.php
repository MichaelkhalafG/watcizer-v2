<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders (status, storefront_id, created_at)` — the dashboard home's work queue (2026-10-21).
 *
 * ADDITIVE and REVERSIBLE: one index, no column added, dropped or retyped, no row read or written.
 * `down()` drops exactly this index and nothing else.
 *
 * ── Why it exists ───────────────────────────────────────────────────────────────────────────
 *
 * The rebuilt home page asks "which orders is somebody still working on, and how old is the oldest",
 * over ALL time rather than a date window — because a pending order from six weeks ago is exactly the
 * one worth seeing, and a 30-day cut-off would hide it. `orders` carried indexes on `created_at` and on
 * `storefront_id` separately but none on `status`, so that question was a full table scan.
 *
 * Measured on a scratch copy of the production-shaped catalogue carrying 250,000 synthetic orders:
 *
 *   without the index   EXPLAIN type=ALL, 247,960 rows examined, Using temporary; Using filesort   72 ms
 *   with it             EXPLAIN type=range, Using index (covering)                                  34 ms
 *
 * and per storefront it drops to 6 ms. The real gain is larger than those numbers suggest: the synthetic
 * data has half of all orders in an open state, while a real shop's open orders are a small fraction of
 * its history, and the index ranges over exactly that fraction.
 *
 * ── Why the columns are in this order ───────────────────────────────────────────────────────
 *
 * `status` first so that the "all shops" question — the page's default — is a range over three status
 * values rather than a scan of the whole index; `storefront_id` second so a scoped operator's question
 * narrows inside that range; `created_at` last so `MIN(created_at)` per status comes out of the index
 * without touching a row. One index serves both the combined and the per-shop view, which is why there
 * is one and not two: every index is paid for on every order INSERT, and the checkout is the one write
 * path on this table that a shopper waits for.
 */
return new class extends Migration
{
    private const NAME = 'orders_status_sf_created_idx';

    public function up(): void
    {
        if (! Schema::hasTable('orders') || in_array(self::NAME, self::names(), true)) {
            return;
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'storefront_id', 'created_at'], self::NAME);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! in_array(self::NAME, self::names(), true)) {
            return;
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(self::NAME);
        });
    }

    /** @return list<string> the index names the table carries now */
    private static function names(): array
    {
        $out = [];
        foreach (Schema::getIndexes('orders') as $index) {
            $name = is_array($index) ? ($index['name'] ?? null) : null;
            if (is_string($name)) {
                $out[] = $name;
            }
        }

        return $out;
    }
};
