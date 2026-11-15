<?php

use App\Domain\Catalog\ProductPatcher;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * A META TITLE WRITTEN BY HAND (2026-11-14, decision D7). The storefronts build a product's <title> from the catalogue,
 * except where a PERSON wrote the meta title in the dashboard. `meta_title_by_hand` marks those: on for a signed-in
 * person's text, off for an empty one or for any other writer (an artisan import, a command). v2's product detail
 * answers it per language as `meta.title_by_hand`.
 */

function byHand(int $productId, string $locale): int
{
    return T::int(DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', $locale)->value('meta_title_by_hand'));
}

it('marks a meta title a signed-in person writes, and not one a command writes', function () {
    $id = CatalogFixture::product('watch');

    Auth::logout();
    app(ProductPatcher::class)->patch($id, ['meta_title.en' => 'Written by an import']);
    expect(byHand($id, 'en'))->toBe(0);

    actingAs(Staff::admin());
    app(ProductPatcher::class)->patch($id, ['meta_title.en' => 'Hugo Boss Grand Prix, the team\'s words']);
    expect(byHand($id, 'en'))->toBe(1)
        ->and(byHand($id, 'ar'))->toBe(0);        // only the language that was written

    // a change to something else leaves the mark alone; emptying the meta title clears it
    app(ProductPatcher::class)->patch($id, ['title.en' => 'Another product title']);
    expect(byHand($id, 'en'))->toBe(1);
    app(ProductPatcher::class)->patch($id, ['meta_title.en' => '']);
    expect(byHand($id, 'en'))->toBe(0);
});

it('answers the mark per language on v2\'s product detail', function () {
    $id = CatalogFixture::product('watch');
    CatalogFixture::onStorefront($id, true, 2);
    actingAs(Staff::admin());
    app(ProductPatcher::class)->patch($id, ['meta_title.ar' => 'عنوان كتبه الفريق']);
    app(StorefrontCache::class)->flush(2);
    Auth::logout();

    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)->value('slug'));
    $meta = T::arr(getJson('/api/v2/brandfashion/products/'.$slug)->assertOk()->json('product.meta'));
    expect($meta['title_by_hand'] ?? null)->toBe(['ar' => true, 'en' => false]);
});
