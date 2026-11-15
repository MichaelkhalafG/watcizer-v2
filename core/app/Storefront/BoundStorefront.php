<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Models\Storefront\Storefront;
use Closure;
use RuntimeException;

/**
 * Run code with ONE storefront bound the way a v2 request binds it (the context, its cache tags, the lookups, the
 * category tree and the image host), then put back whatever was bound before — for a command or a compat answer that
 * needs the v2 builders (ProductListing, ProductCards) for a storefront it was not asked through. Moved out of
 * SeoFacts on 2026-11-14 (SEO Phase 3: the articles' live data blocks use it too).
 */
final class BoundStorefront
{
    /**
     * @template T
     *
     * @param  Closure(int): T  $fn  receives the storefront id
     * @return T
     */
    public static function run(string|int $storefront, Closure $fn): mixed
    {
        $model = is_int($storefront)
            ? Storefront::query()->whereKey($storefront)->first()
            : Storefront::query()->where('code', $storefront)->first();
        if (! $model instanceof Storefront) {
            throw new RuntimeException("No storefront {$storefront}.");
        }
        $id = (int) $model->id;

        $classes = [StorefrontContext::class, CacheTags::class, Lookups::class, CategoryTree::class];
        $before = [];
        foreach ($classes as $class) {
            $before[$class] = app()->bound($class) ? app($class) : null;
        }
        $assetBefore = config('storefront.request_asset_base');

        $tags = new CacheTags;
        $tags->storefront($id);
        $context = new StorefrontContext($model, 'en', $tags);
        $cache = app(StorefrontCache::class);
        app()->instance(StorefrontContext::class, $context);
        app()->instance(CacheTags::class, $tags);
        app()->instance(Lookups::class, new Lookups($cache, $context));
        app()->instance(CategoryTree::class, new CategoryTree($cache, $context));
        config(['storefront.request_asset_base' => StorefrontUrls::assetBase($id)]);

        try {
            return $fn($id);
        } finally {
            foreach ($classes as $class) {
                if ($before[$class] === null) {
                    app()->forgetInstance($class);
                } else {
                    app()->instance($class, $before[$class]);
                }
            }
            config(['storefront.request_asset_base' => $assetBefore]);
        }
    }
}
