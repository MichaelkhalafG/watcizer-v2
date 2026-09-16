<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1q — the money a reward is worth (wave 4D, study §3.16.3).
 *
 * ── Why the rewards table had no amount ──────────────────────────────────────────────────────
 *
 * The three reward types that REDUCE money — `free_shipping`, `percent_discount`, `fixed_discount`
 * — were declared in `PromotionRules::REWARDS` from the start and refused everywhere else, so
 * nothing ever needed a number to go with them. `free_product` and `free_variant` carry a
 * `quantity` and are priced at zero; there was nothing else to store.
 *
 * Opening the money family needs exactly one new column: the RATE for a percentage, or the AMOUNT
 * for a fixed discount. `free_shipping` needs neither — the cart already carries the shipping cost
 * it waives — so the column is nullable and its meaning is decided by the row's `type`.
 *
 * ── decimal(12,2), the same as every other money column here ─────────────────────────────────
 *
 * `promotion_rule_conditions.amount`, `orders.total_price`, `catalog_products.selling_price` are
 * all decimal(12,2). A percentage stored in the same column costs one wasted decimal place and buys
 * one fewer column, one fewer validation path and one fewer thing to keep in step. 12.50 means
 * 12.5% on a `percent_discount` row and EGP 12.50 on a `fixed_discount` one, and `PromotionRules`
 * is the single place that knows which.
 *
 * Guarded with `hasColumn` like every migration in this wave: M1 runs against a database that may
 * already carry it, and a re-run after a rebuild must be a no-op rather than an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotion_rule_rewards')) {
            return;
        }

        if (! Schema::hasColumn('promotion_rule_rewards', 'amount')) {
            Schema::table('promotion_rule_rewards', function (Blueprint $t): void {
                $t->decimal('amount', 12, 2)->nullable()->after('variant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('promotion_rule_rewards', 'amount')) {
            Schema::table('promotion_rule_rewards', function (Blueprint $t): void {
                $t->dropColumn('amount');
            });
        }
    }
};
