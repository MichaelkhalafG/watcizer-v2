<?php

use App\Domain\Content\HomeRails;
use App\Domain\Media\CutoutMask;
use App\Storefront\CutMasks;
use App\Storefront\StorefrontCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;

/*
 * ── The developer's review of 2026-11-05 (round 2 and its additions) ─────────────────────────────
 *
 *  • every home section has a place on the Home sliders screen (the campaign sliders, the category tiles, the
 *    brand strip, the Banners block), and two new sliders: the best value, and "under a price";
 *  • `home:rebuild` lays out a new home WITHOUT deleting anything, and `--restore` puts the rows back;
 *  • a brand banner is offered the brand's RANGE (a watch, a bag, an accessory), never socks while it has more;
 *  • a slider's colour comes from the shop's presets or any colour; its BLEND never tints the product — the
 *    photo's studio background gives way through a mask, the product's pixels are the photo's own;
 *  • the menu carries the Sale pane's facts and a section's top picks.
 *
 * As in HomeZoneTest, every assertion reads the payload a storefront reads.
 */

const R2_API_KEY = 'round-two-test-key';

beforeEach(function () {
    config(['compat.api_key' => R2_API_KEY]);
    actingAs(Staff::admin());
});

/** @return array<mixed> */
function r2Home(): array
{
    app(StorefrontCache::class)->flush(1);

    return T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/home')->assertOk()->json());
}

/** @return array<mixed> */
function r2Compat(): array
{
    app(StorefrontCache::class)->flush(1);

    return T::arr(withHeaders(['Api-Code' => R2_API_KEY])->getJson('/api/catalog/home')->assertOk()->json());
}

/** The products storefront 1 can sell now, as the sliders see them. */
function r2Buyable(): Builder
{
    return DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.in_stock', 1);
}

/** A PNG file: a white studio ground, a product in the middle, and a white area ENCLOSED by the product. */
function r2StudioPhoto(string $file, bool $lifestyle = false): void
{
    $im = imagecreatetruecolor(400, 400);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
    if ($lifestyle) {
        imagefilledrectangle($im, 0, 0, 399, 399, (int) imagecolorallocate($im, 96, 132, 88));     // fills its frame
    } else {
        imagefilledrectangle($im, 100, 100, 300, 300, (int) imagecolorallocate($im, 30, 60, 140));  // the product
        imagefilledrectangle($im, 170, 170, 230, 230, (int) imagecolorallocate($im, 255, 255, 255)); // a white dial
    }
    imagepng($im, $file);
    imagedestroy($im);
}

/** The opacity (0 transparent … 255 opaque) of a mask at a point given in the PHOTO's 400 px space. */
function r2Opacity(string $png, int $x, int $y): int
{
    $mask = imagecreatefromstring($png);
    assert($mask !== false);
    $scale = imagesx($mask) / 400;
    $alpha = (imagecolorat($mask, (int) round($x * $scale), (int) round($y * $scale)) >> 24) & 0x7F;

    return (int) round((127 - $alpha) * 255 / 127);
}

// ── every section has a place ────────────────────────────────────────────────────────────────────

