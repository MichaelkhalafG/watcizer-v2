<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1j — the review's payment findings: real outcomes, a findings table, and two missing indexes
 * (adversarial review of wave 4C, decisions 2026-09-13).
 *
 * ── 1. `payment_statuses.outcome` (🟡-4) ─────────────────────────────────────────────────────
 *
 * `success` is an `enum('true','false')` on a SHARED legacy table, so a refund and a sale looked
 * identical: Paymob sends `success=true` with `is_refunded=true`, and the row said "true". The
 * settlement CSV then counted a refund as revenue — a reconciliation error found months later by
 * the finance side, which is the worst place to find one.
 *
 * `outcome` records what actually happened: `success`, `failed`, `refunded`, `voided`, `pending`.
 * NULLABLE on purpose — every row written before this migration genuinely does not know, and
 * back-filling a guess would be worse than an honest NULL. Readers fall back to `success` for
 * those (see `CallbackPolicy::outcomeOf()`).
 *
 * ── 2. `payment_reconciliation_findings` (🔴-1) ──────────────────────────────────────────────
 *
 * A callback that must NOT move the order — one arriving for a terminal order, a refund, a decline
 * after payment, an amount mismatch — records the money and raises a finding a human clears. A
 * derived view would have been cheaper, but a finding that must be CLEARED needs somewhere to put
 * the clearing, and "someone eyeballs the payments table monthly" is how a silent oversell survives
 * a season.
 *
 * `order_id` has an index and NO foreign key, for the same reason `orders.storefront_id` has none:
 * `orders` is shared with the legacy app, and a constraint added here is a constraint that app must
 * satisfy.
 *
 * Dashboard-authored, so it joins `CoreChecksumCommand::DASHBOARD_TABLES` — a rebuild must not
 * erase an unresolved money finding — and its `create` is GUARDED, because `core:drop-clean` leaves
 * those tables standing while clearing every `core_migrations` row (`RebuildSurvivesTest`).
 *
 * ── 3. Two single-column `created_at` indexes (🟡-6) ─────────────────────────────────────────
 *
 * `inventory_movements` grows forever and the ledger screen's default sort is `created_at DESC` —
 * the first page every operator loads. `payment_statuses.created_at` is what the settlement export
 * orders and filters by. Neither had an index that a plain date sort or range could use; the
 * composite indexes on those tables all lead with another column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. the real outcome ──────────────────────────────────────────────────────────────
        if (! Schema::hasColumn('payment_statuses', 'outcome')) {
            Schema::table('payment_statuses', function (Blueprint $table): void {
                $table->string('outcome', 16)->nullable()->after('success');
            });
        }

        // ── 2. the findings ──────────────────────────────────────────────────────────────────
        Schema::hasTable('payment_reconciliation_findings') or Schema::create('payment_reconciliation_findings', function (Blueprint $table): void {
            $table->id();
            // No FK: `orders` is shared (see the class docblock).
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_status_id')->nullable();
            $table->string('provider', 32);
            // An application constant, never an enum in the database (§2.1-6).
            $table->string('kind', 40);
            $table->string('outcome', 16)->nullable();
            $table->string('order_status', 16)->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            /** Everything a human needs to judge it, without going back to the provider's portal. */
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('note', 255)->nullable();

            // The query the command and the screen both run: unresolved, newest first.
            $table->index(['resolved_at', 'id'], 'prf_open_idx');
            $table->index('order_id', 'prf_order_idx');
        });

        // ── 3. the two date indexes ──────────────────────────────────────────────────────────
        if (! self::hasIndex('inventory_movements', 'im_created_idx')) {
            DB::statement('ALTER TABLE `inventory_movements` ADD INDEX `im_created_idx` (`created_at`)');
        }
        if (! self::hasIndex('payment_statuses', 'ps_created_idx')) {
            DB::statement('ALTER TABLE `payment_statuses` ADD INDEX `ps_created_idx` (`created_at`)');
        }
    }

    public function down(): void
    {
        // The additive column on the SHARED table stays, for the reason M1h gives: dropping a
        // column the legacy app may by then be reading is the bigger risk.
        Schema::dropIfExists('payment_reconciliation_findings');

        if (self::hasIndex('inventory_movements', 'im_created_idx')) {
            DB::statement('ALTER TABLE `inventory_movements` DROP INDEX `im_created_idx`');
        }
        if (self::hasIndex('payment_statuses', 'ps_created_idx')) {
            DB::statement('ALTER TABLE `payment_statuses` DROP INDEX `ps_created_idx`');
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
            $name = is_object($row) && property_exists($row, 'Key_name') ? $row->Key_name : null;
            if ($name === $index) {
                return true;
            }
        }

        return false;
    }
};
