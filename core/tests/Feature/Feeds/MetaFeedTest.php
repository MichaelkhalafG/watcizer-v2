<?php

use App\Compat\CompatCart;
use App\Domain\Inventory\StockWriteGuard;
use App\Feeds\MetaFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\CatalogFixture;
use Tests\Support\Scratch;
use Tests\Support\T;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * The Meta catalogue feed (2026-10-10, config/feeds.php, `feeds:meta`, `MetaFeedController`).
 *
 * Per storefront from day one, generated to a file and only SERVED by the URL; the data is the
 * shop's own — availability per product by the shop's rule, sale_price by `catalogPrice()`, images
 * absolute on the storefront's host, the link the shop routes. Each guard is a case below. The feed
 * directory is a scratch directory; the products are fixtures inside the test's transaction.
 */

// A fabricated 40-character token, SPLIT across two quoted pieces: one quoted 40-character literal is
// the S2 key shape the pre-commit guard blocks (scripts/git-hooks/pre-commit, the same way its own
// fixtures are split). PHP joins the pieces at compile time; the value is unchanged.
const FEED_TOKEN = 'TestToken0123456789'.'abcdefghijklmnopqrstu';

beforeEach(function () {
    config()->set('feeds.path', Scratch::dir('feeds'));
    config()->set('feeds.meta.watchizer.token', FEED_TOKEN);
});

/**
 * A product visible on Watchizer, placed in Watches > Diver, with the given columns set.
 *
 * @param  array<string, mixed>  $set  catalog_products columns
 */
function feedProduct(array $set = []): int
{
    $id = CatalogFixture::product('watch', 1000.0);
    CatalogFixture::onStorefront($id);
    CatalogFixture::place($id, T::int(DB::table('storefront_categories')->where('storefront_id', 1)
        ->where('legacy_source', 'sub_type')->where('legacy_id', 1)->value('id')));
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update($set + ['stock_express' => 1]));

    return $id;
}

/** @return array<int, array<string, string>> the generated feed, keyed by product id */
function feedRows(): array
{
    app(MetaFeed::class)->generate('watchizer');
    $h = fopen(MetaFeed::path('watchizer'), 'rb');
    if ($h === false) {
        throw new RuntimeException('the feed file cannot be read');
    }
    $header = array_map(fn (mixed $c): string => T::str($c), T::arr(fgetcsv($h, null, ',', '"', '')));
    $rows = [];
    while (($line = fgetcsv($h, null, ',', '"', '')) !== false) {
        $row = [];
        foreach ($header as $i => $column) {
            $row[$column] = T::str($line[$i] ?? '');
        }
        $rows[T::int($row['id'] ?? null)] = $row;
    }
    fclose($h);

    return $rows;
}

/**
 * One product's line, failing the test when it is not in the feed.
 *
 * @param  array<int, array<string, string>>  $rows
 * @return array<string, string>
 */
function feedRow(array $rows, int $id): array
{
    expect(array_key_exists($id, $rows))->toBeTrue("product {$id} is not in the feed");

    return $rows[$id] ?? [];
}

/** @return array<array-key, mixed> the product's listing-index entry on Watchizer */
function feedEntry(int $id): array
{
    foreach (catalogueServices(1)->listing->entries() as $e) {
        if ($e['id'] === $id) {
            return $e;
        }
    }
    throw new RuntimeException("product {$id} is not in the listing index");
}

