<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Domain\Campaigns\Campaigns;
use App\Support\Val;
use Illuminate\Support\Carbon;

/**
 * The live timed campaigns of a storefront, as the storefront apps read them
 * (`GET /api/v2/{sf}/campaigns`, the twelve updates Part 2, 2026-11-05).
 *
 * One object per presentation — `hot_deals` and `flash_sale` — each `null` when no campaign of that
 * presentation is live at this instant, so the header's countdown, the home slider and the campaign
 * page all appear and disappear on the same answer:
 *
 *   now          the server's clock (ISO 8601 with offset) — the countdown counts against THIS, so a
 *                shopper's wrong clock cannot open a sale early or keep one open
 *   next_start_at  beside `now`, not inside a campaign: the next instant an active campaign of this
 *                storefront starts (either presentation), or null — whoever holds this answer drops it
 *                at that second and asks again (the follow-up of 2026-11-06)
 *   ends_at      the real end, the same form; the storefront closes the campaign at this instant
 *   count        the campaign's products this storefront shows
 *   max_discount the largest discount among them, as the cards print it — a fact, not a slogan
 *   cards        the first {@see self::SLIDER} for the home slider, v2 card rows in the team's order,
 *                each with `campaign` (below) or `campaign: null`
 *   progress     product id → {start, sold, left, pct} for EVERY product that has a recorded start —
 *                the campaign page joins it to the listing's own cards (`products?campaign=…`)
 *
 * `campaign` on a card is real or absent ({@see Campaigns::progress()}): `start` recorded when the
 * product joined the live campaign, `sold` from the stock ledger since then, `left` the live stock,
 * `pct` = sold ÷ start, capped at 100 (a restock mid-campaign can sell more than it started with).
 *
 * WHICH campaign is live is decided on every request (one indexed read), never from a cache: an ended
 * campaign must not be served for a second longer. What IS cached, for {@see self::TTL} seconds under
 * the storefront's version, is the heavy half — the cards and the ledger sums — so a sale shows on the
 * bar within a minute and a dashboard save (which flushes the storefront) shows at once.
 */
final class StorefrontCampaigns
{
    /** How many cards the home slider gets; the page reads the rest through the listing. */
    public const SLIDER = 12;

