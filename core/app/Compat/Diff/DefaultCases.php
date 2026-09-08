<?php

namespace App\Compat\Diff;

use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The standard case list (CLEAN_CORE_STUDY §8.5 item 2, widened after the wave-2 review):
 * every compat endpoint with realistic AND hostile parameter sets — Accept-Language variants
 * (the legacy locale quirk), Accept variants (axios default, native fetch `*\/*`, none),
 * CORS probes from the storefront origin and a foreign one (GET + preflight), twenty product
 * ids incl. the A-17 twins and the gallery sort ties, loosely-coerced ids (`4.0`, `04`, `4abc`,
 * `4.5`, `abc`, overflow), five by-name lookups incl. a miss, unauthenticated calls, the 410
 * list, proxied paths (allowed + refused), and the sitemap paths.
 *
 * Ids are resolved from the local database when none are given (`--ids=`), so the same list
 * can be replayed against staging/production URLs where the harness has no database.
 */
final class DefaultCases
{
    public const STOREFRONT_ORIGIN = 'https://watchizereg.com';

    public const FOREIGN_ORIGIN = 'https://evil.example.com';

    /**
     * @param  list<int>|null  $productIds
     * @param  list<string>|null  $names
     * @return list<DiffCase>
     */
    public static function build(?array $productIds = null, ?array $names = null): array
    {
        $productIds ??= self::productIds();
        $names ??= self::names();
        $first = $productIds[0] ?? 1;
        $ar = ['Accept-Language' => 'ar-EG,ar;q=0.9,en-US;q=0.8,en;q=0.7'];
        $fr = ['Accept-Language' => 'fr-FR,fr;q=0.9'];
        $any = '*/*';

        $cases = [
            new DiffCase('meta', 'api/catalog/meta'),
            new DiffCase('meta:ar', 'api/catalog/meta', 'json', $ar),
            new DiffCase('meta:fr', 'api/catalog/meta', 'json', $fr),
            new DiffCase('meta:no-accept', 'api/catalog/meta', 'json', [], true, 'compat', null),
            new DiffCase('meta:any-accept', 'api/catalog/meta', 'json', [], true, 'compat', $any),
            new DiffCase('all_product', 'api/all_product'),
            new DiffCase('all_product:ar', 'api/all_product', 'json', $ar),
            new DiffCase('all_product:no-accept', 'api/all_product', 'json', [], true, 'compat', null),
            new DiffCase('all_product_image', 'api/all_product_image'),
            new DiffCase('all_product_rating', 'api/all_product_rating'),
            new DiffCase('show_shipping_city', 'api/show_shipping_city'),
            new DiffCase('show_shipping_city:ar', 'api/show_shipping_city', 'json', $ar),
        ];
        foreach ($productIds as $id) {
            $cases[] = new DiffCase("product:{$id}", "api/products/{$id}");
        }
        $cases[] = new DiffCase("product:ar:{$first}", "api/products/{$first}", 'json', $ar);
        $cases[] = new DiffCase("product:no-accept:{$first}", "api/products/{$first}", 'json', [], true, 'compat', null);
        $cases[] = new DiffCase("product:any-accept:{$first}", "api/products/{$first}", 'json', [], true, 'compat', $any);
        // Loose numeric coercion of the id segment (review 🟠-4): MySQL string→number rules on the legacy side.
        foreach (["{$first}.0", '0'.$first, "{$first}abc", "{$first}.5", 'abc', '99999999999999999999', '-'.$first, '0'] as $hostile) {
            $cases[] = new DiffCase("product:hostile:{$hostile}", 'api/products/'.rawurlencode($hostile), 'json', [], true, 'hostile');
        }
        $cases[] = new DiffCase('product:404', 'api/products/999999');
        $cases[] = new DiffCase('product:404:no-accept', 'api/products/999999', 'json', [], true, 'compat', null);
        $cases[] = new DiffCase('product:404:any-accept', 'api/products/999999', 'json', [], true, 'compat', $any);
        foreach ($names as $i => $name) {
            $cases[] = new DiffCase('product:by-name:'.($i + 1), 'api/products/by-name/'.rawurlencode($name));
        }
        $cases[] = new DiffCase('product:by-name:404', 'api/products/by-name/'.rawurlencode('does not exist at all'));
        $cases[] = new DiffCase('product:by-name:404:unrouted', 'api/products/by-name/'.rawurlencode('does not exist ¯\_(ツ)_/¯'));
        $cases[] = new DiffCase('unauthenticated:all_product', 'api/all_product', 'status', [], false);
        $cases[] = new DiffCase('unauthenticated:meta', 'api/catalog/meta', 'status', [], false);
        foreach (['all_brand', 'all_sub_type', 'products?per_page=2', 'categories/main', 'new_colors'] as $gone) {
            $cases[] = new DiffCase("gone:{$gone}", 'api/'.$gone, 'status', [], ! str_starts_with($gone, 'categories'), 'gone');
        }
        // CORS (review 🔴-1): the storefront origin must be allowed, a foreign one must not — on a moved, a proxied and a preflighted path.
        $sf = ['Origin' => self::STOREFRONT_ORIGIN];
        $evil = ['Origin' => self::FOREIGN_ORIGIN];
        $preflight = ['Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'api-code'];
        $cases[] = new DiffCase('meta:cors:storefront-origin', 'api/catalog/meta', 'json', $sf, true, 'cors');
        $cases[] = new DiffCase('meta:cors:foreign-origin', 'api/catalog/meta', 'json', $evil, true, 'cors');
        $cases[] = new DiffCase('proxy:all_offer:cors:storefront-origin', 'api/all_offer', 'json', $sf, true, 'cors');
        $cases[] = new DiffCase('proxy:all_offer:cors:foreign-origin', 'api/all_offer', 'json', $evil, true, 'cors');
        $cases[] = new DiffCase('cors:preflight:storefront-origin', 'api/all_product', 'status', $sf + $preflight, false, 'cors', null, 'OPTIONS');
        $cases[] = new DiffCase('cors:preflight:foreign-origin', 'api/all_product', 'status', $evil + $preflight, false, 'cors', null, 'OPTIONS');
        $cases[] = new DiffCase('cors:preflight:proxied:storefront-origin', 'api/add_to_cart', 'status', $sf + ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'api-code,content-type'], false, 'cors', null, 'OPTIONS');
        // Proxy: allowed rows pass through; anything else is refused here (review 🟡-8).
        $cases[] = new DiffCase('proxy:all_wishlist', 'api/all_wishlist', 'json', [], true, 'proxy');
        $cases[] = new DiffCase('proxy:all_offer', 'api/all_offer', 'json', [], true, 'proxy');
        $cases[] = new DiffCase('proxy:all_blog', 'api/all_blog', 'json', [], true, 'proxy');
        $cases[] = new DiffCase('proxy:all_banner_home', 'api/all_banner_home', 'json', [], true, 'proxy');
        $cases[] = new DiffCase('proxy:refused:404:unrouted', 'api/definitely/not/a/route', 'status', [], true, 'proxy');
        $cases[] = new DiffCase('sitemap:en', 'en/sitemap.xml', 'xml', [], false, 'compat', null);
        $cases[] = new DiffCase('sitemap:ar', 'ar/sitemap.xml', 'xml', [], false, 'compat', null);
        $cases[] = new DiffCase('sitemap:redirect', 'sitemap.xml', 'redirect', [], false, 'compat', null);
        $cases[] = new DiffCase('sitemap:redirect:ar', 'sitemap.xml', 'redirect', $ar, false, 'compat', null);

        return $cases;
    }

