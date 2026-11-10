<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Storefront\StorefrontPricing;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Campaign prices — an extra discount that holds only while a Hot Deals or Flash Sale campaign is LIVE (Michael,
 * 2026-11-10). Per product of a campaign: a campaign PRICE (1,500), or an EXTRA PERCENTAGE off the product's current
 * price (its valid sale, else its price — 20 % of 2,150 → 1,720, rounded to the pound). Nothing in the product's own
 * price fields changes; the price is decided HERE at every read, so it starts and ends at the exact second and goes
 * away by itself when the campaign ends, is switched off, or the product leaves it.
 *
 * ── Which price wins ────────────────────────────────────────────────────────────────────────────
 *
 *  • A campaign price applies only when it is LOWER than the product's current price at that instant (the writer
 *    refuses a higher one; if the product's own sale is lowered below it later, the product's own sale stands).
 *  • A product in a live Flash Sale AND a live Hot Deals: the Flash Sale's price (Michael's rule: Flash Sale wins);
 *    a product priced in only one of them takes that one.
 *  • It is a PRICE, not a promotion: the promotions engine sees the product as on sale (a rule with
 *    `include_sale_items = false` leaves it out), and the engine's own no-stacking rule decides the rest.
 *
 * ── Where it is read ────────────────────────────────────────────────────────────────────────────
 *
 *   cards and the product page (ProductCards::card — `price` is the campaign price, with `regular` and `campaign`
 *   beside it), the cart and the checkout (StorefrontCart — what is CHARGED; an order keeps the price it was placed
 *   at), the engine's on-sale test (PromotionScopes), the listing's price filter, price sort, on-sale and minimum-
 *   discount filters and price bounds (ProductListing via {@see self::sqlSale()}), and the campaigns answer's `prices`
 *   (StorefrontCampaigns), which the storefront apps follow to the second.
 *
 * ── Exact to the second, without a cron ───────────────────────────────────────────────────────────
 *
 * {@see self::windows()} is ONE small cached list — the active campaigns that price a product, with their start and
 * end — forgotten by the writer on every save. From it {@see self::liveKey()} names, at any instant and without a
 * query, which priced campaigns are live; StorefrontCache folds that name into every key it builds, so every cached
 * card, product page, home and listing count is a different entry the second a priced campaign starts or ends.
 */
final class CampaignPrices
{
    public const MODE_PRICE = 'price';

    public const MODE_PERCENT = 'percent';

    /** @var list<string> */
    public const MODES = [self::MODE_PRICE, self::MODE_PERCENT];

    /** Above this extra percentage the dashboard asks for a confirmation (not a refusal), as for a price below cost. */
    public const CONFIRM_PERCENT = 50.0;

    /** A hard ceiling: an extra percentage above this is refused. */
    public const MAX_PERCENT = 90.0;

    private const WINDOWS_KEY = 'campaign_price_windows:';

    private const WINDOWS_TTL = 300;

    /** @var array<string, array<int, array{mode: string, value: float, campaign_id: int, presentation: string, ends_at: string}>> */
    private static array $rules = [];

    /**
     * The ACTIVE campaigns of a storefront that price at least one product and have not ended — cached, forgotten by
     * {@see self::forget()} on every campaign save.
     *
     * @return list<array{id: int, presentation: string, starts_at: string, ends_at: string}>
     */
    public static function windows(int $storefrontId): array
    {
        $cached = Cache::remember(self::WINDOWS_KEY.$storefrontId, self::WINDOWS_TTL, function () use ($storefrontId): array {
            $out = [];
            foreach (DB::table('storefront_campaigns as c')
                ->where('c.storefront_id', $storefrontId)->where('c.is_active', 1)
                ->where('c.ends_at', '>', now()->format('Y-m-d H:i:s'))
                ->whereExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('storefront_campaign_products as cp')
                    ->whereColumn('cp.campaign_id', 'c.id')->whereNotNull('cp.price_mode'))
                ->orderBy('c.starts_at')->orderBy('c.id')
                ->get(['c.id', 'c.presentation', 'c.starts_at', 'c.ends_at']) as $raw) {
                $row = Row::cast($raw);
                $out[] = ['id' => Row::int($row, 'id'), 'presentation' => Row::str($row, 'presentation'), 'starts_at' => Row::str($row, 'starts_at'), 'ends_at' => Row::str($row, 'ends_at')];
            }

            return $out;
        });
        $windows = [];
        foreach (Coerce::arr($cached) as $w) {
            $w = Coerce::arr($w);
            $windows[] = ['id' => Coerce::int($w['id'] ?? 0), 'presentation' => Coerce::str($w['presentation'] ?? ''), 'starts_at' => Coerce::str($w['starts_at'] ?? ''), 'ends_at' => Coerce::str($w['ends_at'] ?? '')];
        }

