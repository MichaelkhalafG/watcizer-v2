<?php

namespace App\Storefront;

use App\Transform\Row;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The OFFERS a storefront is running — the dashboard's promotions (العروض الترويجية, `/manage/promotions`,
 * `App\Domain\Promotions`): rules authored by an admin, each enabled per storefront, applied to a cart by the
 * {@see \App\Domain\Promotions\PromotionEngine}. Round 5, 2026-10-18 (Michael): "Offers" is THIS feature and
 * nothing else — a product's discount is "Sale" (`ProductListing`'s `on_sale`), a different concept.
 *
 * "Running" is exactly the engine's own candidate test, so this list can never show a rule the checkout would
 * not consider: active, inside its window (`starts_at` ≤ now ≤ `ends_at`, both mandatory by schema), enabled
 * for THIS storefront through the pivot (an inner join, as in the engine), and carrying at least one condition
 * (the engine refuses a condition-less rule — it would apply to every cart). Best first: priority, then id.
 *
 * Read-only, three queries whatever the number of rules (the rules, then their conditions and their rewards
 * by id set). The rule's `name` is the admin's text and becomes the shop-facing title here — one language, as
 * the dashboard stores it.
 */
final class StorefrontOffers
{
    /**
     * @return list<array{id: int, name: string, starts_at: string, ends_at: string, conditions: list<array<string, int|string|null>>, rewards: list<array<string, int|string|null>>}>
     */
    public function running(int $storefrontId, DateTimeInterface $now): array
    {
        $stamp = $now->format('Y-m-d H:i:s');

        $rules = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->where('rs.storefront_id', $storefrontId)
            ->where('r.is_active', 1)
            ->where('r.starts_at', '<=', $stamp)
            ->where('r.ends_at', '>=', $stamp)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('promotion_rule_conditions as c')->whereColumn('c.promotion_rule_id', 'r.id'))
            ->orderByDesc('r.priority')->orderBy('r.id')
            ->get(['r.id', 'r.name', 'r.starts_at', 'r.ends_at']);
        if ($rules->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($rules as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }

        $conditions = [];
        foreach (DB::table('promotion_rule_conditions')->whereIn('promotion_rule_id', $ids)->orderBy('id')
            ->get(['promotion_rule_id', 'type', 'product_id', 'variant_id', 'storefront_category_id', 'brand_id', 'quantity', 'amount', 'methods']) as $raw) {
            $c = Row::cast($raw);
            $conditions[Row::int($c, 'promotion_rule_id')][] = [
                'type' => Row::str($c, 'type'),
                'product_id' => Row::nint($c, 'product_id'),
                'variant_id' => Row::nint($c, 'variant_id'),
                'storefront_category_id' => Row::nint($c, 'storefront_category_id'),
                'brand_id' => Row::nint($c, 'brand_id'),
                'quantity' => Row::nint($c, 'quantity'),
                'amount' => Row::nmoney($c, 'amount'),
                'methods' => Row::nstr($c, 'methods'),
            ];
        }

        $rewards = [];
        foreach (DB::table('promotion_rule_rewards')->whereIn('promotion_rule_id', $ids)->orderBy('id')
            ->get(['promotion_rule_id', 'type', 'product_id', 'variant_id', 'amount', 'quantity']) as $raw) {
            $w = Row::cast($raw);
            $rewards[Row::int($w, 'promotion_rule_id')][] = [
                'type' => Row::str($w, 'type'),
                'product_id' => Row::nint($w, 'product_id'),
                'variant_id' => Row::nint($w, 'variant_id'),
                'amount' => Row::nmoney($w, 'amount'),
                'quantity' => Row::nint($w, 'quantity'),
            ];
        }

        $out = [];
        foreach ($rules as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'id');
            $out[] = [
                'id' => $id,
                'name' => Row::str($r, 'name'),
                'starts_at' => Row::str($r, 'starts_at'),
                'ends_at' => Row::str($r, 'ends_at'),
                'conditions' => $conditions[$id] ?? [],
                'rewards' => $rewards[$id] ?? [],
            ];
        }

        return $out;
    }
}