function feedRun(string $command): PendingCommand
{
    $pending = artisan($command);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

it('writes every visible Watchizer product once, and nothing that is hidden, inactive or on another storefront', function () {
    $visible = feedProduct();
    $hidden = feedProduct();
    DB::table('storefront_product')->where('product_id', $hidden)->update(['is_visible' => 0]);
    $inactive = feedProduct(['is_active' => 0]);
    $elsewhere = CatalogFixture::product('watch');
    CatalogFixture::onStorefront($elsewhere, true, CatalogFixture::secondStorefront());

    $rows = feedRows();
    $expected = DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->count();

    expect(array_key_exists($visible, $rows))->toBeTrue()
        ->and(array_key_exists($hidden, $rows))->toBeFalse()
        ->and(array_key_exists($inactive, $rows))->toBeFalse()
        ->and(array_key_exists($elsewhere, $rows))->toBeFalse()
        ->and(count($rows))->toBe($expected);
});

it('states availability per product by the shop rule, and which stock makes it buyable', function () {
    $express = feedProduct(['stock_express' => 3, 'stock_market' => 0]);
    $market = feedProduct(['stock_express' => 0, 'stock_market' => 5]);
    $out = feedProduct(['stock_express' => 0, 'stock_market' => 0]);

    $rows = feedRows();
    expect(feedRow($rows, $express)['availability'] ?? null)->toBe('in stock')
        ->and(feedRow($rows, $express)['custom_label_2'] ?? null)->toBe('express')
        ->and(feedRow($rows, $market)['availability'] ?? null)->toBe('in stock')
        ->and(feedRow($rows, $market)['custom_label_2'] ?? null)->toBe('market')
        ->and(feedRow($rows, $out)['availability'] ?? null)->toBe('out of stock')
        ->and(feedRow($rows, $out)['custom_label_2'] ?? null)->toBe('');
});

it('writes sale_price only when catalogPrice is below the selling price, at the value checkout charges', function () {
    $real = feedProduct(['selling_price' => '1000.00', 'sale_price' => '799.50']);
    $zero = feedProduct(['selling_price' => '1000.00', 'sale_price' => '0.00']);
    $none = feedProduct(['selling_price' => '1000.00', 'sale_price' => null]);
    $equal = feedProduct(['selling_price' => '1000.00', 'sale_price' => '1000.00']);
    $above = feedProduct(['selling_price' => '1000.00', 'sale_price' => '1200.00']);

    $rows = feedRows();
    expect(feedRow($rows, $real)['price'] ?? null)->toBe('1000.00 EGP')
        ->and(feedRow($rows, $real)['sale_price'] ?? null)->toBe(number_format(CompatCart::catalogPrice('1000.00', '799.50'), 2, '.', '').' EGP')
        ->and(feedRow($rows, $real)['sale_price'] ?? null)->toBe('799.50 EGP');
    foreach ([$zero, $none, $equal, $above] as $id) {
        expect(feedRow($rows, $id)['sale_price'] ?? null)->toBe('')
            ->and(feedRow($rows, $id)['price'] ?? null)->toBe('1000.00 EGP');
    }
});

it('puts every image on the configured host, absolute, the gallery in its order and at most 20', function () {
    config()->set('feeds.meta.watchizer.image_host', 'https://images.example.test');
    $id = feedProduct();
    foreach (range(25, 1) as $sort) {                     // inserted in reverse: the order must come from `sort`
        DB::table('catalog_product_images')->insert([
            'product_id' => $id, 'path' => "Product_image/g{$sort}.webp", 'is_cover' => 0, 'sort' => $sort,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $bare = feedProduct();

    $rows = feedRows();
    $gallery = explode(',', feedRow($rows, $id)['additional_image_link'] ?? '');
    expect(feedRow($rows, $id)['image_link'] ?? null)->toBe('https://images.example.test/Uploads_Images/Product/4b-fixture.webp')
        ->and($gallery)->toHaveCount(MetaFeed::MAX_ADDITIONAL_IMAGES)
        ->and($gallery[0])->toBe('https://images.example.test/Uploads_Images/Product_image/g1.webp')
        ->and($gallery[19] ?? null)->toBe('https://images.example.test/Uploads_Images/Product_image/g20.webp')
        ->and(feedRow($rows, $bare)['additional_image_link'] ?? null)->toBe('');
});

it('links the URL the shop routes, on the configured domain, never storefronts.domain', function () {
    DB::table('storefronts')->where('id', 1)->update(['domain' => 'wrong.example.test']);
    $id = feedProduct();
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => 'Feed Link Probe 42']);

    $slug = T::str(feedEntry($id)['slug'] ?? null);
    expect(feedRow(feedRows(), $id)['link'] ?? null)->toBe('https://watchizereg.com/product/'.$slug)
        ->and($slug)->toBe('feed-link-probe-42');
});

it('writes the shop category path, brand and top category, and omits the path when there is none', function () {
    $placed = feedProduct();
    $unplaced = CatalogFixture::product('watch');
    CatalogFixture::onStorefront($unplaced);

    $rows = feedRows();
    $brandId = feedEntry($placed)['brands'] ?? null;
    $brand = (string) catalogueServices(1)->names->name('brands', is_int($brandId) ? $brandId : null, 'en');
    expect($brand)->not->toBe('')
        ->and(feedRow($rows, $placed)['product_type'] ?? null)->toBe('Watches > Diver')
        ->and(feedRow($rows, $placed)['custom_label_1'] ?? null)->toBe('Watches')
        ->and(feedRow($rows, $placed)['custom_label_0'] ?? null)->toBe($brand)
        ->and(feedRow($rows, $placed)['brand'] ?? null)->toBe($brand)
        ->and(feedRow($rows, $unplaced)['product_type'] ?? null)->toBe('')
        ->and(feedRow($rows, $unplaced)['custom_label_1'] ?? null)->toBe('');
});

it('escapes commas and quotes, and puts a multi-line description on one line', function () {
    $id = feedProduct();
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update([
        'title' => 'Steel, "Gold" & Co',
        'short_description' => "<p>First line,\nsecond &amp; \"third\"</p>",
    ]);

    $raw = (string) file_get_contents(app(MetaFeed::class)->generate('watchizer')['path']);
    $row = feedRow(feedRows(), $id);
    expect($row['title'] ?? null)->toBe('Steel, "Gold" & Co')
        ->and($row['description'] ?? null)->toBe('First line, second & "third"')
        ->and($raw)->toContain('"Steel, ""Gold"" & Co"');
});

it('writes the same bytes whatever the chunk size', function () {
    feedProduct();
    feedProduct(['stock_express' => 0, 'stock_market' => 2]);
    $path = MetaFeed::path('watchizer');

    app(MetaFeed::class)->generate('watchizer', 1);
    $one = (string) file_get_contents($path);
    app(MetaFeed::class)->generate('watchizer', 500);

    expect((string) file_get_contents($path))->toBe($one);
});

it('leaves out an excluded product, and an empty exclude list changes nothing', function () {
    $kept = feedProduct();
    $pulled = feedProduct();
    $path = MetaFeed::path('watchizer');

    config()->set('feeds.meta.watchizer.exclude', []);
    app(MetaFeed::class)->generate('watchizer');
    $withEmpty = (string) file_get_contents($path);
    config()->set('feeds.meta.watchizer', array_diff_key(T::arr(config('feeds.meta.watchizer')), ['exclude' => true]));
    app(MetaFeed::class)->generate('watchizer');
    expect((string) file_get_contents($path))->toBe($withEmpty);

    config()->set('feeds.meta.watchizer.exclude', [$pulled]);
    $rows = feedRows();
    expect(array_key_exists($kept, $rows))->toBeTrue()
        ->and(array_key_exists($pulled, $rows))->toBeFalse();
});

it('keeps the file when nothing changed, so its ETag stays stable', function () {
    feedProduct();
    $first = app(MetaFeed::class)->generate('watchizer');
    $etag = sha1_file($first['path']);
    $second = app(MetaFeed::class)->generate('watchizer');

    expect($first['changed'])->toBeTrue()
        ->and($second['changed'])->toBeFalse()
        ->and(sha1_file($second['path']))->toBe($etag)
        ->and(glob(dirname($second['path']).'/*.tmp-*'))->toBe([]);
});

it('reports a value over a Meta limit and writes it whole, never cut', function () {
    $id = feedProduct();
    $long = str_repeat('A', 230);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => $long]);

    $r = app(MetaFeed::class)->generate('watchizer');
    expect($r['over_limit'])->toContain(['id' => $id, 'field' => 'title', 'length' => 230, 'limit' => 200])
        ->and(feedRow(feedRows(), $id)['title'] ?? null)->toBe($long);
});

