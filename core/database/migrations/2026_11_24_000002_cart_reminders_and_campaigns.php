<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * THE ABANDONED-CART E-MAIL AND THE E-MAIL CAMPAIGNS (Michael's approval of 2026-11-24; core/docs/plans/
 * EIGHT_CHANGES_PLAN_20261124.md items 1 and 5). New tables only - nothing existing changes:
 *   core_personal_codes            one single-use code per e-mail, bound to that address (PersonalCodes)
 *   core_unsubscribe_links         the number in an abandoned-cart or campaign e-mail's unsubscribe link (the same path
 *                                  shape as the weekly e-mail's, so the API host's allow-list is unchanged)
 *   core_cart_reminder_settings    per shop: on/off (off until the team switches it on), the delay, the code's terms
 *   core_checkout_contacts         a guest's e-mail typed at the checkout, on its cart (the abandoned-cart e-mail's address)
 *   core_cart_reminders            one row per cart that was e-mailed (or skipped, with the reason): never two per cart
 *   core_email_campaigns           a campaign: audience, message, optional code, schedule, state, counts
 *   core_email_campaign_recipients its recipients, fixed when it starts sending; one row per address
 *   core_email_lists / _rows       an uploaded list (e-mail, name, optional phone), with who confirmed the consent tick
 * Every table is authored by the dashboard or the shops, so none is in the drop list (AGENTS §2.20).
 * Undo: down() drops them (the deploy's undo runs it as SQL: migrate:rollback is refused in every environment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_personal_codes') or Schema::create('core_personal_codes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('code', 24);
            $t->unsignedBigInteger('promotion_rule_id');
            $t->string('email', 191);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('purpose', 20);
            $t->unsignedBigInteger('source_id')->nullable();
            $t->timestamp('expires_at');
            $t->unsignedBigInteger('used_order_id')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['storefront_id', 'code'], 'cpc_storefront_code_unique');
            $t->index(['storefront_id', 'email'], 'cpc_storefront_email_idx');
        });

        Schema::hasTable('core_unsubscribe_links') or Schema::create('core_unsubscribe_links', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('email', 191);
            $t->string('kind', 20);
            $t->timestamp('created_at')->nullable();
            $t->unique(['storefront_id', 'email', 'kind'], 'cul_storefront_email_kind_unique');
        });

        Schema::hasTable('core_cart_reminder_settings') or Schema::create('core_cart_reminder_settings', function (Blueprint $t): void {
            $t->unsignedBigInteger('storefront_id')->primary();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('delay_hours')->default(48);
            $t->boolean('with_code')->default(true);
            $t->decimal('percent', 5, 2)->default(10);
            $t->decimal('cap', 12, 2)->nullable()->default(500);
            $t->unsignedInteger('valid_days')->default(7);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::hasTable('core_checkout_contacts') or Schema::create('core_checkout_contacts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('cart_id');
            $t->string('email', 191);
            $t->timestamps();
            $t->unique(['cart_id'], 'ccc_cart_unique');
            $t->index(['storefront_id', 'email'], 'ccc_storefront_email_idx');
        });

        Schema::hasTable('core_cart_reminders') or Schema::create('core_cart_reminders', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('cart_id');
            $t->string('email', 191);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status', 12);                   // queued | sent | skipped
            $t->string('skip_reason', 40)->nullable();
            $t->unsignedBigInteger('code_id')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['cart_id'], 'ccr_cart_unique');
            $t->index(['storefront_id', 'email', 'created_at'], 'ccr_storefront_email_idx');
        });

        Schema::hasTable('core_email_campaigns') or Schema::create('core_email_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('name', 120);
            $t->string('subject_en', 150);
            $t->string('subject_ar', 150);
            $t->text('body_en');
            $t->text('body_ar');
            $t->json('product_ids')->nullable();
            $t->json('audience');
            $t->unsignedBigInteger('list_id')->nullable();
            $t->decimal('code_percent', 5, 2)->nullable();
            $t->decimal('code_cap', 12, 2)->nullable();
            $t->unsignedInteger('code_days')->nullable();
            $t->string('status', 12)->default('draft');  // draft | scheduled | sending | sent | cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('recipients')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['storefront_id', 'status'], 'cec_storefront_status_idx');
        });

        Schema::hasTable('core_email_campaign_recipients') or Schema::create('core_email_campaign_recipients', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->string('email', 191);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name', 120)->nullable();
            $t->unsignedBigInteger('code_id')->nullable();
            $t->string('status', 12)->default('pending');  // pending | queued | sent | skipped | failed
            $t->string('skip_reason', 40)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['campaign_id', 'email'], 'cecr_campaign_email_unique');
            $t->index(['campaign_id', 'status'], 'cecr_campaign_status_idx');
        });

        Schema::hasTable('core_email_lists') or Schema::create('core_email_lists', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('name', 120);
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedBigInteger('consent_by');
            $t->timestamp('consent_at');
            $t->timestamps();
        });

        Schema::hasTable('core_email_list_rows') or Schema::create('core_email_list_rows', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('list_id');
            $t->string('email', 191);
            $t->string('name', 120)->nullable();
            $t->string('phone', 20)->nullable();
            $t->unique(['list_id', 'email'], 'celr_list_email_unique');
        });
    }

    public function down(): void
    {
        foreach (['core_email_list_rows', 'core_email_lists', 'core_email_campaign_recipients', 'core_email_campaigns', 'core_cart_reminders',
            'core_checkout_contacts', 'core_cart_reminder_settings', 'core_unsubscribe_links', 'core_personal_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
