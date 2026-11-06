<?php

use App\Domain\Content\BannerWriter;
use App\Domain\Content\HomeRails;
use App\Storefront\HomeHero;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withHeaders;

/*
 * ── The home zone (the twelve updates, Part 3, 2026-11-05) ───────────────────────────────────────
 *
 * 3.1  three hero modes — 3D, Campaign, Banners — chosen in the dashboard; Campaign only where one exists;
 *      Watchizer moved to Campaign by the migration so its page did not change; two SIDE slots beside the
 *      large hero, each managed on its own and never a slide — empty slots leave the hero full width.
 * 3.2  per slider: a background colour and a toggle that blends the cards' photos into it; both off = as before.
 * 3.3  a brand banner on the sliders page: a brand, three of ITS products, three colours, a title and a sentence.
 *
 * Every assertion reads the payload a storefront reads (v2 `home`, and compat `catalog/home` where Watchizer's
 * live path is the subject), not the row that was written.
 */

const HZ_API_KEY = 'home-zone-test-key';

beforeEach(function () {
    config(['compat.api_key' => HZ_API_KEY]);
    actingAs(Staff::admin());
});

/** @return array<mixed> the v2 home of Watchizer, freshly built */
function hzHome(): array
{
    app(StorefrontCache::class)->flush(1);

    return T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/home')->assertOk()->json());
}

/** @param  array<string, mixed>  $overrides */
function hzBanner(array $overrides = []): int
{
    return app(BannerWriter::class)->save($overrides + [
        'storefront_id' => 1, 'image_path' => 'Banner_home/hz-'.bin2hex(random_bytes(3)).'.webp',
        'target' => 'url', 'link_url' => '/listing?sale=1', 'sort_order' => 0, 'is_active' => true,
    ], null);
}

/**
 * A brand with at least three products storefront 1 shows, and those products — derived, never named.
 *
 * @return array{brand: int, products: list<int>, other: int}
 */
