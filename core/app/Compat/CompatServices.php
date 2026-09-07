<?php

namespace App\Compat;

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

    public function __construct(StorefrontCache $cache)
    {
        $this->storefrontId = config()->integer('compat.storefront_id');
        $this->names = new CompatNames($cache, $this->storefrontId);
        $this->categories = new CompatCategories($this->storefrontId);
        $this->products = new CompatProducts($this->storefrontId);
        $this->catalog = new CompatCatalog($cache, $this->names, $this->categories, $this->products, $this->storefrontId);
        $this->meta = new CompatMeta($cache, $this->names, $this->categories, $this->storefrontId);
        $this->detail = new CompatProductDetail($this->names, $this->categories, $this->products, $this->storefrontId);
        $this->sitemap = new CompatSitemap($this->names, $this->categories, $this->products);
    }
}
