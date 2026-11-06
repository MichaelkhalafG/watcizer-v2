<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

/*
 * ── The mega menu's data (Part 4) and the category picture fallback (Part 5), 2026-11-05 ─────────
 *
 * Part 5's rule, as settled: a category with no image of its own shows the cover of its MOST RECENTLY ADDED
 * product (`created_at`, then id) that a shopper can buy on this storefront — visible, active, in stock, with
 * a photo — and a category whose picture the team deleted shows NOTHING, with no fallback.
 * Part 4's menu is built from the same tree: every tile, brand chip and featured card is a fact.
 *
 * Subjects are derived: the busiest sub-category of the storefront, and the products placed in it.
 */

/** @return array<mixed> the menu of Watchizer, freshly built */
function mnMenu(): array
{
    app(StorefrontCache::class)->flush(1);

    return T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/menu?locale=en')->assertOk()->json('data'));
}

/**
 * A menu sub-category of storefront 1 with at least three in-stock, pictured products placed in it.
 *
 * @return array{node: int, parent: int, products: list<int>}
 */
function mnNode(): array
{
    $raw = T::one(
        DB::table('storefront_categories as c')
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'c.id')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('c.storefront_id', 1)->where('c.depth', 2)->where('c.is_active', 1)->where('c.show_in_menu', 1)
            ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.in_stock', 1)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id'))
            ->groupBy('c.id', 'c.parent_id')->havingRaw('COUNT(DISTINCT p.id) >= 3')
            ->orderByRaw('COUNT(DISTINCT p.id) DESC')->select(['c.id', 'c.parent_id'])
    );
    $node = Row::int($raw, 'id');

    return ['node' => $node, 'parent' => Row::int($raw, 'parent_id'), 'products' => mnBuyable($node)];
}

/**
 * The products the fallback may choose from in a node, newest first — the rule, written a second time on purpose.
 *
 * @return list<int>
 */
function mnBuyable(int $node): array
{
    return array_values(array_map(fn (mixed $id): int => T::int($id), DB::table('storefront_category_product as scp')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('scp.storefront_category_id', $node)
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.in_stock', 1)
        ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'))
        ->orderByDesc('p.created_at')->orderByDesc('p.id')->distinct()->pluck('p.id')->all()));
}

/** Sell a product out through the service, at whichever level owns its stock (a variant product has no product-level stock). */
function mnSellOut(int $productId): void
{
    $service = app(InventoryService::class);
    $variants = DB::table('catalog_product_variants')->where('product_id', $productId)->pluck('id')->all();
    $targets = $variants === []
        ? [StockTarget::product($productId)]
        : array_map(fn (mixed $id): StockTarget => StockTarget::variant($productId, T::int($id)), $variants);
    foreach ($targets as $target) {
        $service->set($target, InventoryService::BUCKET_EXPRESS, 0, 'manual');
        $service->set($target, InventoryService::BUCKET_MARKET, 0, 'manual');
    }
}

/**
 * The cover file of a product, as the image object's `src` ends.
 *
 * @return non-empty-string
 */
function mnCover(int $productId): string
{
    $path = T::str(DB::table('catalog_product_images')->where('product_id', $productId)->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->value('path'));
    if ($path === '') {
        throw new RuntimeException("product {$productId} has no cover file");
    }

    return $path;
}

/**
 * @param  array<mixed>  $menu
 * @return array<mixed> the tile of a node in the menu
 */
function mnTile(array $menu, int $parent, int $node): array
{
    foreach (T::rows($menu['categories']) as $category) {
        if ($category['id'] === $parent) {
            foreach (T::rows($category['children']) as $child) {
                if ($child['id'] === $node) {
                    return $child;
                }
            }
        }
    }

    return [];
}

// ── Part 5 ───────────────────────────────────────────────────────────────────────────────────────

