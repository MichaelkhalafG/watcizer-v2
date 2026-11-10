<?php

use App\Domain\Wheel\WheelPrizes;
use App\Support\Coerce;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "SPIN THE WHEEL" (Watchizer, 2026-11-07; reshaped 2026-11-08 and 2026-11-09 before any deploy — ONE migration).
 *
 * `storefront_wheel_prizes` — THE TEAM'S PRIZES, per storefront, in wheel order: a label in both languages, a TYPE,
 * its value, odds in whole per cent (the rows of a storefront sum to 100), a colour, and the type's own terms:
 *   percent_discount   `value` %, optional `cap` EGP; optional `scope_category_id` (the category and its children only,
 *                      e.g. wallets), optional `min_quantity` of items in that category, and `apply_mode`
 *                      `order` (off every eligible item) or `cheapest` (off ONE unit, the cheapest eligible — "10 % off
 *                      the 2nd watch")
 *   fixed_discount     `value` EGP
 *   free_shipping      the delivery charged
 *   none               "better luck" — no prize, no code
 *   referral           "invite a friend": the winner gets an invite code; a friend's FIRST order gets `value` % off (7 days);
 *                      when that order is DELIVERED the winner gets a `value` % code of their own (48 h)
 *   gift               a free product from `gift_product_ids` (JSON list) — one that is in stock, chosen at the spin
 *
 * `storefront_wheel_spins` — one row per SPIN: the phone (normalised 01xxxxxxxxx, and its ten-digit key — the engine's
 * `p:` customer key), the round's key (`campaign`), the single-use code (NULL for "better luck"), the SNAPSHOT of the
 * prize's terms as won (a later edit changes only the next spins), the prize rule for those terms, the expiry, the
 * order that used it, HMACs of the device and the address, the visit's UTM tags. For a referral prize: the invite code
 * and its own expiry, and — since the invite needs an ACCOUNT (Michael, 2026-11-09 evening) — the account that activated
 * it and when: the code is dormant until the winner signs in; the reward mail goes to that account. A winner's referral
 * REWARD is a row of its own (campaign `referral-<referral id>`, the spin's phone, `source_referral_id`).
 *
 * `storefront_wheel_referrals` — one row per FRIEND who used an invite: the winner's spin, the friend's phone (key),
 * the order, its state (placed → delivered | cancelled), the reward spin issued on delivery. Unique per spin and friend.
 *
 * `promotion_rules.issuer` — `wheel` marks a prize rule: it is reached only through a spin's code, never typed.
 *
 * Both wheel tables are on CoreChecksumCommand::DASHBOARD_TABLES (never dropped by a rebuild). Creation guarded by
 * hasTable (the ledger is `core_migrations`); the ALTER guarded by hasColumn. The brief's prizes are seeded ONCE for
 * every storefront that has none (insert-only).
 *
 * Rollback (never automatic; only after the code that reads these tables is gone):
 *   DROP TABLE storefront_wheel_referrals; DROP TABLE storefront_wheel_spins; DROP TABLE storefront_wheel_prizes;
 *   ALTER TABLE promotion_rules DROP INDEX promo_issuer_idx, DROP COLUMN issuer;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_07_000000_wheel_spins';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_wheel_prizes') or Schema::create('storefront_wheel_prizes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedTinyInteger('position');                    // wheel order, clockwise from the pointer
            $t->string('label_en', 80);
            $t->string('label_ar', 80);
            $t->string('type', 16);                                 // WheelPrizes::TYPES
            $t->decimal('value', 12, 2)->nullable();                // the percentage or the amount; NULL for shipping, none, gift
            $t->unsignedTinyInteger('odds');                        // whole per cent; a storefront's rows sum to 100
            $t->char('color', 7);                                   // #rrggbb, the segment's colour on the wheel
            $t->decimal('cap', 12, 2)->nullable();                  // percent prizes: the most it takes off, in EGP
            $t->unsignedBigInteger('scope_category_id')->nullable(); // percent prizes: this category and its children only
            $t->unsignedTinyInteger('min_quantity')->nullable();    // percent prizes: at least this many items in the category
            $t->string('apply_mode', 16)->default('order');         // percent prizes: order | cheapest
            $t->text('gift_product_ids')->nullable();               // gift prizes: JSON list of product ids
            $t->timestamps();

            $t->index(['storefront_id', 'position'], 'wheel_prize_order_idx');
            $t->foreign('storefront_id', 'wheel_prize_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
        });

        Schema::hasTable('storefront_wheel_spins') or Schema::create('storefront_wheel_spins', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('campaign', 40);                             // the round's key at the time of the spin (round-1, …)
            $t->string('phone', 20);                                // normalised: 01xxxxxxxxx
            $t->string('phone_key', 16);                            // the last ten digits — the engine's `p:` customer key
            $t->string('code', 40)->nullable();                     // the single-use code, upper case; NULL for "better luck"
            // THE TERMS, as won (a later edit of the prize changes only the next spins)
            $t->unsignedBigInteger('prize_id')->nullable();         // the prize row at the time; NULL once that row is gone
            $t->string('prize_label_en', 80);
            $t->string('prize_label_ar', 80);
            $t->string('prize_type', 16);
            $t->decimal('prize_value', 12, 2)->nullable();
            $t->decimal('prize_cap', 12, 2)->nullable();
            $t->decimal('min_order', 12, 2)->nullable();
            $t->unsignedBigInteger('prize_scope_category_id')->nullable();
            $t->unsignedTinyInteger('prize_min_quantity')->nullable();
            $t->string('prize_apply_mode', 16)->default('order');
            $t->unsignedBigInteger('gift_product_id')->nullable();  // gift prizes: the product chosen at the spin
            $t->char('color', 7);
            $t->unsignedBigInteger('promotion_rule_id')->nullable(); // the prize rule for exactly these terms; NULL for none
            $t->dateTime('expires_at');                             // the spin + the wheel's validity hours at the time
            $t->string('invite_code', 40)->nullable();              // referral prizes: the code a friend types
            $t->dateTime('invite_expires_at')->nullable();          // referral prizes: the spin + 7 days
            $t->unsignedBigInteger('user_id')->nullable();          // referral prizes: the account that activated the invite
            $t->dateTime('invite_activated_at')->nullable();        // referral prizes: when the winner signed in to activate it
            $t->unsignedBigInteger('source_referral_id')->nullable(); // a winner's REWARD row: the referral that earned it
            $t->unsignedBigInteger('used_order_id')->nullable();
            $t->dateTime('used_at')->nullable();
            $t->char('device_hash', 64)->nullable();                // HMAC of the browser's own id, never the id
            $t->char('ip_hash', 64)->nullable();                    // HMAC of the address, never the address
            $t->string('utm_source', 120)->nullable();
            $t->string('utm_medium', 120)->nullable();
            $t->string('utm_campaign', 120)->nullable();
            $t->string('utm_content', 120)->nullable();
            $t->string('locale', 2)->default('ar');
            $t->timestamp('created_at')->nullable();

            $t->unique(['storefront_id', 'campaign', 'phone_key'], 'wheel_phone_once_unique');
            $t->unique('code', 'wheel_code_unique');
            $t->unique('invite_code', 'wheel_invite_unique');
            $t->index(['storefront_id', 'campaign', 'device_hash'], 'wheel_device_idx');
            $t->index(['storefront_id', 'created_at'], 'wheel_created_idx');
            $t->index(['storefront_id', 'phone_key'], 'wheel_phone_idx');
            $t->index('used_order_id', 'wheel_order_idx');
            $t->index('prize_id', 'wheel_prize_idx');
            $t->index(['storefront_id', 'user_id'], 'wheel_user_idx');
            $t->foreign('storefront_id', 'wheel_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
            // No foreign key to `promotion_rules` or to the prize row: a rule is deactivated, never deleted once it has
            // granted (PromotionWriter::delete); a prize row may be removed by the team while codes won on it still stand.
        });

        Schema::hasTable('storefront_wheel_referrals') or Schema::create('storefront_wheel_referrals', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->unsignedBigInteger('spin_id');                      // the winner's spin (its invite code)
            $t->string('friend_phone', 20);
            $t->string('friend_phone_key', 16);
            $t->unsignedBigInteger('order_id');                     // the friend's first order
            $t->string('status', 12)->default('placed');            // placed | delivered | cancelled
            $t->unsignedBigInteger('reward_spin_id')->nullable();   // the winner's reward row, once delivered
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();

            $t->unique(['spin_id', 'friend_phone_key'], 'wheel_referral_friend_unique');
            $t->index('order_id', 'wheel_referral_order_idx');
            $t->index(['storefront_id', 'status'], 'wheel_referral_state_idx');
            $t->foreign('storefront_id', 'wheel_referral_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
        });

        if (Schema::hasTable('promotion_rules') && ! Schema::hasColumn('promotion_rules', 'issuer')) {
            Schema::table('promotion_rules', function (Blueprint $t): void {
                $t->string('issuer', 16)->nullable()->after('code');
                $t->index('issuer', 'promo_issuer_idx');
            });
        }

        // The brief's prizes for every storefront that has none yet — insert-only, so a re-run never touches a wheel the
        // team has edited (AGENTS §2.20's insert-only rule for dashboard-owned rows). The category and the gift lists are
        // the team's to choose on the dashboard: a scoped prize is seeded WITHOUT its category and the dashboard says so.
        foreach (DB::table('storefronts')->orderBy('id')->pluck('id') as $storefrontId) {
            $storefrontId = Coerce::int($storefrontId);
            if (DB::table('storefront_wheel_prizes')->where('storefront_id', $storefrontId)->exists()) {
                continue;
            }
            foreach (WheelPrizes::DEFAULTS as $position => $prize) {
                DB::table('storefront_wheel_prizes')->insert([
                    'storefront_id' => $storefrontId,
                    'position' => $position,
                    'label_en' => $prize['label_en'],
                    'label_ar' => $prize['label_ar'],
                    'type' => $prize['type'],
                    'value' => $prize['value'],
                    'odds' => $prize['odds'],
                    'color' => $prize['color'],
                    'cap' => $prize['cap'],
                    'scope_category_id' => WheelPrizes::seededCategory($storefrontId, $prize['scope_slug']),
                    'min_quantity' => $prize['min_quantity'],
                    'apply_mode' => $prize['apply_mode'],
                    'gift_product_ids' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_wheel_referrals');
        Schema::dropIfExists('storefront_wheel_spins');
        Schema::dropIfExists('storefront_wheel_prizes');
        if (Schema::hasTable('promotion_rules') && Schema::hasColumn('promotion_rules', 'issuer')) {
            Schema::table('promotion_rules', function (Blueprint $t): void {
                $t->dropIndex('promo_issuer_idx');
                $t->dropColumn('issuer');
            });
        }
    }
};
