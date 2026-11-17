<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * REVIEWS FROM EVERYONE (Michael's decisions, 2026-11-17).
 *   product_reviews — a review may now come from a product page without logging in (source = guest): no order, no
 *     request (order_id, request_id nullable); its e-mail is kept only as its SHA-256 (email_hash: one review per
 *     e-mail per product, the per-e-mail limit, and the Verified buyer check), and when that e-mail is a customer's
 *     account the review is attached to it silently (user_id). verified = the e-mail (or the request's order) has a
 *     DELIVERED order containing the product. Every review is LIVE at once (status approved) unless a spam signal
 *     holds it (status pending, held_reason: link | phone) — never by its stars. The team hides any review (rejected).
 *   storefront_pickup_stores — per branch, for the in-store customers: google_review_url (Google's write-a-review
 *     page), review_key (the branch's address on our own domain, /review/<key>, which counts its scans and sends on
 *     to Google) and whatsapp_review_template (the message the staff copy from the dashboard).
 *   storefront_review_scans — the scans of each branch's /review/<key>, one row per branch per day.
 * Additive and nullable; every table survives core:drop-clean, so each change is guarded. Undo: drop the three
 * storefront_pickup_stores columns, the five product_reviews columns and storefront_review_scans; product_reviews
 * order_id/request_id back to NOT NULL only after deleting the guest rows (source = 'guest'); DELETE FROM
 * core_migrations WHERE migration = '2026_11_18_000002_guest_reviews'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_reviews', 'source')) {
            Schema::table('product_reviews', function (Blueprint $t): void {
                $t->unsignedBigInteger('order_id')->nullable()->change();
                $t->unsignedBigInteger('request_id')->nullable()->change();
                $t->string('source', 16)->default('request')->after('request_id');
                $t->unsignedBigInteger('user_id')->nullable()->after('source');
                $t->char('email_hash', 64)->nullable()->after('user_id');
                $t->boolean('verified')->default(false)->after('email_hash');
                $t->string('held_reason', 32)->nullable()->after('status');
                $t->unique(['storefront_id', 'product_id', 'email_hash'], 'product_reviews_email_product_unique');
                $t->index(['storefront_id', 'email_hash'], 'product_reviews_email_idx');
            });
        }
        if (! Schema::hasColumn('storefront_pickup_stores', 'review_key')) {
            Schema::table('storefront_pickup_stores', function (Blueprint $t): void {
                $t->string('google_review_url', 500)->nullable()->after('whatsapp');
                $t->string('review_key', 32)->nullable()->after('google_review_url');
                $t->text('whatsapp_review_template')->nullable()->after('review_key');
                $t->unique(['storefront_id', 'review_key'], 'pickup_stores_review_key_unique');
            });
        }
        // a dashboard table survives the clean-side drop, so its creation must survive the migrate (RebuildSurvivesTest)
        Schema::hasTable('storefront_review_scans') or Schema::create('storefront_review_scans', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->date('day');
            $t->unsignedInteger('scans')->default(0);
            $t->timestamps();
            $t->unique(['store_id', 'day'], 'review_scans_store_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_review_scans');
        if (Schema::hasColumn('storefront_pickup_stores', 'review_key')) {
            Schema::table('storefront_pickup_stores', function (Blueprint $t): void {
                $t->dropUnique('pickup_stores_review_key_unique');
                $t->dropColumn(['google_review_url', 'review_key', 'whatsapp_review_template']);
            });
        }
        if (Schema::hasColumn('product_reviews', 'source')) {
            Schema::table('product_reviews', function (Blueprint $t): void {
                $t->dropUnique('product_reviews_email_product_unique');
                $t->dropIndex('product_reviews_email_idx');
                $t->dropColumn(['source', 'user_id', 'email_hash', 'verified', 'held_reason']);
            });
        }
    }
};