function hzBrand(): array
{
    $visible = fn (): Builder => DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
        ->whereNull('p.deleted_at')->where('p.is_active', 1);

    $brand = T::int($visible()->groupBy('p.brand_id')->havingRaw('COUNT(*) >= 3')->orderBy('p.brand_id')->value('p.brand_id'));
    $products = array_map(fn (mixed $id): int => T::int($id), $visible()->where('p.brand_id', $brand)->orderBy('p.id')->limit(3)->pluck('p.id')->all());
    $other = T::int($visible()->where('p.brand_id', '!=', $brand)->orderBy('p.id')->value('p.id'));

    return ['brand' => $brand, 'products' => array_values($products), 'other' => $other];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function hzBrandBanner(array $overrides = []): array
{
    $b = hzBrand();

    return $overrides + [
        'kind' => 'brand_banner', 'target_id' => $b['brand'], 'product_ids' => $b['products'],
        'title_ar' => 'أسبوع الماركة', 'title_en' => 'Brand week',
        'description_ar' => 'ثلاث قطع مختارة من هذه الماركة.', 'description_en' => 'Three pieces chosen from this brand.',
        'colors' => ['#10214f', '#C8102E', '#ffffff'], 'card_count' => 3, 'is_active' => true,
    ];
}

// ── 3.1 the hero modes ───────────────────────────────────────────────────────────────────────────

it('offers three hero modes, Campaign only to the storefront that has one, and serves the chosen one', function () {
    expect(HomeHero::MODES)->toBe(['three_d', 'campaign', 'banner'])
        ->and(HomeHero::available('watchizer'))->toBe(['three_d', 'campaign', 'banner'])
        ->and(HomeHero::available('brandfashion'))->toBe(['three_d', 'banner'])
        // an unset or unknown value is still the 3D hero: the live shop's page must not depend on this key existing
        ->and(HomeHero::of(null))->toBe('three_d')
        ->and(HomeHero::of(['home' => ['hero' => 'carousel']]))->toBe('three_d')
        ->and(HomeHero::of(['home' => ['hero' => 'campaign']]))->toBe('campaign');

    $wz = Props::of(get('/manage/storefronts/1/banners')->assertOk());
    $bf = Props::of(get('/manage/storefronts/2/banners')->assertOk());
    expect($wz['heroes'])->toBe(['three_d', 'campaign', 'banner'])
        ->and($bf['heroes'])->toBe(['three_d', 'banner'])
        ->and($bf['hero_modes'])->toBe(['three_d', 'campaign', 'banner']);

    foreach (['three_d', 'campaign', 'banner'] as $mode) {
        post('/manage/storefronts/1/banners/hero', ['hero' => $mode])->assertSessionHasNoErrors();
        expect(hzHome()['hero'])->toBe($mode);
        // Watchizer's LIVE home reads compat: the same answer there.
        expect(T::arr(withHeaders(['Api-Code' => HZ_API_KEY])->getJson('/api/catalog/home')->assertOk()->json())['hero'])->toBe($mode);
    }

    // Brand Fashion has no campaign hero: refused by the server, and its setting does not move.
    $before = DB::table('storefronts')->where('id', 2)->value('settings');
    post('/manage/storefronts/2/banners/hero', ['hero' => 'campaign'])->assertSessionHasErrors('hero');
    expect(DB::table('storefronts')->where('id', 2)->value('settings'))->toBe($before);
});

it('the migration moves Watchizer from 3D to Campaign once, and leaves every other choice alone', function () {
    $migration = require database_path('migrations/2026_11_05_000004_hero_campaign_mode.php');
    $up = function () use ($migration): void {
        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            throw new RuntimeException('the migration file did not return a migration with an up()');
        }
        $migration->up();
    };
    $hero = function (int $id): mixed {
        $home = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $id)->value('settings')), true))['home'] ?? null;

        return is_array($home) ? ($home['hero'] ?? null) : null;
    };
    $set = function (int $id, ?string $mode): void {
        $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $id)->value('settings')), true));
        if ($mode === null) {
            unset($settings['home']);
        } else {
            $settings['home'] = ['hero' => $mode];
        }
        DB::table('storefronts')->where('id', $id)->update(['settings' => json_encode($settings)]);
    };
    $bf = $hero(2);

    $set(1, 'three_d');
    $up();
    expect($hero(1))->toBe('campaign')->and($hero(2))->toBe($bf);

    $set(1, null);                                   // a missing value read as three_d, so it moves too
    $up();
    expect($hero(1))->toBe('campaign');

    $set(1, 'banner');                               // somebody's choice: left alone
    $up();
    expect($hero(1))->toBe('banner');

    // …and the other keys of `settings` are still there.
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings')), true));
    expect($settings)->toHaveKey('identity');
});

// ── 3.1 the side slots ───────────────────────────────────────────────────────────────────────────