        return $windows;
    }

    public static function forget(int $storefrontId): void
    {
        Cache::forget(self::WINDOWS_KEY.$storefrontId);
        self::$rules = [];
    }

    /**
     * The live PRICED campaign of each presentation at an instant (no query: the cached windows).
     *
     * @return array{flash_sale: int|null, hot_deals: int|null}
     */
    public static function liveIds(int $storefrontId, ?Carbon $at = null): array
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');
        $out = ['flash_sale' => null, 'hot_deals' => null];
        foreach (self::windows($storefrontId) as $w) {
            if ($w['starts_at'] <= $now && $now < $w['ends_at'] && array_key_exists($w['presentation'], $out)) {
                $out[$w['presentation']] = $w['id'];          // ordered by start: the one that started last wins
            }
        }

        return $out;
    }

    /** '' when no priced campaign is live, else a name of the live ones (`p<flash>.<hot>`) — StorefrontCache's key part. */
    public static function liveKey(int $storefrontId, ?Carbon $at = null): string
    {
        $ids = self::liveIds($storefrontId, $at);

        return $ids['flash_sale'] === null && $ids['hot_deals'] === null ? '' : 'p'.($ids['flash_sale'] ?? '').'.'.($ids['hot_deals'] ?? '');
    }

    /**
     * Every name {@see self::liveKey()} can take while the cached windows stand: none, now, and the state from each
     * window's start and from each window's end — the names a held entry of one product can sit under.
     *
     * @return list<string>
     */
    public static function liveKeys(int $storefrontId): array
    {
        $names = ['' => true, self::liveKey($storefrontId) => true];
        foreach (self::windows($storefrontId) as $w) {
            foreach ([$w['starts_at'], $w['ends_at']] as $at) {
                $names[self::liveKey($storefrontId, Carbon::parse($at))] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * Product id → the campaign price rule that applies now (Flash Sale first, Hot Deals for what it does not price).
     * One query while a priced campaign is live, none otherwise; kept for the request under the live name.
     *
     * @return array<int, array{mode: string, value: float, campaign_id: int, presentation: string, ends_at: string}>
     */
    public static function rules(int $storefrontId, ?Carbon $at = null): array
    {
        $ids = self::liveIds($storefrontId, $at);
        if ($ids['flash_sale'] === null && $ids['hot_deals'] === null) {
            return [];
        }
        $memo = $storefrontId.':'.self::liveKey($storefrontId, $at);
        if (isset(self::$rules[$memo])) {
            return self::$rules[$memo];
        }
        $ends = [];
        foreach (self::windows($storefrontId) as $w) {
            $ends[$w['id']] = $w['ends_at'];
        }
        $out = [];
        foreach (['flash_sale', 'hot_deals'] as $presentation) {
            $campaignId = $ids[$presentation];
            if ($campaignId === null) {
                continue;
            }
            foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->whereNotNull('price_mode')
                ->get(['product_id', 'price_mode', 'price_value']) as $raw) {
                $row = Row::cast($raw);
                $productId = Row::int($row, 'product_id');
                $mode = Row::str($row, 'price_mode');
                $value = Row::nfloat($row, 'price_value');
                if (isset($out[$productId]) || ! in_array($mode, self::MODES, true) || $value === null || $value <= 0) {
                    continue;                                   // Flash Sale's price already stands, or not a price
                }
                $out[$productId] = ['mode' => $mode, 'value' => $value, 'campaign_id' => $campaignId, 'presentation' => $presentation, 'ends_at' => $ends[$campaignId] ?? ''];
            }
        }

        return self::$rules[$memo] = $out;
    }

    /** The product's current price: its valid sale (0 < sale < price), else its price. */
    public static function current(float $price, ?float $sale): float
    {
        return $sale !== null && $sale > 0 && $sale < $price ? $sale : $price;
    }

    /**
     * What a rule makes of a product's prices — or null when it would not be lower than the current price.
     * A percentage is taken off the current price and rounded to the pound, in whole cents (no float drift; the SQL
     * of {@see self::sqlSale()} rounds the same).
     */
    public static function campaignPrice(string $mode, float $value, float $price, ?float $sale): ?float
    {
        $current = self::current($price, $sale);
        if ($mode === self::MODE_PRICE) {
            $candidate = round($value, 2);
        } elseif ($mode === self::MODE_PERCENT) {
            $cents = (int) round($current * 100);
            $candidate = (float) round($cents * (10000 - (int) round($value * 100)) / 1000000);
        } else {
            return null;
        }

        return $candidate > 0 && $candidate < $current ? $candidate : null;
    }

    /**
     * The sale a shopper is shown and charged NOW for a product of this storefront: the campaign price when one
     * applies, else the product's own sale (unchanged). The decimal strings the price columns hold.
     *
     * @return array{sale: string|null, rule: array{mode: string, value: float, campaign_id: int, presentation: string, ends_at: string}|null}
     */
    public static function saleFor(int $storefrontId, int $productId, string $price, ?string $sale, ?Carbon $at = null): array
    {
        $rule = self::rules($storefrontId, $at)[$productId] ?? null;
        if ($rule === null) {
            return ['sale' => $sale, 'rule' => null];
        }
        $campaign = self::campaignPrice($rule['mode'], $rule['value'], (float) $price, $sale === null ? null : (float) $sale);
        if ($campaign === null) {
            return ['sale' => $sale, 'rule' => null];
        }

        return ['sale' => number_format($campaign, 2, '.', ''), 'rule' => $rule];
    }

    /**
     * The `price` object of a card: the campaign price when one applies, with `regular` (the price without it) and
     * `campaign` (which, and until when) beside it so a page held from before the end can go back by itself.
     *
     * @return array<string, mixed>
     */
    public static function priceOf(int $storefrontId, int $productId, string $price, ?string $sale, string $currency): array
    {
        $now = self::saleFor($storefrontId, $productId, $price, $sale);
        $resolved = StorefrontPricing::resolve($price, $now['sale'], $currency);
        if ($now['rule'] === null) {
            return $resolved;
        }

        return $resolved + [
            'regular' => StorefrontPricing::resolve($price, $sale, $currency),
            'campaign' => ['presentation' => $now['rule']['presentation'], 'ends_at' => Carbon::parse($now['rule']['ends_at'])->toIso8601String()],
        ];
    }

    /**
     * For the listing's SQL: LEFT JOINs the live priced campaigns' rows (at most two) to `sp` and returns the SQL
     * expression of the campaign price — NULL for a product without one — or null when no priced campaign is live
     * (the query is then untouched, and its index-ordered plan with it). Idempotent on a query. The SQL is built from
     * this class's constants and table aliases only (the campaign id is a binding), hence `literal-string`.
     *
     * @return literal-string|null
     */
    public static function sqlSale(Builder $query, int $storefrontId, ?Carbon $at = null): ?string
    {
        $ids = self::liveIds($storefrontId, $at);
        $current = '(CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN sp.effective_sale_price ELSE sp.effective_price END)';
        $joined = [];
        foreach ($query->joins ?? [] as $join) {
            if ($join instanceof JoinClause && is_string($join->table)) {
                $joined[] = $join->table;
            }
        }
        $parts = [];
        foreach (['flash_sale' => 'cpf', 'hot_deals' => 'cph'] as $presentation => $alias) {
            $campaignId = $ids[$presentation];
            if ($campaignId === null) {
                continue;
            }
            if (! in_array("storefront_campaign_products as {$alias}", $joined, true)) {
                $query->leftJoin("storefront_campaign_products as {$alias}", function (JoinClause $join) use ($alias, $campaignId): void {
                    $join->on("{$alias}.product_id", '=', 'sp.product_id')->where("{$alias}.campaign_id", '=', $campaignId)->whereNotNull("{$alias}.price_mode");
                });
            }
            $pct = "ROUND({$current} * (100 - {$alias}.price_value) / 100)";
            $parts[] = "(CASE WHEN {$alias}.price_mode = 'price' AND {$alias}.price_value > 0 AND {$alias}.price_value < {$current} THEN {$alias}.price_value"
                ." WHEN {$alias}.price_mode = 'percent' AND {$pct} > 0 AND {$pct} < {$current} THEN {$pct} ELSE NULL END)";
        }

        return $parts === [] ? null : 'COALESCE('.implode(', ', $parts).')';
    }
}
