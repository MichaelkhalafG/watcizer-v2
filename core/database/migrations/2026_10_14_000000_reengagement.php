<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2a (2026-10-01): the weekly re-engagement e-mail, rebuilt on core (decided 2026-09-28; the two
 * open questions answered 2026-09-30: no price hold — only products whose price has not changed for
 * 14 days; the same mailbox, protected by the transactional reserve).
 *
 *  - `core_customer_seen` — the "last seen" core owns: a customer's last signed-in storefront
 *    request, per storefront (written at most once an hour per customer, by `CompatAuth`).
 *  - `core_price_watch` — each product's current price and since when it has been that price, so
 *    "unchanged for 14 days" is a fact rather than a guess (`updated_at` moves with every sale).
 *  - `core_marketing_optouts` — addresses that pressed "unsubscribe", per storefront. Honoured by
 *    every run, forever.
 *  - `core_reengagement_settings` — per storefront: paused, and who receives it (the team only,
 *    or customers). Defaults: NOT paused, TEAM ONLY — the first runs go to team inboxes.
 *  - `core_reengagement_runs` / `core_reengagement_sends` — each week's run (planned → previewed →
 *    sent / paused) and who got which products, which is what enforces "never the same customer two
 *    weeks running, never the same products twice".
 *
 * All on the never-dropped list: none has a legacy source. `hasTable` guards for switch night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_customer_seen') or Schema::create('core_customer_seen', function (Blueprint $t): void {
            $t->unsignedBigInteger('user_id');
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            // DATETIME, not TIMESTAMP: a non-null TIMESTAMP gets ON UPDATE CURRENT_TIMESTAMP from MariaDB
            // (explicit_defaults_for_timestamp=0), so any update would silently rewrite it.
            $t->dateTime('last_seen_at');
            $t->primary(['user_id', 'storefront_id']);
        });

        Schema::hasTable('core_price_watch') or Schema::create('core_price_watch', function (Blueprint $t): void {
            $t->unsignedBigInteger('product_id')->primary();
            $t->decimal('price', 12, 2);
            $t->dateTime('since');                             // DATETIME: see last_seen_at above
        });

        Schema::hasTable('core_marketing_optouts') or Schema::create('core_marketing_optouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('email', 191);
            $t->timestamp('created_at')->nullable();
            $t->unique(['storefront_id', 'email']);
        });

        Schema::hasTable('core_reengagement_settings') or Schema::create('core_reengagement_settings', function (Blueprint $t): void {
            $t->foreignId('storefront_id')->primary()->constrained('storefronts')->cascadeOnDelete();
            $t->boolean('paused')->default(false);
            $t->string('audience', 12)->default('team');      // team | customers
            $t->text('team_emails')->nullable();               // one per line; empty = nothing planned (R7)
            $t->timestamps();
        });

        Schema::hasTable('core_reengagement_runs') or Schema::create('core_reengagement_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('storefront_id')->constrained('storefronts')->cascadeOnDelete();
            $t->string('week', 8);                             // ISO week, e.g. 2026-W40
            $t->string('audience', 12);
            $t->string('status', 12);                          // previewed | sent | paused | cancelled
            $t->unsignedInteger('recipients')->default(0);
            $t->timestamp('previewed_at')->nullable();
            $t->timestamp('send_after')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['storefront_id', 'week']);
        });

        Schema::hasTable('core_reengagement_sends') or Schema::create('core_reengagement_sends', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->constrained('core_reengagement_runs')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();     // null for a team address
            $t->string('email', 191);
            $t->text('product_ids');                           // JSON list
            $t->timestamp('created_at')->nullable();
            $t->unique(['run_id', 'email']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        foreach (['core_reengagement_sends', 'core_reengagement_runs', 'core_reengagement_settings', 'core_marketing_optouts', 'core_price_watch', 'core_customer_seen'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