it('gives a category with no image its newest BUYABLE product\'s photo, and moves on when that product cannot be bought', function () {
    $s = mnNode();
    DB::table('storefront_categories')->where('id', $s['node'])->update(['image_path' => null, 'image_hidden' => 0]);
    [$newest, $second, $third] = $s['products'];

    $tile = mnTile(mnMenu(), $s['parent'], $s['node']);
    expect($tile['image_source'])->toBe('product')
        ->and(T::str(T::arr($tile['image'])['src']))->toEndWith(mnCover($newest));

    // HIDDEN on this storefront: the next most recent takes over.
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $newest)->update(['is_visible' => 0]);
    expect(T::str(T::arr(mnTile(mnMenu(), $s['parent'], $s['node'])['image'])['src']))->toEndWith(mnCover($second));

    // SOLD OUT: a product nobody can buy does not represent the category either.
    mnSellOut($second);
    expect(Row::bool(T::one(DB::table('catalog_products')->where('id', $second)), 'in_stock'))->toBeFalse();
    expect(T::str(T::arr(mnTile(mnMenu(), $s['parent'], $s['node'])['image'])['src']))->toEndWith(mnCover($third));

    // ARCHIVED (switched off in the catalogue): the same.
    DB::table('catalog_products')->where('id', $third)->update(['is_active' => 0]);
    $after = mnTile(mnMenu(), $s['parent'], $s['node']);
    $left = mnBuyable($s['node']);
    if ($left === []) {
        expect($after === [] || $after['image'] === null)->toBeTrue();
    } else {
        expect(T::str(T::arr($after['image'])['src']))->toEndWith(mnCover($left[0]));
    }
});

it('"most recent" is the date the product was ADDED, not the date it was last edited', function () {
    $s = mnNode();
    DB::table('storefront_categories')->where('id', $s['node'])->update(['image_path' => null, 'image_hidden' => 0]);
    [$newest, $second] = $s['products'];

    // Editing an OLDER product does not make it the category's face…
    DB::table('catalog_products')->where('id', $second)->update(['updated_at' => now()->addDay()]);
    expect(T::str(T::arr(mnTile(mnMenu(), $s['parent'], $s['node'])['image'])['src']))->toEndWith(mnCover($newest));

    // …a product ADDED later does.
    DB::table('catalog_products')->where('id', $second)->update(['created_at' => now()->addDay()]);
    expect(T::str(T::arr(mnTile(mnMenu(), $s['parent'], $s['node'])['image'])['src']))->toEndWith(mnCover($second));
});

it('keeps a category\'s OWN image, and shows NOTHING once the team deletes the picture — no fallback', function () {
    CatalogFixture::assumeSwitched();
    $s = mnNode();
    actingAs(Staff::admin());

    put("/manage/storefronts/1/categories/{$s['node']}/image", ['mode' => 'own', 'image_path' => 'Category_type/own-picture.webp'])->assertSessionHasNoErrors();
    $tile = mnTile(mnMenu(), $s['parent'], $s['node']);
    expect($tile['image_source'])->toBe('own')->and(T::str(T::arr($tile['image'])['src']))->toEndWith('Category_type/own-picture.webp');

    // DELETE = show nothing. The products are still there, in stock, with photos: it must NOT fall back.
    put("/manage/storefronts/1/categories/{$s['node']}/image", ['mode' => 'none'])->assertSessionHasNoErrors();
    $tile = mnTile(mnMenu(), $s['parent'], $s['node']);
    expect(mnBuyable($s['node']))->not->toBe([])
        ->and($tile['image'])->toBeNull()
        ->and($tile['image_source'])->toBeNull();
    $row = T::one(DB::table('storefront_categories')->where('id', $s['node']));
    expect(Row::nstr($row, 'image_path'))->toBeNull()->and(Row::bool($row, 'image_hidden'))->toBeTrue();

    // The screen says which state it is in; "automatic" is its own choice, and brings the product photo back.
    $listed = fn (): array => T::arr(array_values(array_filter(T::rows(Props::of(get('/manage/storefronts/1/categories')->assertOk())['nodes']), fn (array $n): bool => $n['id'] === $s['node']))[0]);
    expect($listed()['image_mode'])->toBe('none')->and($listed()['image_url'])->toBeNull();
    put("/manage/storefronts/1/categories/{$s['node']}/image", ['mode' => 'auto'])->assertSessionHasNoErrors();
    expect($listed()['image_mode'])->toBe('auto')
        ->and(T::str($listed()['image_url']))->toEndWith(mnCover(mnBuyable($s['node'])[0]))
        ->and(mnTile(mnMenu(), $s['parent'], $s['node'])['image_source'])->toBe('product');

    // An upload with no file, and a mode that is not one of the three, are refused.
    put("/manage/storefronts/1/categories/{$s['node']}/image", ['mode' => 'own'])->assertSessionHasErrors('image_path');
    put("/manage/storefronts/1/categories/{$s['node']}/image", ['mode' => 'random'])->assertSessionHasErrors('mode');
    expect(DB::table('core_activity_log')->where('subject_type', 'storefront_categories')->where('subject_id', $s['node'])->count())->toBe(3);
});

