<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Import\BrandFashion\CataloguePrepare;
use App\Domain\Import\BrandFashion\LegacyBlogs;
use App\Domain\Import\CategoryMerger;
use App\Models\Storefront\StorefrontRedirect;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\CatalogFixture;

/*
 * The decisions of 2026-11-11 applied before and after the legacy import: the prepare step (D-19,
 * D-10, D-12, D-15) with its undo, and the article migration (D-13). Each test makes its own input.
 */

/** A one-record products.jsonl naming a brand and a category path. */
function decisionsExtract(string $brand, string $path): string
{
    $file = tempnam(sys_get_temp_dir(), 'bfx');
    file_put_contents($file, json_encode(['legacy_id' => 990000201, 'brand' => $brand, 'category_paths' => [$path], 'images' => [], 'regular' => 100.0])."\n");

    return $file;
}

/** A closure type's Arabic name by its English one (null when there is no such row). */
function decisionsClosureAr(string $en): ?string
{
    $id = DB::table('catalog_closure_type_translations')->where('locale', 'en')->where('name', $en)->value('closure_type_id');

    return $id === null ? null : Coerce::nstr(DB::table('catalog_closure_type_translations')->where('closure_type_id', $id)->where('locale', 'ar')->value('name'));
}

it('creates the lookups, the missing brand, switches the categories on and adds SATIN10 — and undoes it', function () {
    CatalogFixture::assumeSwitched();
    $node = CategoryMerger::find(2, 'fashion/materials/satin');
    if ($node === null) {
        Assert::markTestSkipped('this copy has no fashion/materials/satin node on Brand Fashion');
    }
    DB::table('storefront_categories')->where('id', $node)->update(['is_active' => 0, 'show_in_menu' => 0]);
    DB::table('promotion_rules')->where('code', CataloguePrepare::SATIN10['code'])->delete();
    $brand = 'Bft Brand '.substr(md5((string) microtime(true)), 0, 6);

    $prepare = app(CataloguePrepare::class);
    $plan = $prepare->plan(decisionsExtract($brand, 'fashion/materials/satin'));
    expect(array_column($plan['brands'], 'name'))->toContain($brand)
        ->and(array_column($plan['nodes'], 'id'))->toContain($node)
        ->and($plan['coupon']['category_id'])->toBe($node);

    $undo = $prepare->apply($plan);

    $brandId = Coerce::int(DB::table('catalog_brand_translations')->where('locale', 'en')->where('name', $brand)->value('brand_id'));
    $rule = Coerce::int($undo['rule']);
    expect($brandId)->toBeGreaterThan(0)
        ->and(DB::table('storefront_categories')->where('id', $node)->value('is_active'))->toEqual(1)
        ->and(DB::table('storefront_categories')->where('id', $node)->value('show_in_menu'))->toEqual(1)   // on = active AND in the menu
        ->and(DB::table('catalog_unit_translations')->where('locale', 'en')->where('name', 'metres')->exists())->toBeTrue()
        ->and(DB::table('catalog_material_translations')->where('locale', 'en')->where('name', 'Mineral')->exists())->toBeTrue()
        ->and(DB::table('catalog_closure_type_translations')->where('locale', 'en')->where('name', 'Clasp')->exists())->toBeTrue()
        // O-3: Buckle's Arabic is إبزيم, and مشبك is Clasp's — one row per Arabic name, as the dashboard demands
        ->and(decisionsClosureAr('Buckle'))->toBe('إبزيم')
        ->and(decisionsClosureAr('Clasp'))->toBe('مشبك')
        ->and(DB::table('catalog_closure_type_translations')->where('locale', 'ar')->where('name', 'مشبك')->count())->toBe(1)
        ->and(DB::table('promotion_rules')->where('id', $rule)->value('code'))->toBe('SATIN10')
        ->and(DB::table('promotion_rule_storefront')->where('promotion_rule_id', $rule)->pluck('storefront_id')->all())->toBe([2])
        ->and(DB::table('promotion_rule_scopes')->where('promotion_rule_id', $rule)->where('dimension', 'category')->value('value_id'))->toBe($node)
        ->and(DB::table('promotion_rule_rewards')->where('promotion_rule_id', $rule)->value('type'))->toBe('percent_discount')
        ->and(DB::table('core_activity_log')->where('subject_type', 'promotion_rules')->where('subject_id', $rule)->where('action', ActivityLog::CREATED)->exists())->toBeTrue()
        ->and(DB::table('core_activity_log')->where('subject_type', 'storefront_categories')->where('subject_id', $node)->where('action', ActivityLog::UPDATED)->exists())->toBeTrue();

    $prepare->restore($undo);

    expect(DB::table('storefront_categories')->where('id', $node)->value('is_active'))->toEqual(0)
        ->and(DB::table('storefront_categories')->where('id', $node)->value('show_in_menu'))->toEqual(0)
        ->and(DB::table('promotion_rules')->where('id', $rule)->value('is_active'))->toEqual(0)
        ->and(DB::table('catalog_brands')->where('id', $brandId)->exists())->toBeFalse()      // unused: deleted
        ->and(decisionsClosureAr('Clasp'))->toBeNull()                                         // unused: deleted
        ->and(decisionsClosureAr('Buckle'))->toBe('مشبك');                                     // and Buckle's Arabic back
});

