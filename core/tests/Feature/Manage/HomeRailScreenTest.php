<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\HomeRails;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * The dashboard's Home rails screen (C-1 stage 4 slice C, `HomeRailController`). Which product
 * rails the home page shows and in what order — add, switch off, retitle, reorder, delete, each one
 * activity-logged. Same ability and storefront scope as banners.
 */

/** @return list<array{id: int, kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, position: int, is_active: bool, card_count: int}> */
function screenRails(): array
{
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    foreach ([['offers', null], ['newest', null]] as [$kind, $target]) {
        HomeRails::create(1, ['kind' => $kind, 'target_id' => $target, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 8]);
    }

    return HomeRails::all(1);
}

it('renders the rails in order, with a target\'s name and the options the form offers', function () {
    actingAs(Staff::dataEntry());
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $grade = T::int(DB::table('catalog_grades')->orderBy('id')->value('id'));
    HomeRails::create(1, ['kind' => 'grade', 'target_id' => $grade, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 8]);
    HomeRails::create(1, ['kind' => 'offers', 'target_id' => null, 'title_en' => 'Deals', 'title_ar' => null, 'is_active' => false, 'card_count' => 12]);

    $props = Props::of(get('/manage/storefronts/1/home-rails')->assertOk());
    $rails = T::rows($props['rails']);
    $targets = T::arr($props['targets']);

    expect(array_column($rails, 'kind'))->toBe(['grade', 'offers'])
        ->and(T::str($rails[0]['target_name']))->not->toBe('')
        ->and($rails[1]['target_name'])->toBeNull()
        ->and(T::str($rails[1]['title_en']))->toBe('Deals')
        ->and(array_keys($targets))->toBe(['grade', 'brand', 'category_type'])
        ->and(T::arr($targets['grade']))->not->toBeEmpty();

    // The category list offers what a shopper can browse — never the legacy tree's placeholder root.
    $categoryValues = array_column(T::rows($targets['category_type']), 'value');
    $root = DB::table('storefront_categories')->where('storefront_id', 1)->where('legacy_source', 'category_root')->value('id');
    expect($categoryValues)->not->toContain((string) T::int($root));

    expect(Props::navKeys(get('/manage/storefronts/1/home-rails')))->toContain('home_rails');
});

it('creates, retitles, switches off and deletes through the screen, logging each', function () {
    $admin = Staff::admin();
    actingAs($admin);
    screenRails();
    $brand = T::int(DB::table('catalog_brands')->orderBy('id')->value('id'));

    post('/manage/storefronts/1/home-rails', [
        'kind' => 'brand', 'target_id' => $brand, 'title_en' => 'Our pick', 'title_ar' => '', 'is_active' => true, 'card_count' => 6,
    ])->assertSessionHasNoErrors();
    $id = T::int(DB::table('storefront_home_rails')->orderByDesc('id')->value('id'));
    $row = T::row(DB::table('storefront_home_rails')->where('id', $id)->first());
    expect(T::int($row->position))->toBe(30)                  // added at the END
        ->and($row->title_ar)->toBeNull()                      // an empty title is "use the name"
        ->and(T::str($row->title_en))->toBe('Our pick');

    put("/manage/storefronts/1/home-rails/{$id}", [
        'kind' => 'brand', 'target_id' => $brand, 'title_en' => null, 'title_ar' => 'اختيارنا', 'is_active' => false, 'card_count' => 6,
    ])->assertSessionHasNoErrors();
    $row = T::row(DB::table('storefront_home_rails')->where('id', $id)->first());
    expect(T::int($row->is_active))->toBe(0)->and(T::str($row->title_ar))->toBe('اختيارنا');

    delete("/manage/storefronts/1/home-rails/{$id}")->assertSessionHasNoErrors();
    expect(DB::table('storefront_home_rails')->where('id', $id)->exists())->toBeFalse();

    $log = DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_home_rails')->where('subject_id', $id)->orderBy('id')->get();
    expect(array_map(fn ($r): string => T::str(T::row($r)->action), $log->all()))->toBe([ActivityLog::CREATED, ActivityLog::UPDATED, ActivityLog::DELETED]);
    $first = T::row($log->first());
    expect(T::int($first->user_id))->toBe(T::int($admin->getAttribute('id')))
        ->and(T::str($first->subject_label))->toBe("brand #{$brand}")
        ->and(T::int($first->storefront_id))->toBe(1);
});

it('reorders with the whole list, logs each moved rail, and refuses a stale or partial list', function () {
    actingAs(Staff::admin());
    [$offers, $newest] = screenRails();

    post('/manage/storefronts/1/home-rails/order', ['order' => [$newest['id'], $offers['id']]])->assertSessionHasNoErrors();
    expect(array_column(HomeRails::all(1), 'kind'))->toBe(['newest', 'offers'])
        ->and(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_home_rails')->where('action', ActivityLog::UPDATED)->count())->toBe(2);

    post('/manage/storefronts/1/home-rails/order', ['order' => [$offers['id']]])->assertSessionHasErrors('order');
    post('/manage/storefronts/1/home-rails/order', ['order' => [$offers['id'], $newest['id'], 999999]])->assertSessionHasErrors('order');
    expect(array_column(HomeRails::all(1), 'kind'))->toBe(['newest', 'offers']);
});

it('refuses a targeted rail without a target, or with one this storefront cannot show; drops a target on the others', function () {
    actingAs(Staff::admin());
    screenRails();
    $root = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->where('legacy_source', 'category_root')->value('id'));
    $base = ['title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 8];

    post('/manage/storefronts/1/home-rails', ['kind' => 'grade', 'target_id' => null] + $base)->assertSessionHasErrors('target_id');
    post('/manage/storefronts/1/home-rails', ['kind' => 'grade', 'target_id' => 99999999] + $base)->assertSessionHasErrors('target_id');
    post('/manage/storefronts/1/home-rails', ['kind' => 'category_type', 'target_id' => $root] + $base)->assertSessionHasErrors('target_id');
    post('/manage/storefronts/1/home-rails', ['kind' => 'nope', 'target_id' => null] + $base)->assertSessionHasErrors('kind');
    post('/manage/storefronts/1/home-rails', ['kind' => 'offers', 'target_id' => null, 'card_count' => 99] + $base)->assertSessionHasErrors('card_count');
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->count())->toBe(2);

    post('/manage/storefronts/1/home-rails', ['kind' => 'offers', 'target_id' => 5] + $base)->assertSessionHasNoErrors();
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->orderByDesc('id')->value('target_id'))->toBeNull();
});

it('404s a rail of another storefront, and keeps customers out', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 2)->delete();
    $other = HomeRails::create(2, ['kind' => 'offers', 'target_id' => null, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 8]);

    actingAs(Staff::admin());
    put("/manage/storefronts/1/home-rails/{$other}", ['kind' => 'offers', 'is_active' => true, 'card_count' => 8])->assertNotFound();
    delete("/manage/storefronts/1/home-rails/{$other}")->assertNotFound();
    expect(DB::table('storefront_home_rails')->where('id', $other)->exists())->toBeTrue();

    actingAs(Staff::customer());
    get('/manage/storefronts/1/home-rails')->assertStatus(403);
});
