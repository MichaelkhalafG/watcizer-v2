<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a promotion rule is ACTUALLY doing right now — the at-a-glance state of the rule list
 * (wave 4D, developer addition 2026-09-13).
 *
 * ── Why the list computes this instead of showing `is_active` ────────────────────────────────
 *
 * The developer's requirement: *"an admin should never have to open a rule to know whether it's
 * doing anything."* Same principle as the catalogue's four at-a-glance states (task 4.3), and the
 * same reasoning: `is_active = 1` is what the admin TYPED, not what the shop is doing. A rule can
 * be switched on, inside its window, and still give away nothing — because the gift is out of
 * stock, because it is enabled for no storefront, or because the gift is not visible on the
 * storefront it is enabled for. Every one of those is invisible in the row's own columns.
 *
 * ── The headline state, and why there is exactly one ─────────────────────────────────────────
 *
 * A list cell holds one thing. So the states are ORDERED by how much they should worry an admin,
 * and the first that applies is the headline:
 *
 *   1. `expired`             — the window has closed. Nothing will happen again.
 *   2. `scheduled`           — the window has not opened. Nothing is wrong.
 *   3. `inactive`            — switched off by hand.
 *   4. `no_storefront`       — on no storefront at all: a draft, whatever the switch says.
 *   5. `no_stock`            — live, and the gift has none. **The one that costs the client.**
 *   6. `not_visible`         — live, and the gift is not visible on every storefront chosen.
 *   7. `running`             — actually doing something.
 *
 * `no_stock` sits above `not_visible` deliberately: stock is shared, so it is true everywhere at
 * once, while a visibility problem may affect one storefront out of two.
 *
 * ── The asymmetry, again, because it decides the shape of this class ─────────────────────────
 *
 * **Stock is rule-level.** One pool, one ledger (a locked decision) — an out-of-stock gift is out
 * of stock on every storefront, so reporting it per storefront would be a lie dressed as detail.
 * **Visibility is per storefront**, because `storefront_product.is_visible` is a per-storefront
 * column and a rule really can be live on Watchizer and inert on Brand Fashion.
 *
 * So `state` is one word about the rule, and `storefronts[]` carries the per-storefront truth
 * beside it.
 */
final class PromotionState
{
    public const RUNNING = 'running';

    public const SCHEDULED = 'scheduled';

    public const EXPIRED = 'expired';

    public const INACTIVE = 'inactive';

    public const NO_STOREFRONT = 'no_storefront';

    public const NO_STOCK = 'no_stock';

    public const NOT_VISIBLE = 'not_visible';

    /**
     * The state of one rule, with everything the list cell renders beside it.
     *
     * @param  stdClass  $rule  a row carrying id, is_active, starts_at, ends_at
     * @return array{state: string, label: string, tone: string, storefronts: list<array<string, mixed>>, skips: array{stock: int, visibility: array<int, int>, last_at: string|null}, reward_stock: int|null}
     */
    public static function of(stdClass $rule): array
    {
        $id = Row::int($rule, 'id');
        $now = now()->format('Y-m-d H:i:s');

        $storefronts = self::storefrontStates($id);
        $rewardStock = self::lowestRewardStock($id);
        $skips = PromotionSkips::forRule($id);

        $state = self::headline($rule, $now, $storefronts, $rewardStock);

        return [
            'state' => $state,
            'label' => self::label($state),
            'tone' => self::tone($state),
            'storefronts' => $storefronts,
            'skips' => $skips,
            // NULL when the rule has no product reward to count (it should not happen, and if it
            // does the state is already `no_storefront` or worse).
            'reward_stock' => $rewardStock,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $storefronts
     */
    private static function headline(stdClass $rule, string $now, array $storefronts, ?int $rewardStock): string
    {
        $endsAt = Row::nstr($rule, 'ends_at');
        $startsAt = Row::nstr($rule, 'starts_at');

        if ($endsAt !== null && $endsAt < $now) {
            return self::EXPIRED;
        }
        if ($startsAt !== null && $startsAt > $now) {
            return self::SCHEDULED;
        }
        if (! Row::bool($rule, 'is_active')) {
            return self::INACTIVE;
        }
        if ($storefronts === []) {
            return self::NO_STOREFRONT;
        }
        if ($rewardStock !== null && $rewardStock < 1) {
            return self::NO_STOCK;
        }

        foreach ($storefronts as $storefront) {
            if (($storefront['reward_visible'] ?? true) === false) {
                return self::NOT_VISIBLE;
            }
        }

        return self::RUNNING;
    }

    /**
     * Per storefront: is the rule enabled there, and is its reward visible there?
     *
     * Visibility is asked per storefront because it IS per storefront. One query per rule, not one
     * per storefront per reward: the list renders every rule the shop has.
     *
     * @return list<array<string, mixed>>
     */
    private static function storefrontStates(int $ruleId): array
    {
        $rows = DB::table('promotion_rule_storefront as rs')
            ->leftJoin('storefronts as s', 's.id', '=', 'rs.storefront_id')
            ->where('rs.promotion_rule_id', $ruleId)
            ->orderBy('rs.storefront_id')
            ->get(['rs.storefront_id', 's.name', 's.is_active']);

        if ($rows->isEmpty()) {
            return [];
        }

        // Every product this rule rewards, once.
        $rewardProducts = self::rewardProductIds($ruleId);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $storefrontId = Row::int($row, 'storefront_id');

            $visible = true;
            if ($rewardProducts !== []) {
                $visibleCount = DB::table('catalog_products as p')
                    ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                        $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
                    })
                    ->whereIn('p.id', $rewardProducts)
                    ->whereNull('p.deleted_at')
                    ->where('p.is_active', 1)
                    ->where('sp.is_visible', 1)
                    ->count();

                // EVERY reward has to be visible: a rule promising two gifts that can deliver one
                // is a shop that looks like it is cheating, which is why the engine refuses it too.
                $visible = $visibleCount === count($rewardProducts);
            }

            $out[] = [
                'id' => $storefrontId,
                'name' => Row::nstr($row, 'name') ?? ('#'.$storefrontId),
                'storefront_active' => Row::nbool($row, 'is_active') ?? false,
                'reward_visible' => $visible,
            ];
        }