it('serves the file with an ETag, and 304 when Meta already has it', function () {
    feedProduct();
    $path = app(MetaFeed::class)->generate('watchizer')['path'];
    $etag = '"'.sha1_file($path).'"';

    get('/feeds/meta/watchizer/'.FEED_TOKEN.'.csv')
        ->assertOk()
        ->assertHeader('ETag', $etag)
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertCookieMissing('XSRF-TOKEN');
    withHeaders(['If-None-Match' => $etag])->get('/feeds/meta/watchizer/'.FEED_TOKEN.'.csv')->assertStatus(304);
});

it('answers one plain 404 for a wrong token, an unconfigured storefront and a missing file — never a fallback', function () {
    feedProduct();
    app(MetaFeed::class)->generate('watchizer');                       // Watchizer's file exists
    config()->set('feeds.meta.brandfashion', null);

    $wrong = get('/feeds/meta/watchizer/'.str_repeat('x', 40).'.csv');
    $other = get('/feeds/meta/brandfashion/'.FEED_TOKEN.'.csv');       // Watchizer's token, BF's URL
    config()->set('feeds.meta.brandfashion', T::arr(config('feeds.meta.watchizer')));
    $noFile = get('/feeds/meta/brandfashion/'.FEED_TOKEN.'.csv');      // configured, never generated

    foreach ([$wrong, $other, $noFile] as $response) {
        $response->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex')->assertSee('Not found');
    }
    expect(is_file(MetaFeed::path('brandfashion')))->toBeFalse();
});