// ── Part 4 ───────────────────────────────────────────────────────────────────────────────────────

it('serves the mega menu from the storefront\'s own facts: tiles, brands, a featured product, genders, sale', function () {
    $menu = mnMenu();
    expect(array_keys($menu))->toBe(['categories', 'genders', 'brands', 'sale']);

    $categories = T::rows($menu['categories']);
    expect($categories)->not->toBe([]);
    foreach ($categories as $category) {
        // a top-level entry that HAS products, with the count the tree gives it
        $count = T::int(DB::table('storefront_categories as c')
            ->join('storefront_categories as n', fn (JoinClause $j) => $j->on('n.storefront_id', '=', 'c.storefront_id')->on('n.path', 'like', DB::raw("CONCAT(c.path, '%')")))
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'n.id')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('c.id', $category['id'])->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->distinct()->count('scp.product_id'));
        expect($category['count'])->toBe($count)->and($count)->toBeGreaterThan(0)
            ->and($category['legacy_id'])->toBeInt();

        foreach (T::rows($category['children']) as $child) {
            expect(T::int($child['count']))->toBeGreaterThan(0)
                ->and($child)->toHaveKeys(['id', 'slug', 'path', 'name', 'legacy_id', 'image', 'image_source']);
        }
        // popular brands: at most eight, most products first, each really in this category
        $brandCounts = array_column(T::rows($category['brands']), 'count');
        $sorted = $brandCounts;
        rsort($sorted);
        expect(count($brandCounts))->toBeLessThanOrEqual(8)->and($brandCounts)->toBe($sorted);

        // the featured card: a product of THIS category that can be bought, and the lowest price in it
        if ($category['featured'] !== null) {
            $featured = T::arr($category['featured']);
            $product = T::arr($featured['product']);
            $row = T::one(DB::table('catalog_products')->where('id', $product['id']));
            expect(Row::bool($row, 'in_stock'))->toBeTrue()
                ->and(T::arr($product['image'])['src'] ?? null)->toBeString()
                ->and(T::float($featured['price_from']))->toBeGreaterThan(0.0)
                ->and(T::float($featured['price_from']))->toBeLessThanOrEqual(T::float(T::arr($product['price'])['sale_amount'] ?? T::arr($product['price'])['amount']));
        }
    }

    // genders: only ones that have products, each tile a sub-category holding products of that gender
    foreach (T::rows($menu['genders']) as $gender) {
        expect(T::int($gender['count']))->toBeGreaterThan(0)->and(T::rows($gender['children']))->not->toBe([]);
        foreach (T::rows($gender['children']) as $tile) {
            $n = T::int(DB::table('storefront_category_product as scp')
                ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
                ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
                ->join('catalog_product_gender as pg', 'pg.product_id', '=', 'p.id')
                ->where('scp.storefront_category_id', $tile['id'])->where('scp.storefront_id', 1)->where('pg.gender_id', $gender['id'])
                ->where('p.is_active', 1)->whereNull('p.deleted_at')->distinct()->count('p.id'));
            expect($tile['count'])->toBe($n);
        }
    }

    // sale: the real count of in-stock discounted products, or null when there is none
    $onSale = DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.in_stock', 1)
        ->where('sp.effective_sale_price', '>', 0)->whereColumn('sp.effective_sale_price', '<', 'sp.effective_price')->count();
    expect($menu['sale'] === null ? 0 : T::int(T::arr($menu['sale'])['count']))->toBe($onSale);
});

it('answers the menu per storefront, and 404s a storefront that does not exist', function () {
    getJson('https://api.michaelkhalaf.com/api/v2/brandfashion/menu')->assertOk()->assertJsonStructure(['data' => ['categories', 'genders', 'brands', 'sale'], 'locale']);
    getJson('https://api.watchizereg.com/api/v2/nosuchshop/menu')->assertNotFound();
});
