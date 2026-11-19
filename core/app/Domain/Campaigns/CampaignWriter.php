<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes a timed campaign — the one door for the dashboard page and the scheduler
 * (the twelve updates, Part 2, 2026-11-05).
 *
 * ── The rules it holds, so the storefront never has to guess ─────────────────────────────────
 *
 *  • a name, one of the two presentations, a start before an end;
 *  • 1 to {@see Campaigns::MAX_PRODUCTS} DIFFERENT products, each one placed on that storefront
 *    (a product the shop does not carry cannot be put in its campaign);
 *  • **no two ACTIVE campaigns of one presentation may overlap on a storefront** — "the live Flash
 *    Sale" must be one campaign, because the header has one countdown and the page one list. A
 *    campaign that is switched off overlaps nothing.
 *
 * ── The progress bar (2026-11-10) ─────────────────────────────────────────────────────────────
 *
 * Nothing is recorded for it any more: the bar counts from each product's last restock in the stock ledger
 * ({@see Campaigns::sinceRestock()}). The start snapshot this writer used to take when a campaign went live
 * (`start_express`, `start_market`, `start_movement_id`, `snapshot_at`, and the campaign's `activated_at`) is
 * neither written nor read; the columns stay until a later clean-up migration.
 *
 * ── Campaign prices (2026-11-10, {@see CampaignPrices}) ───────────────────────────────────────
 *
 * `prices` (optional: absent = the prices stay as they are) maps a picked product to a campaign PRICE or an EXTRA
 * PERCENTAGE. Refused: a product that is not picked, an unknown mode, a value at or under zero, a percentage above
 * {@see CampaignPrices::MAX_PERCENT}, and a price that would not be LOWER than the product's current price. Asked for
 * (`confirm_prices`), not refused, as the dashboard's price safeguard does: a campaign price under the purchase price,
 * and an extra percentage above {@see CampaignPrices::CONFIRM_PERCENT}. A product without an entry keeps its normal
 * price during the campaign. Every save forgets the cached price windows, so a change is live at once.
 *
 * ── An edit changes only what was edited (2026-11-19, the team's hot-deals report) ────────────────────────────────
 *
 * The confirmation is asked only for a price that is NEW or CHANGED in this save: a price already stored on the
 * campaign was confirmed when it was saved, and asking again for every one of them on each edit put hundreds of
 * names in front of the team and blocked the save. A product added is inserted, a product removed is deleted, and a
 * kept row is written only when its price or its place in the order changed (removing a product shifts no other row;
 * moving one renumbers the list).
 */
final class CampaignWriter
{
    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>, prices?: array<int, array{mode: string, value: float}>, confirm_prices?: bool}  $data
     */
    public static function create(int $storefrontId, array $data, ?int $userId): int
    {
        self::assertValid($storefrontId, null, $data);

        $id = DB::transaction(function () use ($storefrontId, $data, $userId): int {
            $id = (int) DB::table('storefront_campaigns')->insertGetId([
                ...self::columns($data),
                'storefront_id' => $storefrontId,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::syncProducts($id, $data['product_ids'], $data['prices'] ?? null);

            return $id;
        });
        CampaignPrices::forget($storefrontId);

        return $id;
    }

    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>, prices?: array<int, array{mode: string, value: float}>, confirm_prices?: bool}  $data
     */
    public static function update(int $storefrontId, int $campaignId, array $data, ?int $userId): void
    {
        self::assertValid($storefrontId, $campaignId, $data);

        DB::transaction(function () use ($storefrontId, $campaignId, $data, $userId): void {
            DB::table('storefront_campaigns')->where('storefront_id', $storefrontId)->where('id', $campaignId)
                ->update([...self::columns($data), 'updated_by' => $userId, 'updated_at' => now()]);
            self::syncProducts($campaignId, $data['product_ids'], $data['prices'] ?? null);
        });
        CampaignPrices::forget($storefrontId);
    }

    public static function delete(int $storefrontId, int $campaignId): void
    {
        // The products go with it (the foreign key cascades) — and their campaign prices.
        DB::table('storefront_campaigns')->where('storefront_id', $storefrontId)->where('id', $campaignId)->delete();
        CampaignPrices::forget($storefrontId);
    }

    /**
     * A campaign's prices as stored: product id → {mode, value}, for the dashboard and the activity log.
     *
     * @return array<int, array{mode: string, value: float}>
     */
    public static function prices(int $campaignId): array
    {
        $out = [];
        foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->whereNotNull('price_mode')
            ->orderBy('position')->get(['product_id', 'price_mode', 'price_value']) as $raw) {
            $out[Row::int($raw, 'product_id')] = ['mode' => Row::str($raw, 'price_mode'), 'value' => Row::nfloat($raw, 'price_value') ?? 0.0];
        }

        return $out;
    }

    /**
     * One stored price and one asked for are the same rule: the same mode and the same value to the cent.
     *
     * @param  array{mode: string, value: float}|null  $stored
     * @param  array{mode: string, value: float}  $rule
     */
    private static function samePrice(?array $stored, array $rule): bool
    {
        return $stored !== null && $stored['mode'] === $rule['mode'] && self::cents($stored['value']) === self::cents($rule['value']);
    }

    private static function cents(float|string|null $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    /**
     * The picks, in order, written as a DIFFERENCE (2026-11-19): new ones inserted, removed ones deleted, and a kept
     * one written only when its price changed or the order of the kept ones changed. While the kept rows keep their
     * relative order and the new picks come after them, the stored positions stay as they are (a gap left by a removed
     * product orders the same) and a new pick takes a position after the last one; a reorder renumbers. With `$prices`
     * (not null) each pick's campaign price is set to its entry, or cleared.
     *
     * @param  list<int>  $productIds
     * @param  array<int, array{mode: string, value: float}>|null  $prices
     */
    private static function syncProducts(int $campaignId, array $productIds, ?array $prices = null): void
    {
        /** @var array<int, array{id: int, position: int, mode: ?string, value: ?string}> $existing */
        $existing = [];
        foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->orderBy('position')->orderBy('id')
            ->get(['id', 'product_id', 'position', 'price_mode', 'price_value']) as $raw) {
            $existing[Row::int($raw, 'product_id')] = [
                'id' => Row::int($raw, 'id'), 'position' => Row::int($raw, 'position'),
                'mode' => Row::nstr($raw, 'price_mode'), 'value' => self::cents(Row::nstr($raw, 'price_value')),
            ];
        }

        // the kept picks in the order asked, against their stored order; and whether every new pick comes after them
        $kept = array_values(array_filter($productIds, fn (int $id): bool => isset($existing[$id])));
        $storedOrder = array_values(array_filter(array_keys($existing), fn (int $id): bool => in_array($id, $productIds, true)));
        $lastKept = -1;
        $firstNew = null;
        foreach ($productIds as $i => $id) {
            if (isset($existing[$id])) {
                $lastKept = $i;
            } elseif ($firstNew === null) {
                $firstNew = $i;
            }
        }
        $renumber = $kept !== $storedOrder || ($firstNew !== null && $firstNew < $lastKept);
        $next = $existing === [] ? 0 : max(array_column($existing, 'position')) + 1;

        foreach ($productIds as $index => $productId) {
            $mode = $prices !== null && isset($prices[$productId]) ? $prices[$productId]['mode'] : null;
            $value = $prices !== null && isset($prices[$productId]) ? self::cents($prices[$productId]['value']) : null;
            if (isset($existing[$productId])) {
                $row = $existing[$productId];
                $change = [];
                if ($renumber && $row['position'] !== $index) {
                    $change['position'] = $index;
                }
                if ($prices !== null && ($row['mode'] !== $mode || $row['value'] !== $value)) {
                    $change['price_mode'] = $mode;
                    $change['price_value'] = $value;
                }
                if ($change !== []) {
                    DB::table('storefront_campaign_products')->where('id', $row['id'])->update($change + ['updated_at' => now()]);
                }
                unset($existing[$productId]);

                continue;
            }
            DB::table('storefront_campaign_products')->insert([
                'campaign_id' => $campaignId, 'product_id' => $productId, 'position' => $renumber ? $index : $next++,
                'created_at' => now(), 'updated_at' => now(),
            ] + ($prices === null ? [] : ['price_mode' => $mode, 'price_value' => $value]));
        }

        if ($existing !== []) {
            DB::table('storefront_campaign_products')->whereIn('id', array_column($existing, 'id'))->delete();
        }
    }

    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>, prices?: array<int, array{mode: string, value: float}>, confirm_prices?: bool}  $data
     * @return array<string, mixed>
     */
    private static function columns(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'presentation' => $data['presentation'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_active' => $data['is_active'],
        ];
    }

    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>, prices?: array<int, array{mode: string, value: float}>, confirm_prices?: bool}  $data
     */
    private static function assertValid(int $storefrontId, ?int $campaignId, array $data): void
    {
        if (trim($data['name']) === '') {
            throw new InvalidArgumentException('name');
        }
        if (! in_array($data['presentation'], Campaigns::PRESENTATIONS, true)) {
            throw new InvalidArgumentException('presentation');
        }
        if ($data['starts_at'] >= $data['ends_at']) {
            throw new InvalidArgumentException('ends_at');
        }

        $ids = $data['product_ids'];
        if ($ids === [] || count($ids) > Campaigns::MAX_PRODUCTS || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('product_ids');
        }
        $placed = [];
        foreach (DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->whereNull('p.deleted_at')->whereIn('sp.product_id', $ids)->pluck('sp.product_id') as $id) {
            $placed[] = Coerce::int($id);
        }
        $missing = array_values(array_diff($ids, $placed));
        if ($missing !== []) {
            // which ones (2026-11-19): the dashboard names them, the first five and "show all"
            throw new InvalidArgumentException('product_ids.placed:'.implode(',', $missing));
        }

        if ($data['is_active'] && self::overlapping($storefrontId, $campaignId, $data['presentation'], $data['starts_at'], $data['ends_at']) !== null) {
            throw new InvalidArgumentException('overlap');
        }

        self::assertPrices($storefrontId, $ids, $data['prices'] ?? [], $data['confirm_prices'] ?? false, $campaignId === null ? [] : self::prices($campaignId));
    }

    /**
     * The campaign prices' rules (the class docblock). Refusals name the product: `prices.<rule>:<product id>`;
     * the confirmation names every NEW or CHANGED price that needs it: `prices.confirm:<id>,<id>` — a price stored on
     * the campaign as it is (`$stored`) was confirmed when it was saved and is not asked about again.
     *
     * @param  list<int>  $productIds
     * @param  array<int, array{mode: string, value: float}>  $prices
     * @param  array<int, array{mode: string, value: float}>  $stored
     */
    private static function assertPrices(int $storefrontId, array $productIds, array $prices, bool $confirmed, array $stored = []): void
    {
        if ($prices === []) {
            return;
        }
        $facts = [];
        foreach (DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->whereIn('sp.product_id', array_keys($prices))
            ->get(['sp.product_id', 'sp.effective_price', 'sp.effective_sale_price', 'p.purchase_price']) as $raw) {
            $facts[Row::int($raw, 'product_id')] = [
                'price' => (float) Row::money($raw, 'effective_price'),
                'sale' => Row::nmoney($raw, 'effective_sale_price') === null ? null : (float) Row::money($raw, 'effective_sale_price'),
                'purchase' => (float) Row::money($raw, 'purchase_price'),
            ];
        }
        $confirm = [];
        foreach ($prices as $productId => $rule) {
            if (! in_array($productId, $productIds, true) || ! isset($facts[$productId])) {
                throw new InvalidArgumentException('prices.product:'.$productId);
            }
            if (! in_array($rule['mode'], CampaignPrices::MODES, true)) {
                throw new InvalidArgumentException('prices.mode:'.$productId);
            }
            if ($rule['value'] <= 0 || ($rule['mode'] === CampaignPrices::MODE_PERCENT && $rule['value'] > CampaignPrices::MAX_PERCENT)) {
                throw new InvalidArgumentException('prices.value:'.$productId);
            }
            $f = $facts[$productId];
            $campaign = CampaignPrices::campaignPrice($rule['mode'], $rule['value'], $f['price'], $f['sale']);
            if ($campaign === null) {
                throw new InvalidArgumentException('prices.not_lower:'.$productId);
            }
            if (self::samePrice($stored[$productId] ?? null, $rule)) {
                continue;
            }
            if ($campaign < $f['purchase'] || ($rule['mode'] === CampaignPrices::MODE_PERCENT && $rule['value'] > CampaignPrices::CONFIRM_PERCENT)) {
                $confirm[] = $productId;
            }
        }
        if ($confirm !== [] && ! $confirmed) {
            throw new InvalidArgumentException('prices.confirm:'.implode(',', $confirm));
        }
    }

    /** The name of an ACTIVE campaign of this presentation whose window crosses the given one, or null. */
    public static function overlapping(int $storefrontId, ?int $exceptId, string $presentation, string $startsAt, string $endsAt): ?string
    {
        $query = DB::table('storefront_campaigns')
            ->where('storefront_id', $storefrontId)->where('presentation', $presentation)->where('is_active', 1)
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        $name = $query->orderBy('starts_at')->value('name');

        return is_string($name) ? $name : null;
    }
}
