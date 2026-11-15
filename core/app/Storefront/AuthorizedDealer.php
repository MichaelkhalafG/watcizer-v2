<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Support\Coerce;
use Illuminate\Support\Facades\DB;

/**
 * AUTHORIZED DEALER, PER BRAND AND PER SHOP (Michael, 2026-11-14 — a confirmed fact, never a blanket claim).
 *
 * A shop's list lives in `storefronts.settings.public.authorized_brands` (brand slugs; the storefront screen's switches,
 * all OFF by default; Watchizer's Hugo Boss set ON by the deploy's data step). `public` is what v2 meta sends, so the
 * shops read the same list. Where a brand is on the list its pages, its products, About, the warranty page, llms.txt and
 * its articles may say "authorized dealer" / «وكيل معتمد»; nowhere else — and never on a product the hide-from-search
 * rule matches (hidden or restricted), whatever the list says. No structured data carries the claim.
 */
final class AuthorizedDealer
{
    /** @return list<string> the brand slugs this shop is an authorized dealer for */
    public static function brands(int $storefrontId): array
    {
        $settings = json_decode(Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('settings')), true);
        $list = Coerce::arr(Coerce::arr(Coerce::arr(is_array($settings) ? $settings : [])['public'] ?? null)['authorized_brands'] ?? null);

        return array_values(array_unique(array_filter(array_map(fn ($s): string => Coerce::str($s), $list), fn (string $s): bool => $s !== '')));
    }

    /** May this product say it? Its brand is on the shop's list AND the hide-from-search rule does not match it. */
    public static function forProduct(int $storefrontId, ?string $brandSlug, ?string $exposure): bool
    {
        if ($brandSlug === null || $brandSlug === '' || $exposure === 'hidden' || $exposure === 'restricted') {
            return false;
        }

        return in_array($brandSlug, self::brands($storefrontId), true);
    }

    /** May a text about this brand id (a brand / landing page, an article) say it on this shop? */
    public static function forBrandId(int $storefrontId, ?int $brandId): bool
    {
        if ($brandId === null) {
            return false;
        }
        $slug = DB::table('catalog_brands')->where('id', $brandId)->value('slug');

        return is_string($slug) && in_array($slug, self::brands($storefrontId), true);
    }
}
