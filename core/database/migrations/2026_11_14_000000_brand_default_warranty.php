<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A brand's DEFAULT WATCH WARRANTY, in years (SEO Phase 1, Michael 2026-11-14). A watch whose own `warranty_years` is
 * empty carries its brand's default; a brand with none carries the shop's 1 year (config catalog.warranty). Watches only:
 * a bag, a perfume or a wallet states a warranty only when the product records its own. The rule is App\Storefront\Warranty.
 *
 * NULL = "the shop's default". Additive and nullable, so the code before this change runs unchanged beside it — which is
 * why the deploy runs this migration BEFORE the new code goes live (the new code reads the column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_brands', function (Blueprint $t): void {
            $t->unsignedTinyInteger('default_warranty_years')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_brands', function (Blueprint $t): void {
            $t->dropColumn('default_warranty_years');
        });
    }
};
