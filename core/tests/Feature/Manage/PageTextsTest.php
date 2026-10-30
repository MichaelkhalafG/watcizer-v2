<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\SeoTexts;
use App\Http\Controllers\Manage\SeoTextController;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

/*
 * PAGE TEXTS (M1y, 2026-10-30, SEO item 5): the introduction and meta description of a brand or category page, per
 * storefront and language. The dashboard lists the storefront's own pages and saves both languages (logged); the v2
 * listing carries the text of the page it IS as `meta.context`, both languages, and nothing for a filtered view; the
 * seeder fills only real pages, keeps what the dashboard wrote and writes nothing without --apply.
 */

beforeEach(function () {
    DB::table(SeoTexts::TABLE)->delete();
    app(StorefrontCache::class)->flush(1);
});

/** @return array{key: string, name: string, depth: int} the first top-level category page of Watchizer */
function ptCategory(): array
{
    foreach (SeoTextController::categories(1) as $c) {
        if ($c['depth'] === 1) {
            return $c;
        }
    }
    throw new RuntimeException('no category page on storefront 1');
}

it('lists the storefront\'s brand and category pages and saves both languages, logged', function () {
    actingAs(Staff::admin());
    $category = ptCategory();

    $props = Props::of(get('/manage/storefronts/1/page-texts')->assertOk());
    $subjects = T::rows($props['subjects']);
    expect(array_values(array_unique(array_map(fn (array $s): string => T::str($s['type']), $subjects))))->toBe(['brand', 'category'])
        ->and(array_column($subjects, 'key'))->toContain($category['key'])
        ->and(Props::navKeys(get('/manage/storefronts/1/page-texts')))->toContain('page_texts');

    put('/manage/storefronts/1/page-texts', [
        'type' => 'category', 'key' => $category['key'],
        'en' => ['description' => "First paragraph.\n\nSecond.", 'meta_description' => 'An English meta.'],
        'ar' => ['description' => '', 'meta_description' => 'وصف عربي.'],
    ])->assertSessionHasNoErrors();

    $en = SeoTexts::for(1, 'category', $category['key'], 'en');
    $ar = SeoTexts::for(1, 'category', $category['key'], 'ar');
    expect($en['description'] ?? null)->toBe("First paragraph.\n\nSecond.")
        ->and($en['meta_description'] ?? null)->toBe('An English meta.')
        ->and(T::arr($ar))->toHaveKey('description')->and(T::arr($ar)['description'])->toBeNull()   // an empty field is stored as nothing
        ->and($ar['meta_description'] ?? null)->toBe('وصف عربي.')
        ->and(DB::table('core_activity_log')->where('subject_type', SeoTexts::TABLE)->where('action', ActivityLog::UPDATED)->count())->toBe(2);

    // a page the storefront does not have is refused, and nothing is written
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => 'no-such-brand', 'en' => ['description' => 'x']])
        ->assertSessionHasErrors('key');
    expect(DB::table(SeoTexts::TABLE)->where('subject_key', 'no-such-brand')->exists())->toBeFalse();
});

it('carries the page\'s own text on the v2 listing as meta.context, both languages, and none for a filtered view', function () {
    $category = ptCategory();
    $brand = SeoTextController::brands(1)[0];
    SeoTexts::save(1, 'category', $category['key'], ['en' => ['description' => 'Cat EN', 'meta_description' => 'Cat meta EN'], 'ar' => ['description' => 'تصنيف']], null);
    SeoTexts::save(1, 'brand', $brand['key'], ['en' => ['meta_description' => 'Brand meta']], null);
    $brandId = T::int(DB::table('catalog_brands')->where('slug', $brand['key'])->value('id'));

    $cat = getJson('/api/v2/watchizer/products?per_page=1&category='.$category['key'])->assertOk();
    expect($cat->json('meta.context.type'))->toBe('category')
        ->and($cat->json('meta.context.key'))->toBe($category['key'])
        ->and($cat->json('meta.context.en.description'))->toBe('Cat EN')
        ->and($cat->json('meta.context.en.meta_description'))->toBe('Cat meta EN')
        ->and($cat->json('meta.context.ar.description'))->toBe('تصنيف');

    // by slug and by id (the storefront sends the id), the same brand text
    foreach ([$brand['key'], (string) $brandId] as $token) {
        $res = getJson('/api/v2/watchizer/products?per_page=1&brand='.$token)->assertOk();
        expect($res->json('meta.context.key'))->toBe($brand['key'])
            ->and($res->json('meta.context.en.meta_description'))->toBe('Brand meta')
            ->and($res->json('meta.context.ar'))->toBeNull();
    }

    // a category AND a brand is a filtered view, not a page; so is the whole catalogue
    expect(getJson('/api/v2/watchizer/products?per_page=1&category='.$category['key'].'&brand='.$brand['key'])->assertOk()->json('meta.context'))->toBeNull()
        ->and(getJson('/api/v2/watchizer/products?per_page=1')->assertOk()->json('meta.context'))->toBeNull();

    // the other storefront's text is not this one's
    expect(SeoTexts::bilingual(2, 'category', $category['key']))->toBeNull();
});

it('seeds the prepared texts only onto real pages, keeps a written one, and writes nothing on a dry run', function () {
    $rolex = collect(SeoTextController::brands(1))->firstWhere('key', 'rolex');
    expect($rolex)->not->toBeNull();   // the dev data has Rolex on Watchizer; the seeder file names it
    SeoTexts::save(1, 'brand', 'rolex', ['en' => ['description' => 'Typed in the dashboard']], null);

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer']);
    expect(DB::table(SeoTexts::TABLE)->count())->toBe(1);

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--apply' => true]);
    $output = Artisan::output();
    expect(SeoTexts::for(1, 'brand', 'rolex', 'en')['description'] ?? null)->toBe('Typed in the dashboard')
        ->and($output)->toContain('keep   brand rolex');

    // every row written is a page the storefront has, with the shop's name filled in
    $pages = array_merge(
        array_map(fn (array $b) => 'brand:'.$b['key'], SeoTextController::brands(1)),
        array_map(fn (array $c) => 'category:'.$c['key'], SeoTextController::categories(1)),
    );
    foreach (DB::table(SeoTexts::TABLE)->where('storefront_id', 1)->get() as $raw) {
        $row = T::row($raw);
        expect($pages)->toContain(T::str($row->subject_type).':'.T::str($row->subject_key))
            ->and(T::str($row->meta_description ?? ''))->not->toContain('{shop}');
    }
    $watches = SeoTexts::for(1, 'category', 'watches', 'en');
    if ($watches !== null) {
        expect(T::str($watches['meta_description']))->toContain('Watchizer');
    }

    // a second run changes nothing
    $before = DB::table(SeoTexts::TABLE)->count();
    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--apply' => true]);
    expect(DB::table(SeoTexts::TABLE)->count())->toBe($before);
});
