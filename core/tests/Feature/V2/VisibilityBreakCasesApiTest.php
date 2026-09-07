<?php

use Database\Seeders\StorefrontSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\getJson;

/*
 * The four visibility break-cases of CategoryVisibilityTest, applied at the API level
 * (study §3.3): when the last visible product of a node disappears, the node leaves the tree,
 * the meta and the sitemap, the category listing 404s and the product detail 404s.
 */

beforeEach(fn () => H::flush());

function apiNodeVisible(string $path): bool
{
    $paths = H::treePaths(getJson(H::base('categories'))->assertOk()->json('tree'));
    $inMeta = in_array($path, H::treePaths(getJson(H::base('meta'))->assertOk()->json('tree')), true);
    $inSitemap = str_contains((string) getJson(H::base('sitemaps/ar/categories.xml'))->assertOk()->getContent(), '/c/'.$path.'<');
    $listing = getJson(H::base('products?category='.$path))->getStatusCode();
    $inTree = in_array($path, $paths, true);
    expect($inMeta)->toBe($inTree)->and($inSitemap)->toBe($inTree)->and($listing)->toBe($inTree ? 200 : 404);

    return $inTree;
}

function breakCase(string $name, Closure $mutate): void
{
    $node = H::smallSubtree();
    $ids = $node['products'];
    $slug = H::str(DB::table('storefront_product')->where('storefront_id', 1)->whereIn('product_id', $ids)->orderBy('product_id')->value('slug'));
    expect(apiNodeVisible($node['path']))->toBeTrue("[$name] node visible before the mutation");
    getJson(H::base('products/'.$slug))->assertOk();

    $mutate($ids);
    H::flush();

    expect(apiNodeVisible($node['path']))->toBeFalse("[$name] node must disappear");
    getJson(H::base('products/'.$slug))->assertNotFound();
}

it('hides the node when its products are made invisible on the storefront', function () {
    breakCase('is_visible', fn (array $ids) => DB::table('storefront_product')->where('storefront_id', 1)->whereIn('product_id', $ids)->update(['is_visible' => 0]));
});

it('hides the node when its products are deactivated', function () {
    breakCase('is_active', fn (array $ids) => DB::table('catalog_products')->whereIn('id', $ids)->update(['is_active' => 0]));
});

it('hides the node when its products are soft-deleted', function () {
    breakCase('deleted_at', fn (array $ids) => DB::table('catalog_products')->whereIn('id', $ids)->update(['deleted_at' => now()]));
});

it('hides the node when its placements belong to another storefront', function () {
    StorefrontSeeder::ensure(['id' => 2, 'code' => 'brand_fashion', 'name' => 'Brand Fashion', 'locales' => ['ar', 'en'], 'default_locale' => 'ar', 'currency' => 'EGP', 'is_active' => true]);
    breakCase('foreign placement', function (array $ids): void {
        $node = H::smallSubtree();
        DB::table('storefront_category_product')->where('storefront_category_id', $node['id'])->whereIn('product_id', $ids)->update(['storefront_id' => 2]);
        // The product stays placed on the storefront's depth-1 node only through the sub node: remove the type placement too.
        DB::table('storefront_category_product')->where('storefront_id', 1)->whereIn('product_id', $ids)->delete();
        DB::table('storefront_product')->where('storefront_id', 1)->whereIn('product_id', $ids)->update(['is_visible' => 0]);
    });
});
