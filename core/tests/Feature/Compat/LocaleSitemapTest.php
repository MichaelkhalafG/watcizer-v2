<?php

use App\Compat\CompatLocaleSitemap;
use App\Compat\CompatServices;
use App\Domain\Content\BlogWriter;
use App\Support\LegacySlug;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\get;

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
        foreach (['/products', '/offers', '/blogs'] as $dead) {
            expect($locs)->not->toContain(($locale === 'ar' ? 'https://watchizereg.com/ar' : 'https://watchizereg.com').$dead);
        }
        // Changed deliberately (2026-10-02): the four trust pages were 404s and asserted ABSENT; the
        // storefront has them now, so they are asserted PRESENT, in this language.
        foreach (CompatLocaleSitemap::TRUST_PAGES as $page) {
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
        ->and(in_array('https://watchizereg.com/ar/blog/how-to-read-a-watch-dial', $after, true))->toBeTrue()
        ->and(in_array('https://watchizereg.com/ar/blog/a-draft-article', $after, true))->toBeFalse();
});

it('lists a brand only when it has a visible product', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $used = array_flip(array_filter(array_map(fn (array $e): ?int => $e['brands'], $entries), fn (?int $b): bool => $b !== null));
    $locs = array_map(fn (SimpleXMLElement $u): string => (string) $u->loc, sitemapUrls('en'));

    $withProducts = 0;
    foreach (DB::table('catalog_brand_translations')->where('locale', 'en')->get(['brand_id', 'name']) as $raw) {
        $row = T::row($raw);
        $url = 'https://watchizereg.com/brand/'.LegacySlug::make(T::str($row->name));
        if (isset($used[T::int($row->brand_id)])) {
            $withProducts++;
            expect($locs)->toContain($url);
        } else {
            expect($locs)->not->toContain($url);
        }
    }
    expect($withProducts)->toBeGreaterThan(0);
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
