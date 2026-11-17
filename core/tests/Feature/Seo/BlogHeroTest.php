<?php

use App\Console\Commands\BlogHeroesCommand;
use App\Domain\Catalog\SearchExposure;
use App\Domain\Content\BlogHero;
use App\Domain\Content\BlogWriter;
use App\Domain\Inventory\StockWriteGuard;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CatalogFixture;
use Tests\Support\Shops;
use Tests\Support\SideDdl;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * AN ARTICLE'S HERO PICTURE (Michael, 2026-11-17): a photo from our own catalogue — of the article's brand or kind, in
 * stock and visible on its shop, never one the search-exposure rules keep back — stored once (it never changes on a
 * rebuild), with its alt text in both languages; the team's choice wins and is never replaced by the rule.
 */

const HERO_KEY = 'blog-hero-test-key';

beforeEach(function () {
    config(['compat.api_key' => HERO_KEY]);
});

/** A product of that brand on Brand Fashion, in stock or not, with that English title and one photo of that width. */
function heroProduct(string $brandSlug, string $title, bool $inStock = true, float $price = 3000, int $width = 900): int
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
    // a fixture's stock flag, through the guard's own door (the transform's)
    StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $id)->update(['brand_id' => T::int($brand), 'in_stock' => $inStock ? 1 : 0]));
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => $title]);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->update(['title' => 'ساعة '.$title]);
    DB::table('catalog_product_images')->where('product_id', $id)->update(['width' => $width, 'height' => $width]);
    app(SearchExposure::class)->refresh($id);
    CatalogFixture::onStorefront($id, true, 2);

    return $id;
}

function heroArticle(string $slug, ?int $brandId = null, string $family = 'watch'): int
{
    $id = app(BlogWriter::class)->save(null, [
        'storefront_id' => 2, 'slug' => $slug, 'is_published' => true,
        'title' => ['ar' => 'عنوان '.$slug, 'en' => 'Title '.$slug], 'body' => ['ar' => 'نص', 'en' => 'Text'],
        'meta_title' => ['ar' => '', 'en' => ''], 'meta_description' => ['ar' => '', 'en' => ''],
    ]);
    DB::table('core_blogs')->where('id', $id)->update(['brand_id' => $brandId, 'family' => $family, 'cover_path' => null, 'hero_image_id' => null, 'hero_source' => null]);

    return $id;
}

/**
 * One row as an object (the helpers read stdClass).
 *
 * @param  list<string>  $columns
 */
function heroRow(string $table, int $id, array $columns): stdClass
{
    return (object) T::arr((array) DB::table($table)->where('id', $id)->first($columns));
}

function heroImageOf(int $productId): int
{
    return T::int(DB::table('catalog_product_images')->where('product_id', $productId)->orderByDesc('is_cover')->orderBy('id')->value('id'));
}

it("picks an in-stock photo of the article's brand, stores it with both alt texts, and keeps it on the next run", function () {
    $brandProduct = heroProduct('herotest-brand', 'Herotest Chronograph One');
    heroProduct('herotest-brand', 'Herotest Sold Out', false, 9000);
    $brand = T::int(DB::table('catalog_products')->where('id', $brandProduct)->value('brand_id'));
    app(StorefrontCache::class)->flush(2);
    $id = heroArticle('how-to-choose-a-herotest-chronograph', $brand);

    $pick = app(BlogHero::class)->ensure($id);
    expect($pick)->not->toBeNull()
        ->and(T::arr($pick)['product_id'])->toBe($brandProduct);  // the dearer one is sold out
    $row = T::arr((array) DB::table('core_blogs')->where('id', $id)->first());
    expect($row['hero_source'])->toBe(BlogHero::AUTO)
        ->and($row['hero_image_id'])->toBe(heroImageOf($brandProduct))
        ->and(DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'ar')->value('cover_alt'))->toBe('ساعة Herotest Chronograph One');
    // stored: a second run changes nothing
    expect(app(BlogHero::class)->ensure($id))->toBeNull();
});

it('never picks a product the search-exposure rules keep back, and re-picks when its product becomes one', function () {
    $open = heroProduct('herotest-two', 'Herotest Two Diver');
    $brand = T::int(DB::table('catalog_products')->where('id', $open)->value('brand_id'));
    $copy = heroProduct('herotest-two', 'Herotest Two Diver mirror copy', true, 99000);
    expect(SearchExposure::effective(heroRow('catalog_products', $copy, ['search_class', 'search_override'])))->toBe('hidden');
    $id = heroArticle('herotest-two-diver-guide', $brand);

    expect(T::arr(app(BlogHero::class)->ensure($id))['product_id'])->toBe($open);   // never the dearer copy
    // the team hides the product from search: the next run replaces the hero
    DB::table('catalog_products')->where('id', $open)->update(['search_override' => 0]);
    expect(app(BlogHero::class)->reason(heroRow('core_blogs', $id, BlogHero::COLUMNS)))->toBe('its product is now kept from search');
});