it('serves the two side slots on their own — never as hero slides — and null when a slot is empty', function () {
    DB::table('storefront_banners')->where('storefront_id', 1)->delete();
    $slide = hzBanner();

    $home = hzHome();
    expect($home['side_banners'])->toBe([null, null])
        ->and(array_column(T::arr($home['banners']), 'id'))->toBe([$slide]);

    // The BOTTOM slot only: the top stays null, and the hero's slides do not grow.
    $bottom = hzBanner(['placement' => BannerWriter::SIDE[1], 'link_url' => '/category/watches']);
    $home = hzHome();
    $side = T::arr($home['side_banners']);
    expect($side[0])->toBeNull()
        ->and(T::int(T::arr($side[1])['id']))->toBe($bottom)
        ->and(T::arr($side[1])['href'])->toBe('/category/watches')
        ->and(T::arr(T::arr($side[1])['image'])['src'] ?? null)->toContain('Banner_home/')
        ->and(array_column(T::arr($home['banners']), 'id'))->toBe([$slide]);

    // Both filled; a slot shows ONE banner — the first in the dashboard's order — and skips an inactive one.
    $top = hzBanner(['placement' => BannerWriter::SIDE[0], 'sort_order' => 5]);
    hzBanner(['placement' => BannerWriter::SIDE[0], 'sort_order' => 1, 'is_active' => false]);
    $later = hzBanner(['placement' => BannerWriter::SIDE[0], 'sort_order' => 9]);
    $side = T::arr(hzHome()['side_banners']);
    expect(T::int(T::arr($side[0])['id']))->toBe($top)->and(T::int(T::arr($side[1])['id']))->toBe($bottom);

    // A slot whose banner's window has closed falls to the next, then to empty.
    DB::table('storefront_banners')->where('id', $top)->update(['ends_at' => now()->subDay()]);
    expect(T::int(T::arr(T::arr(hzHome()['side_banners'])[0])['id']))->toBe($later);
    DB::table('storefront_banners')->where('id', $later)->update(['starts_at' => now()->addDay()]);
    expect(T::arr(hzHome()['side_banners'])[0])->toBeNull();

    // Watchizer's live path carries the same two slots.
    $compat = T::arr(withHeaders(['Api-Code' => HZ_API_KEY])->getJson('/api/catalog/home')->assertOk()->json());
    expect(T::arr($compat['side_banners'])[0])->toBeNull()
        ->and(T::int(T::arr(T::arr($compat['side_banners'])[1])['id']))->toBe($bottom);

    // The dashboard lists all three placements and says which is which.
    $rows = T::rows(T::arr(Props::of(get('/manage/storefronts/1/banners')->assertOk())['table'])['data'] ?? null);
    $placements = array_column($rows, 'placement', 'id');
    expect($placements[$slide])->toBe('home')->and($placements[$bottom])->toBe('home_side_2')->and($placements[$top])->toBe('home_side_1');
});

// ── 3.2 the slider's background and blend ────────────────────────────────────────────────────────

it('carries a slider\'s background colour and blend toggle to the home — and nothing for a slider that has neither', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $base = ['kind' => 'newest', 'target_id' => null, 'title_ar' => null, 'title_en' => null, 'card_count' => 8, 'is_active' => true];

    post('/manage/storefronts/1/home-rails', $base)->assertSessionHasNoErrors();
    post('/manage/storefronts/1/home-rails', ['kind' => 'offers', 'background' => '#fde9d9', 'blend' => true] + $base)->assertSessionHasNoErrors();
    post('/manage/storefronts/1/home-rails', ['kind' => 'featured', 'background' => '#101820', 'blend' => false] + $base)->assertSessionHasNoErrors();

    $byKind = [];
    foreach (T::rows(hzHome()['rails']) as $rail) {
        $byKind[T::str($rail['kind'])] = [$rail['background'], $rail['blend']];
    }
    expect($byKind['newest'])->toBe([null, false], 'a slider nobody coloured renders exactly as before')
        ->and($byKind['offers'])->toBe(['#FDE9D9', true])
        ->and($byKind['featured'])->toBe(['#101820', false]);
    expect(T::int(DB::table('storefront_home_rails')->where('storefront_id', 1)->whereNull('settings')->count()))->toBe(1);

    // The same two keys on Watchizer's live path.
    $compat = T::rows(T::arr(withHeaders(['Api-Code' => HZ_API_KEY])->getJson('/api/catalog/home')->assertOk()->json())['rails']);
    $offers = array_values(array_filter($compat, fn (array $r): bool => $r['kind'] === 'offers'))[0];
    expect($offers['background'])->toBe('#FDE9D9')->and($offers['blend'])->toBeTrue();

    // Blending needs something to blend into; a colour must be a colour.
    post('/manage/storefronts/1/home-rails', ['kind' => 'grade', 'blend' => true] + $base)->assertSessionHasErrors();
    post('/manage/storefronts/1/home-rails', ['background' => 'navy'] + $base)->assertSessionHasErrors('background');
    post('/manage/storefronts/1/home-rails', ['background' => '#12345'] + $base)->assertSessionHasErrors('background');
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->count())->toBe(3);

    // Clearing the colour puts the slider back as it was.
    $offersId = T::int(DB::table('storefront_home_rails')->where('storefront_id', 1)->where('kind', 'offers')->value('id'));
    \Pest\Laravel\put('/manage/storefronts/1/home-rails/'.$offersId, ['kind' => 'offers', 'background' => null, 'blend' => false] + $base)->assertSessionHasNoErrors();
    expect(DB::table('storefront_home_rails')->where('id', $offersId)->value('settings'))->toBeNull();
});

