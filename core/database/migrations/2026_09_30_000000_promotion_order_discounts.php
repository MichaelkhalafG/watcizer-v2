<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1r — what a money reward took off an order, and which rule took it (wave 4D, study §3.16.3).
 *
 * ── The gap this closes ──────────────────────────────────────────────────────────────────────
 *
 * A free-item reward is fully traceable: it becomes a row in `order_items` carrying
 * `promotion_rule_id` and `is_reward = 1`, so the order says what it gave away and why. A MONEY
 * reward writes no line at all — it is a smaller number on `orders.total_price_for_order` — so
 * until this table an order whose lines summed to 500 and whose total read 475 had **nothing**
 * recording which promotion did it, or by how much.
 *
 * An operator could not answer "why is this order 25 less?", and the settlement export could not
 * attribute the difference. In a codebase whose whole culture is audit trails — the inventory
 * ledger, the activity log, "a log that gets rewritten is worth nothing" — money that moves without
 * a reason is the one thing that must not ship.
 *
 * ── Why a core table rather than columns on `orders` ─────────────────────────────────────────
 *
 * `orders` is the SHARED legacy table: the legacy application reads and writes it, and its schema
 * is not core's to change. A core-owned table costs nothing there and is read by a single join.
 *
 * ── UNIQUE on order_id, because one rule applies per cart ────────────────────────────────────
 *
 * The engine grants exactly one rule per cart (§3.16.1), so an order has at most one discount. The
 * unique key makes that an invariant the DATABASE holds rather than a promise the checkout makes,
 * and it turns a double-submit or a retried transaction into a refused insert instead of a second
 * row that would double the apparent discount in the settlement file.
 *
 * ── No foreign key on `order_id`, deliberately ───────────────────────────────────────────────
 *
 * The same reasoning as `order_items.promotion_rule_id`: `orders` is written by the legacy app and
 * lives outside core's migration history, so an FK from a core table into it would make core's
 * schema depend on a table it does not own. `promotion_rule_id` DOES get one — `promotion_rules` is
 * core's, and a discount pointing at a deleted rule would be exactly the untraceable state this
 * table exists to prevent. `PromotionWriter::delete()` already refuses to delete a rule that has
 * granted anything, so the RESTRICT can never fire in normal use; it is the backstop.
 *
 * ── In DASHBOARD_TABLES ──────────────────────────────────────────────────────────────────────
 *
 * It describes an ORDER, and orders are legacy rows that a rebuild never touches. A discount record
 * dropped on switch night would leave those orders unexplained for ever, which is the same argument
 * that put `payment_reconciliation_findings` and the promotion tables on the preserved list.
 *
 * Guarded with `hasTable` for the reason AGENTS §2.20 gives: `core:drop-clean` preserves this table
 * and clears the whole migration ledger, so `migrate` re-runs this file against a table that is
 * already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('promotion_order_discounts') or Schema::create('promotion_order_discounts', function (Blueprint $t): void {
            $t->id();

            // The legacy order. No FK — see the class docblock.
            $t->unsignedBigInteger('order_id');

            $t->unsignedBigInteger('promotion_rule_id');

            /*
             * What came off the total, in EGP. decimal(12,2) like every other money column here, and
             * ALWAYS positive: it is an amount removed, not a signed adjustment. A negative discount
             * would be a surcharge, which no promotion may produce.
             */
            $t->decimal('amount', 12, 2);

            /*
             * Whether the discount included a waived shipping cost. Kept separately from the amount
             * because it changes what the number MEANS to somebody reconciling: EGP 50 off the goods
             * and EGP 50 of free delivery are the same figure and a different transaction.
             */
            $t->boolean('free_shipping')->default(false);

            $t->timestamp('created_at')->nullable();

            // One rule per cart, so one discount per order — held by the database.
            $t->unique('order_id', 'promo_discount_order_unique');
            $t->index('promotion_rule_id', 'promo_discount_rule_idx');

            $t->foreign('promotion_rule_id', 'promo_discount_rule_fk')
                ->references('id')->on('promotion_rules')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_order_discounts');
    }
};
