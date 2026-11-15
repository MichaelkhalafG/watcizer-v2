<?php

use App\Domain\Catalog\SearchExposure;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * WHAT SEARCH ENGINES AND AI ARE SHOWN OF A PRODUCT (Michael, 2026-11-14). The products stay on sale as they are; a
 * product the rule matches is HIDDEN from search (noindex, out of the sitemaps, the feeds, /llms.txt) — except a
 * matched Rolex, which is RESTRICTED (indexable, but no brand or authenticity claim, out of lists and feeds). The
 * dashboard's switch always wins, both ways.
 */

const EXPOSURE_BRANDS = ['rolex', 'tommy hilfiger', 'michael kors', 'prada'];

/** A product of the brand with that slug (made when the copy has none), titled and priced as given. */
function exposureProduct(string $brandSlug, float $price, string $en, string $ar = 'منتج'): int
{
    $brand = DB::table('catalog_brands')->where('slug', $brandSlug)->value('id');
    if ($brand === null) {
        $brand = DB::table('catalog_brands')->insertGetId(['slug' => $brandSlug, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_brand_translations')->insert([
            ['brand_id' => $brand, 'locale' => 'en', 'name' => ucwords(str_replace('-', ' ', $brandSlug))],
            ['brand_id' => $brand, 'locale' => 'ar', 'name' => 'ماركة '.$brandSlug],
        ]);
    }
    $id = CatalogFixture::product('watch', $price);
    DB::table('catalog_products')->where('id', $id)->update(['brand_id' => T::int($brand)]);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => $en]);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->update(['title' => $ar]);
    app(SearchExposure::class)->refresh($id);

    return $id;
}

function exposureClass(int $id): ?string
{
    $v = DB::table('catalog_products')->where('id', $id)->value('search_class');

    return is_string($v) ? $v : null;
}

it('matches copy words, "inspired by" a brand, Style on a maison and maisons below the line — and nothing else', function () {
    $reasons = fn (string $brand, float $price, string $en, string $ar = ''): array => SearchExposure::reasons($brand, $price, $en, $ar, EXPOSURE_BRANDS);

    expect($reasons('generic', 900, 'Mirror quality chronograph'))->toBe(['copy word'])
        ->and($reasons('generic', 900, 'Watch', 'ساعة هاي كوبي'))->toBe(['copy word'])
        ->and($reasons('generic', 1300, 'Women Bracelet Stainless Steel Inspired By Michael Kors BMK10'))->toBe(['inspired by a brand'])
        ->and($reasons('hublot', 5600, 'Big Bang King Skeleton Style Watch'))->toContain('style + luxury maison')
        ->and($reasons('patek-philippe', 4200, 'Patek Philippe Nautilus'))->toBe(['watch maison below EGP 100,000'])
        ->and($reasons('chanel', 1100, 'Chanel bag'))->toBe(['fashion maison below EGP 10,000'])
        // what the 2026-11-14 sample showed must NOT match: descriptive words, a style that is not a brand, Gucci/Prada
        ->and($reasons('generic', 350, 'Silver Snake Chain Bracelet with Fuchsia Purple Crystal Style Beads'))->toBe([])
        ->and($reasons('generic', 450, 'Cap inspired By SNAPBACK cap116'))->toBe([])
        ->and($reasons('prada', 1100, 'Prada sunglasses'))->toBe([])
        // Gucci: its WATCHES below EGP 20,000 (Michael, 2026-11-14), never its perfumes or sunglasses
        ->and(SearchExposure::reasons('gucci', 5500, 'Gucci G-Timeless', '', EXPOSURE_BRANDS, 'watch'))->toBe(['watch of gucci below EGP 20,000'])
        ->and(SearchExposure::reasons('gucci', 5500, 'Gucci Bloom', '', EXPOSURE_BRANDS, 'perfume'))->toBe([])
        ->and(SearchExposure::reasons('gucci', 45000, 'Gucci Dive', '', EXPOSURE_BRANDS, 'watch'))->toBe([])
        ->and($reasons('rolex', 250000, 'Rolex Submariner'))->toBe([]);            // priced as a genuine one is

    expect(SearchExposure::classOf('rolex', ['watch maison below EGP 100,000']))->toBe(SearchExposure::RESTRICTED)
        ->and(SearchExposure::classOf('hublot', ['watch maison below EGP 100,000']))->toBe(SearchExposure::HIDDEN)
        ->and(SearchExposure::classOf('rolex', []))->toBeNull();
});

