<?php

use App\Storefront\StorefrontCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * Product ratings, the write path (B1, 2026-10-01). Decisions: signed-in customers only, no
 * purchase requirement, no moderation; one rating per customer per product, a second submission
 * replaces the first. The storefront's review form posts its fields as a QUERY STRING
 * (`http.post(url, null, {params})`), with a `user_id` that must never be believed.
 */

const RATING_KEY = 'product-rating-test-key';

beforeEach(function () {
    config(['compat.api_key' => RATING_KEY]);
});

/** A visible product on storefront 1 that exists ONLY in the new catalogue (not in legacy `products`). */
function ratableProduct(): int
{
    $id = T::int(DB::table('catalog_products as p')->join('storefront_product as sp', function (JoinClause $j): void {
        $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
    })->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->whereNotExists(fn (Builder $q) => $q->from('products as l')->whereColumn('l.id', 'p.id'))
        ->orderBy('p.id')->value('p.id'));
    DB::table('product_ratings')->where('product_id', $id)->delete();

    return $id;
}

/**
 * @param  array<string, int|string>  $query
 * @return TestResponse<Response>
 */
function ratePost(array $query, ?string $token): TestResponse
{
    $headers = ['Api-Code' => RATING_KEY];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return withHeaders($headers)->postJson('/api/add_product_rating?'.http_build_query($query));
}

it('refuses a rating without a sign-in', function () {
    ratePost(['product_id' => ratableProduct(), 'rating' => 5, 'comment' => 'x'], null)->assertUnauthorized();
    expect(DB::table('product_ratings')->count())->toBe(0);
});

it('saves a signed-in customer\'s rating of a dashboard-only product, as the form sends it, with no purchase needed', function () {
    [$user, $token] = Shopper::withToken();
    $product = ratableProduct();
    $cache = app(StorefrontCache::class);
    $version = $cache->version(1);
    // The product's cached detail and card, as a page view would have left them.
    $cardKey = $cache->key(1, 'compat_card', config()->string('compat.pinned_locale').':'.$product);
    $detailKey = $cache->key(1, 'product', (string) $product);
    Cache::put($cardKey, ['stale' => true], 600);
    Cache::put($detailKey, ['stale' => true], 600);

    ratePost(['product_id' => $product, 'rating' => 4, 'comment' => '  Lovely watch  ', 'user_id' => 1], $token)
        ->assertOk()->assertJson(['success' => true, 'message' => 'Rating added successfully', 'status' => 'created']);

    $row = T::row(DB::table('product_ratings')->where('product_id', $product)->first());
    expect(T::int($row->user_id))->toBe(T::int($user->getAttribute('id')))     // the token's customer, not the form's user_id
        ->and(T::int($row->rating))->toBe(4)
        ->and(T::str($row->comment))->toBe('Lovely watch');

    $p = T::row(DB::table('catalog_products')->where('id', $product)->first(['rating_avg', 'rating_count']));
    // K6 (developer, 2026-09-30 — this test used to demand a storefront-wide flush): only THIS product's
    // card and detail are forgotten; the storefront's catalogue cache is left alone.
    expect(T::str($p->rating_avg))->toBe('4.00')->and(T::int($p->rating_count))->toBe(1)
        ->and(Cache::has($cardKey))->toBeFalse()
        ->and(Cache::has($detailKey))->toBeFalse()
        ->and($cache->version(1))->toBe($version);

    $read = array_values(array_filter(T::arr(withHeaders(['Api-Code' => RATING_KEY])->getJson('/api/all_product_rating')->assertOk()->json()),
        fn (mixed $r): bool => T::arr($r)['product_id'] === $product));
    expect($read)->toHaveCount(1)->and(T::arr($read[0])['rating'])->toBe(4);
});

it('makes the browser revalidate the ratings list, so a shopper sees their own review on the next page', function () {
    $first = withHeaders(['Api-Code' => RATING_KEY])->get('/api/all_product_rating')->assertOk();
    $cc = (string) $first->headers->get('Cache-Control');
    expect($cc)->toContain('no-cache', 'private')          // variadic: every needle must be present
        ->and(str_contains($cc, 'max-age=600'))->toBeFalse();

    $etag = (string) $first->headers->get('ETag');
    withHeaders(['Api-Code' => RATING_KEY, 'If-None-Match' => $etag])->get('/api/all_product_rating')->assertStatus(304);

    [, $token] = Shopper::withToken();
    ratePost(['product_id' => ratableProduct(), 'rating' => 3], $token)->assertOk();
    withHeaders(['Api-Code' => RATING_KEY, 'If-None-Match' => $etag])->get('/api/all_product_rating')->assertOk();
});

it('replaces the first rating with the second — one per customer per product — and averages across customers', function () {
    [, $first] = Shopper::withToken();
    [, $second] = Shopper::withToken();
    $product = ratableProduct();

    ratePost(['product_id' => $product, 'rating' => 2, 'comment' => 'meh'], $first)->assertOk()->assertJson(['status' => 'created']);
    $id = DB::table('product_ratings')->where('product_id', $product)->value('id');
    ratePost(['product_id' => $product, 'rating' => 5, 'comment' => 'grew on me'], $first)->assertOk()->assertJson(['status' => 'replaced']);
    ratePost(['product_id' => $product, 'rating' => 4], $second)->assertOk();

    $rows = DB::table('product_ratings')->where('product_id', $product)->orderBy('id')->get(['id', 'rating', 'comment']);
    expect($rows)->toHaveCount(2)
        ->and(T::int(T::row($rows[0])->id))->toBe(T::int($id))
        ->and(T::int(T::row($rows[0])->rating))->toBe(5)
        ->and(T::str(T::row($rows[0])->comment))->toBe('grew on me')
        ->and(T::row($rows[1])->comment)->toBeNull()
        ->and(T::str(DB::table('catalog_products')->where('id', $product)->value('rating_avg')))->toBe('4.50')
        ->and(T::int(DB::table('catalog_products')->where('id', $product)->value('rating_count')))->toBe(2);
});

it('refuses a rating outside 1–5, a product this shop does not show, and a sixth try in a minute', function () {
    [, $token] = Shopper::withToken();
    $product = ratableProduct();

    ratePost(['product_id' => $product, 'rating' => 0], $token)->assertStatus(422);
    ratePost(['product_id' => $product, 'rating' => 6], $token)->assertStatus(422);
    ratePost(['product_id' => $product], $token)->assertStatus(422);
    ratePost(['product_id' => 999_999_999, 'rating' => 3], $token)->assertNotFound()->assertJson(['success' => false]);

    $hidden = T::int(DB::table('catalog_products as p')->whereNotExists(fn (Builder $q) => $q->from('storefront_product as sp')
        ->whereColumn('sp.product_id', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))->min('p.id'));
    ratePost(['product_id' => $hidden, 'rating' => 3], $token)->assertNotFound();

    expect(DB::table('product_ratings')->whereIn('product_id', [$product, $hidden])->count())->toBe(0);
    ratePost(['product_id' => $product, 'rating' => 3], $token)->assertStatus(429);
});
