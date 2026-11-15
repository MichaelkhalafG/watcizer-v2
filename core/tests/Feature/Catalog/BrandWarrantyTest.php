<?php

use App\Compat\CompatProducts;
use App\Domain\Catalog\LookupPatcher;
use App\Domain\Catalog\LookupWriter;
use App\Storefront\Warranty;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * THE WARRANTY A PRODUCT CARRIES (SEO Phase 1, Michael 2026-11-14). A watch with no warranty of its own carries its
 * brand's default; a brand with none gives a watch the shop's one year; anything that is not a watch states a warranty
 * only when it records its own; a product's own value always wins — 0 ("no warranty") included. Both storefront APIs
 * answer it the same way: v2's product detail (Brand Fashion, and Watchizer's v2 reads) and the compat rows (Watchizer).
 */

/** A brand of its own, with the given default. */
function warrantyBrand(?int $years): int
{
    $id = T::int(DB::table('catalog_brands')->insertGetId([
        'slug' => 'warranty-brand-'.bin2hex(random_bytes(4)), 'is_active' => 1, 'default_warranty_years' => $years,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    DB::table('catalog_brand_translations')->insert([
        ['brand_id' => $id, 'locale' => 'en', 'name' => 'Warranty Brand '.$id],
        ['brand_id' => $id, 'locale' => 'ar', 'name' => 'ماركة ضمان '.$id],
    ]);

    return $id;
}

/** A product on both storefronts, of that brand, with its own warranty. */
function warrantyProduct(string $family, int $brand, ?int $own): int
{
    $id = CatalogFixture::product($family, 100.0);
    DB::table('catalog_products')->where('id', $id)->update(['brand_id' => $brand, 'warranty_years' => $own]);
    CatalogFixture::onStorefront($id, true, 1);
    CatalogFixture::onStorefront($id, true, 2);

    return $id;
}

/**
 * What each API answers for it: [v2 on Brand Fashion, compat on Watchizer].
 *
 * @return array{0: mixed, 1: string|null}
 */
function warrantyAnswers(int $id): array
{
    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)->value('slug'));
    $v2 = getJson('/api/v2/brandfashion/products/'.$slug)->assertOk()->json('product.warranty_years');
    $row = (new CompatProducts(1))->query()->where('p.id', $id)->first();

    return [$v2, $row === null ? 'not listed' : CompatProducts::warranty($row)];
}

it('follows the rule: own value, else a watch\'s brand default, else a watch\'s one year, else nothing', function () {
    config(['catalog.warranty.watch_default_years' => 1]);
    expect(Warranty::years(3, 2, 'watch'))->toBe(3)
        ->and(Warranty::years(0, 2, 'watch'))->toBe(0)          // the product's "no warranty" wins
        ->and(Warranty::years(null, 2, 'watch'))->toBe(2)
        ->and(Warranty::years(null, null, 'watch'))->toBe(1)
        ->and(Warranty::years(null, 2, 'bag'))->toBeNull()      // a brand default is for watches only
        ->and(Warranty::years(2, null, 'perfume'))->toBe(2);    // a non-watch's own value is stated
});

it('answers the same warranty on v2 and compat for every case', function () {
    $twoYears = warrantyBrand(2);
    $none = warrantyBrand(null);

    expect(warrantyAnswers(warrantyProduct('watch', $twoYears, null)))->toBe([2, '2'])     // the brand's default
        ->and(warrantyAnswers(warrantyProduct('watch', $none, null)))->toBe([1, '1'])      // the shop's one year
        ->and(warrantyAnswers(warrantyProduct('watch', $twoYears, 3)))->toBe([3, '3'])     // its own wins
        ->and(warrantyAnswers(warrantyProduct('watch', $twoYears, 0)))->toBe([0, '0'])     // its own 0 wins
        ->and(warrantyAnswers(warrantyProduct('bag', $twoYears, null)))->toBe([null, null]); // not a watch: nothing
});

it('keeps a brand\'s empty default EMPTY through the full-replace form, and refuses one out of range', function () {
    $def = LookupWriter::definition('brands');
    expect($def['extra'])->toHaveKey('default_warranty_years');

    $brand = warrantyBrand(2);
    $payload = fn (string $years): array => [
        'name' => ['en' => 'Warranty Brand '.$brand, 'ar' => 'ماركة ضمان '.$brand],
        'extra' => ['slug' => 'warranty-brand-'.$brand, 'logo_path' => null, 'is_active' => true, 'default_warranty_years' => $years],
    ];

    app(LookupWriter::class)->update('brands', $brand, $payload(''));
    expect(DB::table('catalog_brands')->where('id', $brand)->value('default_warranty_years'))->toBeNull();   // not 0

    app(LookupWriter::class)->update('brands', $brand, $payload('0'));
    expect(T::int(DB::table('catalog_brands')->where('id', $brand)->value('default_warranty_years')))->toBe(0);

    $rules = LookupWriter::rules('brands', $brand)['extra.default_warranty_years'];
    expect($rules)->toContain('min:0')->and($rules)->toContain('max:10');
    expect(fn () => app(LookupPatcher::class)->patch('brands', $brand, ['extra.default_warranty_years' => 11]))
        ->toThrow(RuntimeException::class, 'out of its range');
});