    public const TTL = 60;

    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly ProductCards $cards,
    ) {}

    /** @return array<string, mixed> */
    public function build(?Carbon $at = null): array
    {
        $now = $at ?? now();
        $out = ['now' => $now->toIso8601String()];

        foreach (Campaigns::live($this->ctx->id(), $now) as $presentation => $campaign) {
            $heavy = $campaign === null ? null : $this->heavy($campaign);
            /*
             * A LIVE CAMPAIGN WITH NOTHING TO SHOW IS NOT SHOWN (item 40, decided 2026-11-05). A campaign inside its
             * dates whose products are all hidden, deleted or not this storefront's has `count` 0. Its home slider was
             * already left out for that, but the header switch, the Sale panel's entry and the campaign's page were
             * drawn from "a campaign is live" alone — a switch that led to a page with a countdown and no product.
             * It goes out as null, exactly as a campaign outside its dates does, so every surface treats the two the
             * same; the moment one of its products is showable again it is back, by itself.
             */
            $out[$presentation] = $campaign === null || $heavy === null || $heavy['count'] === 0 ? null : [
                'id' => $campaign['id'],
                'presentation' => $campaign['presentation'],
                'name' => $campaign['name'],
                'starts_at' => Carbon::parse($campaign['starts_at'])->toIso8601String(),
                'ends_at' => Carbon::parse($campaign['ends_at'])->toIso8601String(),
            ] + $heavy;
        }

        // The next scheduled START on this storefront, or null: a storefront app that holds this answer drops its
        // copy at that second, so a sale is on every page from its first second (Campaigns::nextStart).
        $next = Campaigns::nextStart($this->ctx->id(), $now);
        $out['next_start_at'] = $next === null ? null : Carbon::parse($next)->toIso8601String();

        return $out;
    }

    /**
     * The ids of the live campaign of a presentation that this storefront shows — what the listing's
     * `campaign` filter narrows to. Null when none is live: the filter then matches nothing.
     *
     * @return list<int>|null
     */
    public function liveProductIds(string $presentation): ?array
    {
        $campaign = Campaigns::liveOne($this->ctx->id(), $presentation);

        return $campaign === null ? null : Campaigns::productIds($campaign['id']);
    }

    /**
     * @param  array{id: int, ends_at: string}  $campaign
     * @return array{count: int, max_discount: int, cards: list<array<string, mixed>>, progress: array<int, array{start: int, sold: int, left: int, pct: int|null, state: string}>, product_ids: list<int>}
     */
    private function heavy(array $campaign): array
    {
        /** @var array{count: int, max_discount: int, cards: list<array<string, mixed>>, progress: array<int, array{start: int, sold: int, left: int, pct: int|null, state: string}>, product_ids: list<int>} $heavy */
        $heavy = $this->cache->remember(
            $this->ctx->id(),
            'campaign',
            $campaign['id'].':'.$this->ctx->locale,
            self::TTL,
            function () use ($campaign): array {
                $ids = Campaigns::productIds($campaign['id']);
                if ($ids === []) {
                    return ['count' => 0, 'max_discount' => 0, 'cards' => [], 'progress' => [], 'product_ids' => []];
                }

                // Only what THIS storefront shows: `base()` is the listing's own rule (visible, active, not deleted).
                $byId = [];
                foreach ($this->cards->build($this->cards->base()->whereIn('sp.product_id', $ids)->select(ProductCards::columns())->get()->all()) as $card) {
                    $byId[Val::int($card, 'id')] = $card;
                }

                $measured = Campaigns::progress($campaign);
                $progress = [];
                $cards = [];
                $maxDiscount = 0;
                foreach ($ids as $id) {
                    $card = $byId[$id] ?? null;
                    if ($card === null) {
                        continue;
                    }
                    $price = is_array($card['price'] ?? null) ? $card['price'] : [];
                    $maxDiscount = max($maxDiscount, is_int($price['discount_pct'] ?? null) ? $price['discount_pct'] : 0);

                    $bar = null;
                    if (isset($measured[$id])) {
                        $stock = is_array($card['stock'] ?? null) ? $card['stock'] : [];
                        $bar = self::bar(
                            $measured[$id]['start'],
                            $measured[$id]['sold'],
                            max(0, (is_int($stock['express'] ?? null) ? $stock['express'] : 0) + (is_int($stock['market'] ?? null) ? $stock['market'] : 0)),
                            ($stock['in_stock'] ?? true) !== false,
                        );
                        $progress[$id] = $bar;
                    }
                    $cards[] = $card + ['campaign' => $bar];
                }

                return [
                    'count' => count($cards),
                    'max_discount' => $maxDiscount,
                    'cards' => array_slice($cards, 0, self::SLIDER),
                    'progress' => $progress,
                    // EVERY product of the campaign this storefront shows (ids only; item 41, 2026-11-05): a listing
                    // card and a product page ask "is this product in the live offer?" of this list — the slider's
                    // cards are only the first twelve.
                    'product_ids' => array_map(fn (array $card): int => Val::int($card, 'id'), $cards),
                ];
            },
        );

        return $heavy;
    }

    /**
     * WHAT A CARD'S BAR SAYS — one rule, so the bar and the stock label can never contradict each other
     * (the developer's review, 2026-11-05: a card read "50% sold" and "Sold out" at once).
     *
     * Three real numbers: `start`, the units on the shelf when the campaign was activated (the snapshot);
     * `sold`, the units the stock ledger says were sold since then; `left`, the units a shopper can buy on
     * this storefront NOW (0 when the product is not in stock here, whatever the shelf count says).
     *
     *   selling      left > 0                 the bar is sold / start, never more than 99 % while a unit can
     *                                         still be bought; the label is "N left"
     *   sold_out     left = 0, sold ≥ start   every unit of the campaign was SOLD: the bar is full, "Sold out"
     *   unavailable  left = 0, sold < start   the stock reached zero some other way — an adjustment, a return
     *                                         to the supplier, another channel — not by this deal's sales.
     *                                         `pct` is NULL: the card says "Sold out" and draws NO bar, rather
     *                                         than a share the deal did not sell.
     *
     * @return array{start: int, sold: int, left: int, pct: int|null, state: string}
     */
    public static function bar(int $start, int $sold, int $units, bool $inStock): array
    {
        $left = $inStock ? max(0, $units) : 0;
        $sold = max(0, $sold);
        if ($left > 0) {
            return ['start' => $start, 'sold' => $sold, 'left' => $left, 'pct' => min(99, (int) round($sold / max(1, $start) * 100)), 'state' => 'selling'];
        }
        if ($sold >= $start) {
            return ['start' => $start, 'sold' => $sold, 'left' => 0, 'pct' => 100, 'state' => 'sold_out'];
        }

        return ['start' => $start, 'sold' => $sold, 'left' => 0, 'pct' => null, 'state' => 'unavailable'];
    }
}
