<?php

use App\Domain\Content\HomeRails;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/*
 * Block A3 (2026-11-12): a hand-picked slider has no listing of its own, so its "See more" is an optional link the team
 * sets on the dashboard — kept in the rail's existing `settings` JSON (no migration), an internal path only, and handed
 * to both shops' home answers as `more_href`. No link, no button.
 */

/** @return list<int> visible products of a storefront */
function moreLinkPicks(int $storefront): array
{
    return array_values(array_map(fn (mixed $id): int => T::int($id), DB::table('storefront_product')->where('storefront_id', $storefront)
        ->where('is_visible', 1)->orderBy('product_id')->limit(2)->pluck('product_id')->all()));
}

it('keeps a hand-picked slider\'s "See more" link and hands it to the shop; none set, none sent', function () {
    actingAs(Staff::admin());
    $picks = moreLinkPicks(2);
    $send = fn (array $o) => post('/manage/storefronts/2/home-rails', $o + ['kind' => 'custom', 'target_id' => null, 'title_en' => 'BFT Picks', 'title_ar' => 'مختارات', 'is_active' => true, 'card_count' => 2, 'product_ids' => $picks]);

    $send(['more_href' => '/listing?sale=1'])->assertSessionHasNoErrors();
    $rail = collect(HomeRails::all(2))->firstWhere('title_en', 'BFT Picks');
    expect(T::arr($rail)['more_href'])->toBe('/listing?sale=1');

    $served = collect(T::arr(getJson('/api/v2/brandfashion/home')->assertOk()->json('rails')))->firstWhere('id', T::arr($rail)['id']);
    expect(T::arr($served)['more_href'] ?? 'absent')->toBe('/listing?sale=1');

    $send(['title_en' => 'BFT Picks 2'])->assertSessionHasNoErrors();
    expect(T::arr(collect(HomeRails::all(2))->firstWhere('title_en', 'BFT Picks 2'))['more_href'])->toBeNull();
});

it('refuses a "See more" link that leaves the shop or is not a path, and ignores it on other kinds', function () {
    actingAs(Staff::admin());
    $picks = moreLinkPicks(1);
    $send = fn (array $o) => post('/manage/storefronts/1/home-rails', $o + ['kind' => 'custom', 'target_id' => null, 'title_en' => 'BFT Bad', 'title_ar' => 'مختارات', 'is_active' => true, 'card_count' => 2, 'product_ids' => $picks]);
    foreach (['https://example.com', '//evil.example/x', 'javascript:alert(1)', '/a b', 'listing'] as $bad) {
        $send(['more_href' => $bad])->assertSessionHasErrors('more_href');
    }
    expect(DB::table('storefront_home_rails')->where('title_en', 'BFT Bad')->exists())->toBeFalse()
        ->and(HomeRails::isPath('/brand/rolex'))->toBeTrue()
        ->and(HomeRails::isPath('/'))->toBeTrue();

    post('/manage/storefronts/1/home-rails', ['kind' => 'newest', 'target_id' => null, 'title_en' => 'BFT New', 'title_ar' => null, 'is_active' => true, 'card_count' => 8, 'more_href' => '/listing'])->assertSessionHasNoErrors();
    expect(T::arr(collect(HomeRails::all(1))->firstWhere('title_en', 'BFT New'))['more_href'])->toBeNull();   // only a hand-picked slider keeps one
});
