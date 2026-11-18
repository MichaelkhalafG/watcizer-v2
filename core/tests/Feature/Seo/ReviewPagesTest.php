<?php

use App\Domain\Reviews\Reviews;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * THE PRODUCT PAGE'S REVIEWS TAB (2026-11-18): the first page and the bars per star come with the product; "Show more"
 * reads the next pages from products/{id}/reviews, read only. Approved reviews of this shop only; a product the shop
 * does not show answers 404.
 */
beforeEach(function () {
    DB::table('product_reviews')->delete();
});

function tabReview(int $storefront, int $product, int $rating, string $status = 'approved', int $minutesAgo = 0): int
{
    return T::int(DB::table('product_reviews')->insertGetId([
        'storefront_id' => $storefront, 'product_id' => $product, 'order_id' => null, 'request_id' => null, 'source' => 'guest',
        'email_hash' => hash('sha256', uniqid('tab', true)), 'verified' => false, 'author_name' => 'Tab Tester', 'rating' => $rating,
        'body' => 'A review for the Reviews tab test.', 'photos' => null, 'locale' => 'en', 'status' => $status,
        'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
    ]));
}

it('counts the approved reviews per star, 5 to 1, and leaves the held and hidden ones out', function () {
    $product = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($product, true, 1);
    foreach ([5, 5, 5, 4, 2] as $r) {
        tabReview(1, $product, $r);
    }
    tabReview(1, $product, 1, 'pending');
    tabReview(1, $product, 1, 'rejected');
    tabReview(2, $product, 1);                                   // another shop's

    $out = Reviews::approvedFor(1, $product);
    expect($out['count'])->toBe(5)
        ->and($out['breakdown'])->toBe([5 => 3, 4 => 1, 3 => 0, 2 => 1, 1 => 0])
        ->and(array_keys($out['breakdown']))->toBe([5, 4, 3, 2, 1]);

    $empty = Reviews::approvedFor(1, CatalogFixture::product('watch', 1000.0));
    expect($empty['count'])->toBe(0)->and($empty['breakdown'])->toBe([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]);
});

it('pages the approved reviews ten at a time, newest first, and says when there are more', function () {
    $product = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($product, true, 1);
    $ids = [];
    for ($i = 0; $i < 23; $i++) {
        $ids[] = tabReview(1, $product, 5, 'approved', 100 - $i);          // the last one written is the newest
    }
    tabReview(1, $product, 5, 'pending');

    $first = Reviews::approvedFor(1, $product);
    expect(array_column($first['items'], 'id'))->toBe(array_slice(array_reverse($ids), 0, 10));

    $two = getJson("/api/v2/watchizer/products/{$product}/reviews?page=2")->assertOk()->json('data');
    expect(T::arr($two)['page'])->toBe(2)->and(T::arr($two)['has_more'])->toBeTrue()
        ->and(array_column(T::arr(T::arr($two)['items']), 'id'))->toBe(array_slice(array_reverse($ids), 10, 10));

    $three = T::arr(getJson("/api/v2/watchizer/products/{$product}/reviews?page=3")->assertOk()->json('data'));
    expect($three['has_more'])->toBeFalse()->and(T::arr($three['items']))->toHaveCount(3)
        ->and(array_keys(T::arr(T::arr($three['items'])[0])))->toBe(['id', 'name', 'rating', 'body', 'photos', 'locale', 'date', 'verified']);
});

it("answers 404 for a product the shop does not show, and never another shop's reviews", function () {
    $shown = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($shown, true, 1);
    tabReview(2, $shown, 5);
    // Brand Fashion does not show it: 404 there; Watchizer shows it, with none of Brand Fashion's reviews
    getJson("/api/v2/brandfashion/products/{$shown}/reviews?page=2")->assertNotFound();
    expect(T::arr(getJson("/api/v2/watchizer/products/{$shown}/reviews?page=1")->assertOk()->json('data.items')))->toBe([]);

    $hidden = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($hidden, false, 1);
    tabReview(1, $hidden, 5);
    getJson("/api/v2/watchizer/products/{$hidden}/reviews?page=1")->assertNotFound();
    getJson('/api/v2/watchizer/products/999999999/reviews')->assertNotFound();
});
