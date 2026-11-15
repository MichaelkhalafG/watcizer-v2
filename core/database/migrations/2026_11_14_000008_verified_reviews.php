<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * VERIFIED REVIEWS (SEO Phase 5 item 1, 2026-11-14).
 *   review_requests — one per delivered order asked for a review: the link's token (stored as its SHA-256), who it went
 *     to, when it was sent, until when it works.
 *   product_reviews — what the customer wrote, per product of that order: stars, words, photos, the name shown; status
 *     pending → approved | rejected (the dashboard's Reviews screen). Only approved ones are shown.
 * Two new tables; nothing existing changes. Undo: DROP TABLE product_reviews, review_requests; DELETE FROM
 * core_migrations WHERE migration = '2026_11_14_000008_verified_reviews'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one guard per table: both survive core:drop-clean (CoreChecksumCommand::DASHBOARD_TABLES), so a rebuild's
        // migrate meets them already there
        Schema::hasTable('review_requests') or Schema::create('review_requests', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('order_id')->unique();
            $t->char('token_hash', 64)->unique();
            $t->string('email', 191)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('locale', 2)->default('ar');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['storefront_id', 'created_at'], 'review_requests_sf_idx');
        });
        if (Schema::hasTable('product_reviews')) {
            return;
        }
        Schema::create('product_reviews', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('request_id');
            $t->string('author_name', 60);
            $t->unsignedTinyInteger('rating');
            $t->text('body');
            $t->text('photos')->nullable();
            $t->string('locale', 2)->default('ar');
            $t->string('status', 16)->default('pending');
            $t->unsignedBigInteger('moderated_by')->nullable();
            $t->timestamp('moderated_at')->nullable();
            $t->string('rejection_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'product_id'], 'product_reviews_order_product_unique');
            $t->index(['storefront_id', 'product_id', 'status'], 'product_reviews_shown_idx');
            $t->index(['storefront_id', 'status', 'created_at'], 'product_reviews_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('review_requests');
    }
};
