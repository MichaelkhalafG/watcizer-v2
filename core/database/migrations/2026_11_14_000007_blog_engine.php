<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE BLOG ENGINE (SEO Phase 3, 2026-11-14): an article's life on core_blogs.
 *   - status: draft → checked (passed the quality gate) → scheduled (has a publish_at) → published; held (failed the
 *     gate, or failed to publish five times — the reasons in gate_report / publish_error); needs-update is a FLAG on a
 *     published article (needs_update_reasons), not a status: the article stays online while the team refreshes it.
 *   - publish_at: when it goes live, STORED IN UTC (shown in Cairo time); blog:publish-due publishes what is due.
 *   - modified_at: the last REAL change of the article's content (its text, or its live data blocks moving past the
 *     brief's thresholds) — the Article's dateModified and the sitemap's lastmod; stock flips never touch it.
 *   - slug_ar: the Arabic address (/ar/blog/<arabic>); the English slug stays `slug`.
 *   - topic, brand_id, family, tags: what the article is about (archives, related articles, internal links).
 *   - data_snapshot: the article's data blocks as last announced — per block its LIVE ids, LIVE count and price range
 *     (JSON; the nightly job compares against it with the brief's thresholds).
 *   - publish_attempts, publish_error: a failed publication is retried by the next run, and held after five.
 * Existing rows: published (published_at set) or draft. Undo: see the down() below, and DELETE FROM core_migrations
 * WHERE migration = '2026_11_14_000007_blog_engine'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // guarded: core_blogs survives core:drop-clean while the migration ledger does not (RebuildSurvivesTest)
        if (Schema::hasColumn('core_blogs', 'status')) {
            return;
        }
        Schema::table('core_blogs', function (Blueprint $t): void {
            $t->string('status', 16)->default('draft')->after('body_format');
            $t->timestamp('publish_at')->nullable()->after('published_at');
            $t->timestamp('modified_at')->nullable()->after('publish_at');
            $t->string('slug_ar', 191)->nullable()->after('slug');
            $t->string('topic', 64)->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->string('family', 16)->nullable();
            $t->text('tags')->nullable();
            $t->text('gate_report')->nullable();
            $t->timestamp('gate_checked_at')->nullable();
            $t->text('needs_update_reasons')->nullable();
            $t->text('data_snapshot')->nullable();
            $t->unsignedTinyInteger('publish_attempts')->default(0);
            $t->string('publish_error', 500)->nullable();
            $t->index(['storefront_id', 'status', 'publish_at'], 'blogs_queue_idx');
            $t->unique(['storefront_id', 'slug_ar'], 'blogs_slug_ar_unique');
        });
        DB::table('core_blogs')->whereNotNull('published_at')->update(['status' => 'published']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('core_blogs', 'status')) {
            return;
        }
        Schema::table('core_blogs', function (Blueprint $t): void {
            $t->dropUnique('blogs_slug_ar_unique');
            $t->dropIndex('blogs_queue_idx');
            $t->dropColumn(['status', 'publish_at', 'modified_at', 'slug_ar', 'topic', 'brand_id', 'family', 'tags', 'gate_report',
                'gate_checked_at', 'needs_update_reasons', 'data_snapshot', 'publish_attempts', 'publish_error']);
        });
    }
};
