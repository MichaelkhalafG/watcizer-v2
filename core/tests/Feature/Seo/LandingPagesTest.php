<?php

use App\Domain\Inventory\StockWriteGuard;
use App\Domain\Seo\LandingPages;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * LANDING PAGES (SEO Phase 2, 2026-11-14): a brand × gender / family page exists where it has 6+ LIVE indexable products
 * AND narrows the brand page; it is indexable only once its copy passed the quality gate in both languages.
 */

/**
 * A brand of its own on storefront 2: 8 men's watches and 6 women's bags.
 *
 * @return array{slug: string, id: int}
 */
function lpBrand(): array
{
    $slug = 'lp-brand-'.bin2hex(random_bytes(3));
    $brand = T::int(DB::table('catalog_brands')->insertGetId(['slug' => $slug, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    DB::table('catalog_brand_translations')->insert([
        ['brand_id' => $brand, 'locale' => 'en', 'name' => 'LP Brand '.$brand], ['brand_id' => $brand, 'locale' => 'ar', 'name' => 'ماركة '.$brand],
    ]);
    $men = T::int(DB::table('catalog_gender_translations')->where('locale', 'en')->where('name', 'Men')->value('gender_id'));
    $women = T::int(DB::table('catalog_gender_translations')->where('locale', 'en')->where('name', 'Women')->value('gender_id'));
    foreach ([['watch', $men, 8], ['bag', $women, 6]] as [$family, $gender, $n]) {
        for ($i = 0; $i < $n; $i++) {
            $id = CatalogFixture::product($family, 1000.0 + $i);
            DB::table('catalog_products')->where('id', $id)->update(['brand_id' => $brand, 'search_class' => null, 'search_override' => null]);
            // the fixture gives every product Men; this brand's genders are exactly the ones set here
            DB::table('catalog_product_gender')->where('product_id', $id)->delete();
            DB::table('catalog_product_gender')->insert(['product_id' => $id, 'gender_id' => $gender]);
            CatalogFixture::onStorefront($id, true, 2);
        }
    }
    app(StorefrontCache::class)->flush(2);

    return ['slug' => $slug, 'id' => $brand];
}

it('makes a page only where it has 6+ LIVE products and narrows the brand page, and indexes it only after the gate', function () {
    $b = lpBrand();
    $pages = app(LandingPages::class)->ofBrand(2, $b['slug']);
    $keys = array_column($pages, 'key');
    // men = the 8 watches, women = the 6 bags: each narrows the brand (14); watches and bags likewise. bags/women is
    // ALL the bags (it does not narrow /bags) and watches/men all the watches: no page for either.
    expect($keys)->toEqualCanonicalizing([$b['slug'].'/men', $b['slug'].'/women', $b['slug'].'/watches', $b['slug'].'/bags'])
        ->and(array_column($pages, 'indexable'))->each->toBeFalse();

    // the listing for brand + gender IS the landing page: its key, not indexable yet
    $res = getJson('/api/v2/brandfashion/products?brand='.$b['slug'].'&gender=Men')->assertOk();
    expect($res->json('meta.landing'))->toBe(['key' => $b['slug'].'/men', 'exists' => true, 'indexable' => false])
        ->and($res->json('meta.total'))->toBe(8);
    expect(getJson('/api/v2/brandfashion/products?brand='.$b['slug'].'&family=bag')->json('meta.total'))->toBe(6);
    // the brand page lists its landing pages for the shop's links
    expect(array_column(T::arr(getJson('/api/v2/brandfashion/products?brand='.$b['slug'])->json('meta.landings')), 'path'))
        ->toContain('/brand/'.$b['slug'].'/men', '/brand/'.$b['slug'].'/bags');

    // both languages passed the gate, but nothing is in stock → still not indexable (Michael, 2026-11-15: 3 in stock)
    foreach (['en', 'ar'] as $locale) {
        DB::table('core_seo_texts')->insert(['storefront_id' => 2, 'subject_type' => 'landing', 'subject_key' => $b['slug'].'/men',
            'locale' => $locale, 'description' => 'x', 'gate_status' => 'passed', 'created_at' => now(), 'updated_at' => now()]);
    }
    app(StorefrontCache::class)->flush(2);
    expect(app(LandingPages::class)->find(2, $b['slug'].'/men'))->toMatchArray(['in_stock' => 0, 'indexable' => false]);

    // 3 of the 8 watches in stock → indexable, and in the sitemap
    $watches = DB::table('catalog_products')->where('brand_id', $b['id'])->where('family', 'watch')->orderBy('id')->pluck('id')->all();
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->whereIn('id', array_slice($watches, 0, 3))->update(['stock_express' => 1, 'in_stock' => 1]));
    app(StorefrontCache::class)->flush(2);
    expect(app(LandingPages::class)->find(2, $b['slug'].'/men'))->toMatchArray(['in_stock' => 3, 'indexable' => true])
        ->and(getJson('/api/v2/brandfashion/products?brand='.$b['slug'].'&gender=Men')->json('meta.landing.indexable'))->toBeTrue();

    // one sells out → 2 in stock → noindex again, on its own; the page itself stays (8 LIVE products)
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $watches[0])->update(['stock_express' => 0, 'in_stock' => 0]));
    app(StorefrontCache::class)->flush(2);
    expect(app(LandingPages::class)->find(2, $b['slug'].'/men'))->toMatchArray(['products' => 8, 'in_stock' => 2, 'indexable' => false]);
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $watches[0])->update(['stock_express' => 1, 'in_stock' => 1]));
    app(StorefrontCache::class)->flush(2);

    // one language held → not indexable again
    DB::table('core_seo_texts')->where('subject_key', $b['slug'].'/men')->where('locale', 'ar')->update(['gate_status' => 'held']);
    app(StorefrontCache::class)->flush(2);
    expect(app(LandingPages::class)->find(2, $b['slug'].'/men')['indexable'] ?? null)->toBeFalse();
});

