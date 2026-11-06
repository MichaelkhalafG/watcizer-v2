<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Timed campaigns — Hot Deals and Flash Sale (the twelve updates, Part 2, 2026-11-05).
 *
 * ONE mechanism, two presentations: a campaign is a product selection with a start and an end on one
 * storefront, shown as a home slider, a page of its own and a countdown. Which of the two looks it
 * takes is one column (`presentation`); nothing else differs, and nothing here branches on it.
 *
 * ── When a campaign is LIVE ───────────────────────────────────────────────────────────────────
 *
 * `is_active = 1` AND `starts_at <= now` AND `now < ends_at`. The end is exclusive and it is the REAL
 * end: at that instant the campaign stops being live for every reader, so its products leave the
 * slider and the page and the countdown leaves the header — there is no grace, no rollover and no
 * restart (the brief, 2.3). At most one campaign of a presentation is live on a storefront at a time
 * ({@see CampaignWriter} refuses overlapping active windows), so "the live Hot Deals campaign" is
 * always one row or none.
 *
 * ── The progress bar is REAL or absent ────────────────────────────────────────────────────────
 *
 * "80% sold · only 13 left" needs three numbers per product:
 *
 *   start  the stock the product had when it joined the live campaign — `start_express + start_market`,
 *          recorded ONCE by `CampaignWriter::activate()` (decision D1, 2026-11-04). The database held
 *          no such number before; it is never derived after the fact.
 *   sold   read from the stock LEDGER: units of order lines sold from that moment until the campaign's
 *          end, minus the units those same lines gave back (a cancellation, a failed payment)
 *          — `inventory_movements`, reasons `order` and its releases. Stock is one shared warehouse, so
 *          a sale on the other storefront counts: the units are gone either way, and "start − sold =
 *          left" has to hold for the shopper reading the bar.
 *   left   the live stock, from the card itself.
 *
 * A product with no recorded start (a campaign that was live before this code was deployed, or a
 * product whose start was zero) has NO bar: {@see self::progress()} returns no entry for it and the
 * storefronts render nothing. Nothing is estimated.
 */
final class Campaigns
{
    public const HOT_DEALS = 'hot_deals';

    public const FLASH_SALE = 'flash_sale';

    /** @var list<string> */
    public const PRESENTATIONS = [self::HOT_DEALS, self::FLASH_SALE];

    /** A selection is typed in a picker, one product at a time; this is a ceiling against a runaway paste. */
    public const MAX_PRODUCTS = 300;

    /** Where a campaign stands at an instant — for the dashboard list, which shows every campaign. */
    public const STATE_OFF = 'off';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_LIVE = 'live';

    public const STATE_ENDED = 'ended';

    /**
     * The live campaign of each presentation on a storefront — at most one each.
     *
     * @return array<string, array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}|null>
     */
    public static function live(int $storefrontId, ?Carbon $at = null): array
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');
        $out = array_fill_keys(self::PRESENTATIONS, null);

        foreach (
            DB::table('storefront_campaigns')
                ->where('storefront_id', $storefrontId)->where('is_active', 1)
                ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
                // The writer refuses two live campaigns of one presentation; if rows written some other
                // way ever overlap, the one that started LAST wins and the answer is still one row.
                ->orderBy('starts_at')->orderBy('id')
                ->get(['id', 'storefront_id', 'name', 'presentation', 'starts_at', 'ends_at', 'activated_at']) as $raw
        ) {
            $row = self::row($raw);
            if (array_key_exists($row['presentation'], $out)) {
                $out[$row['presentation']] = $row;
            }
        }

