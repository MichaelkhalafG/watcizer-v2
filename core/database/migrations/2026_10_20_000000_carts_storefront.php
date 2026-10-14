<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M2g — C10: a cart per storefront (developer: option (c), 2026-10-12; built 2026-10-13).
 *
 * `carts.storefront_id` — `NOT NULL DEFAULT 1`, Watchizer. Before this a signed-in customer of
 * both shops shared ONE cart (`carts`: user_id, guest_token — no storefront), so a line added on
 * Brand Fashion appeared in the Watchizer cart. Now `CompatCart` reads and writes the row for ITS
 * storefront, and the only production inserter sets the column explicitly (`CartStorefrontTest`
 * pins that in the source).
 *
 * Why a DEFAULT and not plain NOT NULL (backlog C10-SHAPE): the local compat-diff harness boots the
 * legacy app, which inserts `carts` rows without the column; a default lets that insert succeed
 * and take Watchizer, with no NULL ever existing and no "NULL means Watchizer" rule in production
 * code. Its cost — a future insert that forgets the storefront silently becomes Watchizer — is
 * closed by the test, not accepted.
 *
 * Existing rows are backfilled to Watchizer by the default as the column is added (every cart that
 * exists today is a Watchizer cart: Brand Fashion has not opened). The explicit UPDATE below is the
 * record of that intent and a no-op on MariaDB. Index `(user_id, storefront_id)` — the signed-in
 * lookup. `cart_items` untouched: a line belongs to its cart, and the cart now knows its shop.
 * Guarded, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('carts', 'storefront_id')) {
            Schema::table('carts', function (Blueprint $t): void {
                $t->unsignedBigInteger('storefront_id')->default(1)->after('guest_token');
            });
            DB::table('carts')->whereNull('storefront_id')->orWhere('storefront_id', 0)->update(['storefront_id' => 1]);
            Schema::table('carts', function (Blueprint $t): void {
                $t->index(['user_id', 'storefront_id'], 'carts_user_storefront_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('carts', 'storefront_id')) {
            Schema::table('carts', function (Blueprint $t): void {
                $t->dropIndex('carts_user_storefront_index');
                $t->dropColumn('storefront_id');
            });
        }
    }
};
