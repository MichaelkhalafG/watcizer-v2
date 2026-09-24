<?php

namespace App\Compat;

use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;

/**
 * One wiring point for the compat builders (storefront 1, shared caches). Controllers resolve
 * this through the container; nothing else constructs the builders.
 */
final class CompatServices
{
    public readonly int $storefrontId;

    public readonly CompatNames $names;

    public readonly CompatCategories $categories;

    public readonly CompatProducts $products;

    public readonly CompatCatalog $catalog;

    public readonly CompatMeta $meta;

    public readonly CompatProductDetail $detail;

    public readonly CompatSitemap $sitemap;

    public readonly CompatCart $cart;

    public readonly CompatCheckout $checkout;

    public readonly CompatAccount $account;

    public function __construct(StorefrontCache $cache, InventoryService $inventory, CompatStorefront $storefront)
    {
        /*
         * ── The single door (review 🔴-4) ────────────────────────────────────────────────────
         *
         * This was `config('compat.storefront_id')` — one constant for the whole live surface, so
         * every cart, order, stock movement and promotion evaluation was attributed to Watchizer
         * whichever shop the customer was in. Every builder below takes its storefront from this one
         * line, which is why the defect was everywhere at once and why correcting it here corrects
         * all of them. See `CompatStorefront` for how the identity is resolved and what it assumes.
         */
        $this->storefrontId = $storefront->id();
        $this->names = new CompatNames($cache, $this->storefrontId);
        $this->categories = new CompatCategories($this->storefrontId);
        $this->products = new CompatProducts($this->storefrontId);
        $this->catalog = new CompatCatalog($cache, $this->names, $this->categories, $this->products, $this->storefrontId);
        $this->meta = new CompatMeta($cache, $this->names, $this->categories, $this->storefrontId);
        $this->detail = new CompatProductDetail($this->names, $this->categories, $this->products, $this->storefrontId);
        $this->sitemap = new CompatSitemap($this->names, $this->categories, $this->products);
        $this->cart = new CompatCart($this->storefrontId);
        $this->checkout = new CompatCheckout($this->cart, $inventory, $this->storefrontId);
        $this->account = new CompatAccount;
    }
}