it('sends every home section as a row in its place, with no products, and names the ones the dashboard manages', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $place = 10;
    foreach (['categories', 'hot_deals', 'newest', 'flash_sale', 'brands'] as $kind) {
        DB::table('storefront_home_rails')->insert([
            'storefront_id' => 1, 'kind' => $kind, 'target_id' => null, 'title_en' => null, 'title_ar' => null,
            'position' => $place, 'is_active' => true, 'card_count' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $place += 10;
    }

    $home = r2Home();
    $kinds = array_map(fn (array $r): string => T::str($r['kind']), T::rows($home['rails']));
    expect($kinds)->toBe(['categories', 'hot_deals', 'newest', 'flash_sale', 'brands'])
        ->and($home['managed_sections'])->toEqualCanonicalizing(['categories', 'hot_deals', 'flash_sale', 'brands']);
    foreach (T::rows($home['rails']) as $rail) {
        if ($rail['kind'] !== 'newest') {
            expect($rail['cards'])->toBe([])->and($rail['count'])->toBe(0);
        }
    }

    // Watchizer's live path says the same.
    $compat = r2Compat();
    expect(array_map(fn (array $r): string => T::str($r['kind']), T::rows($compat['rails'])))->toBe(['categories', 'hot_deals', 'newest', 'flash_sale', 'brands'])
        ->and($compat['managed_sections'])->toEqualCanonicalizing(['categories', 'hot_deals', 'flash_sale', 'brands']);
    foreach (T::rows($compat['rails']) as $rail) {
        if ($rail['kind'] !== 'newest') {
            expect($rail['products'])->toBe([]);
        }
    }
});

it('fills the best-value slider with real discounts in stock, biggest first, and the price slider with products at or under its cap', function () {
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $base = ['target_id' => null, 'title_ar' => null, 'title_en' => null, 'card_count' => 8, 'is_active' => true];
    $twentieth = r2Buyable()->orderBy('sp.effective_price')->offset(20)->value('sp.effective_price');
    $cap = (int) ceil(is_numeric($twentieth) ? (float) $twentieth : 0.0);
    expect($cap)->toBeGreaterThan(0);

    post('/manage/storefronts/1/home-rails', ['kind' => 'best_value'] + $base)->assertSessionHasNoErrors();
    post('/manage/storefronts/1/home-rails', ['kind' => 'price', 'max_price' => $cap] + $base)->assertSessionHasNoErrors();
    post('/manage/storefronts/1/home-rails', ['kind' => 'price'] + $base)->assertSessionHasErrors('max_price');

    $byKind = [];
    foreach (T::rows(r2Home()['rails']) as $rail) {
        $byKind[T::str($rail['kind'])] = $rail;
    }

    $discounts = [];
    foreach (T::rows($byKind['best_value']['cards']) as $card) {
        $price = T::arr($card['price']);
        expect($price['has_sale'])->toBeTrue()->and(T::arr($card['stock'])['in_stock'])->toBeTrue();
        $discounts[] = T::int($price['discount_pct']);
    }
    $sorted = $discounts;
    rsort($sorted);
    expect($discounts)->not->toBe([])->and($discounts)->toBe($sorted, 'the biggest discount leads');

    expect(T::int($byKind['price']['target']))->toBe($cap);
    foreach (T::rows($byKind['price']['cards']) as $card) {
        $price = T::arr($card['price']);
        $pays = $price['has_sale'] ? $price['sale_amount'] : $price['amount'];
        expect(is_numeric($pays) ? (float) $pays : INF)->toBeLessThanOrEqual($cap)->and(T::arr($card['stock'])['in_stock'])->toBeTrue();
    }
});

// ── home:rebuild ─────────────────────────────────────────────────────────────────────────────────

it('home:rebuild writes nothing without --apply, switches the old rows off without deleting one, refuses a second apply, and --restore puts them back', function () {
    $snapshot = fn (): array => DB::table('storefront_home_rails')->where('storefront_id', 1)->orderBy('id')
        ->get(['id', 'kind', 'position', 'is_active'])->map(fn (object $r): array => (array) $r)->all();
    $before = $snapshot();
    $ids = array_column($before, 'id');

    expect(Artisan::call('home:rebuild', ['--storefront' => 'watchizer']))->toBe(0);
    expect($snapshot())->toBe($before, 'a dry run writes nothing');

    expect(Artisan::call('home:rebuild', ['--storefront' => 'watchizer', '--apply' => true]))->toBe(0);
    $after = DB::table('storefront_home_rails')->where('storefront_id', 1)->get();
    // not one row deleted…
    expect($after->pluck('id')->intersect($ids)->count())->toBe(count($ids));
    $active = $after->where('is_active', 1);
    $kinds = $active->pluck('kind')->all();
    // …and the new home has its sections, each once, and both new sliders
    foreach (['hot_deals', 'flash_sale', 'categories', 'brands', 'banners'] as $section) {
        expect(count(array_keys($kinds, $section, true)))->toBe(1, "one $section row");
    }
    // …and the "who and what" sliders are curated rows: products in stock, no product in two of them, brands mixed
    expect($kinds)->toContain('custom')->toContain('newest');
    $seen = [];
    foreach (HomeRails::active(1) as $rail) {
        if ($rail['kind'] !== 'custom') {
            continue;
        }
        expect(count($rail['product_ids']))->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(12)
            ->and(array_intersect($rail['product_ids'], $seen))->toBe([], 'a product is on one curated slider only')
            ->and(r2Buyable()->whereIn('p.id', $rail['product_ids'])->count())->toBe(count($rail['product_ids']), 'every one of them is in stock');
        $brands = DB::table('catalog_products')->whereIn('id', $rail['product_ids'])->distinct()->count('brand_id');
        $brandOf = DB::table('catalog_products')->whereIn('id', $rail['product_ids'])->pluck('brand_id', 'id');
        expect($brands)->toBeGreaterThan(1, 'the brands are mixed')
            ->and($brandOf[$rail['product_ids'][0]])->not->toBe($brandOf[$rail['product_ids'][1]], 'the first two are not of one brand');
        $seen = [...$seen, ...$rail['product_ids']];
    }
    // Hot Deals and the Flash Sale are never neighbours
    $order = array_map(fn (array $r): string => $r['kind'], HomeRails::active(1));
    expect(abs(array_search('hot_deals', $order, true) - array_search('flash_sale', $order, true)))->toBeGreaterThan(1);
    // its coloured sliders use this shop's presets, not the beige of the first cut
    $presets = array_column(HomeRails::presets('watchizer'), 'hex');
    foreach (HomeRails::active(1) as $rail) {
        if ($rail['background'] !== null) {
            expect($presets)->toContain($rail['background']);
        }
    }

    expect(Artisan::call('home:rebuild', ['--storefront' => 'watchizer', '--apply' => true]))->toBe(1, 'a second apply is refused');

    expect(Artisan::call('home:rebuild', ['--storefront' => 'watchizer', '--restore' => true]))->toBe(0);
    $restored = DB::table('storefront_home_rails')->where('storefront_id', 1)->whereIn('id', $ids)->orderBy('id')
        ->get(['id', 'kind', 'position', 'is_active'])->map(fn (object $r): array => (array) $r)->all();
    expect($restored)->toBe($before, 'the old rows are exactly as they were');
    expect(DB::table('storefront_home_rails')->where('storefront_id', 1)->whereNotIn('id', $ids)->where('is_active', 1)->count())->toBe(0);
});

// ── a brand banner shows the brand's range ───────────────────────────────────────────────────────

it('offers a brand banner the brand\'s range — a watch first, then a bag — and no socks while the brand has anything else', function () {
    $kindOf = function (int $productId): string {
        $slugs = DB::table('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->leftJoin('storefront_categories as top', 'top.id', '=', 'c.parent_id')
            ->where('scp.storefront_id', 1)->where('scp.product_id', $productId)->get(['c.slug as slug', 'top.slug as top_slug']);
        $is = 'loose';
        foreach ($slugs as $row) {
            if ($row->slug === 'watches' || $row->top_slug === 'watches') {
                return 'watch';
            }
            if ($row->slug === 'bags') {
                $is = 'bag';
            } elseif ($row->slug === 'socks' && $is !== 'bag') {
                $is = 'socks';
            } elseif ($row->top_slug !== null && ! in_array($is, ['bag', 'socks'], true)) {
                $is = 'accessory';
            }
        }

        return $is;
    };

    $pictured = fn (): Builder => r2Buyable()->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'));
    $brands = $pictured()->groupBy('p.brand_id')->havingRaw('COUNT(*) >= 3')->orderByRaw('COUNT(*) DESC')->limit(12)->pluck('p.brand_id');
    $checked = 0;
    foreach ($brands as $brand) {
        $brandId = T::int($brand);
        $picks = HomeRails::suggestBannerProducts(1, $brandId);
        expect($picks)->toHaveCount(3)->and(array_unique($picks))->toHaveCount(3);

        $kinds = [];
        foreach ($pictured()->where('p.brand_id', $brandId)->pluck('p.id') as $id) {
            $kinds[T::int($id)] = $kindOf(T::int($id));
        }
        $have = array_count_values($kinds);
        $picked = array_map(fn (int $id): string => $kinds[$id], $picks);

        if (($have['watch'] ?? 0) > 0) {
            expect($picked[0])->toBe('watch', "brand $brandId has watches: the first pick is one");
        }
        if (($have['watch'] ?? 0) > 0 && ($have['bag'] ?? 0) > 0) {
            expect($picked[1])->toBe('bag', "brand $brandId has both: the second pick is a bag");
        }
        $better = ($have['watch'] ?? 0) + ($have['bag'] ?? 0) + ($have['accessory'] ?? 0);
        if ($better >= 3) {
            expect($picked)->not->toContain('socks');
            expect($picked)->not->toContain('loose');
        }
        $checked++;

        // the dashboard's picker asks for the same three
        expect(getJson("/manage/storefronts/1/home-rails/banner-picks?brand=$brandId")->assertOk()->json('ids'))->toBe($picks);
    }
    expect($checked)->toBeGreaterThan(0);
});

// ── the slider's colour: presets ─────────────────────────────────────────────────────────────────

it('offers each shop its own preset colours on the Home sliders screen, every one a valid colour and none of them the old beige', function () {
    foreach ([1 => 'watchizer', 2 => 'brandfashion'] as $id => $code) {
        if (! DB::table('storefronts')->where('id', $id)->where('code', $code)->exists()) {
            continue;
        }
        $presets = T::rows(Props::of(get("/manage/storefronts/$id/home-rails")->assertOk())['background_presets']);
        expect(array_column($presets, 'hex'))->toBe(array_column(HomeRails::presets($code), 'hex'));
        foreach ($presets as $preset) {
            expect(HomeRails::isHex(T::str($preset['hex'])))->toBeTrue()->and(T::str($preset['name']))->not->toBe('');
        }
        expect(array_column($presets, 'hex'))->not->toContain('#EFE7DA');
        expect(array_column($presets, 'hex'))->not->toContain('#E9E4DC');
    }
    expect(array_column(HomeRails::presets('watchizer'), 'hex'))->not->toBe(array_column(HomeRails::presets('brandfashion'), 'hex'));
});

// ── the blend never tints the product ────────────────────────────────────────────────────────────

it('masks only the white CONNECTED TO THE EDGE of a photo: the product and the white enclosed by it stay opaque', function () {
    $file = sys_get_temp_dir().'/r2-studio-'.bin2hex(random_bytes(4)).'.png';
    r2StudioPhoto($file);
    $mask = CutoutMask::fromFile($file);
    @unlink($file);

    expect($mask)->not->toBeNull();
    assert($mask !== null);
    expect(max($mask['width'], $mask['height']))->toBe(CutoutMask::SIZE)
        ->and(strlen($mask['png']))->toBeLessThan(4096, 'a mask is small enough to travel inside the page')
        ->and(r2Opacity($mask['png'], 20, 20))->toBe(0, 'the studio white is transparent')
        ->and(r2Opacity($mask['png'], 380, 200))->toBe(0)
        ->and(r2Opacity($mask['png'], 130, 130))->toBe(255, 'the product is opaque')
        ->and(r2Opacity($mask['png'], 200, 200))->toBe(255, 'white INSIDE the product is never made transparent')
        ->and($mask['background'])->toBeGreaterThan(0.5);
});

it('gives no mask to a photo that has no studio background, and none to a file that is not an image', function () {
    $file = sys_get_temp_dir().'/r2-life-'.bin2hex(random_bytes(4)).'.png';
    r2StudioPhoto($file, lifestyle: true);
    expect(CutoutMask::fromFile($file))->toBeNull();
    file_put_contents($file, 'not an image');
    expect(CutoutMask::fromFile($file))->toBeNull();
    @unlink($file);
    expect(CutoutMask::fromFile($file))->toBeNull();
});

it('sends the masks with a slider only when its blend is on, makes a missing one once from the photo, and never for a photo it cannot read', function () {
    $root = sys_get_temp_dir().'/r2-media-'.bin2hex(random_bytes(4));
    mkdir($root.'/Product', 0777, true);
    config(['media.root' => $root]);

    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    $base = ['target_id' => null, 'title_ar' => null, 'title_en' => null, 'card_count' => 4, 'is_active' => true];
    post('/manage/storefronts/1/home-rails', ['kind' => 'newest', 'background' => '#DCEBFA', 'blend' => false] + $base)->assertSessionHasNoErrors();

    $rail = T::rows(r2Home()['rails'])[0];
    $ids = array_map(fn (array $c): int => T::int($c['id']), T::rows($rail['cards']));
    expect($ids)->not->toBe([])->and($rail['masks'])->toBe([], 'no blend: the slider carries no masks');

    // the first product's cover is a studio photo on this disk; the second one's file is missing
    $covers = [];
    foreach ($ids as $id) {
        $covers[$id] = T::int(DB::table('catalog_product_images')->where('product_id', $id)->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->value('id'));
    }
    DB::table('catalog_product_images')->whereIn('id', $covers)->update(['cut_mask' => null, 'cut_mask_at' => null]);
    [$first, $second] = [$ids[0], $ids[1]];
    DB::table('catalog_product_images')->where('id', $covers[$first])->update(['path' => 'Product/r2-first.png']);
    DB::table('catalog_product_images')->where('id', $covers[$second])->update(['path' => 'Product/r2-missing.png']);
    r2StudioPhoto($root.'/Product/r2-first.png');

    $railId = T::int(DB::table('storefront_home_rails')->where('storefront_id', 1)->value('id'));
    \Pest\Laravel\put('/manage/storefronts/1/home-rails/'.$railId, ['kind' => 'newest', 'background' => '#DCEBFA', 'blend' => true] + $base)->assertSessionHasNoErrors();

    $masks = T::arr(T::rows(r2Home()['rails'])[0]['masks']);
    expect($masks)->toHaveKey($first);
    expect($masks)->not->toHaveKey($second);
    expect(T::str($masks[$first]))->toStartWith('data:image/png;base64,');
    $row = DB::table('catalog_product_images')->where('id', $covers[$first])->first(['cut_mask', 'cut_mask_at']);
    expect($row?->cut_mask)->not->toBeNull()->and($row?->cut_mask_at)->not->toBeNull();
    // a file that is not there is not marked as examined: it may arrive
    expect(DB::table('catalog_product_images')->where('id', $covers[$second])->value('cut_mask_at'))->toBeNull();

    // made once: with the file gone, the stored mask still travels — on Watchizer's live path too
    @unlink($root.'/Product/r2-first.png');
    $compat = T::arr(T::rows(r2Compat()['rails'])[0]['masks']);
    expect($compat)->toHaveKey($first)->and($compat[$first])->toBe($masks[$first]);
    expect(CutMasks::forProducts([$first], false))->toHaveKey($first);

    @rmdir($root.'/Product');
    @rmdir($root);
});

// ── the menu: the Sale pane and a section's top picks ────────────────────────────────────────────

it('tells the menu what is on sale by category and brand, and gives each section top picks that are not its featured product', function () {
    app(StorefrontCache::class)->flush(1);
    $menu = T::arr(T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/menu')->assertOk()->json())['data']);

    $sale = T::arr($menu['sale']);
    $real = T::int(r2Buyable()->whereRaw('sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price')->count());
    expect(T::int($sale['count']))->toBe($real, 'the Sale count is the products really discounted and in stock');
    $total = 0;
    foreach (T::rows($sale['categories']) as $category) {
        expect(T::int($category['count']))->toBeGreaterThan(0)->and(T::int($category['max_discount']))->toBeGreaterThan(0)->toBeLessThan(100);
        $total += T::int($category['count']);
    }
    foreach (T::rows($sale['brands']) as $brand) {
        expect(T::int($brand['count']))->toBeGreaterThan(0)->toBeLessThanOrEqual($real);
    }
    if ($real > 0) {
        expect($total)->toBeGreaterThan(0);
    }

    foreach ([...T::rows($menu['categories']), ...T::rows($menu['genders'])] as $node) {
        $picks = T::rows($node['picks']);
        expect(count($picks))->toBeLessThanOrEqual(4);
        $featured = T::arr($node['featured'] ?? [])['product'] ?? null;
        $ids = array_map(fn (array $p): int => T::int($p['id']), $picks);
        expect(array_unique($ids))->toHaveCount(count($ids));
        if (is_array($featured)) {
            expect($ids)->not->toContain(T::int($featured['id']));
        }
        foreach ($picks as $pick) {
            expect($pick)->toHaveKeys(['id', 'slug', 'title', 'brand', 'image', 'price']);
        }
    }
});

// ── a live campaign with nothing to show ─────────────────────────────────────────────────────────

it('does not send a campaign that is inside its dates but has no product this storefront shows, and lists every product id of one that has', function () {
    DB::table('storefront_campaigns')->where('storefront_id', 1)->delete();
    $ids = array_map(fn (mixed $id): int => T::int($id), r2Buyable()->orderByDesc('p.id')->limit(3)->pluck('p.id')->all());
    $make = function (string $presentation, array $products): int {
        $id = T::int(DB::table('storefront_campaigns')->insertGetId([
            'storefront_id' => 1, 'name' => 'r2 '.$presentation, 'presentation' => $presentation,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true, 'activated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        foreach ($products as $i => $product) {
            DB::table('storefront_campaign_products')->insert(['campaign_id' => $id, 'product_id' => $product, 'position' => $i]);
        }

        return $id;
    };
    $make('hot_deals', $ids);
    $make('flash_sale', []);

    app(StorefrontCache::class)->flush(1);
    $body = T::arr(T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/campaigns')->assertOk()->json())['data']);
    expect($body['flash_sale'])->toBeNull('inside its dates, nothing to show: not sent');
    $hot = T::arr($body['hot_deals']);
    expect($hot['product_ids'])->toEqualCanonicalizing($ids)->and(T::int($hot['count']))->toBe(3);
});
