<?php

use App\Mail\ReviewRequestMail;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutMiddleware;

/*
 * ── Reviews, one page for every shop (Michael, 2026-11-19, part 3 of the three fixes) ─────────────────────────────────
 *
 * /manage/reviews lists the reviews of every shop the user may manage, with a Shop filter (All or one shop) and a Shop
 * column; quick filters for held, stars and the last 7 days; the product linked on its OWN shop. The rights are the
 * old ones, per shop: a user scoped to one shop sees, moderates and previews only that shop. The old per-shop address
 * redirects here. The E-mail tab previews the real review e-mail and sends a test to the signed-in user only.
 */
beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    DB::table('product_reviews')->delete();
});

function hubReview(int $storefront, int $product, int $rating, string $status = 'approved', int $daysAgo = 0, string $body = 'A review for the hub test.'): int
{
    return T::int(DB::table('product_reviews')->insertGetId([
        'storefront_id' => $storefront, 'product_id' => $product, 'order_id' => null, 'request_id' => null, 'source' => 'guest',
        'email_hash' => hash('sha256', uniqid('hub', true)), 'verified' => false, 'author_name' => 'Hub Tester', 'rating' => $rating,
        'body' => $body, 'photos' => null, 'locale' => 'en', 'status' => $status,
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
    ]));
}

/** @return array<int, array<mixed>> the page's rows by id */
function hubRows(string $query = ''): array
{
    $rows = [];
    get('/manage/reviews'.$query)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$rows) {
        $page->component('Manage/Reviews/Index');
        foreach (T::arr(T::arr(T::arr($page->toArray()['props'])['table'])['data']) as $row) {
            $row = T::arr($row);
            $rows[T::int($row['id'])] = $row;
        }
    });

    return $rows;
}

it('lists every shop the user manages, with a Shop column, and the shop filter narrows to one', function () {
    $p = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($p, true, 1);
    CatalogFixture::onStorefront($p, true, 2);
    $wz = hubReview(1, $p, 5);
    $bf = hubReview(2, $p, 4);
    actingAs(Staff::admin());

    $all = hubRows();
    expect(array_keys($all))->toContain($wz, $bf)
        ->and($all[$wz]['shop'])->toBe('Watchizer')->and($all[$bf]['storefront_id'])->toBe(2);
    // the product on its OWN shop: each review's link names its shop's address and the product's slug there
    expect(T::str($all[$wz]['product_url']))->toEndWith('/product/'.T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $p)->value('slug')))
        ->and(T::str($all[$bf]['product_url']))->toEndWith('/product/'.T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $p)->value('slug')))
        ->and($all[$wz]['product_url'])->not->toBe($all[$bf]['product_url']);

    expect(array_keys(hubRows('?filters[shop]=2')))->toBe([$bf]);
    expect(array_keys(hubRows('?filters[shop]=1')))->toBe([$wz]);
});

it('filters held, live and hidden, by stars, and the last 7 days', function () {
    $p = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($p, true, 1);
    $held = hubReview(1, $p, 3, 'pending');
    $live = hubReview(1, $p, 5, 'approved');
    $hidden = hubReview(1, $p, 1, 'rejected');
    $old = hubReview(1, $p, 5, 'approved', 30);
    actingAs(Staff::admin());

    expect(array_keys(hubRows('?filters[status]=held')))->toBe([$held])
        ->and(array_keys(hubRows('?filters[status]=hidden')))->toBe([$hidden])
        ->and(array_keys(hubRows('?filters[status]=live')))->toEqualCanonicalizing([$live, $old])
        ->and(array_keys(hubRows('?filters[stars]=5')))->toEqualCanonicalizing([$live, $old])
        ->and(array_keys(hubRows('?filters[stars]=5&filters[recent]=7')))->toBe([$live])
        ->and(array_keys(hubRows('?filters[recent]=7')))->toEqualCanonicalizing([$held, $live, $hidden]);
    // the quick filters' counts
    get('/manage/reviews?filters[shop]=1')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts.held', 1)->where('counts.live', 2)->where('counts.hidden', 1));
});

it('keeps the rights per shop: a user of one shop sees, moderates and previews only that shop', function () {
    $p = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($p, true, 1);
    CatalogFixture::onStorefront($p, true, 2);
    $wz = hubReview(1, $p, 5, 'pending');
    $bf = hubReview(2, $p, 5, 'pending');
    actingAs(Staff::dataEntryFor(1));

    get('/manage/reviews')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('shops', 1)->where('shops.0.id', 1));
    expect(array_keys(hubRows()))->toBe([$wz]);
    // naming the other shop in the filter is dropped, never obeyed
    expect(array_keys(hubRows('?filters[shop]=2')))->toBe([$wz]);
    // moderation of the other shop's review: refused, unchanged
    put("/manage/storefronts/2/reviews/{$bf}", ['status' => 'approved'])->assertStatus(404);
    expect(DB::table('product_reviews')->where('id', $bf)->value('status'))->toBe('pending');
    put("/manage/storefronts/1/reviews/{$wz}", ['status' => 'approved'])->assertSessionHasNoErrors();
    expect(DB::table('product_reviews')->where('id', $wz)->value('status'))->toBe('approved');
    get('/manage/reviews/email-preview?shop=2')->assertForbidden();
    get('/manage/reviews/email-preview?shop=1')->assertOk();
});

it('sends the old per-shop address to the one page, filtered on that shop', function () {
    actingAs(Staff::admin());
    get('/manage/storefronts/2/reviews')->assertRedirect('/manage/reviews?'.http_build_query(['filters' => ['shop' => 2]]));
});

it('previews the real review e-mail of a shop, in both languages, with an example link', function () {
    actingAs(Staff::admin());
    $html = get('/manage/reviews/email-preview?shop=1')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();
    expect($html)->toContain('كيف وجدت طلبك؟')->toContain('How was your order?')->toContain('/review/example');
});

it('sends a test of the review e-mail to the signed-in user only, and creates no review request', function () {
    Mail::fake();
    $admin = Staff::admin();
    actingAs($admin);
    $requests = DB::table('review_requests')->count();
    post('/manage/reviews/email-test', ['shop' => 2])->assertSessionHasNoErrors();
    Mail::assertSent(ReviewRequestMail::class, fn (ReviewRequestMail $m) => $m->hasTo(T::str($admin->getAttribute('email'))) && count($m->to) === 1);
    Mail::assertSentCount(1);
    expect(DB::table('review_requests')->count())->toBe($requests);
    // a shop the user does not manage: refused, nothing sent
    actingAs(Staff::dataEntryFor(1));
    post('/manage/reviews/email-test', ['shop' => 2])->assertForbidden();
    Mail::assertSentCount(1);
});

it('lists every branch of the shops with its shop, its address and its logo for the QR card', function () {
    actingAs(Staff::admin());
    get('/manage/reviews')->assertOk()->assertInertia(function (AssertableInertia $page) {
        $branches = T::arr(T::arr($page->toArray()['props'])['branches']);
        foreach ($branches as $b) {
            $b = T::arr($b);
            expect($b)->toHaveKeys(['id', 'storefront_id', 'shop', 'shop_code', 'logo', 'review_link', 'scans_30_days', 'scans_total'])
                ->and(T::str($b['logo']))->toStartWith('/assets/brand/');
            if ($b['review_link'] !== null) {
                expect(T::str($b['review_link']))->toContain('/review/');
            }
        }
        $page->has('email.to')->has('email.after_days');
    });
});
