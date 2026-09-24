<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1l — promotions (wave 4D, study §3.16, approved 2026-09-13).
 *
 * ── Five new tables, all DASHBOARD-OWNED ─────────────────────────────────────────────────────
 *
 * An admin types every row here and no transform can regenerate one, so all five join
 * `CoreChecksumCommand::DASHBOARD_TABLES` and are never in the drop list (AGENTS §2.20,
 * resolution 🔴-3). Switch night's rebuild therefore re-runs this migration over tables that
 * still exist, which is why every `Schema::create` below is guarded with `Schema::hasTable()` —
 * wave 4C's closing rebuild died at exactly that point on `storefront_payment_providers`, and
 * `RebuildSurvivesTest` holds the rule for every preserved table.
 *
 * ── Plus two additive columns on the SHARED `order_items` ────────────────────────────────────
 *
 * `promotion_rule_id` and `is_reward`. Additive, nullable/defaulted and FK-LESS, exactly as M1f
 * added `variant_id` to the same table: the legacy application reads these rows through its own
 * Eloquent relation and must not be broken by a constraint it knows nothing about.
 *
 * D-24: because that relation selects `*`, the legacy app will serialise both new columns — so
 * `CompatAccount::orderItemRow()` adds them in `SHOW COLUMNS` order and the compat payload stays
 * byte-identical. A reward line is a REAL line in both applications and is never filtered.
 *
 * ── What is NOT here ────────────────────────────────────────────────────────────────────────
 *
 * No redemption table: per-customer limits and coupon codes are explicitly out of scope
 * (§3.16.7), and inventing the table now would invite both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('promotion_rules') or Schema::create('promotion_rules', function (Blueprint $t): void {
            $t->id();
            // Admin-facing, Arabic in practice. Not a slug and not a code: rules match carts, never
            // something a customer types (§3.16.7 item 3).
            $t->string('name', 120);
            // Higher wins. One rule per cart, ties broken by the LOWER id (§3.16.1).
            $t->integer('priority')->default(0);
            $t->boolean('is_active')->default(false);
            /*
             * `datetime`, not `timestamp`, and MariaDB forced the question: a SECOND
             * `timestamp NOT NULL` in one table gets an implicit `DEFAULT '0000-00-00 00:00:00'`,
             * which this `sql_mode` rejects outright — `1067 Invalid default value for 'ends_at'`.
             *
             * `datetime` is also the right type on the merits. A promotion window is absolute wall
             * clock — "ends on the 30th at midnight" — and `timestamp` converts through the
             * session time zone and stops in 2038. Both are NOT NULL because "no end date" is an
             * authoring refusal, and a column that enforces it cannot be gone around by a console
             * caller.
             */
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            // The candidate query: active, in-window, best priority first.
            $t->index(['is_active', 'starts_at', 'ends_at'], 'promo_active_window_idx');
            $t->index(['priority', 'id'], 'promo_priority_idx');
        });

        /*
         * Promotions follow the PRODUCT model (developer decision 2026-09-13): a rule is authored
         * once and the operator ticks which storefronts it applies to, exactly like placement. The
         * pivot is an INNER JOIN in the candidate query, so a rule not enabled for the cart's
         * storefront is never even a candidate — not filtered afterwards, which is the kind of
         * thing that gets forgotten in a second query.
         */
        Schema::hasTable('promotion_rule_storefront') or Schema::create('promotion_rule_storefront', function (Blueprint $t): void {
            $t->unsignedBigInteger('promotion_rule_id');
            $t->unsignedBigInteger('storefront_id');
            $t->primary(['promotion_rule_id', 'storefront_id'], 'promo_storefront_pk');
            $t->index('storefront_id', 'promo_storefront_sf_idx');
        });

        Schema::hasTable('promotion_rule_conditions') or Schema::create('promotion_rule_conditions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('promotion_rule_id');
            // One of PromotionRules::CONDITIONS. Validated in PHP, not by an enum: adding a
            // condition type must not need a migration on a shared-host database.
            $t->string('type', 32);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->unsignedBigInteger('storefront_category_id')->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->integer('quantity')->nullable();
            $t->decimal('amount', 12, 2)->nullable();
            // `payment_method_in` only: a short comma list (cash,paymob,whatsapp).
            $t->string('methods', 64)->nullable();
            $t->timestamps();
            $t->index('promotion_rule_id', 'promo_cond_rule_idx');
        });

        Schema::hasTable('promotion_rule_rewards') or Schema::create('promotion_rule_rewards', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('promotion_rule_id');
            /*
             * `free_product` | `free_variant` are BUILT. `free_shipping`, `percent_discount` and
             * `fixed_discount` are declared in `PromotionRules::REWARDS` and refused at authoring
             * until the storefront moves to v2 (§3.16.9 / wave 9): `addOrder()` compares the
             * client's total with the server's and 422s on any disagreement, and the running
             * frontend cannot compute a promotion-aware total. The column is a string so the
             * unlock is a code change, not a migration.
             */
            $t->string('type', 32);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('variant_id')->nullable();
            // Bounded, always: "an unbounded reward" is an authoring refusal.
            $t->integer('quantity')->default(1);
            $t->timestamps();
            $t->index('promotion_rule_id', 'promo_reward_rule_idx');
        });

        /*
         * The skip counter, keyed per (rule, storefront, reason) — my recommendation, approved.
         *
         * Per storefront because VISIBILITY is a per-storefront column, so a rule really can be
         * inert on Brand Fashion and live on Watchizer. Per reason because STOCK is shared (a
         * locked decision): an out-of-stock gift is out of stock everywhere, so the screen shows
         * that as ONE rule-level fact while still being able to answer "Brand Fashion missed forty
         * of the fifty", which a single aggregate column would throw away forever.
         *
         * Written ONLY on the checkout path. `PromotionEngine::evaluate()` writes nothing, and the
         * cart is read on every storefront page: incrementing there would turn the hottest GET in
         * the application into a write.
         */
        Schema::hasTable('promotion_rule_skips') or Schema::create('promotion_rule_skips', function (Blueprint $t): void {
            $t->unsignedBigInteger('promotion_rule_id');
            $t->unsignedBigInteger('storefront_id');
            // reward_out_of_stock | reward_not_visible
            $t->string('reason', 32);
            $t->unsignedInteger('skips')->default(0);
            $t->timestamp('last_at')->nullable();
            $t->primary(['promotion_rule_id', 'storefront_id', 'reason'], 'promo_skips_pk');
        });

        // ── the shared table, additively ─────────────────────────────────────────────────────
        if (! Schema::hasColumn('order_items', 'promotion_rule_id')) {
            Schema::table('order_items', function (Blueprint $t): void {
                // No FK, like M1f's `variant_id`: the legacy app writes this table too and must not
                // meet a constraint it does not know about. A deleted rule leaves the order line's
                // history intact, which is correct — the order records what happened.
                $t->unsignedBigInteger('promotion_rule_id')->nullable()->after('variant_id');
            });
        }

        if (! Schema::hasColumn('order_items', 'is_reward')) {
            Schema::table('order_items', function (Blueprint $t): void {
                // DEFAULT 0 so every row the legacy app has ever written, and every row it writes
                // tomorrow, reads as "not a reward" without the legacy app knowing the column exists.
                $t->boolean('is_reward')->default(false)->after('promotion_rule_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['promotion_rule_skips', 'promotion_rule_rewards', 'promotion_rule_conditions',
            'promotion_rule_storefront', 'promotion_rules'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['is_reward', 'promotion_rule_id'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
