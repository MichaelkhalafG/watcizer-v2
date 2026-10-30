<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1y — the TEXT of a brand page and a category page, per storefront and per language (2026-10-30, SEO item 5): an
 * introduction shown on the page under the products and the page's meta description.
 *
 * A table of its own, keyed by the brand's SLUG or the category's PATH — never by the rows of `catalog_brand_*` or
 * `storefront_category_*`: those are CLEAN tables that switch night rebuilds from the legacy database
 * (`CoreChecksumCommand::CLEAN_TABLES`), and a text written into them would be wiped. Slugs and paths are what the
 * transform rebuilds deterministically, so a text keyed by them survives the rebuild. Dashboard-authored, so it joins
 * `DASHBOARD_TABLES`. Guarded (AGENTS §2.20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_seo_texts') or Schema::create('core_seo_texts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('subject_type', 16);         // brand | category
            $t->string('subject_key', 191);         // the brand's slug, or the category's path (watches/gmt)
            $t->char('locale', 2);
            $t->text('description')->nullable();    // the introduction, under the products (plain paragraphs)
            $t->string('meta_description', 320)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['storefront_id', 'subject_type', 'subject_key', 'locale'], 'seo_text_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_seo_texts');
    }
};