// ── 3.3 the brand banner ─────────────────────────────────────────────────────────────────────────

it('builds a brand banner from a brand, three of its products, three colours, a title and a sentence', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $b = hzBrand();
    post('/manage/storefronts/1/home-rails', hzBrandBanner())->assertSessionHasNoErrors();

    $rails = T::rows(hzHome()['rails']);
    expect($rails)->toHaveCount(1);
    $banner = $rails[0];
    $slug = T::str(DB::table('catalog_brands')->where('id', $b['brand'])->value('slug'));

    expect($banner['kind'])->toBe('brand_banner')
        // the brand, with the slug its page lives at — the banner links to /brand/{slug}
        ->and(T::int(T::arr($banner['brand'])['id']))->toBe($b['brand'])
        ->and(T::arr($banner['brand'])['slug'])->toBe($slug)
        ->and(T::arr($banner['brand']))->toHaveKey('logo')
        // its three products, in the order picked
        ->and(array_map(fn (mixed $card): int => T::int(T::arr($card)['id']), T::arr($banner['cards'])))->toBe($b['products'])
        ->and($banner['colors'])->toBe(['#10214F', '#C8102E', '#FFFFFF'])
        ->and($banner['title'])->toBe(['en' => 'Brand week', 'ar' => 'أسبوع الماركة'])
        ->and($banner['description'])->toBe(['en' => 'Three pieces chosen from this brand.', 'ar' => 'ثلاث قطع مختارة من هذه الماركة.']);

    // Watchizer's live path: the same rail, its target the brand, its products the three picks.
    $compat = T::rows(T::arr(withHeaders(['Api-Code' => HZ_API_KEY])->getJson('/api/catalog/home')->assertOk()->json())['rails'])[0];
    expect($compat['kind'])->toBe('brand_banner')
        ->and($compat['target'])->toBe($b['brand'])
        ->and($compat['products'])->toBe($b['products'])
        ->and($compat['colors'])->toBe(['#10214F', '#C8102E', '#FFFFFF'])
        ->and(T::arr($compat['description'])['en'])->toBe('Three pieces chosen from this brand.');
});

it('REFUSES a brand banner that is not what the banner draws, each on its own field', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $b = hzBrand();
    $url = '/manage/storefronts/1/home-rails';

    post($url, hzBrandBanner(['product_ids' => array_slice($b['products'], 0, 2)]))->assertSessionHasErrors('product_ids');
    post($url, hzBrandBanner(['product_ids' => [$b['products'][0], $b['products'][1], $b['other']]]))->assertSessionHasErrors('product_ids');
    post($url, hzBrandBanner(['colors' => ['#10214f', '#C8102E']]))->assertSessionHasErrors('colors');
    post($url, hzBrandBanner(['colors' => ['#10214f', '#C8102E', 'white']]))->assertSessionHasErrors();
    post($url, hzBrandBanner(['description_en' => '']))->assertSessionHasErrors('description_en');
    post($url, hzBrandBanner(['title_ar' => '']))->assertSessionHasErrors('title_ar');
    post($url, hzBrandBanner(['target_id' => null]))->assertSessionHasErrors('target_id');

    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->count())->toBe(0);
});

