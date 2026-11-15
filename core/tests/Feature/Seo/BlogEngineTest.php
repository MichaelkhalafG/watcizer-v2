<?php

use App\Console\Commands\BlogImportCommand;
use App\Domain\Content\ArabicSlug;
use App\Domain\Content\BlogWriter;
use App\Domain\Content\DataBlocks;
use App\Domain\Inventory\StockWriteGuard;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * THE BLOG ENGINE (SEO Phase 3, 2026-11-14): Arabic slugs, scheduled publication with a pause switch, the article's live
 * data blocks resolved by core (every LIVE product, in stock first; counts over all LIVE products), and the nightly
 * comparison that dates an article only on a REAL change — never on a stock flip.
 */
const ENGINE_KEY = 'blog-engine-test-key';

beforeEach(function () {
    config(['compat.api_key' => ENGINE_KEY]);
    DB::table('core_blog_translations')->delete();
    DB::table('core_blogs')->delete();
    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode(array_diff_key(
        T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings') ?? '{}'), true) ?: []), ['blog' => 1]))]);
});

/**
 * A brand of its own on storefront 1: three watches, the last out of stock.
 *
 * @return array{slug: string, ids: list<int>}
 */
function engineBrand(): array
{
    $slug = 'be-brand-'.bin2hex(random_bytes(3));
    $brand = T::int(DB::table('catalog_brands')->insertGetId(['slug' => $slug, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    DB::table('catalog_brand_translations')->insert([['brand_id' => $brand, 'locale' => 'en', 'name' => 'BE '.$brand], ['brand_id' => $brand, 'locale' => 'ar', 'name' => 'ب '.$brand]]);
    $ids = [];
    foreach ([2000.0, 3000.0, 4000.0] as $i => $price) {
        $id = CatalogFixture::product('watch', $price);
        DB::table('catalog_products')->where('id', $id)->update(['brand_id' => $brand, 'search_class' => null, 'search_override' => null]);
        StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update(['in_stock' => $i < 2 ? 1 : 0]));
        CatalogFixture::onStorefront($id, true, 1);
        // the placement's price is the fixture's 500 for everyone; this brand's are its own
        DB::table('storefront_product')->where('product_id', $id)->where('storefront_id', 1)->update(['effective_price' => $price]);
        $ids[] = $id;
    }
    app(StorefrontCache::class)->flush(1);

    return ['slug' => $slug, 'ids' => $ids];
}

function engineArticle(string $slug, string $bodyEn, string $status = 'published', ?string $publishAt = null): int
{
    $id = app(BlogWriter::class)->save(null, [
        'storefront_id' => 1, 'slug' => $slug, 'is_published' => $status === 'published',
        'title' => ['ar' => 'ساعاتُ تومي هيلفيغر رجالي', 'en' => 'Title '.$slug],
        'body' => ['ar' => 'نص', 'en' => $bodyEn],
        'meta_title' => ['ar' => '', 'en' => ''], 'meta_description' => ['ar' => '', 'en' => ''],
    ]);
    if ($status !== 'published') {
        DB::table('core_blogs')->where('id', $id)->update(['status' => $status, 'publish_at' => $publishAt]);
    }

    return $id;
}

it('makes Arabic slugs without diacritics, hyphenated, cut at a word, unique, and keeps them once published', function () {
    expect(ArabicSlug::make('ساعاتُ تومي، هيلفيغر؟ — رجالي ٢٠٢٦!'))->toBe('ساعات-تومي-هيلفيغر-رجالي-2026')
        ->and(ArabicSlug::make('إطلالة أنيقة'))->toBe('اطلالة-انيقة')
        ->and(mb_strlen(ArabicSlug::make(str_repeat('كلمة ', 40))))->toBeLessThanOrEqual(ArabicSlug::MAX);

    $a = engineArticle('first', 'x');
    $b = engineArticle('second', 'y');
    expect(DB::table('core_blogs')->where('id', $a)->value('slug_ar'))->toBe('ساعات-تومي-هيلفيغر-رجالي')
        ->and(DB::table('core_blogs')->where('id', $b)->value('slug_ar'))->toBe('ساعات-تومي-هيلفيغر-رجالي-2');

    // published: a new Arabic title does not move the address
    app(BlogWriter::class)->save($a, ['storefront_id' => 1, 'slug' => 'first', 'is_published' => true, 'title' => ['ar' => 'عنوان جديد', 'en' => 'T'], 'body' => ['ar' => 'نص', 'en' => 'x']]);
    expect(DB::table('core_blogs')->where('id', $a)->value('slug_ar'))->toBe('ساعات-تومي-هيلفيغر-رجالي');

    // the storefront finds the article by either slug
    $blog = T::arr(withHeaders(['Api-Code' => ENGINE_KEY])->getJson('/api/catalog/blog?slug='.rawurlencode('ساعات-تومي-هيلفيغر-رجالي'))->assertOk()->json('blog'));
    expect($blog['slug'])->toBe('first')->and($blog['slug_ar'])->toBe('ساعات-تومي-هيلفيغر-رجالي')
        ->and(T::str($blog['published_at']))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/');
});

it('publishes what is due, never early, and waits while the shop is paused', function () {
    $due = engineArticle('due', 'x', 'scheduled', now()->subMinute()->format('Y-m-d H:i:s'));
    $later = engineArticle('later', 'x', 'scheduled', now()->addDay()->format('Y-m-d H:i:s'));

    withHeaders(['Api-Code' => ENGINE_KEY])->getJson('/api/catalog/blog?slug=due')->assertNotFound();

    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode(['blog' => ['paused' => true]] + T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings') ?? '{}'), true) ?: []))]);
    Artisan::call('blog:publish-due');
    expect(DB::table('core_blogs')->where('id', $due)->value('status'))->toBe('scheduled');

    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode(array_diff_key(T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings')), true)), ['blog' => 1]))]);
    Artisan::call('blog:publish-due');
    Artisan::call('blog:publish-due');                       // safe to re-run
    expect(DB::table('core_blogs')->where('id', $due)->value('status'))->toBe('published')
        ->and(DB::table('core_blogs')->where('id', $due)->value('published_at'))->not->toBeNull()
        ->and(DB::table('core_blogs')->where('id', $later)->value('status'))->toBe('scheduled');
    withHeaders(['Api-Code' => ENGINE_KEY])->getJson('/api/catalog/blog?slug=due')->assertOk();
    withHeaders(['Api-Code' => ENGINE_KEY])->getJson('/api/catalog/blog?slug=later')->assertNotFound();
});

it('resolves the data blocks: every LIVE product, in stock first; counts and ranges over all LIVE products', function () {
    $b = engineBrand();
    $body = "{{count brand={$b['slug']}}} models from {{price-range brand={$b['slug']} family=watch}}.\n\n{{products brand={$b['slug']} sort=price_desc limit=3}}\n\n{{count brand=no-such-brand}}";
    engineArticle('blocks', $body);
    $blog = T::arr(withHeaders(['Api-Code' => ENGINE_KEY])->getJson('/api/catalog/blog?slug=blocks')->assertOk()->json('blog'));
    $blocks = T::arr($blog['blocks']);
    expect(T::arr($blocks["count brand={$b['slug']}"]))->toMatchArray(['kind' => 'count', 'total' => 3, 'available' => 2])
        ->and(T::arr($blocks["price-range brand={$b['slug']} family=watch"]))->toMatchArray(['min' => 2000, 'max' => 4000]);
    $list = T::arr($blocks["products brand={$b['slug']} sort=price_desc limit=3"]);
    // price_desc within each stock group: 3000, 2000 in stock — then the 4000 that is out of stock, still listed
    expect(array_column(T::arr($list['items']), 'id'))->toBe([$b['ids'][1], $b['ids'][0], $b['ids'][2]])
        ->and(array_column(T::arr($list['items']), 'in_stock'))->toBe([true, true, false])
        ->and(T::arr($blocks['count brand=no-such-brand']))->toMatchArray(['total' => 0, 'unknown' => ['brand']]);
});

it('dates an article on a REAL change only: a stock flip moves nothing, a product gone does', function () {
    $b = engineBrand();
    $id = engineArticle('living', "{{products brand={$b['slug']} limit=6}}");
    Artisan::call('blog:refresh-blocks');                    // the first pass records the baseline
    expect(DB::table('core_blogs')->where('id', $id)->value('data_snapshot'))->not->toBeNull()
        ->and(DB::table('core_blogs')->where('id', $id)->value('modified_at'))->toBeNull();

    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $b['ids'][0])->update(['in_stock' => 0]));      // a stock flip
    app(StorefrontCache::class)->flush(1);
    Artisan::call('blog:refresh-blocks');
    expect(DB::table('core_blogs')->where('id', $id)->value('modified_at'))->toBeNull();

    DB::table('catalog_products')->where('id', $b['ids'][1])->update(['is_active' => 0]);     // GONE
    app(StorefrontCache::class)->flush(1);
    Artisan::call('blog:refresh-blocks');
    expect(DB::table('core_blogs')->where('id', $id)->value('modified_at'))->not->toBeNull();
});

it('compares snapshots with the brief\'s thresholds', function () {
    $was = ['t' => ['ids' => [], 'set' => 'a', 'total' => 100, 'min' => 1000, 'max' => 2000, 'missing' => []]];
    expect(DataBlocks::realChanges($was, $was))->toBe([])
        ->and(DataBlocks::realChanges($was, ['t' => ['set' => 'a', 'total' => 108, 'min' => 1040, 'max' => 2000, 'missing' => []] + $was['t']]))->toBe([])
        ->and(DataBlocks::realChanges($was, ['t' => ['total' => 115] + $was['t']]))->toHaveCount(1)
        ->and(DataBlocks::realChanges($was, ['t' => ['min' => 1100] + $was['t']]))->toHaveCount(1)
        ->and(DataBlocks::realChanges($was, ['t' => ['set' => 'b'] + $was['t']]))->toHaveCount(1);
});

it('imports a batch: what passes is scheduled one a day from the start date, never three of a cluster in a row', function () {
    $rows = array_map(fn ($c) => ['cluster' => $c], ['a', 'a', 'a', 'b', 'a']);
    expect(array_column(BlogImportCommand::spread($rows), 'cluster'))->toBe(['a', 'a', 'b', 'a', 'a']);

    $dir = sys_get_temp_dir().'/blog-import-'.bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents($dir.'/one.en.md', "---\nslug: imported-one\ntitle: Imported one\nmeta_description: x\nbrand: no-such-brand\ncluster: c1\n---\n## Too short\nA few words.\n");
    file_put_contents($dir.'/one.ar.md', "---\nslug: مقال مستورد\ntitle: مقال مستورد\n---\n## قصير\nكلمات قليلة.\n");
    Artisan::call('blog:import', ['shop' => 'watchizer', 'folder' => $dir, '--start' => '2027-01-10', '--apply' => true]);
    $row = DB::table('core_blogs')->where('slug', 'imported-one')->first();
    // far below the minimum length: HELD with its reasons, never scheduled, never published
    expect($row)->not->toBeNull()
        ->and(T::str(data_get($row, 'status')))->toBe('held')
        ->and(data_get($row, 'publish_at'))->toBeNull()
        ->and(data_get($row, 'published_at'))->toBeNull()
        ->and(T::str(data_get($row, 'slug_ar')))->toBe('مقال-مستورد')
        ->and(T::str(data_get($row, 'gate_report')))->toContain('words of prose');
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});
