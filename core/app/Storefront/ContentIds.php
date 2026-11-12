<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The id a product carries in a storefront's ad catalogues — the Meta pixel's `content_ids`, the Conversions API's
 * Purchase, the Meta feed's `id` and the Google feed's `id` — so that all four ALWAYS agree (Block C, 2026-11-12).
 *
 * ── Watchizer ───────────────────────────────────────────────────────────────────────────────────────
 *
 * The product's own id, as before. Nothing changes.
 *
 * ── Brand Fashion: the old site's ids, for continuity ───────────────────────────────────────────────
 *
 * The old WordPress shop sent its WooCommerce product id as the pixel's content id (PixelYourSite,
 * `woo_content_id = product_id`, no prefix or suffix; pixel 1594687941022756) and its Google Merchant Center items as
 * `gla_<WooCommerce id>` (Google Listings & Ads, `online:en:EG:gla_875`). A product that IS one of those old products
 * keeps that id, so the ad catalogues, audiences and history built on it keep working after the switch:
 *
 *   • `import_ref = bfwp:<id>` — imported from the old site by `bf:legacy-import`;
 *   • `import_ref = woo:<id>`  — the September sheet copy of that old product, when no imported product holds the same
 *                                id (the 32 archived sheet copies have imported twins: the import wins).
 *
 * Every other Brand Fashion product is `p<id>` (Google `p<id>` too). **Decided overnight — Michael to confirm:** the
 * bare product id is NOT used, because the two id spaces overlap — measured on the rehearsal copy: 288 products whose
 * own id equals some old WooCommerce id (old ids run 875 – 66,800, core's up to 33,944 and growing), i.e. two products
 * sharing one content id in the catalogue. A letter can never equal an old numeric id.
 */
final class ContentIds
{
    /** The storefronts whose catalogue ids continue an old shop's (by id). */
    public const LEGACY = [Storefront::BRAND_FASHION_ID];

    /**
     * product id => the content id (pixel, CAPI, Meta feed).
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public static function map(int $storefrontId, array $productIds): array
    {
        $out = [];
        $legacy = in_array($storefrontId, self::LEGACY, true) ? self::legacy($productIds) : [];
        foreach ($productIds as $id) {
            $out[$id] = isset($legacy[$id]) ? (string) $legacy[$id] : (in_array($storefrontId, self::LEGACY, true) ? 'p'.$id : (string) $id);
        }

        return $out;
    }

    /** One product's content id. */
    public static function of(int $storefrontId, int $productId): string
    {
        return self::map($storefrontId, [$productId])[$productId];
    }

    /**
     * product id => the Google feed's item id: the old `gla_<WooCommerce id>` for an old product, else the content id.
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public static function google(int $storefrontId, array $productIds): array
    {
        $out = [];
        $legacy = in_array($storefrontId, self::LEGACY, true) ? self::legacy($productIds) : [];
        $content = self::map($storefrontId, $productIds);
        foreach ($productIds as $id) {
            $out[$id] = isset($legacy[$id]) ? 'gla_'.$legacy[$id] : $content[$id];
        }

        return $out;
    }

    /**
     * product id => its old WooCommerce id, for the products that carry one (rules above).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private static function legacy(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $refs = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            foreach (DB::table('catalog_products')->whereIn('id', $chunk)->where(fn (Builder $q): Builder => $q->where('import_ref', 'like', 'bfwp:%')->orWhere('import_ref', 'like', 'woo:%'))
                ->get(['id', 'import_ref']) as $raw) {
                $r = Row::cast($raw);
                if (preg_match('/^(bfwp|woo):(\d+)$/', Row::str($r, 'import_ref'), $m) === 1) {
                    $refs[Row::int($r, 'id')] = [$m[1], (int) $m[2]];
                }
            }
        }
        // a sheet copy's old id is its own only when no IMPORTED product holds the same old id
        $wooIds = array_values(array_map(fn (array $ref): int => $ref[1], array_filter($refs, fn (array $ref): bool => $ref[0] === 'woo')));
        $imported = [];
        foreach (array_chunk($wooIds, 1000) as $chunk) {
            foreach (DB::table('catalog_products')->whereIn('import_ref', array_map(fn (int $w): string => 'bfwp:'.$w, $chunk))->pluck('import_ref') as $ref) {
                $imported[(int) substr(Coerce::str($ref), 5)] = true;
            }
        }
        $out = [];
        foreach ($refs as $id => [$kind, $old]) {
            if ($kind === 'bfwp' || ! isset($imported[$old])) {
                $out[$id] = $old;
            }
        }

        return $out;
    }
}