it('tells the sliders page which brands have no logo, so nobody builds a banner around a blank', function () {
    $missing = array_map(
        fn (mixed $id): string => (string) T::int($id),
        DB::table('catalog_brands')->where(fn (Builder $q) => $q->whereNull('logo_path')->orWhere('logo_path', ''))->orderBy('id')->pluck('id')->all(),
    );
    $props = Props::of(get('/manage/storefronts/1/home-rails')->assertOk());

    expect($props['brands_without_logo'])->toBe(array_values($missing))
        ->and($props['banner_products'])->toBe(HomeRails::BANNER_PRODUCTS)
        ->and($props['kinds'])->toContain('brand_banner');

    // A brand WITH a logo serves it on the banner; one without serves null — the storefront then prints the name.
    $with = DB::table('catalog_brands')->whereNotNull('logo_path')->where('logo_path', '!=', '')->where('is_active', 1)->orderBy('id')->first(['id', 'logo_path']);
    expect($with)->not->toBeNull();
    expect(in_array((string) Row::int(T::row($with), 'id'), $missing, true))->toBeFalse();
});

// ── the design pass (2026-11-05): the hero AND the Banners block together ────────────────────────────
//
// A hero (the campaign, or the 3D watch) leads the home and the Banners block is a home SECTION whose place is a
// row among the sliders; with no hero (`banner`) the block is the first thing on the page and the row is passed
// over. One row per storefront, given to every storefront by the migration.

/**
 * The ids of the rails a home payload carries, in order.
 *
 * @param  array<mixed>  $home
 * @return list<int>
 */
function hzRailIds(array $home): array
{
    $ids = [];
    foreach (T::arr($home['rails'] ?? []) as $rail) {
        $ids[] = T::int(T::arr($rail)['id']);
    }

    return $ids;
}

/** @return array<mixed> Watchizer's LIVE home (compat) */
function hzCompatHome(): array
{
    return T::arr(withHeaders(['Api-Code' => HZ_API_KEY])->getJson('/api/catalog/home')->assertOk()->json());
}

/** Put storefront 1's Banners block row first or last among its rails, through the screen's own endpoint. */
function hzPlaceBlock(int $block, bool $first): void
{
    $ids = array_values(array_filter(array_map(fn (array $r): int => $r['id'], HomeRails::all(1)), fn (int $id): bool => $id !== $block));
    $first ? array_unshift($ids, $block) : array_push($ids, $block);
    post('/manage/storefronts/1/home-rails/order', ['order' => $ids])->assertSessionHasNoErrors();
}

it('the migration gives every storefront ONE Banners block row, on and first, and keeps the sliders in their order', function () {
    DB::table('storefront_home_rails')->where('kind', 'banners')->delete();
    $before = array_map(fn (array $r): int => $r['id'], HomeRails::all(1));

    $migration = require database_path('migrations/2026_11_05_000005_banners_block_row.php');
    $up = function () use ($migration): void {
        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            throw new RuntimeException('the migration file did not return a migration with an up()');
        }
        $migration->up();
    };
    $up();

    $after = HomeRails::all(1);
    expect($after[0]['kind'])->toBe(HomeRails::BANNERS)
        ->and($after[0]['is_active'])->toBeTrue()
        ->and(array_map(fn (array $r): int => $r['id'], array_slice($after, 1)))->toBe($before);
    foreach (DB::table('storefronts')->pluck('id') as $id) {
        expect(DB::table('storefront_home_rails')->where('storefront_id', $id)->where('kind', 'banners')->count())->toBe(1);
    }

    $up();                                            // again: a storefront that has its row is left alone
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->where('kind', 'banners')->count())->toBe(1)
        ->and(array_map(fn (array $r): int => $r['id'], HomeRails::all(1)))->toBe(array_map(fn (array $r): int => $r['id'], $after));
});

