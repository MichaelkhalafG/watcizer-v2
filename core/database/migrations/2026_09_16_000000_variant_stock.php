<?php

use App\Domain\Inventory\InventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1f — wave 3.5: a cart line, an order line and a stock movement may name a VARIANT.
 *
 * `catalog_product_variants` already exists (M1) with its own `stock_express` / `stock_market`,
 * and `inventory_movements.variant_id` already exists. What was missing is the path from a sale to
 * that column: the commerce tables had no way to say WHICH variant a line was for.
 *
 * ── Additive, and deliberately without foreign keys ──────────────────────────────────────────
 *
 * `cart_items` and `order_items` are SHARED commerce tables (study §2.6) that survive switch
 * night, while `catalog_product_variants` is a CLEAN table that is DROPPED and rebuilt by the
 * §3.4 step 3b procedure. A foreign key from the survivor to the rebuilt table would either block
 * the drop or be left dangling by it, so `variant_id` is a plain indexed column here and the
 * constraint is added by **M2**, in the switch window, alongside the five product-id repoints it
 * already performs — exactly the reasoning that leaves `order_items.product_id` pointing at legacy
 * `products` until that same moment.
 *
 * Until M2 runs, referential integrity for `variant_id` is held by
 * {@see InventoryService}, which refuses a movement whose variant does not
 * exist or does not belong to the named product, and by `inventory:verify`, which reports an
 * orphan.
 *
 * ── The cart line key: deliberately NOT widened ─────────────────────────────────────────────
 *
 * The obvious move would be to add `variant_id` to `uniq_cart_line`, so the same shirt in two
 * sizes is two lines. It is not done, for two reasons found while attempting it:
 *
 *  1. **The index already enforces nothing for an ordinary line.** It covers
 *     (cart, product, offer, band, dial, type_stock), four of which are nullable, and MariaDB
 *     treats NULLs as distinct in a unique index. A normal Watchizer line — product set, offer and
 *     both colours NULL — has therefore never been deduplicated by the database at all. Widening
 *     it with another nullable column would change nothing about that.
 *  2. **Dropping it is not free on a shared table.** `cart_items` has no standalone index on
 *     `cart_id`, so `uniq_cart_line` is what its foreign key leans on; MariaDB refuses the drop
 *     with error 1553. Replacing it would mean adding a helper index and rebuilding a constraint
 *     on a table the legacy application still writes — real risk, for an index that enforces
 *     nothing.
 *
 * So the cart line key is enforced where it has always actually been enforced: in the
 * application, by `CompatCart::upsertItem()`'s select-then-insert, which wave 3.5 extends to
 * include the variant. That is honest rather than ideal, and it is on the wave-3.5 flag list
 * together with the concurrency gap it implies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cart_items', 'variant_id')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
                $table->index('variant_id', 'cart_items_variant_idx');
            });
        }

        if (! Schema::hasColumn('order_items', 'variant_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
                $table->index('variant_id', 'order_items_variant_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['cart_items' => 'cart_items_variant_idx', 'order_items' => 'order_items_variant_idx'] as $table => $index) {
            if (self::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
            if (Schema::hasColumn($table, 'variant_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('variant_id'));
            }
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->where('INDEX_NAME', $index)->exists();
    }
};
