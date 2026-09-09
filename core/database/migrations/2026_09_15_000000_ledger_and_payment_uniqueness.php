<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1e — database-level uniqueness for the two things wave 3's adversarial review found could be
 * written twice: an order's stock movement, and a Paymob transaction.
 *
 * ── inventory_movements: exactly-once per ORDER LINE per reason ─────────────────────────────
 *
 * The review asked for `UNIQUE (reference_type, reference_id, reason)`. That exact triple cannot
 * be used, and why matters more than the index itself:
 *
 *   `commitOrder()` writes ONE movement per order LINE. A three-line order legitimately produces
 *   three rows carrying `('orders', 42, 'order')`, so the bare triple would reject the second and
 *   third line and break every multi-line order. Narrowing it to (…, product_id, bucket) does not
 *   help either: the legacy cart keys a line by (product, offer, band, dial, type_stock), so the
 *   SAME product in two band colours is two cart lines, two order lines and therefore two
 *   movements with the same product AND the same bucket. That is valid history.
 *
 * The only thing that is exactly one-per-movement is the `order_items` row, so this migration
 * adds `reference_line_id` and makes the unique key
 * **(reference_type, reference_id, reason, reference_line_id)**.
 *
 * **What happens to legitimate repeat reasons.** `reference_line_id` is NULL for every movement
 * that is not an order line — `adjustment`, `manual`, `restock`, `import`, `erp_sync` and the
 * `transform` baseline — and MariaDB does not treat two NULLs as equal in a unique index. Those
 * reasons stay completely unconstrained and may repeat as often as the business needs: a product
 * can be restocked weekly, `inventory:verify --fix` can re-base the same bucket more than once,
 * and the transform can append as many corrections as legacy data movement demands. The
 * constraint bites on exactly one thing: a second `order` / `order_cancel` / `payment_failed`
 * movement for a line that already has one.
 *
 * It is the SECOND of two layers. The first is the `SELECT … FOR UPDATE` on the order row that
 * `commitOrder()` and `releaseOrder()` now take, which serialises the check-then-act. The index
 * is what survives a future caller who forgets the lock, or two application servers racing.
 *
 * **No backfill.** Existing order movements keep `reference_line_id = NULL`, so a re-release of a
 * PRE-migration order is caught by the row lock rather than by the index. Deliberate: matching
 * historical movements back to order lines is guesswork exactly when an order has two lines for
 * the same product and bucket, and switch night starts from an empty ledger anyway (§3.4 step 3b
 * drops `inventory_movements` with the rest of the clean tables).
 *
 * ── payment_statuses: exactly-once per Paymob transaction ───────────────────────────────────
 *
 * `CallbackPayment` is idempotent by a SELECT on `pay_transaction_id` followed by an INSERT — the
 * same check-then-act, and Paymob really does retry. NULL stays allowed, because the legacy
 * column is nullable and a callback without an id is still worth recording.
 *
 * `payment_statuses` is a SHARED commerce table (study §2.6) that the legacy application also
 * writes, and unlike the clean tables it is NOT dropped by the rebuild recipe. Both halves of
 * this migration are therefore written to be safely re-runnable: each index is added only when it
 * is absent, so a rebuild that clears `core_migrations` and re-runs the set cannot fail on a
 * duplicate key name. The index only ever rejects an insert the legacy code already meant to
 * skip, so the legacy `CallbackPayment` keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_movements', 'reference_line_id')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->unsignedBigInteger('reference_line_id')->nullable()->after('reference_id');
            });
        }

        // Historical rows carry NULL, so no duplicate can exist on a database this codebase has
        // built. The check stays because a production run may meet a ledger it has not seen.
        $clash = DB::table('inventory_movements')
            ->selectRaw('reference_type, reference_id, reason, reference_line_id, COUNT(*) AS n')
            ->whereNotNull('reference_line_id')
            ->groupBy('reference_type', 'reference_id', 'reason', 'reference_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($clash !== null) {
            throw new RuntimeException(
                'inventory_movements already holds duplicate (reference_type, reference_id, reason, reference_line_id) rows. '.
                'Resolve them before adding the unique index: a duplicate here means stock was credited twice.'
            );
        }

        if (! self::hasIndex('inventory_movements', 'im_reference_once_unique')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->unique(['reference_type', 'reference_id', 'reason', 'reference_line_id'], 'im_reference_once_unique');
            });
        }

        // ── payment_statuses ──────────────────────────────────────────────────────────────
        $duplicateTxn = DB::table('payment_statuses')
            ->selectRaw('pay_transaction_id, COUNT(*) AS n')
            ->whereNotNull('pay_transaction_id')
            ->groupBy('pay_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateTxn !== null) {
            throw new RuntimeException(
                'payment_statuses already holds more than one row for the same pay_transaction_id. '.
                'A Paymob callback was processed twice; resolve those rows before adding the unique index.'
            );
        }

        if (! self::hasIndex('payment_statuses', 'ps_pay_transaction_unique')) {
            Schema::table('payment_statuses', function (Blueprint $table): void {
                $table->unique('pay_transaction_id', 'ps_pay_transaction_unique');
            });
        }
    }

    public function down(): void
    {
        if (self::hasIndex('payment_statuses', 'ps_pay_transaction_unique')) {
            Schema::table('payment_statuses', function (Blueprint $table): void {
                $table->dropUnique('ps_pay_transaction_unique');
            });
        }
        if (self::hasIndex('inventory_movements', 'im_reference_once_unique')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->dropUnique('im_reference_once_unique');
            });
        }
        if (Schema::hasColumn('inventory_movements', 'reference_line_id')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->dropColumn('reference_line_id');
            });
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
