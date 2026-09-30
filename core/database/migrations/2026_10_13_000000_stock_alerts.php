<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1z (2026-10-01): "e-mail me when it's back" and the daily mail count that paces bulk mail.
 *
 * `core_stock_alerts` — one row per (storefront, product, e-mail address). A shopper subscribes on
 * an out-of-stock product page (one tap when signed in). When units come back, up to 5 waiting rows
 * per unit restocked are marked `notified` and each gets one e-mail. `notified` rows are deleted 30
 * days after the e-mail, `waiting` rows 180 days after subscribing (`stock-alerts:prune`, daily).
 * `token` is the row's unsubscribe/cancel link. No foreign key to `catalog_products`: it is
 * transform output, and a key into it would stop `core:drop-clean`.
 *
 * `core_mail_daily` — how many messages went out today, split `transactional` / `bulk`, counted
 * from Laravel's MessageSent event (so every sender is counted, including the customer mails that
 * do not go through the outbox). Bulk mail (restock alerts, the re-engagement campaign) may only
 * use what is left of `MAIL_DAILY_CAP` after `MAIL_TRANSACTIONAL_RESERVE`; transactional mail never
 * looks at either, so an order confirmation is never held back by a batch.
 *
 * Both on the never-dropped list (`CoreChecksumCommand::DASHBOARD_TABLES`): shoppers' requests and
 * a send record, with no legacy source. `hasTable` guards for switch night's `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_stock_alerts') or Schema::create('core_stock_alerts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->string('email', 191);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->char('locale', 2)->default('ar');
            $t->char('token', 40)->unique();
            $t->string('status', 12)->default('waiting');     // waiting | notified | cancelled
            $t->timestamp('created_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->unique(['storefront_id', 'product_id', 'email']);
            $t->index(['product_id', 'status', 'created_at']);
        });

        Schema::hasTable('core_mail_daily') or Schema::create('core_mail_daily', function (Blueprint $t): void {
            $t->date('day');
            $t->string('bucket', 16);                          // transactional | bulk
            $t->unsignedInteger('sent')->default(0);
            $t->primary(['day', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_mail_daily');
        Schema::dropIfExists('core_stock_alerts');
    }
};