it("keeps the team's choice, refuses a product kept from search, and shows the hero in the shop's API", function () {
    $p = heroProduct('herotest-three', 'Herotest Three Pilot');
    $hidden = heroProduct('herotest-three', 'Herotest Three replica', true, 500);
    $id = heroArticle('herotest-three-pilot');

    // a product kept from search is refused before anything is written
    expect(fn () => app(BlogWriter::class)->save($id, [
        'storefront_id' => 2, 'slug' => 'herotest-three-pilot', 'is_published' => true, 'hero_product' => (string) $hidden,
        'title' => ['ar' => 'عنوان', 'en' => 'Title'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
        'meta_title' => ['ar' => '', 'en' => ''], 'meta_description' => ['ar' => '', 'en' => ''],
    ]))->toThrow(ValidationException::class);

    app(BlogWriter::class)->save($id, [
        'storefront_id' => 2, 'slug' => 'herotest-three-pilot', 'is_published' => true, 'hero_product' => (string) $p,
        'title' => ['ar' => 'عنوان', 'en' => 'Title'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
        'meta_title' => ['ar' => '', 'en' => ''], 'meta_description' => ['ar' => '', 'en' => ''],
    ]);
    expect(DB::table('core_blogs')->where('id', $id)->value('hero_source'))->toBe(BlogHero::DASHBOARD)
        ->and(app(BlogHero::class)->ensure($id, true))->toBeNull();        // --force never replaces the team's choice

    // the shop's API: the compat route answers for the host's storefront
    $blog = T::arr(withHeaders(['Api-Code' => HERO_KEY])->getJson(Shops::api('brandfashion').'/api/catalog/blog?slug=herotest-three-pilot')->assertOk()->json('blog'));
    $hero = T::arr($blog['hero'] ?? []);
    expect(T::str($hero['src'] ?? ''))->toContain('/Uploads_Images/')
        ->and(T::str($hero['src'] ?? ''))->not->toContain('Uploads_Images/Uploads_Images')
        ->and(T::arr($hero['alt'] ?? [])['en'] ?? null)->toBe('Herotest Three Pilot')
        ->and(T::arr($hero['product'] ?? [])['slug'] ?? null)->not->toBeNull()
        ->and(T::str($blog['cover'] ?? ''))->toBe(T::str($hero['src'] ?? ''));
});

it('stores a cover path in one form and never doubles the upload folder', function () {
    expect(\App\Compat\CompatBlogs::stored('/Uploads_Images/Product_image/a.webp'))->toBe('Product_image/a.webp')
        ->and(\App\Compat\CompatBlogs::stored('Uploads_Images/Product/b.webp'))->toBe('Product/b.webp')
        ->and(\App\Compat\CompatBlogs::stored('Blog/c.webp'))->toBe('Blog/c.webp')
        ->and(\App\Compat\CompatBlogs::stored('  '))->toBeNull();
});

it('runs blog:heroes as a dry run by default, backs up and undoes with --apply / --undo', function () {
    // first, before anything is read: else the command's CREATE commits this test's transaction (or, made on the
    // side, the snapshot predates the table and MariaDB answers 1412)
    SideDdl::run(BlogHeroesCommand::backupDdl(), freshSnapshot: true);
    $p = heroProduct('herotest-four', 'Herotest Four Field');
    $brand = T::int(DB::table('catalog_products')->where('id', $p)->value('brand_id'));
    $id = heroArticle('herotest-four-field-watch', $brand);
    DB::table('core_blogs')->where('id', $id)->update(['cover_path' => 'Product_image/old.webp']);

    Artisan::call('blog:heroes', ['--shop' => 'brandfashion']);
    expect(DB::table('core_blogs')->where('id', $id)->value('cover_path'))->toBe('Product_image/old.webp');

    Artisan::call('blog:heroes', ['--shop' => 'brandfashion', '--apply' => true]);
    expect(DB::table('core_blogs')->where('id', $id)->value('hero_image_id'))->toBe(heroImageOf($p))
        ->and(DB::table(BlogHeroesCommand::BACKUP)->where('blog_id', $id)->value('cover_path'))->toBe('Product_image/old.webp');

    Artisan::call('blog:heroes', ['--undo' => true]);
    expect(DB::table('core_blogs')->where('id', $id)->value('cover_path'))->toBe('Product_image/old.webp')
        ->and(DB::table('core_blogs')->where('id', $id)->value('hero_image_id'))->toBeNull();
});
