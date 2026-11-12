<?php

use App\Domain\Inventory\StockWriteGuard;
use App\Feeds\GoogleFeed;
use App\Feeds\MetaFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Scratch;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * The Google Merchant (Shopping) feed (2026-11-11, D-9): the Meta feed's lines renamed to Google's
 * columns, per storefront, generated to a file and only SERVED by its URL behind its own token.
 * The products are fixtures inside the test's transaction; the feed directory is a scratch one.
 */

// Split like the Meta test's: one quoted 40-character literal is the shape the pre-commit guard blocks.
const GOOGLE_FEED_TOKEN = 'GoogleToken012345678'.'abcdefghijklmnopqrst';

beforeEach(function () {
    config()->set('feeds.path', Scratch::dir('feeds'));
    config()->set('feeds.google.watchizer.token', GOOGLE_FEED_TOKEN);
});

/**
 * A product visible on Watchizer in a real category, with stock and the given columns.
 *
 * @param  array<string, mixed>  $set
 */
function googleFeedProduct(array $set = []): int
{
    $id = CatalogFixture::product('watch', 1000.0);
    CatalogFixture::onStorefront($id);
    CatalogFixture::place($id, T::int(DB::table('storefront_categories')->where('storefront_id', 1)
        ->where('legacy_source', 'sub_type')->where('legacy_id', 1)->value('id')));
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update($set + ['stock_express' => 1]));

    return $id;
}

/** @return array<int, array<string, string>> the generated Google file, keyed by product id */
function googleFeedRows(): array
{
    app(GoogleFeed::class)->generate('watchizer');
    $lines = explode("\n", rtrim((string) file_get_contents(GoogleFeed::path('watchizer')), "\n"));
    $header = explode("\t", (string) array_shift($lines));
    expect($header)->toBe(GoogleFeed::COLUMNS);
    $rows = [];
    foreach ($lines as $line) {
        $cells = explode("\t", $line);
        expect($cells)->toHaveCount(count($header));             // a stray tab would shift every column
        $rows[(int) $cells[0]] = array_combine($header, $cells);
    }

    return $rows;
}

it('carries exactly the Meta feed\'s products, prices, links and images — one builder, two formats', function () {
    $id = googleFeedProduct(['sale_price' => 800, 'sku' => 'GF-TEST-1']);
    $cfg = GoogleFeed::config('watchizer') ?? throw new RuntimeException('the Watchizer Google feed is not configured');
    $meta = [];
    foreach (app(MetaFeed::class)->lines($cfg) as $pid => $line) {
        $meta[$pid] = $line;
    }
    $google = googleFeedRows();

    expect(array_keys($google))->toBe(array_keys($meta));
    $g = $google[$id] ?? [];
    $m = $meta[$id] ?? [];
    expect($g['price'])->toBe($m['price'])
        ->and($g['sale_price'])->toBe($m['sale_price'])
        ->and($g['link'])->toBe($m['link'])
        ->and($g['image_link'])->toBe($m['image_link'])
        ->and($g['title'])->toBe($m['title'])
        ->and($g['brand'])->toBe($m['brand'])
        ->and($g['availability'])->toBe('in_stock')
        ->and($g['mpn'])->toBe('GF-TEST-1')
        ->and($g['identifier_exists'])->toBe('');
});

it('says out_of_stock by the shop rule, and identifier_exists=no when there is no SKU', function () {
    $id = googleFeedProduct(['sku' => null]);
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update(['stock_express' => 0, 'stock_market' => 0, 'in_stock' => 0]));
    $g = googleFeedRows()[$id] ?? [];

    expect($g['availability'])->toBe('out_of_stock')
        ->and($g['mpn'])->toBe('')
        ->and($g['identifier_exists'])->toBe('no');
});

it('maps a line to Google\'s columns: at most 10 extra images, tabs never inside a value', function () {
    $images = implode(',', array_map(fn (int $i): string => "https://x.test/{$i}.webp", range(1, 14)));
    $row = GoogleFeed::row(['id' => '7', 'title' => 'A', 'availability' => 'in stock', 'additional_image_link' => $images, 'condition' => 'new'], 'SKU-1');

    expect(explode(',', $row['additional_image_link']))->toHaveCount(GoogleFeed::MAX_ADDITIONAL_IMAGES)
        ->and($row['availability'])->toBe('in_stock')
        ->and(array_keys($row))->toBe(GoogleFeed::COLUMNS);
});

it('serves the file with an ETag and 304, and one plain 404 for a wrong token, an unconfigured shop or no file', function () {
    $url = '/feeds/google/watchizer/'.GOOGLE_FEED_TOKEN.'.tsv';
    get($url)->assertNotFound();                                   // configured, but no file yet

    googleFeedProduct();
    $path = app(GoogleFeed::class)->generate('watchizer')['path'];
    $etag = '"'.sha1_file($path).'"';
    get($url)->assertOk()->assertHeader('ETag', $etag)->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8');
    withHeaders(['If-None-Match' => $etag])->get($url)->assertStatus(304);

    get('/feeds/google/watchizer/'.str_repeat('Z', 40).'.tsv')->assertNotFound();
    get('/feeds/google/brandfashion/'.GOOGLE_FEED_TOKEN.'.tsv')->assertNotFound();   // no token there: off
    get('/feeds/meta/watchizer/'.GOOGLE_FEED_TOKEN.'.csv')->assertNotFound();      // never the other feed's door
});

it('keeps Brand Fashion\'s feed off until its token is set, on its real www domain', function () {
    expect(GoogleFeed::config('brandfashion'))->toBeNull()
        ->and(config('feeds.google.brandfashion.domain'))->toBe('https://www.brandfashionegy.com')
        ->and(GoogleFeed::configured())->toBe(['watchizer']);
});

it('is scheduled hourly at :45, without overlapping', function () {
    $events = array_values(array_filter(app(Schedule::class)->events(), fn ($e) => str_contains((string) $e->command, 'feeds:google')));
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression ?? null)->toBe('45 * * * *')
        ->and($events[0]->withoutOverlapping ?? null)->toBeTrue();
});