        return $out;
    }

    /**
     * The SMALLEST stock any of this rule's rewards can draw on, across both buckets.
     *
     * Smallest, not total: the rule delivers all of its rewards or none, so the one with nothing
     * left is the one that decides. A variant reward reads the variant's own columns, because that
     * is where per-variant stock lives (wave 3.5).
     */
    private static function lowestRewardStock(int $ruleId): ?int
    {
        $rewards = DB::table('promotion_rule_rewards')
            ->where('promotion_rule_id', $ruleId)
            ->get(['type', 'product_id', 'variant_id', 'quantity']);

        $lowest = null;

        foreach ($rewards as $raw) {
            $reward = Row::cast($raw);
            if (! PromotionRules::isItemReward(Row::str($reward, 'type'))) {
                continue;                               // a money-reducing type has no stock to read
            }

            $variantId = Row::nint($reward, 'variant_id');
            $productId = Row::nint($reward, 'product_id');

            $row = $variantId !== null
                ? DB::table('catalog_product_variants')->where('id', $variantId)->first(['stock_express', 'stock_market'])
                : ($productId !== null ? DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']) : null);

            if (! is_object($row)) {
                $lowest = 0;                            // the reward points at nothing: it can never be given

                continue;
            }
            $stock = Row::cast($row);

            // The best single bucket, because a reward is never split across two.
            $best = max(Row::int($stock, 'stock_express'), Row::int($stock, 'stock_market'));
            // Measured against what the rule actually promises, so "3 in stock, gives away 5" reads
            // as zero rather than as three.
            $deliverable = intdiv($best, max(1, Row::int($reward, 'quantity')));

            $lowest = $lowest === null ? $deliverable : min($lowest, $deliverable);
        }

        return $lowest;
    }

    /** @return list<int> */
    private static function rewardProductIds(int $ruleId): array
    {
        $ids = DB::table('promotion_rule_rewards as r')
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'r.variant_id')
            ->where('r.promotion_rule_id', $ruleId)
            ->selectRaw('COALESCE(r.product_id, v.product_id) AS pid')
            ->pluck('pid');

        $out = [];
        foreach (Coerce::intList($ids) as $id) {
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        /** @var list<int> $unique */
        $unique = array_keys($out);

        return $unique;
    }

    /** Arabic, for a non-technical operator reading a list. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::RUNNING => ManageText::t('promotions.state_running', 'تعمل الآن'),
            self::SCHEDULED => ManageText::t('promotions.state_scheduled', 'مجدولة'),
            self::EXPIRED => ManageText::t('promotions.state_expired', 'منتهية'),
            // The list's own filter already says this word — one key, or the two drift.
            self::INACTIVE => ManageText::t('promotions.list_suspended', 'موقوفة'),
            self::NO_STOREFRONT => ManageText::t('promotions.state_no_storefront', 'غير مفعّلة على أي متجر'),
            self::NO_STOCK => ManageText::t('promotions.state_no_stock', 'غير مفعّلة حاليًا: مخزون الهدية صفر'),
            self::NOT_VISIBLE => ManageText::t('promotions.state_not_visible', 'الهدية غير معروضة على أحد المتاجر'),
            default => $state,
        };
    }

    /**
     * The badge colour. `no_stock` and `not_visible` are DESTRUCTIVE rather than merely neutral:
     * a rule the admin believes is running and which gives away nothing is the failure mode that
     * costs the client, and a quiet grey chip is how it stays unnoticed.
     */
    public static function tone(string $state): string
    {
        return match ($state) {
            self::RUNNING => 'success',
            self::SCHEDULED => 'default',
            self::EXPIRED, self::INACTIVE => 'neutral',
            self::NO_STOCK, self::NOT_VISIBLE, self::NO_STOREFRONT => 'destructive',
            default => 'outline',
        };
    }
}
