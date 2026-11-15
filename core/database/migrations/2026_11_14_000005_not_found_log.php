<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * THE 404 MONITOR (SEO Phase 1, 2026-11-14): every address a shop answered "page not found" for, once per path, with
 * how often people and search engines asked for it, when first and last, and the page that linked to it. Written by
 * the shops' servers (`POST /api/v2/{storefront}/not-found`, with the storefront server key), read by the dashboard
 * screen "Pages not found". A row the team marks fixed is kept, and opens again if the path is asked for again.
 *
 * Undo (core records migrations in core_migrations): DROP TABLE seo_not_found; DELETE FROM core_migrations WHERE
 * migration = '2026_11_14_000005_not_found_log';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_not_found', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('path', 512);
            $t->char('path_hash', 40);                       // sha1 of the lower-cased path (StorefrontRedirect::hashPath)
            $t->unsignedInteger('hits')->default(0);
            $t->unsignedInteger('bot_hits')->default(0);      // of which: search engines and other crawlers
            $t->string('last_referer', 512)->nullable();      // the page that linked here (no query string)
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->unique(['storefront_id', 'path_hash'], 'snf_path_unique');
            $t->index(['storefront_id', 'resolved_at', 'last_seen_at'], 'snf_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_not_found');
    }
};
