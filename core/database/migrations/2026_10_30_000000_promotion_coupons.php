<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1u — coupon codes, targeted discounts and usage limits on the EXISTING promotions engine (2026-10-30, Michael:
 * "the client's team sets up promotions themselves from the dashboard"; the Black Friday coupon is the first).
 *
 * ── Extends, never duplicates ────────────────────────────────────────────────────────────────
 *
 * Wave 4D's `promotion_rules` already carries the storefronts, the window, the conditions and the money rewards
 * (`percent_discount`, `fixed_discount`). What it deliberately left out (§3.16.7) is what this adds:
 *
 *  1. `promotion_rules` — the CUSTOMER-facing parts of a rule: an optional `code` (unique, matched without regard to
 *     case by the column's collation), the AR/EN `label` the cart shows, `apply_per` (`order` | `item`: one discount
 *     per order, or one per eligible unit — Black1000 is EGP 1,000 PER WATCH), a `max_discount` cap, a
 *     `min_order_amount` (the goods at the prices charged, the basis free shipping uses), the usage limits, whether
 *     items already on sale qualify, whether the rule is `stackable` with others (default: no — the best single
 *     discount wins), the `unit_floor` no discounted unit price may go below, and the "buy from X" quantity/amount.
 *     `starts_at`/`ends_at` become NULLABLE: a rule may be saved as an inactive DRAFT with no window (Black1000 is
 *     created that way); `PromotionWriter` still refuses to ACTIVATE a rule without both dates, and the engine's
 *     window query cannot match a NULL date.
 *  2. `promotion_rule_scopes` — WHICH items: `role` = `target` (the items the discount is taken off) or `qualify`
 *     (the items that must be in the cart, "buy from X"), by `dimension` = brand | category | sub_type | grade |
 *     family. AND across dimensions, OR within one; no target rows = every item.
 *  3. `promotion_redemptions` — one row per (rule, order): what usage limits count, per customer by `customer_key`.
 *  4. `promotion_order_discount_lines` — the discount PER ORDER LINE, so a return or a report can use it.
 *  5. `promotion_order_discounts` — gains `code`; its UNIQUE(order_id) becomes UNIQUE(order_id, promotion_rule_id),
 *     because a rule marked stackable may now share an order with another (still one row per rule per order).
 *
 * Every create is guarded with `hasTable`, every column with `hasColumn` (AGENTS §2.20: `core:drop-clean` preserves
 * these tables and clears the ledger, so `migrate` re-runs this file over tables that are already there). The three
 * new tables join `CoreChecksumCommand::DASHBOARD_TABLES`. No FK into `orders`/`order_items` (legacy-owned), as M1r.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_rules')) {
            $columns = [
                'code' => fn (Blueprint $t) => $t->string('code', 40)->nullable()->after('name'),
                'label_en' => fn (Blueprint $t) => $t->string('label_en', 160)->nullable()->after('code'),
                'label_ar' => fn (Blueprint $t) => $t->string('label_ar', 160)->nullable()->after('label_en'),
                'apply_per' => fn (Blueprint $t) => $t->string('apply_per', 8)->default('order')->after('label_ar'),
                'max_discount' => fn (Blueprint $t) => $t->decimal('max_discount', 12, 2)->nullable()->after('apply_per'),
                'min_order_amount' => fn (Blueprint $t) => $t->decimal('min_order_amount', 12, 2)->nullable()->after('max_discount'),
                'usage_limit' => fn (Blueprint $t) => $t->unsignedInteger('usage_limit')->nullable()->after('min_order_amount'),
                'usage_limit_per_customer' => fn (Blueprint $t) => $t->unsignedInteger('usage_limit_per_customer')->nullable()->after('usage_limit'),
                'include_sale_items' => fn (Blueprint $t) => $t->boolean('include_sale_items')->default(true)->after('usage_limit_per_customer'),
                'stackable' => fn (Blueprint $t) => $t->boolean('stackable')->default(false)->after('include_sale_items'),
                'unit_floor' => fn (Blueprint $t) => $t->decimal('unit_floor', 12, 2)->default(0)->after('stackable'),
                'qualify_min_quantity' => fn (Blueprint $t) => $t->unsignedInteger('qualify_min_quantity')->nullable()->after('unit_floor'),
                'qualify_min_amount' => fn (Blueprint $t) => $t->decimal('qualify_min_amount', 12, 2)->nullable()->after('qualify_min_quantity'),
            ];
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('promotion_rules', $name)) {
                    Schema::table('promotion_rules', function (Blueprint $t) use ($add): void {
                        $add($t);
                    });
                }
            }
            if (! self::hasIndex('promotion_rules', 'promo_code_unique')) {
                Schema::table('promotion_rules', function (Blueprint $t): void {
                    $t->unique('code', 'promo_code_unique');
                });
            }
            // a DRAFT may have no window yet (idempotent: MODIFY to the same definition is a no-op)
            DB::statement('ALTER TABLE promotion_rules MODIFY starts_at DATETIME NULL, MODIFY ends_at DATETIME NULL');
        }

        Schema::hasTable('promotion_rule_scopes') or Schema::create('promotion_rule_scopes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('promotion_rule_id');
            $t->string('role', 8);              // target | qualify
            $t->string('dimension', 16);        // brand | category | sub_type | grade | family
            $t->unsignedBigInteger('value_id')->nullable();   // brand / storefront_categories node / grade
            $t->string('value', 32)->nullable();              // family (watch, bag, ...)
            $t->timestamps();
            $t->index(['promotion_rule_id', 'role'], 'promo_scope_rule_idx');
        });

        Schema::hasTable('promotion_redemptions') or Schema::create('promotion_redemptions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('promotion_rule_id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('storefront_id');
            $t->string('code', 40)->nullable();
            // u:<user id> | e:<lower-case e-mail> | p:<phone digits> — the strongest the order carries
            $t->string('customer_key', 191)->nullable();
            $t->decimal('amount', 12, 2);
            $t->timestamp('created_at')->nullable();
            $t->unique(['promotion_rule_id', 'order_id'], 'promo_redemption_unique');
            $t->index(['promotion_rule_id', 'customer_key'], 'promo_redemption_customer_idx');
            $t->index('order_id', 'promo_redemption_order_idx');
        });

        Schema::hasTable('promotion_order_discount_lines') or Schema::create('promotion_order_discount_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('order_item_id')->nullable();
            $t->unsignedBigInteger('promotion_rule_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->unsignedInteger('quantity');
            $t->decimal('amount', 12, 2);       // what came off THIS line, always positive
            $t->timestamp('created_at')->nullable();
            $t->index('order_id', 'promo_line_order_idx');
            $t->index('promotion_rule_id', 'promo_line_rule_idx');
        });

        if (Schema::hasTable('promotion_order_discounts')) {
            if (! Schema::hasColumn('promotion_order_discounts', 'code')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->string('code', 40)->nullable()->after('promotion_rule_id');
                });
            }
            if (! self::hasIndex('promotion_order_discounts', 'promo_discount_order_rule_unique')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->unique(['order_id', 'promotion_rule_id'], 'promo_discount_order_rule_unique');
                });
            }
            if (self::hasIndex('promotion_order_discounts', 'promo_discount_order_unique')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->dropUnique('promo_discount_order_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_order_discount_lines');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_rule_scopes');

        if (Schema::hasTable('promotion_order_discounts')) {
            // one row per order again: only possible while no order holds two (a stacked order must be resolved first)
            if (! self::hasIndex('promotion_order_discounts', 'promo_discount_order_unique')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->unique('order_id', 'promo_discount_order_unique');
                });
            }
            if (self::hasIndex('promotion_order_discounts', 'promo_discount_order_rule_unique')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->dropUnique('promo_discount_order_rule_unique');
                });
            }
            if (Schema::hasColumn('promotion_order_discounts', 'code')) {
                Schema::table('promotion_order_discounts', function (Blueprint $t): void {
                    $t->dropColumn('code');
                });
            }
        }

        if (Schema::hasTable('promotion_rules')) {
            // a draft without a window cannot survive NOT NULL: it is dated in the past and left inactive
            DB::table('promotion_rules')->whereNull('starts_at')->update(['starts_at' => '2000-01-01 00:00:00', 'is_active' => 0]);
            DB::table('promotion_rules')->whereNull('ends_at')->update(['ends_at' => '2000-01-01 00:00:00', 'is_active' => 0]);
            DB::statement('ALTER TABLE promotion_rules MODIFY starts_at DATETIME NOT NULL, MODIFY ends_at DATETIME NOT NULL');
            if (self::hasIndex('promotion_rules', 'promo_code_unique')) {
                Schema::table('promotion_rules', function (Blueprint $t): void {
                    $t->dropUnique('promo_code_unique');
                });
            }
            foreach (['qualify_min_amount', 'qualify_min_quantity', 'unit_floor', 'stackable', 'include_sale_items',
                'usage_limit_per_customer', 'usage_limit', 'min_order_amount', 'max_discount', 'apply_per',
                'label_ar', 'label_en', 'code'] as $column) {
                if (Schema::hasColumn('promotion_rules', $column)) {
                    Schema::table('promotion_rules', function (Blueprint $t) use ($column): void {
                        $t->dropColumn($column);
                    });
                }
            }
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
