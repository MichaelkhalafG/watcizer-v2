<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M2f — the mailbox split (W3/W4, developer: option A on 2026-10-12, built 2026-10-13).
 *
 *  - `storefront_mailboxes` — ONE row per storefront: the SMTP mailbox its e-mail leaves from
 *    (host, port, encryption, username, the password ENCRYPTED under APP_KEY like a payment
 *    contract's credentials), the from address and name, the per-mailbox daily cap and
 *    transactional reserve, and the TEAM addresses that receive the admin copy of an order
 *    (C12: per storefront, several, never the other shop's). Dashboard-authored, so it joins
 *    `CoreChecksumCommand::DASHBOARD_TABLES` and survives the switch-night drop.
 *  - `core_mail_daily.storefront_id` — the counter is PER MAILBOX: `day, bucket, storefront_id`.
 *    Existing rows keep 0 ("unattributed", the days before the split); they fade with the 30-day
 *    window. Hostinger's limit is 1,000 per ROLLING 24 hours per mailbox; this counter is keyed by
 *    CALENDAR day and resets at midnight — the two can disagree, and that is known and recorded,
 *    not fixed (backlog MAIL-PLAN).
 *
 * Both guarded, additive. `cart_items`, `orders` untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_mailboxes') or Schema::create('storefront_mailboxes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id')->unique();
            $t->foreign('storefront_id')->references('id')->on('storefronts')->cascadeOnDelete();
            $t->boolean('is_enabled')->default(true);
            $t->string('host', 255)->nullable();
            $t->unsignedSmallInteger('port')->default(587);
            $t->string('encryption', 8)->default('tls');             // tls | ssl | none
            $t->string('username', 255)->nullable();
            $t->text('credentials')->nullable();                      // encrypted array: password
            $t->string('from_address', 255)->nullable();
            $t->string('from_name', 100)->nullable();
            $t->unsignedInteger('daily_cap')->default(800);
            $t->unsignedInteger('transactional_reserve')->default(200);
            $t->text('order_recipients')->nullable();                 // JSON list of addresses
            $t->timestamps();
        });

        if (Schema::hasTable('core_mail_daily') && ! Schema::hasColumn('core_mail_daily', 'storefront_id')) {
            Schema::table('core_mail_daily', function (Blueprint $t): void {
                $t->unsignedBigInteger('storefront_id')->default(0)->after('bucket');
            });
            Schema::table('core_mail_daily', function (Blueprint $t): void {
                $t->dropPrimary(['day', 'bucket']);
                $t->primary(['day', 'bucket', 'storefront_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('core_mail_daily', 'storefront_id')) {
            Schema::table('core_mail_daily', function (Blueprint $t): void {
                $t->dropPrimary(['day', 'bucket', 'storefront_id']);
                $t->dropColumn('storefront_id');
                $t->primary(['day', 'bucket']);
            });
        }
        Schema::dropIfExists('storefront_mailboxes');
    }
};
