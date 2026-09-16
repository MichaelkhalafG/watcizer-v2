<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * The skip counter — the ONLY thing in the promotions engine that writes during evaluation, and
 * the reason it lives outside {@see PromotionEngine} (wave 4D, study §3.16.4/§3.16.9).
 *
 * ── Why it is a separate class ───────────────────────────────────────────────────────────────
 *
 * `PromotionEngine::evaluate()` writes nothing, which is what makes "the same cart evaluated twice
 * yields the same result; nothing accumulates" a property of the type. A counter inside it would
 * take that away — and worse, the cart is read on every storefront page, so it would turn the
 * hottest GET in the application into a write.
 *
 * So the engine RETURNS the skips it found and the CHECKOUT records them. Once per refused grant,
 * on the one path where a customer actually tried to buy something.
 *
 * ── Why a customer's silence is an admin's alarm ─────────────────────────────────────────────
 *
 * The developer's requirement, in their words: *"an admin advertising a gift that never applies is
 * the failure mode that actually costs the client."* The customer must never see a promotion fail
 * — they get the cart they would have had — so the only way anybody learns is this count and the
 * badge beside it.
 *
 * ── Per (rule, storefront, reason), and the asymmetry that forced it ─────────────────────────
 *
 * **Per storefront** because visibility is a per-storefront column: a rule really can be inert on
 * Brand Fashion and live on Watchizer. **Per reason** because stock is SHARED — one pool, one
 * ledger, a locked decision — so an out-of-stock gift is out of stock everywhere, and rendering
 * that per storefront would be a lie dressed as detail.
 *
 * The screen therefore reads the same rows two ways: the stock figure as ONE rule-level total,
 * because stock is one pool; the visibility figures per storefront, because visibility is not.
 * Storing it aggregate would have thrown away the only commercially interesting question in it —
 * "Brand Fashion missed forty of the fifty, should we stock more?" — permanently.
 */
final class PromotionSkips
{
    /**
     * Record every refusal the engine reported, for one storefront.
     *
     * Never inside the caller's transaction: a skip is a fact about an attempt that HAPPENED, and
     * if the order then rolls back the attempt still happened. That is the same reasoning as the
     * lost-callback finding being written outside the rolled-back payment transaction.
     *
     * @param  array<int, string>  $skipped  rule id => a `PromotionRules::SKIP_*` reason
     */
    public function record(array $skipped, int $storefrontId): void
    {
        foreach ($skipped as $ruleId => $reason) {
            if (! in_array($reason, PromotionRules::SKIP_REASONS, true)) {
                continue;                               // never invent a counter for an unknown reason
            }

            /*
             * One statement, no read-modify-write: two concurrent checkouts refusing the same
             * out-of-stock gift must both be counted, and `$row->skips + 1` in PHP would lose one
             * of them. The same conditional-update discipline as `adjustOffer()`.
             */
            $updated = DB::table('promotion_rule_skips')
                ->where('promotion_rule_id', $ruleId)
                ->where('storefront_id', $storefrontId)
                ->where('reason', $reason)
                ->update(['skips' => DB::raw('skips + 1'), 'last_at' => now()]);

            if ($updated === 0) {
                DB::table('promotion_rule_skips')->insert([
                    'promotion_rule_id' => $ruleId,
                    'storefront_id' => $storefrontId,
                    'reason' => $reason,
                    'skips' => 1,
                    'last_at' => now(),
                ]);
            }
        }
    }

    /**
     * What the promotions screen shows for one rule.
     *
     * Two shapes out of the same rows, per the asymmetry above: `stock` is one number because
     * stock is one pool; `visibility` is per storefront because visibility is per storefront.
     *
     * @return array{stock: int, visibility: array<int, int>, last_at: string|null}
     */
    public static function forRule(int $ruleId): array
    {
        $stock = 0;
        $visibility = [];
        $lastAt = null;

        foreach (
            DB::table('promotion_rule_skips')
                ->where('promotion_rule_id', $ruleId)
                ->get(['storefront_id', 'reason', 'skips', 'last_at']) as $raw
        ) {
            $row = Row::cast($raw);
            $reason = Row::str($row, 'reason');
            $skips = Row::int($row, 'skips');
            $seen = Row::nstr($row, 'last_at');

            if ($reason === PromotionRules::SKIP_OUT_OF_STOCK) {
                // Summed across storefronts and shown as one figure: the misses are real and
                // countable per storefront, but the CONDITION that caused them is not.
                $stock += $skips;
            } else {
                $visibility[Row::int($row, 'storefront_id')] = $skips;
            }

            if ($seen !== null && ($lastAt === null || $seen > $lastAt)) {
                $lastAt = $seen;
            }
        }

        return ['stock' => $stock, 'visibility' => $visibility, 'last_at' => $lastAt];
    }
}