it('draws the Banners block where its row is while a hero leads, and passes the row over when the block itself leads', function () {
    hzBanner();
    $block = HomeRails::blockId(1) ?? HomeRails::create(1, ['kind' => HomeRails::BANNERS, 'target_id' => null, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 1]);

    foreach (['campaign', 'three_d'] as $hero) {
        post('/manage/storefronts/1/banners/hero', ['hero' => $hero])->assertSessionHasNoErrors();

        hzPlaceBlock($block, first: true);
        $v2 = hzHome();
        $first = T::arr(T::arr($v2['rails'])[0]);
        expect($first['id'])->toBe($block)
            ->and($first['kind'])->toBe('banners')
            ->and($first['cards'])->toBe([])                              // no products: the page draws `banners` there
            ->and(hzRailIds(hzCompatHome())[0])->toBe($block);            // Watchizer's live path says the same

        hzPlaceBlock($block, first: false);
        $ids = hzRailIds(hzHome());
        $compat = hzRailIds(hzCompatHome());
        expect($ids[count($ids) - 1])->toBe($block)
            ->and($ids[0])->not->toBe($block)
            ->and($compat[count($compat) - 1])->toBe($block);
    }

    // No hero: the block is the top of the page, automatically — its row is not sent, so it is never drawn twice.
    post('/manage/storefronts/1/banners/hero', ['hero' => 'banner'])->assertSessionHasNoErrors();
    expect(hzHome()['hero'])->toBe('banner')
        ->and(hzRailIds(hzHome()))->not->toContain($block)
        ->and(hzRailIds(hzCompatHome()))->not->toContain($block);

    // Under a hero with the row switched OFF: the hero alone.
    post('/manage/storefronts/1/banners/hero', ['hero' => 'campaign'])->assertSessionHasNoErrors();
    put('/manage/storefronts/1/home-rails/'.$block, ['kind' => 'banners', 'target_id' => null, 'is_active' => false, 'card_count' => 1])->assertSessionHasNoErrors();
    expect(hzRailIds(hzHome()))->not->toContain($block)
        ->and(hzRailIds(hzCompatHome()))->not->toContain($block);
});

it('keeps ONE Banners block per storefront, offers the kind only when there is none, and tells both screens where it stands', function () {
    $block = HomeRails::blockId(1) ?? HomeRails::create(1, ['kind' => HomeRails::BANNERS, 'target_id' => null, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 1]);
    $new = ['kind' => 'banners', 'target_id' => null, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 1];

    post('/manage/storefronts/1/home-rails', $new)->assertSessionHasErrors('kind');
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->where('kind', 'banners')->count())->toBe(1);

    $screen = Props::of(get('/manage/storefronts/1/home-rails')->assertOk());
    expect($screen['kinds'])->not->toContain('banners')
        ->and(HomeHero::MODES)->toContain($screen['hero'])
        ->and($screen['block_banners'])->toBeInt();

    hzPlaceBlock($block, first: true);
    post('/manage/storefronts/1/banners/hero', ['hero' => 'campaign'])->assertSessionHasNoErrors();
    expect(Props::of(get('/manage/storefronts/1/banners')->assertOk())['block_row'])->toBe(['is_active' => true, 'place' => 1]);

    // Deleted: both screens say so, the kind is offered again, and it can be added back (at the end, like any rail).
    delete('/manage/storefronts/1/home-rails/'.$block)->assertSessionHasNoErrors();
    expect(Props::of(get('/manage/storefronts/1/banners')->assertOk())['block_row'])->toBeNull()
        ->and(Props::of(get('/manage/storefronts/1/home-rails')->assertOk())['kinds'])->toContain('banners');

    post('/manage/storefronts/1/home-rails', $new)->assertSessionHasNoErrors();
    $row = T::arr(Props::of(get('/manage/storefronts/1/banners')->assertOk())['block_row']);
    expect($row['is_active'])->toBeTrue()
        ->and($row['place'])->toBe(count(HomeRails::all(1)));
});