        return $out;
    }

    /**
     * The next instant a campaign of this storefront STARTS: the earliest `starts_at` after `$at` among its
     * active campaigns, either presentation — null when none is scheduled (the follow-up of 2026-11-06).
     *
     * The storefront apps hold the campaigns answer for a few seconds so a page does not wait for it. An END
     * they can see coming (it is in the answer); a START they could not, so a sale used to reach a page with
     * the next poll. With this instant they drop their copy at that second and ask again. A campaign that is
     * switched off is not a start; one that is switched on later is found by the next poll, as before.
     */
    public static function nextStart(int $storefrontId, ?Carbon $at = null): ?string
    {
        $next = DB::table('storefront_campaigns')
            ->where('storefront_id', $storefrontId)->where('is_active', 1)
            ->where('starts_at', '>', ($at ?? now())->format('Y-m-d H:i:s'))
            ->whereColumn('ends_at', '>', 'starts_at')
            ->min('starts_at');

        return is_string($next) ? $next : null;
    }

    /** @return array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}|null */
    public static function liveOne(int $storefrontId, string $presentation, ?Carbon $at = null): ?array
    {
        return self::live($storefrontId, $at)[$presentation] ?? null;
    }

    /**
     * The campaign's products, in the team's order.
     *
     * @return list<int>
     */
    public static function productIds(int $campaignId): array
    {
        $ids = [];
        foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->orderBy('position')->orderBy('id')->pluck('product_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Start and sold, per product that HAS a recorded start above zero. No entry = no bar.
     *
     * `left` is not here: it is the live stock, which the storefront card already carries, and reading it
     * a second time would let the two disagree for the length of a cache.
     *
     * @param  array{id: int, ends_at: string}  $campaign
     * @return array<int, array{start: int, sold: int}>
     */
    public static function progress(array $campaign): array
    {
        $start = [];
        foreach (
            DB::table('storefront_campaign_products')->where('campaign_id', $campaign['id'])->whereNotNull('snapshot_at')
                ->get(['product_id', 'start_express', 'start_market']) as $raw
        ) {
            $total = (Row::nint($raw, 'start_express') ?? 0) + (Row::nint($raw, 'start_market') ?? 0);
            if ($total > 0) {
                $start[Row::int($raw, 'product_id')] = $total;
            }
        }
        if ($start === []) {
            return [];
        }

        /*
         * Sold = the units of every ORDER LINE whose sale (`reason = order`) was written AFTER the product's
         * snapshot — after that position in the ledger, `start_movement_id`, so a sale in the same second as
         * the snapshot falls on exactly one side of it — and BEFORE the campaign's end, net of whatever that
         * same line gave back or took again later:
         * `order` −q, `order_cancel` / `payment_failed` / `reinstate_cancel` +q, `order_reinstate` −q.
         * Summing the signed deltas of the line and negating gives the units that are really gone.
         * A gift line (`promotion_reward`) is not a sale and never joins: `s.reason` is `order` only.
         */
        $sold = [];
        foreach (
            DB::table('storefront_campaign_products as cp')
                ->join('inventory_movements as s', function (JoinClause $join): void {
                    $join->on('s.product_id', '=', 'cp.product_id')
                        ->where('s.reference_type', '=', 'orders')->where('s.reason', '=', 'order')
                        ->whereColumn('s.id', '>', 'cp.start_movement_id');
                })
                ->join('inventory_movements as m', function (JoinClause $join): void {
                    $join->on('m.reference_id', '=', 's.reference_id')
                        ->on('m.reference_line_id', '=', 's.reference_line_id')
                        ->where('m.reference_type', '=', 'orders')
                        ->whereIn('m.reason', ['order', 'order_cancel', 'payment_failed', 'order_reinstate', 'reinstate_cancel']);
                })
                ->where('cp.campaign_id', $campaign['id'])->whereNotNull('cp.snapshot_at')
                ->where('s.created_at', '<', $campaign['ends_at'])
                ->groupBy('cp.product_id')
                ->selectRaw('cp.product_id, -SUM(m.quantity_delta) AS sold')
                ->get() as $raw
        ) {
            $sold[Row::int($raw, 'product_id')] = max(0, (int) (Row::nfloat($raw, 'sold') ?? 0.0));
        }

        $out = [];
        foreach ($start as $productId => $total) {
            $out[$productId] = ['start' => $total, 'sold' => $sold[$productId] ?? 0];
        }

        return $out;
    }

    /** off | scheduled | live | ended — what the dashboard list says about a row. */
    public static function state(bool $active, string $startsAt, string $endsAt, ?Carbon $at = null): string
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');

        return match (true) {
            $endsAt <= $now => self::STATE_ENDED,
            ! $active => self::STATE_OFF,
            $startsAt > $now => self::STATE_SCHEDULED,
            default => self::STATE_LIVE,
        };
    }

    /**
     * @return array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}
     */
    private static function row(object $raw): array
    {
        $raw = Row::cast($raw);

        return [
            'id' => Row::int($raw, 'id'),
            'storefront_id' => Row::int($raw, 'storefront_id'),
            'name' => Row::str($raw, 'name'),
            'presentation' => Row::str($raw, 'presentation'),
            'starts_at' => Row::str($raw, 'starts_at'),
            'ends_at' => Row::str($raw, 'ends_at'),
            'activated_at' => Row::nstr($raw, 'activated_at'),
        ];
    }
}