    /**
     * Twenty ids: the first eight, every A-17 twin, products with gallery sort ties, the last id.
     *
     * @return list<int>
     */
    public static function productIds(): array
    {
        $ids = [];
        foreach (DB::table('catalog_products')->select('id')->orderBy('id')->limit(8)->get() as $r) {
            $ids[] = Row::int($r, 'id');
        }
        $twins = DB::table('catalog_product_translations')->selectRaw('title, GROUP_CONCAT(product_id ORDER BY product_id) AS ids')->where('locale', 'en')->groupBy('title')->havingRaw('COUNT(*) > 1')->get();
        foreach ($twins as $t) {
            foreach (explode(',', Row::str($t, 'ids')) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ties = DB::table('catalog_product_images')->selectRaw('product_id')->where('is_cover', 0)->groupBy('product_id', 'sort')->havingRaw('COUNT(*) > 1')->limit(4)->get();
        foreach ($ties as $t) {
            $ids[] = Row::int($t, 'product_id');
        }
        $last = DB::table('catalog_products')->max('id');
        if (is_int($last)) {
            $ids[] = $last;
        }

        return array_values(array_unique(array_slice(array_values(array_unique($ids)), 0, 20)));
    }

    /**
     * Five English titles: a twin title, one with a special character, two ordinary ones, an Arabic-only miss.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];
        $twin = DB::table('catalog_product_translations')->selectRaw('title')->where('locale', 'en')->groupBy('title')->havingRaw('COUNT(*) > 1')->orderBy('title')->value('title');
        if (is_string($twin)) {
            $names[] = $twin;
        }
        $special = DB::table('catalog_product_translations')->where('locale', 'en')->where(function (Builder $q): void {
            $q->where('title', 'like', '%&%')->orWhere('title', 'like', '%?%')->orWhere('title', 'like', "%'%");
        })->orderBy('product_id')->value('title');
        if (is_string($special)) {
            $names[] = $special;
        }
        foreach (DB::table('catalog_product_translations')->select('title')->where('locale', 'en')->orderByDesc('product_id')->limit(2)->get() as $r) {
            $names[] = Row::str($r, 'title');
        }
        $arabic = DB::table('catalog_product_translations')->select('title')->where('locale', 'ar')->orderBy('product_id')->value('title');
        if (is_string($arabic)) {
            $names[] = $arabic;                                    // by-name matches EN only → 404 on both
        }

        return array_values(array_unique($names));
    }
}
