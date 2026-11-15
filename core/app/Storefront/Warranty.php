<?php

namespace App\Storefront;

/**
 * THE WARRANTY A PRODUCT CARRIES (SEO Phase 1, Michael 2026-11-14) — one rule, read by the v2 product detail and by the
 * compat catalogue and detail alike, so the two storefronts can never state two different lengths for one watch.
 *
 *  1. the product's OWN `warranty_years` wins, whatever it is — 0 included (data entry's "no warranty");
 *  2. else, a WATCH carries its brand's `default_warranty_years`;
 *  3. else, a watch carries the shop's default (config catalog.warranty.watch_default_years, 1 year);
 *  4. anything that is not a watch states no warranty unless it records its own.
 *
 * The brand default is read beside the product row (a correlated sub-select on the brand, `brand_warranty_years`), so
 * no reader adds a query.
 */
final class Warranty
{
    /** The sub-select every product read adds: the brand's default, as `brand_warranty_years`. */
    public const BRAND_SUBSELECT = '(SELECT wb.default_warranty_years FROM catalog_brands wb WHERE wb.id = p.brand_id) AS brand_warranty_years, '
        .'(SELECT wb2.warranty_by_store FROM catalog_brands wb2 WHERE wb2.id = p.brand_id) AS brand_warranty_by_store';

    public const PROVIDERS = ['agent', 'store'];

    public static function years(?int $own, ?int $brandDefault, ?string $family): ?int
    {
        if ($own !== null) {
            return $own;
        }
        if ($family !== 'watch') {
            return null;
        }

        return $brandDefault ?? config()->integer('catalog.warranty.watch_default_years', 1);
    }

    /**
     * WHO GIVES IT (Michael, 2026-11-14): 'agent' (the brand's agent) or 'store' (the shop's own) — or null when the
     * product carries no warranty, or is not a watch and records no provider of its own.
     *
     *  1. a product the hide-from-search rule matches (`hidden` or `restricted`) is ALWAYS 'store' — never the agent's;
     *  2. else the product's own `warranty_provider` wins;
     *  3. else a WATCH follows its brand: `warranty_by_store` → 'store', otherwise 'agent' (the client: most watches);
     *  4. anything else states no provider.
     * Never names an agent company — the storefronts say "the brand agent's warranty" or "the store's warranty".
     */
    public static function provider(?string $own, ?bool $brandByStore, ?string $family, ?string $exposure, ?int $years): ?string
    {
        if ($years === null || $years <= 0) {
            return null;
        }
        if ($exposure === 'hidden' || $exposure === 'restricted') {
            return 'store';
        }
        if ($own !== null && in_array($own, self::PROVIDERS, true)) {
            return $own;
        }
        if ($family !== 'watch') {
            return null;
        }

        return $brandByStore === true ? 'store' : 'agent';
    }
}
