<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Transform\Row;
use Illuminate\Support\Carbon;
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
 * ── The start quantities ({@see self::activate()}) ───────────────────────────────────────────
 *
 * The first time a campaign is found LIVE, each of its products gets its stock at that moment written
 * beside it (`start_express`, `start_market`, `snapshot_at`, and `start_movement_id` — the ledger's
 * last movement at that instant, which is where "sold" starts counting). A product added while the campaign is
 * already live is recorded then, from then. A snapshot is written ONCE and never refreshed: switching
 * the campaign off and on again, or moving its end, keeps the numbers it started with — a bar that
 * resets when somebody edits a date would be the theatre the brief forbids. A new sale is a new
 * campaign.
 *
 * It is called after every save and by `campaigns:activate` on the one-minute cron, which is what
 * records a campaign that was scheduled for a later start.
 */
final class CampaignWriter
{
    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>}  $data
     */
    public static function create(int $storefrontId, array $data, ?int $userId): int
    {
        self::assertValid($storefrontId, null, $data);

        return DB::transaction(function () use ($storefrontId, $data, $userId): int {
            $id = (int) DB::table('storefront_campaigns')->insertGetId([
                ...self::columns($data),
                'storefront_id' => $storefrontId,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::syncProducts($id, $data['product_ids']);
            self::activate($id);

            return $id;
        });
    }

    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>}  $data
     */
    public static function update(int $storefrontId, int $campaignId, array $data, ?int $userId): void
    {
        self::assertValid($storefrontId, $campaignId, $data);

        DB::transaction(function () use ($storefrontId, $campaignId, $data, $userId): void {
            DB::table('storefront_campaigns')->where('storefront_id', $storefrontId)->where('id', $campaignId)
                ->update([...self::columns($data), 'updated_by' => $userId, 'updated_at' => now()]);
            self::syncProducts($campaignId, $data['product_ids']);
            self::activate($campaignId);
        });
    }

    public static function delete(int $storefrontId, int $campaignId): void
    {
        // The products go with it (the foreign key cascades).
        DB::table('storefront_campaigns')->where('storefront_id', $storefrontId)->where('id', $campaignId)->delete();
    }

    /**
     * Record the start quantities of every product of a LIVE campaign that has none yet.
     *
     * Idempotent and cheap when there is nothing to do: one query finds the candidates. Returns how many
     * products were recorded by this call.
     *
     * @param  int|null  $campaignId  one campaign, or null for every live campaign (the scheduler)
     */
    public static function activate(?int $campaignId = null, ?Carbon $at = null): int
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');

        $live = DB::table('storefront_campaigns')->where('is_active', 1)->where('starts_at', '<=', $now)->where('ends_at', '>', $now);
        if ($campaignId !== null) {
            $live->where('id', $campaignId);
        }

        $recorded = 0;
        foreach ($live->pluck('id') as $rawId) {
            $id = is_numeric($rawId) ? (int) $rawId : 0;
            $recorded += DB::transaction(function () use ($id, $now): int {
                /*
                 * The stock a shopper could buy at this moment: the product's own two buckets — for a
                 * product sold through variants they are the maintained SUM of its variants (AGENTS
                 * §2.5), so one read is right for both kinds. Read and written in one statement, so a
                 * sale landing between a read and a write cannot be counted twice or lost.
                 */
                $done = DB::update(
                    'UPDATE storefront_campaign_products cp
                       JOIN catalog_products p ON p.id = cp.product_id
                        SET cp.start_express = GREATEST(p.stock_express, 0),
                            cp.start_market = GREATEST(p.stock_market, 0),
                            cp.start_movement_id = (SELECT COALESCE(MAX(m.id), 0) FROM inventory_movements m),
                            cp.snapshot_at = ?, cp.updated_at = ?
                      WHERE cp.campaign_id = ? AND cp.snapshot_at IS NULL',
                    [$now, $now, $id],
                );
                DB::table('storefront_campaigns')->where('id', $id)->whereNull('activated_at')->update(['activated_at' => $now]);

                return $done;
            });
        }

        return $recorded;
    }

    /**
     * The picks, in order: new ones inserted, removed ones deleted, kept ones re-positioned WITHOUT
     * touching their snapshot.
     *
     * @param  list<int>  $productIds
     */
    private static function syncProducts(int $campaignId, array $productIds): void
    {
        $existing = [];
        foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->get(['id', 'product_id']) as $raw) {
            $existing[Row::int($raw, 'product_id')] = Row::int($raw, 'id');
        }

        foreach ($productIds as $position => $productId) {
            if (isset($existing[$productId])) {
                DB::table('storefront_campaign_products')->where('id', $existing[$productId])->update(['position' => $position, 'updated_at' => now()]);
                unset($existing[$productId]);

                continue;
            }
            DB::table('storefront_campaign_products')->insert([
                'campaign_id' => $campaignId, 'product_id' => $productId, 'position' => $position,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if ($existing !== []) {
            DB::table('storefront_campaign_products')->whereIn('id', array_values($existing))->delete();
        }
    }

    /**
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>}  $data
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
     * @param  array{name: string, presentation: string, starts_at: string, ends_at: string, is_active: bool, product_ids: list<int>}  $data
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
        $placed = DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->whereNull('p.deleted_at')->whereIn('sp.product_id', $ids)->count();
        if ($placed !== count($ids)) {
            throw new InvalidArgumentException('product_ids.placed');
        }

        if ($data['is_active'] && self::overlapping($storefrontId, $campaignId, $data['presentation'], $data['starts_at'], $data['ends_at']) !== null) {
            throw new InvalidArgumentException('overlap');
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
