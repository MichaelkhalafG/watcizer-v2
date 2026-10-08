<?php

use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `catalog/nav` — the header menu's catalogue facts (C-1 stage 2, 2026-09-27).
 *
 * The menu used to derive them in the browser from the whole catalogue: which brands have
 * products, which sub-types and brands each category type has, which genders exist. The contract
 * is that "has products" means exactly what the full catalogue lists, so the test derives the facts
 * independently from the row-built reference catalogue (`catalogueReferenceRows`, the pre-L8 builder)
 * and compares them with the nav the endpoint builds leanly (L8, 2026-10-06).
 */

const NAV_API_KEY = 'catalog-nav-test-key';

beforeEach(function () {
    config(['compat.api_key' => NAV_API_KEY]);
});

/** @return array<string, mixed> */
function navFromAllProduct(): array
{
    $rows = catalogueReferenceRows();
    $brands = [];
    $subs = [];
    $byCat = [];
    $genders = [];
    foreach ($rows as $p) {
        $p = T::arr($p);
        $b = $p['brand_id'] ?? null;
        $c = $p['category_type_id'] ?? null;
        $s = $p['sub_type_id'] ?? null;
        if (is_int($b)) {
            $brands[$b] = true;
        }
        if (is_int($c) && is_int($s)) {
            $subs[$c][$s] = true;
        }
        if (is_int($c) && is_int($b)) {
            $byCat[$c][$b] = true;
        }
        foreach (T::arr($p['gender'] ?? []) as $g) {
            foreach (T::arr(T::arr($g)['translations'] ?? []) as $t) {
                $t = T::arr($t);
                if (($t['locale'] ?? null) === 'en') {
                    $genders[T::str($t['gender_name'])] = true;
                }
            }
        }
    }
    $ids = function (array $set): array {
        $k = array_map('intval', array_keys($set));
        sort($k);

        return $k;
    };
    $map = function (array $m) use ($ids): array {
        ksort($m);
        $out = [];
        foreach ($m as $k => $set) {
            $out[(string) $k] = $ids(T::arr($set));
        }

        return $out;
    };
    $g = array_keys($genders);
    sort($g);

    return ['brand_ids' => $ids($brands), 'sub_types_by_category' => $map($subs), 'brands_by_category' => $map($byCat), 'genders' => $g];
}

it('lists exactly the brands, sub-types, category brands and genders all_product carries', function () {
    $expected = navFromAllProduct();
    $nav = withHeaders(['Api-Code' => NAV_API_KEY])->getJson('/api/catalog/nav')->assertOk()->json();
    $nav = T::arr($nav);

    $genders = array_map(fn (mixed $g): string => T::str(T::arr($g)['en']), T::arr($nav['genders']));
    $sorted = $genders;
    sort($sorted);

    expect($nav['brand_ids'])->toBe($expected['brand_ids'])
        ->and($nav['brand_ids'])->not->toBeEmpty()
        ->and($nav['sub_types_by_category'])->toBe($expected['sub_types_by_category'])
        ->and($nav['brands_by_category'])->toBe($expected['brands_by_category'])
        ->and($sorted)->toBe($expected['genders']);
});

it('orders genders as the menu shows them and carries both names', function () {
    $nav = T::arr(withHeaders(['Api-Code' => NAV_API_KEY])->getJson('/api/catalog/nav')->assertOk()->json());
    $genders = array_map(fn (mixed $g): array => T::arr($g), T::arr($nav['genders']));
    $en = array_map(fn (array $g): string => T::str($g['en']), $genders);

    $known = array_values(array_intersect(['Men', 'Women', 'Unisex', 'Kids', 'Boys', 'Girls'], $en));
    expect(array_slice($en, 0, count($known)))->toBe($known);
    foreach ($genders as $g) {
        $ar = T::str($g['ar']);
        expect($ar)->not->toBe('')->and($ar)->toMatch('/\p{Arabic}/u');
    }
});

it('refuses a caller without the api code', function () {
    withHeaders(['Api-Code' => 'wrong'])->getJson('/api/catalog/nav')->assertUnauthorized();
});

it('follows a product that moves to a brand with no products, once the storefront cache is flushed', function () {
    $before = T::arr(withHeaders(['Api-Code' => NAV_API_KEY])->getJson('/api/catalog/nav')->json());
    $listed = array_map(fn (mixed $id): int => T::int($id), T::arr($before['brand_ids']));
    $unused = T::int(DB::table('catalog_brands')->whereNotIn('id', $listed)->orderBy('id')->value('id'));
    $productId = T::int(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
        ->where('sp.storefront_id', 1)->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));

    DB::table('catalog_products')->where('id', $productId)->update(['brand_id' => $unused]);
    app(StorefrontCache::class)->flush(1);

    $after = T::arr(withHeaders(['Api-Code' => NAV_API_KEY])->getJson('/api/catalog/nav')->json());
    expect(array_map(fn (mixed $id): int => T::int($id), T::arr($after['brand_ids'])))->toContain($unused);
});
