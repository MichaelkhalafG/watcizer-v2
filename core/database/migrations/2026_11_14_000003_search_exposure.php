<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHAT SEARCH ENGINES AND AI ARE SHOWN OF A PRODUCT (Michael, 2026-11-14). Products the shop sells as "Copy",
 * "Style" or "Inspired by …" — and luxury-maison watches at prices no genuine one has — stay on sale exactly as they
 * are, but are kept out of what search engines and AI read.
 *
 *  - `search_class` — what the RULE says (App\Domain\Catalog\SearchExposure), kept up to date by every product save
 *    and by `catalog:warm`: NULL (nothing to hide), `hidden` (noindex, out of sitemaps, llms.txt, feeds, ItemLists;
 *    no Product structured data) or `restricted` (Rolex: indexable and in the sitemap, but no brand and no
 *    authenticity claim in its structured data, and out of llms.txt, the feeds and every "best/original" list).
 *  - `search_override` — the dashboard's manual switch: NULL = follow the rule, 0 = hide from search, 1 = show
 *    normally. The switch always wins, both ways.
 *
 * Additive and nullable, so the code before this change runs unchanged beside it. Undo (core records migrations in
 * core_migrations): ALTER TABLE catalog_products DROP COLUMN search_class, DROP COLUMN search_override;
 * DELETE FROM core_migrations WHERE migration = '2026_11_14_000003_search_exposure';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $t): void {
            $t->string('search_class', 16)->nullable()->after('is_active');
            $t->boolean('search_override')->nullable()->after('search_class');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $t): void {
            $t->dropColumn(['search_class', 'search_override']);
        });
    }
};
