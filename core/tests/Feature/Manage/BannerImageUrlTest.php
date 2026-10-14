<?php

use App\Domain\Content\BannerImage;
use App\Storefront\StorefrontUrls;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Shops;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * Banner image URLs — two bugs found in ONE live URL on 2026-10-14 (banner 1363, Brand Fashion):
 *
 *   https://api.watchizereg.com/Uploads_Images/1791985901_2026-10-14_6acf88ed28cc1.webp
 *
 *  1. the FOLDER was missing: the banner form stored the bare filename where every other image
 *     column stores `<folder>/<file>`, and `ImageUrl::src()` concatenates — a 404 on both hosts;
 *  2. the HOST was Watchizer's: the dashboard built the URL on the global `storefront.asset_base`
 *     for a storefront-2 banner (the v2 route already named the right host, through
 *     ResolveStorefront's request base).
 *
 * Both are pinned here, and so is the thing that must NOT move: the default storefront's URL is
 * built by exactly the expression it was built by before.
 */

const BANNER_FILE = '1791985901_2026-10-14_6acf88ed28cc1.webp';

function bannerRow(int $storefrontId, string $imagePath): int
{
    return (int) DB::table('storefront_banners')->insertGetId([
        'storefront_id' => $storefrontId,
        'placement' => 'home',
        'image_path' => $imagePath,
        'type_show' => null,
        'link_url' => 'https://example.com/x',
        'product_id' => null,
        'storefront_category_id' => null,
        'sort_order' => 1,
        'is_active' => 1,
        'starts_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return list<string> every banner image URL the dashboard list carries for this storefront */
function dashboardBannerUrls(int $storefrontId): array
{
    actingAs(Staff::dataEntry());
    $rows = Props::rows(Props::table(get('/manage/storefronts/'.$storefrontId.'/banners')->assertOk()));

    return array_map(fn (array $r): string => T::str($r['image_url'] ?? null), $rows);
}

it('puts the banner folder back on a bare filename and keeps a folder that is already there', function () {
    expect(BannerImage::path(BANNER_FILE))->toBe('Banner_home/'.BANNER_FILE)
        ->and(BannerImage::path('Banner_home/'.BANNER_FILE))->toBe('Banner_home/'.BANNER_FILE)
        ->and(BannerImage::path('/Banner_home/'.BANNER_FILE))->toBe('Banner_home/'.BANNER_FILE)
        ->and(BannerImage::path('Other/'.BANNER_FILE))->toBe('Other/'.BANNER_FILE)
        ->and(BannerImage::path(''))->toBe('');
});

it('names a Brand Fashion banner on Brand Fashion\'s host WITH its folder, on the dashboard list', function () {
    $fashion = Shops::id('brandfashion');
    DB::table('storefront_banners')->where('storefront_id', $fashion)->delete();
    bannerRow($fashion, BANNER_FILE);                       // written bare, as the form did until 2026-10-14

    $urls = dashboardBannerUrls($fashion);
    expect($urls)->toHaveCount(1)
        ->and($urls[0])->toBe(StorefrontUrls::assetBase($fashion).'/Uploads_Images/Banner_home/'.BANNER_FILE)
        ->and($urls[0])->not->toContain('watchizereg');
});

it('builds the default storefront\'s banner URL by exactly the expression it always used, folder put back', function () {
    $watchizer = Shops::id('watchizer');
    DB::table('storefront_banners')->where('storefront_id', $watchizer)->delete();
    bannerRow($watchizer, 'Banner_home/'.BANNER_FILE);
    bannerRow($watchizer, BANNER_FILE);

    $base = rtrim(config()->string('storefront.asset_base'), '/');   // what ImageUrl::src() used, unchanged
    $urls = dashboardBannerUrls($watchizer);
    expect($urls)->toHaveCount(2)
        ->and($urls[0])->toBe($base.'/Uploads_Images/Banner_home/'.BANNER_FILE)
        ->and($urls[1])->toBe($base.'/Uploads_Images/Banner_home/'.BANNER_FILE);
});

it('serves the v2 meta banner with its folder, on the storefront\'s own host', function () {
    $fashion = Shops::id('brandfashion');
    DB::table('storefront_banners')->where('storefront_id', $fashion)->delete();
    bannerRow($fashion, BANNER_FILE);

    $res = getJson(Shops::api('brandfashion').'/api/v2/brandfashion/meta')->assertOk();
    $banners = T::arr($res->json('banners'));
    expect($banners)->toHaveCount(1);
    $src = T::str(T::arr(T::arr($banners[0] ?? null)['image'] ?? null)['src'] ?? null);
    expect($src)->toBe(StorefrontUrls::assetBase($fashion).'/Uploads_Images/Banner_home/'.BANNER_FILE);
});

it('stores the folder when the screen writes a banner, so no new row is ever bare', function () {
    actingAs(Staff::dataEntry());
    $fashion = Shops::id('brandfashion');
    DB::table('storefront_banners')->where('storefront_id', $fashion)->delete();

    \Pest\Laravel\post('/manage/storefronts/'.$fashion.'/banners', [
        'image_path' => BANNER_FILE,                            // an old client sending the bare name
        'target' => 'url',
        'link_url' => 'https://example.com/x',
        'sort_order' => 1,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_banners')->where('storefront_id', $fashion)->value('image_path')))
        ->toBe('Banner_home/'.BANNER_FILE);
});
