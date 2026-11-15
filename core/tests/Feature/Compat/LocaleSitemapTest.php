<?php

use App\Compat\CompatLocaleSitemap;
use App\Compat\CompatServices;
use App\Domain\Catalog\SearchExposure;
use App\Domain\Content\BlogWriter;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontUrls;
use App\Support\LegacySlug;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * S-AR stage 3 (2026-10-01): the shop's sitemaps, one per language — /sitemaps/index.xml,
 * /sitemaps/en.xml, /sitemaps/ar.xml (served to Google through the storefront's rewrites) — and B8:
 * no sitemap route opens a session or sets a cookie.
 */

/** @return list<SimpleXMLElement> the <url> elements of a sitemap */
function sitemapUrls(string $locale): array
{
    $xml = simplexml_load_string((string) get("/sitemaps/{$locale}.xml")->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent());
    expect($xml)->not->toBeFalse();

    return $xml === false ? [] : iterator_to_array($xml->url, false);
}

it('indexes one sitemap per language on the shop\'s own domain', function () {
    $xml = simplexml_load_string((string) get('/sitemaps/index.xml')->assertOk()->getContent());
    expect($xml)->not->toBeFalse();
    $locs = [];
    foreach ($xml === false ? [] : iterator_to_array($xml->sitemap, false) as $s) {
        $locs[] = (string) $s->loc;
    }
    expect($locs)->toBe(['https://watchizereg.com/sitemaps/en.xml', 'https://watchizereg.com/sitemaps/ar.xml']);
    get('/sitemaps/fr.xml')->assertNotFound();
});

it('lists every page once per language — English bare, Arabic under /ar — each with its three alternates', function () {
    $entries = app(CompatServices::class)->listing->entries();
    // Rewritten 2026-11-14: every visible product EXCEPT those kept from search engines (SearchExposure: the switch,
    // else the rule) — they stay on sale and leave the sitemap. A matched Rolex is restricted, not hidden: it stays.
    $hidden = array_flip(array_map(fn (mixed $id): int => T::int($id), DB::table('catalog_products as p')
        ->whereRaw(SearchExposure::effectiveSql('p').' = ?', [SearchExposure::HIDDEN])->pluck('p.id')->all()));
    $entries = array_values(array_filter($entries, fn (array $e): bool => ! isset($hidden[$e['id']])));
    $slugs = array_values(array_unique(array_filter(array_map(fn (array $e): string => $e['slug'], $entries), fn (string $s): bool => $s !== '')));

    foreach (['en' => 'https://watchizereg.com/', 'ar' => 'https://watchizereg.com/ar'] as $locale => $prefix) {
        $locs = [];
        $wrong = [];
        foreach (sitemapUrls($locale) as $url) {
            $loc = (string) $url->loc;
            $locs[] = $loc;
            if (! str_starts_with($loc, $prefix) || ($locale === 'en' && str_starts_with($loc, 'https://watchizereg.com/ar/'))) {
                $wrong[] = "{$locale} loc {$loc}";
            }
            $alternates = [];
            foreach ($url->children('http://www.w3.org/1999/xhtml')->link as $link) {
                $alternates[(string) $link->attributes()->hreflang] = (string) $link->attributes()->href;
            }
            $bare = $locale === 'ar' ? 'https://watchizereg.com'.(substr($loc, strlen('https://watchizereg.com/ar')) ?: '/') : $loc;
            if ($alternates !== ['en' => $bare, 'ar' => $locale === 'ar' ? $loc : ($bare === 'https://watchizereg.com/' ? 'https://watchizereg.com/ar' : str_replace('https://watchizereg.com/', 'https://watchizereg.com/ar/', $bare)), 'x-default' => $bare]) {
                $wrong[] = "{$locale} alternates of {$loc}: ".json_encode($alternates);
            }
        }
        expect($wrong)->toBe([])
            ->and($locs)->toBe(array_values(array_unique($locs)));          // no duplicate <loc>

        $products = array_values(array_filter($locs, fn (string $l): bool => str_contains($l, '/product/')));
        expect(count($products))->toBe(count($slugs));                   // twins share one URL
        foreach (['/products', '/offers'] as $dead) {
            expect($locs)->not->toContain(($locale === 'ar' ? 'https://watchizereg.com/ar' : 'https://watchizereg.com').$dead);
        }
        // Rewritten 2026-11-14: `/blogs` was asserted absent, true until Watchizer published its first article and
        // false on production's data since. The rule is the one the next test builds: listed only once the storefront
        // has a published article — asserted here on whatever the copy holds.
        $hasArticles = app(CompatServices::class)->blogs->list() !== [];
        expect(in_array(($locale === 'ar' ? 'https://watchizereg.com/ar' : 'https://watchizereg.com').'/blogs', $locs, true))->toBe($hasArticles);
        // Changed deliberately (2026-10-02): the four trust pages were 404s and asserted ABSENT; the
        // storefront has them now, so they are asserted PRESENT, in this language.
        foreach (CompatLocaleSitemap::TRUST_PAGES as $page) {
            expect($locs)->toContain(($locale === 'ar' ? 'https://watchizereg.com/ar' : 'https://watchizereg.com').$page);
        }
        // The two campaign pages (the design pass, 2026-11-05): permanent, indexable pages — up between campaigns,
        // only their products change — so they are listed like any other fixed page, in this language.
        expect(CompatLocaleSitemap::CAMPAIGN_PAGES)->toBe(['/hot-deals', '/flash-sale']);
        foreach (CompatLocaleSitemap::CAMPAIGN_PAGES as $page) {
            expect($locs)->toContain(($locale === 'ar' ? 'https://watchizereg.com/ar' : 'https://watchizereg.com').$page);
        }
    }
});

it('lists /blogs and each published article only once the storefront has one', function () {
    DB::table('core_blog_translations')->delete();
    DB::table('core_blogs')->delete();
    $locs = fn (): array => array_map(fn (SimpleXMLElement $u): string => (string) $u->loc, sitemapUrls('ar'));
    expect($locs())->not->toContain('https://watchizereg.com/ar/blogs');

    app(BlogWriter::class)->save(null, [
        'storefront_id' => 1, 'slug' => 'how-to-read-a-watch-dial', 'is_published' => true,
        'title' => ['ar' => 'كيف تقرأ ميناء الساعة', 'en' => 'How to read a watch dial'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ]);
    app(BlogWriter::class)->save(null, [
        'storefront_id' => 1, 'slug' => 'a-draft-article', 'is_published' => false,
        'title' => ['ar' => 'مسودة', 'en' => 'Draft'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
    ]);
    $after = $locs();
    expect(in_array('https://watchizereg.com/ar/blogs', $after, true))->toBeTrue()
        // SEO Phase 3 (2026-11-14): a new article's Arabic page is at its Arabic slug, percent-encoded (BUSINESS FACTS 9)
        ->and(in_array('https://watchizereg.com/ar/blog/'.rawurlencode('كيف-تقرا-ميناء-الساعة'), $after, true))->toBeTrue()
        ->and(in_array('https://watchizereg.com/ar/blog/a-draft-article', $after, true))->toBeFalse();
});

// Rewritten 2026-11-14 (SEO Phase 1 item 5): "a brand with a visible product" became "a brand that is not thin" — at
// least compat.thin_listing_min visible products, the number the shop's noindex rule uses (listingWords.js).
it('lists a brand only when it has at least the thin-listing minimum of visible products', function () {
    $min = config()->integer('compat.thin_listing_min');
    expect($min)->toBe(3);
    $entries = app(CompatServices::class)->listing->entries();
    // Rewritten 2026-11-14: counted over the INDEXABLE products — one kept from search engines (SearchExposure) does
    // not keep its brand page in the sitemap (Michael: such a brand page goes noindex and out of the sitemap)
    $hidden = array_flip(array_map(fn (mixed $id): int => T::int($id), DB::table('catalog_products as p')
        ->whereRaw(SearchExposure::effectiveSql('p').' = ?', [SearchExposure::HIDDEN])->pluck('p.id')->all()));
    $entries = array_values(array_filter($entries, fn (array $e): bool => ! isset($hidden[$e['id']])));
    $counts = array_count_values(array_filter(array_map(fn (array $e): ?int => $e['brands'], $entries), fn (?int $b): bool => $b !== null));
    $locs = array_map(fn (SimpleXMLElement $u): string => (string) $u->loc, sitemapUrls('en'));

    $listed = 0;
    foreach (DB::table('catalog_brand_translations')->where('locale', 'en')->get(['brand_id', 'name']) as $raw) {
        $row = T::row($raw);
        $url = 'https://watchizereg.com/brand/'.LegacySlug::make(T::str($row->name));
        $n = $counts[T::int($row->brand_id)] ?? 0;
        if ($n >= $min) {
            $listed++;
            expect($locs)->toContain($url);
        } else {
            expect($locs)->not->toContain($url);
        }
    }
    expect($listed)->toBeGreaterThan(0);
});

it('lists a brand at exactly the minimum, and leaves it out once it drops under', function () {
    $min = config()->integer('compat.thin_listing_min');
    $brand = T::int(DB::table('catalog_brands')->insertGetId(['slug' => 'thin-sitemap-brand-'.bin2hex(random_bytes(4)), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    DB::table('catalog_brand_translations')->insert([
        ['brand_id' => $brand, 'locale' => 'en', 'name' => 'Thin Sitemap Brand '.$brand],
        ['brand_id' => $brand, 'locale' => 'ar', 'name' => 'ماركة '.$brand],
    ]);
    $url = 'https://watchizereg.com/brand/'.LegacySlug::make('Thin Sitemap Brand '.$brand);
    $ids = [];
    for ($i = 0; $i < $min; $i++) {
        $ids[] = $id = CatalogFixture::product('watch', 100.0);
        DB::table('catalog_products')->where('id', $id)->update(['brand_id' => $brand]);
        CatalogFixture::onStorefront($id, true, 1);
    }
    $locs = fn (): array => array_map(fn (SimpleXMLElement $u): string => (string) $u->loc, sitemapUrls('en'));
    expect($locs())->toContain($url);                                          // exactly the minimum: listed

    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $ids[0])->update(['is_visible' => 0]);
    app(StorefrontCache::class)->flush(1);
    Artisan::call('catalog:warm', ['--storefront' => [1]]);
    expect($locs())->not->toContain($url);                                     // one under: left out
});

it('sets no cookie and opens no session on any sitemap route (B8)', function () {
    foreach (['/sitemap.xml', '/en/sitemap.xml', '/ar/sitemap.xml', '/sitemaps/index.xml', '/sitemaps/en.xml', '/sitemaps/ar.xml'] as $path) {
        $response = get($path);
        expect($response->headers->getCookies())->toBe([], "{$path} set a cookie");
    }
});

it('lists only trust pages the storefront actually has', function () {
    // Each path the sitemap names is a route folder with a page in the storefront, so a page removed
    // there cannot linger here as a 404 Google keeps crawling.
    $missing = [];
    foreach (CompatLocaleSitemap::TRUST_PAGES as $path) {
        if (! is_file(base_path('../Frontend-next/app/(main)'.$path.'/page.jsx'))) {
            $missing[] = $path;
        }
    }
    expect($missing)->toBe([]);
});

it("names Brand Fashion's own site on Brand Fashion's sitemaps, never Watchizer's (the backlog item, 2026-10-22)", function () {
    $fashion = Shops::id('brandfashion');
    $own = StorefrontUrls::frontend($fashion);
    expect($own)->not->toContain('watchizereg');

    $index = simplexml_load_string((string) get(Shops::api('brandfashion').'/sitemaps/index.xml')->assertOk()->getContent());
    expect($index)->not->toBeFalse();
    $locs = [];
    foreach ($index === false ? [] : iterator_to_array($index->sitemap, false) as $s) {
        $locs[] = (string) $s->loc;
    }
    expect($locs)->toBe([$own.'/sitemaps/en.xml', $own.'/sitemaps/ar.xml']);

    // Every <loc> and every alternate href, read off the raw XML so no namespace handling can hide one.
    $xml = (string) get(Shops::api('brandfashion').'/sitemaps/en.xml')->assertOk()->getContent();
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $locs);
    preg_match_all('#hreflang="[^"]+" href="([^"]+)"#', $xml, $alts);
    $count = count($locs[1]);
    $wrong = array_values(array_filter(array_merge($locs[1], $alts[1]), fn (string $u): bool => ! str_starts_with($u, $own.'/')));
    expect($count)->toBeGreaterThan(10)
        ->and(count($alts[1]))->toBe(3 * $count, 'three alternates per URL')
        ->and($wrong)->toBe([], count($wrong).' URL(s) not on '.$own.': '.implode(', ', array_slice($wrong, 0, 5)));
    // Watchizer's own index is held by the first test of this file: byte for byte where it was.
});

/**
 * The product slugs a sitemap lists, in order (read off the raw XML).
 *
 * @return list<string>
 */
function sitemapProductSlugs(string $xml): array
{
    preg_match_all('#<loc>[^<]*/product/([^<]+)</loc>#', $xml, $m);

    return array_map('rawurldecode', $m[1]);
}

it("lists Brand Fashion's products under the slug its product page answers — never the title slug (2026-11-14: ~4,000 404s a language)", function () {
    $fashion = Shops::id('brandfashion');
    $id = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($id, true, $fashion);
    DB::table('storefront_product')->where('storefront_id', $fashion)->where('product_id', $id)->update(['slug' => 'sitemap-own-slug-'.$id]);
    $titleSlug = LegacySlug::make(T::str(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('title')));
    $later = CatalogFixture::product('watch', 100.0);                       // published tomorrow: its page 404s today
    CatalogFixture::onStorefront($later, true, $fashion);
    DB::table('storefront_product')->where('storefront_id', $fashion)->where('product_id', $later)
        ->update(['slug' => 'sitemap-not-yet-'.$later, 'published_at' => now()->addDay()]);

    foreach (['en', 'ar'] as $locale) {
        $slugs = sitemapProductSlugs((string) get(Shops::api('brandfashion')."/sitemaps/{$locale}.xml")->assertOk()->getContent());
        // the set is the product page's own rule, each once, in id order
        // …minus those kept from search engines (2026-11-14, SearchExposure): on sale, in no sitemap
        $answered = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $fashion)
            ->whereRaw('COALESCE('.SearchExposure::effectiveSql('p').", '') <> ?", [SearchExposure::HIDDEN])
            ->where('sp.slug', '<>', '')->orderBy('sp.product_id')->pluck('sp.slug')->map(fn (mixed $s): string => T::str($s))->all();
        expect($slugs)->toBe($answered)
            ->and($slugs)->toContain('sitemap-own-slug-'.$id)
            ->and($slugs)->not->toContain($titleSlug)
            ->and($slugs)->not->toContain('sitemap-not-yet-'.$later);
    }
    // and the page answers the one listed, and not the one held back
    getJson('/api/v2/brandfashion/products/sitemap-own-slug-'.$id)->assertOk();
    getJson('/api/v2/brandfashion/products/sitemap-not-yet-'.$later)->assertNotFound();
});

it('keeps Watchizer on the title slug its product page resolves', function () {
    $id = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($id, true, 1);
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['slug' => 'not-watchizers-route-'.$id]);
    $slugs = sitemapProductSlugs((string) get('/sitemaps/en.xml')->assertOk()->getContent());

    expect(app(CompatServices::class)->localeSitemap->routesByStorefrontSlug())->toBeFalse()
        ->and($slugs)->not->toContain('not-watchizers-route-'.$id);
});

it('serves a cached sitemap until the storefront is flushed, and catalog:warm builds a cold one', function () {
    $fashion = Shops::id('brandfashion');
    $url = Shops::api('brandfashion').'/sitemaps/en.xml';
    $first = (string) get($url)->assertOk()->getContent();
    $id = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($id, true, $fashion);
    DB::table('storefront_product')->where('storefront_id', $fashion)->where('product_id', $id)->update(['slug' => 'sitemap-cached-'.$id]);

    expect((string) get($url)->getContent())->toBe($first);                 // the cached copy
    app(StorefrontCache::class)->flush($fashion);                            // what a dashboard write does
    // a REQUEST never rebuilds while a last good copy exists — it serves that copy (the 2026-11-12 lesson)…
    expect((string) get($url)->getContent())->toBe($first);
    // …and the 5-minute warm-up builds the new one
    Artisan::call('catalog:warm', ['--storefront' => [$fashion]]);
    expect(Artisan::output())->toContain("storefront {$fashion}: sitemaps ready (en ")
        ->and(sitemapProductSlugs((string) get($url)->getContent()))->toContain('sitemap-cached-'.$id);
});

it('dates a brand page by its newest product, a page-text save moves it, and code-written pages carry no date (2026-11-14)', function () {
    $min = config()->integer('compat.thin_listing_min');
    $catalogSlug = 'dated-brand-'.bin2hex(random_bytes(4));         // a page text is keyed by the catalogue slug
    $brand = T::int(DB::table('catalog_brands')->insertGetId(['slug' => $catalogSlug, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    DB::table('catalog_brand_translations')->insert([
        ['brand_id' => $brand, 'locale' => 'en', 'name' => 'Dated Brand '.$brand],
        ['brand_id' => $brand, 'locale' => 'ar', 'name' => 'ماركة '.$brand],
    ]);
    $slug = LegacySlug::make('Dated Brand '.$brand);
    $ids = [];
    for ($i = 0; $i < $min; $i++) {
        $ids[] = $id = CatalogFixture::product('watch', 100.0);
        DB::table('catalog_products')->where('id', $id)->update(['brand_id' => $brand, 'updated_at' => '2026-01-0'.($i + 1).' 10:00:00']);
        CatalogFixture::onStorefront($id, true, 1);
        DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['updated_at' => '2026-01-01 09:00:00']);
    }
    $lastmod = function (string $path): ?string {
        app(StorefrontCache::class)->flush(1);
        Illuminate\Support\Facades\Cache::forget(StorefrontCache::lastGoodKey(1, 'compat_locale_sitemap', 'en'));   // else the last good copy is served
        foreach (sitemapUrls('en') as $u) {
            if ((string) $u->loc === 'https://watchizereg.com'.$path) {
                return isset($u->lastmod) ? (string) $u->lastmod : null;
            }
        }
        throw new RuntimeException("{$path} is not listed");
    };
    $atom = fn (string $db): string => Carbon\Carbon::parse($db, config()->string('app.timezone'))->toAtomString();

    expect($lastmod('/brand/'.$slug))->toBe($atom('2026-01-0'.$min.' 10:00:00'));      // the newest of its products

    DB::table('core_seo_texts')->insert(['storefront_id' => 1, 'subject_type' => 'brand', 'subject_key' => $catalogSlug, 'locale' => 'en',
        'description' => 'x', 'created_at' => '2026-02-01 08:00:00', 'updated_at' => '2026-02-01 08:00:00']);
    expect($lastmod('/brand/'.$slug))->toBe($atom('2026-02-01 08:00:00'));              // its page text saved later

    expect($lastmod('/'))->not->toBeNull()
        ->and($lastmod('/listing'))->not->toBeNull()
        ->and($lastmod('/faq'))->toBeNull()                                            // written in code: no invented date
        ->and($lastmod('/hot-deals'))->toBeNull();
});