it('drops a page that falls under 6 LIVE products, and never counts a product hidden from search engines', function () {
    $b = lpBrand();
    $bags = DB::table('catalog_products')->where('brand_id', $b['id'])->where('family', 'bag')->pluck('id')->all();
    DB::table('catalog_products')->where('id', $bags[0])->update(['search_override' => 0]);     // hidden: no longer counted
    app(StorefrontCache::class)->flush(2);
    expect(array_column(app(LandingPages::class)->ofBrand(2, $b['slug']), 'key'))->not->toContain($b['slug'].'/bags', $b['slug'].'/women');
});

it('lists the landing pages on the dashboard\'s Page texts screen, saves one with its intro, and a change clears its gate verdict', function () {
    $b = lpBrand();
    $key = $b['slug'].'/men';
    $admin = Tests\Support\Staff::admin();
    Pest\Laravel\actingAs($admin)->get('/manage/storefronts/2/page-texts')->assertOk();
    expect(array_column(App\Http\Controllers\Manage\SeoTextController::landings(2), 'key'))->toContain($key);

    $body = ['type' => 'landing', 'key' => $key,
        'en' => ['intro' => 'Men\'s watches from the brand.', 'description' => "## Guide\n\nText.", 'meta_description' => 'Men'],
        'ar' => ['intro' => 'ساعات رجالية من الماركة.', 'description' => "## دليل\n\nنص.", 'meta_description' => 'رجالي']];
    Pest\Laravel\actingAs($admin)->put('/manage/storefronts/2/page-texts', $body)->assertSessionHasNoErrors();
    expect(DB::table('core_seo_texts')->where('subject_type', 'landing')->where('subject_key', $key)->where('locale', 'en')->value('intro'))
        ->toBe('Men\'s watches from the brand.');

    DB::table('core_seo_texts')->where('subject_key', $key)->update(['gate_status' => 'passed']);
    $body['en']['intro'] = 'Changed words.';
    Pest\Laravel\actingAs($admin)->put('/manage/storefronts/2/page-texts', $body)->assertSessionHasNoErrors();
    expect(DB::table('core_seo_texts')->where('subject_key', $key)->where('locale', 'en')->value('gate_status'))->toBeNull()
        ->and(DB::table('core_seo_texts')->where('subject_key', $key)->where('locale', 'ar')->value('gate_status'))->toBe('passed');

    Pest\Laravel\actingAs($admin)->put('/manage/storefronts/2/page-texts', ['type' => 'landing', 'key' => $b['slug'].'/kids'] + $body)
        ->assertSessionHasErrors('key');                                       // not a page the shop has
});