it('never builds a feed on a request', function () {
    app()->bind(MetaFeed::class, fn () => throw new RuntimeException('a feed was built on a request'));

    get('/feeds/meta/watchizer/'.FEED_TOKEN.'.csv')->assertNotFound();   // no file yet: 404, nothing built
    expect(is_file(MetaFeed::path('watchizer')))->toBeFalse();
});

it('generates for configured storefronts only, and refuses one that has no feed', function () {
    config()->set('feeds.meta.watchizer.token', '');
    feedRun('feeds:meta')->expectsOutputToContain('nothing written')->assertSuccessful()->run();
    feedRun('feeds:meta --storefront=watchizer')->assertFailed()->run();
    expect(is_file(MetaFeed::path('watchizer')))->toBeFalse();

    config()->set('feeds.meta.watchizer.token', FEED_TOKEN);
    feedRun('feeds:meta')->expectsOutputToContain('storefront watchizer:')->assertSuccessful()->run();
    expect(is_file(MetaFeed::path('watchizer')))->toBeTrue();
});

it('leaves one log line per storefront saying whether the file was replaced or left unchanged', function () {
    $log = Scratch::dir('feed-log').'/scheduled.log';
    config()->set('logging.channels.scheduled.path', $log);
    feedProduct();

    feedRun('feeds:meta')->assertSuccessful()->run();
    feedRun('feeds:meta')->assertSuccessful()->run();
    $lines = array_values(array_filter(explode('
', (string) file_get_contents($log)), fn (string $l): bool => str_contains($l, 'feeds:meta')));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('.INFO: feeds:meta storefront=watchizer products=')
        ->and($lines[0])->toContain('file=replaced')
        ->and($lines[1] ?? '')->toContain('file=unchanged')
        ->and($lines[1] ?? '')->toMatch('/products=\d+ bytes=\d+ file=unchanged ms=\d+ over_limit=0(\s|$)/');
});

it('writes nothing to the log when no feed is switched on, and one warning when a token is set but unusable', function () {
    $log = Scratch::dir('feed-log-off').'/scheduled.log';
    config()->set('logging.channels.scheduled.path', $log);

    config()->set('feeds.meta.watchizer.token', '');                     // off: no token
    feedRun('feeds:meta')->assertSuccessful()->run();
    expect(is_file($log) ? (string) file_get_contents($log) : '')->toBe('');

    config()->set('feeds.meta.watchizer.token', str_repeat('a', 39));    // meant to be on, wrong shape
    feedRun('feeds:meta')->assertFailed()->run();
    $lines = array_values(array_filter(explode('
', (string) file_get_contents($log)), fn (string $l): bool => $l !== ''));
    expect($lines)->toHaveCount(1)
        ->and($lines[0] ?? '')->toContain('.WARNING: feeds:meta storefront=watchizer token set but unusable')
        ->and(is_file(MetaFeed::path('watchizer')))->toBeFalse();
});

it('is scheduled hourly at :40, without overlapping', function () {
    $events = array_values(array_filter(app(Schedule::class)->events(), fn ($e) => str_contains((string) $e->command, 'feeds:meta')));
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression ?? null)->toBe('40 * * * *')
        ->and($events[0]->withoutOverlapping ?? null)->toBeTrue();
});