it('keeps a product\'s class up to date on save, and lets the switch win both ways', function () {
    $hublot = exposureProduct('hublot', 5200, 'Hublot Big Bang Men\'s Chronograph');
    $rolex = exposureProduct('rolex', 5200, 'Rolex Datejust');
    $plain = exposureProduct('tommy-hilfiger', 3450, 'Tommy Hilfiger 1791047 Men\'s Watch');

    expect(exposureClass($hublot))->toBe('hidden')
        ->and(exposureClass($rolex))->toBe('restricted')
        ->and(exposureClass($plain))->toBeNull();

    $effective = fn (int $id): ?string => SearchExposure::effective(T::row(DB::table('catalog_products')->where('id', $id)->first(['search_class', 'search_override'])));
    DB::table('catalog_products')->where('id', $hublot)->update(['search_override' => 1]);   // "Always show"
    DB::table('catalog_products')->where('id', $plain)->update(['search_override' => 0]);    // "Hide from search engines"
    expect($effective($hublot))->toBeNull()
        ->and($effective($plain))->toBe('hidden')
        ->and(exposureClass($hublot))->toBe('hidden');                                       // the rule's answer is kept beside it
});

it('tells the storefronts: the product page, the listing\'s indexable total, the meta\'s brands, the sitemap', function () {
    $hidden = exposureProduct('hublot', 5200, 'Hublot Big Bang Skeleton');
    CatalogFixture::onStorefront($hidden, true, 2);
    $rolex = exposureProduct('rolex', 5200, 'Rolex Datejust 41');
    CatalogFixture::onStorefront($rolex, true, 2);
    app(StorefrontCache::class)->flush(2);

    $slug = fn (int $id): string => T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)->value('slug'));
    expect(getJson('/api/v2/brandfashion/products/'.$slug($hidden))->assertOk()->json('product.search'))->toBe('hidden')
        ->and(getJson('/api/v2/brandfashion/products/'.$slug($rolex))->assertOk()->json('product.search'))->toBe('restricted');

    // every Hublot is hidden: the brand's listing has products, and none a search engine may index
    $meta = T::arr(getJson('/api/v2/brandfashion/products?brand=hublot&per_page=1')->assertOk()->json('meta'));
    expect(T::int($meta['total'] ?? 0))->toBeGreaterThan(0)
        ->and(T::int($meta['indexable_total'] ?? -1))->toBe(0);

    $search = T::arr(getJson('/api/v2/brandfashion/meta')->assertOk()->json('search'));
    expect($search)->toHaveKeys(['hidden_brand_ids', 'restricted_brand_ids']);

    $xml = (string) get(Shops::api('brandfashion').'/sitemaps/en.xml')->assertOk()->getContent();
    expect($xml)->not->toContain('/product/'.rawurlencode($slug($hidden)).'<')
        ->and($xml)->toContain('/product/'.rawurlencode($slug($rolex)).'<');                 // a matched Rolex stays
});

it('lists ORIGINAL WATCHES only: never a hidden product, a matched Rolex or a Generic watch — first under Watches, in the sitemap', function () {
    $hublot = exposureProduct('hublot', 5200, 'Hublot Big Bang Original Test');
    $rolex = exposureProduct('rolex', 5200, 'Rolex Datejust Original Test');
    $generic = exposureProduct('generic', 900, 'Generic chronograph Original Test');
    $tissot = exposureProduct('tissot', 15000, 'Tissot PRX Original Test');
    foreach ([$hublot, $rolex, $generic, $tissot] as $id) {
        CatalogFixture::onStorefront($id, true, 2);
    }
    app(StorefrontCache::class)->flush(2);

    $ids = [];
    $page = 1;
    do {
        $body = T::arr(getJson('/api/v2/brandfashion/products?collection=original-watches&per_page=96&sort=newest&page='.$page)->assertOk()->json());
        foreach (T::arr($body['data'] ?? []) as $card) {
            $card = T::arr($card);
            $ids[] = T::int($card['id'] ?? 0);
            expect($card['search'] ?? null)->toBeNull();
            expect($card['family'] ?? null)->toBe('watch');
        }
        $last = T::int(T::arr($body['meta'] ?? [])['last_page'] ?? 1);
    } while (++$page <= min($last, 3));
    // the newest first: the four made above lead the list
    expect($ids)->toContain($tissot)
        ->and($ids)->not->toContain($hublot)
        ->and($ids)->not->toContain($rolex)
        ->and($ids)->not->toContain($generic);

    $menu = T::arr(getJson('/api/v2/brandfashion/menu')->assertOk()->json());
    $menu = T::arr($menu['data'] ?? $menu);
    $watches = collect(T::arr($menu['categories'] ?? []))->first(fn (mixed $c): bool => (T::arr($c)['slug'] ?? null) === 'watches');
    expect(T::arr(T::arr(T::arr($watches)['children'] ?? [])[0] ?? [])['href'] ?? null)->toBe('/original-watches');

    expect((string) get(Shops::api('brandfashion').'/sitemaps/en.xml')->assertOk()->getContent())->toContain('/original-watches<');
});
