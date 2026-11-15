<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHO GIVES THE WARRANTY (Michael, 2026-11-14, the client's answer): most watches carry the brand AGENT's warranty, a
 * few the STORE's. Data, never a blanket claim (App\Storefront\Warranty::provider):
 *   catalog_brands.warranty_by_store   — 1 = this brand's watches carry the store's warranty; 0 (default) = the agent's
 *   catalog_products.warranty_provider — the product's own answer, 'agent' | 'store', NULL = its brand's; it wins
 * A product the hide-from-search rule matches (hidden or restricted) is ALWAYS 'store', whatever is stored.
 * Guarded (both tables survive core:drop-clean). Undo: ALTER TABLE catalog_products DROP COLUMN warranty_provider;
 * ALTER TABLE catalog_brands DROP COLUMN warranty_by_store; DELETE FROM core_migrations WHERE migration =
 * '2026_11_14_000009_warranty_provider'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_brands', 'warranty_by_store')) {
            Schema::table('catalog_brands', function (Blueprint $t): void {
                $t->boolean('warranty_by_store')->default(false)->after('default_warranty_years');
            });
        }
        if (! Schema::hasColumn('catalog_products', 'warranty_provider')) {
            Schema::table('catalog_products', function (Blueprint $t): void {
                $t->string('warranty_provider', 8)->nullable()->after('warranty_years');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_products', 'warranty_provider')) {
            Schema::table('catalog_products', fn (Blueprint $t) => $t->dropColumn('warranty_provider'));
        }
        if (Schema::hasColumn('catalog_brands', 'warranty_by_store')) {
            Schema::table('catalog_brands', fn (Blueprint $t) => $t->dropColumn('warranty_by_store'));
        }
    }
};
