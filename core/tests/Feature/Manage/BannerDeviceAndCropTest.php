<?php

use App\Domain\Content\BannerWriter;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * Per-device banners and the crop (F1, the developer's decision 2026-10-20).
 *
 * One `device` column, three values, `both` by default: one banner is one row, and "the same artwork tuned twice"
 * is two rows. The cropped file is what `image_path` serves; the upload it came from is kept in
 * `image_original_path` and the rectangle in `crop`, so the tool reopens where it was left.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bannerBody(array $overrides = []): array
{
    /** @var array<string, mixed> $body */
    $body = $overrides + [
        'image_path' => 'Banner_home/cropped.webp',
        // no `device` here on purpose: a body that does not name one is the pre-F1 shape, and the
        // test below asserts it is stored as `both`. A test that needs a device passes it.
        'target' => 'none',
        'sort_order' => 0,
        'is_active' => true,
    ];

    return $body;
}

it('saves the device, the original and the crop, and gives them back to the screen', function () {
    $rect = ['x' => 10, 'y' => 20, 'w' => 1200, 'h' => 418];

    actingAs(Staff::admin())->post('/manage/storefronts/2/banners', bannerBody([
        'device' => 'mobile',
        'image_original_path' => 'Banner_home/original.webp',
        'crop' => json_encode($rect),
    ]))->assertRedirect();

    $row = T::row(DB::table('storefront_banners')->where('storefront_id', 2)->orderByDesc('id')->first());
    expect(T::str($row->device))->toBe('mobile')
        ->and(T::str($row->image_path))->toBe('Banner_home/cropped.webp')
        ->and(T::str($row->image_original_path))->toBe('Banner_home/original.webp')
        ->and(json_decode(T::str($row->crop), true))->toBe($rect);

    // and the screen is handed them back, with the shapes to crop to
    $page = actingAs(Staff::admin())->get('/manage/storefronts/2/banners')->assertOk();
    $props = Props::of($page);
    // by id, not by position: this storefront already has banners, and the list is sorted by sort_order
    $id = T::int($row->id);
    $mine = T::arr(collect(Props::rows(Props::table($page)))->firstWhere('id', $id) ?? []);
    expect(T::str($mine['device'] ?? null))->toBe('mobile')
        ->and(T::arr($mine['crop'] ?? []))->toBe($rect)
        ->and(T::arr($props['devices'] ?? []))->toBe(BannerWriter::DEVICES)
        ->and(T::arr(T::arr($props['crop_ratios'] ?? [])['mobile'] ?? []))
        ->toMatchArray(['w' => 358, 'h' => 160]);
});

it('asks each storefront for its OWN banner size, measured on its own hero', function () {
    // One shape in both shops (one component, one stylesheet, one aspect-ratio) and a different pixel size,
    // because Watchizer's content column is narrower than Brand Fashion's: measured 2026-10-20 at a 1920
    // viewport, 1400 × 488 against 1600 × 558. The operator is told their own shop's number.
    $sizes = [];
    foreach ([1, 2] as $storefrontId) {
        $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/{$storefrontId}/banners")->assertOk());
        $ratios = T::arr($props['crop_ratios'] ?? []);
        $sizes[$storefrontId] = T::arr($ratios['desktop'] ?? []);
        expect(T::int($sizes[$storefrontId]['w'] ?? null))->toBe(1377)
            ->and(T::int($sizes[$storefrontId]['h'] ?? null))->toBe(480);
    }

    expect(T::str($sizes[1]['recommended'] ?? null))->toBe('2800 × 976')
        ->and(T::str($sizes[2]['recommended'] ?? null))->toBe('3200 × 1116')
        ->and(T::str($sizes[1]['recommended'] ?? null))->not->toBe(T::str($sizes[2]['recommended'] ?? null));
});

it('defaults a banner saved without a device to `both`, and keeps every existing row showing everywhere', function () {
    actingAs(Staff::admin())->post('/manage/storefronts/2/banners', bannerBody())->assertRedirect();

    $row = T::row(DB::table('storefront_banners')->where('storefront_id', 2)->orderByDesc('id')->first());
    expect(T::str($row->device))->toBe('both')
        ->and($row->image_original_path)->toBeNull()
        ->and($row->crop)->toBeNull();
});

it('refuses a device it does not serve, rather than storing a value the shop cannot read', function () {
    actingAs(Staff::admin())->post('/manage/storefronts/2/banners', bannerBody(['device' => 'tablet']))
        ->assertSessionHasErrors('device');
});

it('keeps the original and the rectangle when only the device changes', function () {
    actingAs(Staff::admin())->post('/manage/storefronts/2/banners', bannerBody([
        'device' => 'desktop',
        'image_original_path' => 'Banner_home/original.webp',
        'crop' => json_encode(['x' => 0, 'y' => 0, 'w' => 900, 'h' => 314]),
    ]))->assertRedirect();
    $id = T::int(DB::table('storefront_banners')->where('storefront_id', 2)->max('id'));

    actingAs(Staff::admin())->put("/manage/storefronts/2/banners/{$id}", bannerBody([
        'device' => 'both',
        'image_original_path' => 'Banner_home/original.webp',
        'crop' => json_encode(['x' => 0, 'y' => 0, 'w' => 900, 'h' => 314]),
    ]))->assertRedirect();

    $row = T::row(DB::table('storefront_banners')->where('id', $id)->first());
    expect(T::str($row->device))->toBe('both')
        ->and(T::str($row->image_original_path))->toBe('Banner_home/original.webp');
});

it('reads a rectangle that is not four numbers as no rectangle, rather than refusing the save', function () {
    actingAs(Staff::admin())->post('/manage/storefronts/2/banners', bannerBody(['crop' => '{"x":1}']))
        ->assertRedirect();

    $row = T::row(DB::table('storefront_banners')->where('storefront_id', 2)->orderByDesc('id')->first());
    expect($row->crop)->toBeNull();
});
