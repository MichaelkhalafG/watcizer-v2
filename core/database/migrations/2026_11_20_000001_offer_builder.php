<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * THE OFFER BUILDER (Michael's approval of 2026-11-19; core/docs/promotions/OFFER_BUILDER_PLAN_20261119.md).
 *   promotion_rules.max_units — the most items one order gets the discount on (a fixed number), NULL = no such limit.
 *     "One per qualifying item" (a wallet per watch) is apply_per = 'paired', a value in the existing string column.
 *   storefront_wheel_prizes.offer_rule_id — a wheel segment that gives a builder offer (type 'offer'): the offer's
 *     template rule (issuer 'wheel_template'); NULL = the segment's own fields, exactly as before.
 *   storefront_wheel_spins.prize_offer_rule_id — a spin's snapshot of it.
 * Additive and nullable: every existing rule, prize and spin keeps NULL and behaves as before. Each table survives
 * core:drop-clean, so each change is guarded. Undo: down() drops the three columns (run the wheel data step's --undo
 * first, while a prize may still point at a template).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('promotion_rules', 'max_units')) {
            Schema::table('promotion_rules', function (Blueprint $t): void {
                $t->unsignedInteger('max_units')->nullable()->after('qualify_min_amount');
            });
        }
        if (! Schema::hasColumn('storefront_wheel_prizes', 'offer_rule_id')) {
            Schema::table('storefront_wheel_prizes', function (Blueprint $t): void {
                $t->unsignedBigInteger('offer_rule_id')->nullable()->after('gift_product_ids');
            });
        }
        if (! Schema::hasColumn('storefront_wheel_spins', 'prize_offer_rule_id')) {
            Schema::table('storefront_wheel_spins', function (Blueprint $t): void {
                $t->unsignedBigInteger('prize_offer_rule_id')->nullable()->after('prize_apply_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('storefront_wheel_spins', 'prize_offer_rule_id')) {
            Schema::table('storefront_wheel_spins', fn (Blueprint $t) => $t->dropColumn('prize_offer_rule_id'));
        }
        if (Schema::hasColumn('storefront_wheel_prizes', 'offer_rule_id')) {
            Schema::table('storefront_wheel_prizes', fn (Blueprint $t) => $t->dropColumn('offer_rule_id'));
        }
        if (Schema::hasColumn('promotion_rules', 'max_units')) {
            Schema::table('promotion_rules', fn (Blueprint $t) => $t->dropColumn('max_units'));
        }
    }
};