it('never creates the confirmed aliases or H and X as brands', function () {
    $prepare = app(CataloguePrepare::class);
    foreach (['AP', 'Carti', 'Karl', 'patek philip', 'H', 'X'] as $name) {
        expect(array_column($prepare->plan(decisionsExtract($name, 'watches'))['brands'], 'old'))->not->toContain($name);
    }
});

it('migrates an old article: Markdown, links to the new URLs, our images, a draft without Arabic', function () {
    $uploads = sys_get_temp_dir().'/bft-blog-'.uniqid();
    mkdir($uploads.'/2024/10', 0777, true);
    imagepng(imagecreatetruecolor(800, 500), $uploads.'/2024/10/bft-bag.png');
    config()->set('media.root', sys_get_temp_dir().'/bft-blog-media-'.uniqid());
    DB::table('storefront_redirects')->insert([
        'storefront_id' => 2, 'from_hash' => StorefrontRedirect::hashPath('/product-category/men/men-wallets-men'), 'from_path' => '/product-category/men/men-wallets-men',
        'to_path' => '/category/wallets', 'status' => 301, 'source' => 'bf_legacy', 'hits' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $blogs = app(LegacyBlogs::class);
    $r = $blogs->migrate([
        'slug' => '990000301-2', 'title' => 'BFT Wallet &amp; Bag Care', 'title_ar' => '', 'published_at' => '2024-10-01 10:00:00',
        'body_en_html' => "<h1>Care</h1>\nSee <a href=\"https://brandfashionegy.com/product-category/men/men-wallets-men/\">wallets</a>.\n<img src=\"https://www.brandfashionegy.com/wp-content/uploads/2024/10/bft-bag-1024x640.png\" alt=\"bag\">",
        'body_ar_html' => '', 'cover_file' => null, 'seo' => ['title' => '%title% %sep% %sitename%', 'description' => 'How to care.'],
    ], $uploads, true);

    $blog = DB::table('core_blogs')->where('id', $r['id'])->first();
    $en = DB::table('core_blog_translations')->where('blog_id', $r['id'])->where('locale', 'en')->first();
    expect($r['slug'])->toBe('bft-wallet-bag-care')                   // a numeric WordPress slug is rebuilt from the title
        ->and(LegacyBlogs::newSlug('990000301-2', 'BFT Wallet &amp; Bag Care'))->toBe($r['slug'])
        ->and(Coerce::nstr($blog?->published_at))->toBeNull()          // no Arabic → a draft
        ->and(Coerce::int($blog?->storefront_id))->toBe(2)
        ->and(Coerce::str($en?->body))->toContain('[wallets](/category/wallets)')
        ->and(Coerce::str($en?->body))->toMatch('#!\[bag\]\(/Uploads_Images/Banner_home/[^)]+\.webp\)#')
        ->and(Coerce::str($en?->meta_title))->toBe('BFT Wallet & Bag Care - Brand Fashion')
        ->and(DB::table('core_activity_log')->where('subject_type', 'core_blogs')->where('subject_id', $r['id'])->exists())->toBeTrue()
        ->and($blogs->migrate(['slug' => '990000301-2', 'title' => 'BFT Wallet &amp; Bag Care'], $uploads, true)['action'])->toBe('already');
});

it('publishes an old article with its Arabic from the translation file, on its old date, links and images re-pointed', function () {
    $uploads = sys_get_temp_dir().'/bft-blog-ar-'.uniqid();
    mkdir($uploads.'/2024/10', 0777, true);
    imagepng(imagecreatetruecolor(800, 500), $uploads.'/2024/10/bft-bag-ar.png');
    config()->set('media.root', sys_get_temp_dir().'/bft-blog-ar-media-'.uniqid());
    DB::table('storefront_redirects')->insert([
        'storefront_id' => 2, 'from_hash' => StorefrontRedirect::hashPath('/product-category/women/women-bags'), 'from_path' => '/product-category/women/women-bags',
        'to_path' => '/subtypes/bags', 'status' => 301, 'source' => 'bf_legacy', 'hits' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $img = '<img src="https://brandfashionegy.com/wp-content/uploads/2024/10/bft-bag-ar.png" alt="%s" />';

    $r = app(LegacyBlogs::class)->migrate([
        'slug' => 'bft-bag-care-ar', 'title' => 'BFT Bag Care', 'title_ar' => '', 'published_at' => '2024-10-10 07:54:18',
        'body_en_html' => 'Keep your <a href="https://brandfashionegy.com/product-category/women/women-bags/">bags</a> dry.'.'
'.sprintf($img, 'bag'),
        'body_ar_html' => '', 'cover_file' => null, 'seo' => ['title' => 'BFT Bag Care 2024', 'description' => 'How to care.'],
    ], $uploads, true, 'Brand Fashion', [
        'title' => 'العناية بالحقائب', 'meta_title' => 'العناية بالحقائب 2024', 'meta_description' => 'كيف تعتني بحقيبتك.',
        'body_html' => 'حافظ على <a href="https://brandfashionegy.com/product-category/women/women-bags/">حقائبك</a> جافة.'.'
'.sprintf($img, 'حقيبة'),
    ]);

    $blog = DB::table('core_blogs')->where('id', $r['id'])->first();
    $ar = DB::table('core_blog_translations')->where('blog_id', $r['id'])->where('locale', 'ar')->first();
    expect(Coerce::str($blog?->published_at))->toStartWith('2024-10-10 07:54:18')   // published, on the old date
        ->and(Coerce::str($ar?->title))->toBe('العناية بالحقائب')
        ->and(Coerce::str($ar?->meta_title))->toBe('العناية بالحقائب 2024')
        ->and(Coerce::str($ar?->meta_description))->toBe('كيف تعتني بحقيبتك.')
        ->and(Coerce::str($ar?->body))->toContain('[حقائبك](/subtypes/bags)')       // the old link, re-pointed like the English one
        ->and(Coerce::str($ar?->body))->toMatch('#!\[حقيبة\]\(/Uploads_Images/Banner_home/[^)]+\.webp\)#u')
        ->and(Coerce::str($ar?->body))->not->toContain('brandfashionegy.com')
        ->and($r['images'])->toBe(2);                                                 // the same file, stored once, used twice
});
