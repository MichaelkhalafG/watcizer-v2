<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1m — articles (item 14, developer 2026-09-18).
 *
 * ── Why these are NEW tables and not the legacy `blogs` ──────────────────────────────────────
 *
 * The legacy schema has `blogs (id, image, created_at, updated_at)` and
 * `blog_translations (locale, blog_id, title, text)`, and that is the whole of it. No slug, no
 * published flag, no SEO fields — the three things the developer asked for by name. It is also
 * EMPTY on this dump: zero rows in all three legacy blog tables, so there is nothing to preserve
 * and nobody to break.
 *
 * Core may not write legacy tables regardless (AGENTS §3, and the `legacy` connection runs in
 * `tx_read_only = 1`, so the ALTER would be refused by the server rather than by a policy). Adding
 * columns to a table the legacy application reads with `select *` is exactly the kind of change
 * that breaks it silently.
 *
 * So articles become core-owned, like promotions and the payment contracts before them.
 *
 * ── DASHBOARD-OWNED, therefore never dropped ─────────────────────────────────────────────────
 *
 * An admin types every row and no transform can regenerate one, so both tables join
 * `CoreChecksumCommand::DASHBOARD_TABLES` and stay out of the drop list. Switch night's rebuild
 * re-runs this migration over tables that still exist, which is why both `Schema::create` calls
 * are guarded with `Schema::hasTable()` — wave 4C's closing rebuild died at exactly that point on
 * `storefront_payment_providers`, and `RebuildSurvivesTest` holds the rule for every preserved
 * table.
 *
 * ── What is deliberately NOT here ────────────────────────────────────────────────────────────
 *
 * **No gallery table.** The developer scoped it out in those words: *"NO image gallery for now — a
 * single cover image is enough."* One `cover_path` column, the same shape as a brand's logo.
 *
 * **No author.** `core_activity_log` already records who created and who last changed a row, which
 * is the audit question. A displayed by-line is a content decision nobody has made.
 *
 * **No categories or tags.** Eleven posts on the archived site, none of them categorised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_blogs') or Schema::create('core_blogs', function (Blueprint $t): void {
            $t->id();

            /*
             * The public URL. Unique because it IS the address, and `LegacySlug`-shaped like every
             * other slug in this application so the same rules and the same refusals apply.
             *
             * 191 rather than 255: this database is `utf8mb4` and a unique index over a longer
             * column exceeds MySQL's key length on the older row format. The same limit
             * `storefront_product.slug` carries.
             */
            $t->string('slug', 191)->unique();

            // A single cover, stored as a filename in the shared media tree — exactly as a brand's
            // logo and a product's image are (study §5.4: the database holds a filename only).
            $t->string('cover_path', 255)->nullable();

            /*
             * PUBLISHED is a timestamp, not a boolean.
             *
             * A boolean answers "is it live"; a timestamp answers that AND "since when", which is
             * the question somebody asks when an article appears in search results. It also makes
             * scheduling possible later without a migration, and NULL is unambiguously "never
             * published" rather than "published on a date nobody recorded".
             */
            $t->timestamp('published_at')->nullable();

            $t->timestamps();

            // The list's own ordering: newest first, unpublished drafts pulled to the top by the
            // screen. One index serves both.
            $t->index(['published_at', 'id']);
        });

        Schema::hasTable('core_blog_translations') or Schema::create('core_blog_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('blog_id')->constrained('core_blogs')->cascadeOnDelete();
            $t->string('locale', 5);

            $t->string('title', 255);
            $t->longText('body')->nullable();

            // The SEO fields the developer asked for, per locale like the title. Lengths match the
            // product form's: what a search engine actually shows.
            $t->string('meta_title', 255)->nullable();
            $t->string('meta_description', 500)->nullable();

            $t->timestamps();

            // One row per article per language, which is what makes an upsert safe.
            $t->unique(['blog_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_blog_translations');
        Schema::dropIfExists('core_blogs');
    }
};
