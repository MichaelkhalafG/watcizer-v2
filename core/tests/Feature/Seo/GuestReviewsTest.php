<?php

use App\Console\Commands\ReviewBranchesCommand;
use App\Domain\Reviews\GuestReviews;
use App\Domain\Reviews\Reviews;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CatalogFixture;
use Tests\Support\SideDdl;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * REVIEWS FROM EVERYONE (Michael's decisions, 2026-11-17): a review from a product page without logging in — live at
 * once, protected in the background (Turnstile, limits per e-mail / device / address), held for a link or a phone
 * number only, attached silently to an account with that e-mail, a Verified buyer when that e-mail received the
 * product; the shop's Google review buttons follow; each branch's /review/<key> counts its scans and sends to Google.
 */
beforeEach(function () {
    DB::table('product_reviews')->delete();
    config(['reviews.turnstile.site_key' => '', 'reviews.turnstile.secret_key' => '']);
    RateLimiter::clear('turnstile-off-log');
});

/**
 * A product visible on Watchizer; returns its id and slug.
 *
 * @return array{id: int, slug: string}
 */
function guestProduct(): array
{
    $id = CatalogFixture::product('watch', 2500.0);
    CatalogFixture::onStorefront($id, true, 1);
    app(StorefrontCache::class)->flush(1);

    return ['id' => $id, 'slug' => T::str(DB::table('storefront_product')->where('product_id', $id)->where('storefront_id', 1)->value('slug'))];
}

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function guestBody(int $productId, array $over = []): array
{
    /** @var array<string, mixed> */
    return array_merge([
        'product_id' => $productId, 'name' => 'Salma', 'email' => 'salma.'.bin2hex(random_bytes(3)).'@example.test', 'rating' => 1,
        'body' => 'The strap broke after a week, disappointed.', 'locale' => 'en', 'turnstile' => '', 'device' => 'dev-'.bin2hex(random_bytes(4)),
    ], $over);
}

it('publishes a guest review at once — a one-star review too — and shows it on the product page', function () {
    $p = guestProduct();
    $res = postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id']))->assertCreated();
    expect($res->json('status'))->toBe('live');
    $row = T::row(DB::table('product_reviews')->where('product_id', $p['id'])->first());
    expect($row->status)->toBe('approved')->and($row->source)->toBe('guest')->and(T::int($row->rating))->toBe(1)
        ->and($row->held_reason)->toBeNull()->and(T::int($row->verified))->toBe(0)
        ->and(strlen(T::str($row->email_hash)))->toBe(64);
    $reviews = T::arr(getJson("/api/v2/watchizer/products/{$p['slug']}")->assertOk()->json('product.reviews'));
    expect($reviews['count'])->toBe(1);
});

it('writes a guest review under the storefront of its path, never the other shop', function () {
    // the tripwire in OrderStorefrontTest names this route only because of this proof
    $both = CatalogFixture::product('watch', 2500.0);
    CatalogFixture::onStorefront($both, true, 1);
    CatalogFixture::onStorefront($both, true, 2);
    $watchizerOnly = guestProduct();
    app(StorefrontCache::class)->flush(2);

    postJson('/api/v2/brandfashion/reviews/guest', guestBody($both))->assertCreated();
    expect(DB::table('product_reviews')->where('product_id', $both)->pluck('storefront_id')->map(fn ($v) => T::int($v))->all())->toBe([2]);

    postJson('/api/v2/brandfashion/reviews/guest', guestBody($watchizerOnly['id']))->assertNotFound();
    expect(DB::table('product_reviews')->where('product_id', $watchizerOnly['id'])->count())->toBe(0);
});

it('holds a review with a link or a phone number for the team, whatever its stars', function () {
    expect(GuestReviews::holdReason('great, see https://x.test'))->toBe(GuestReviews::HELD_LINK)
        ->and(GuestReviews::holdReason('message me on wa.me/2010'))->toBe(GuestReviews::HELD_LINK)
        ->and(GuestReviews::holdReason('call 0101 234 5678'))->toBe(GuestReviews::HELD_PHONE)
        ->and(GuestReviews::holdReason('كلمني على ٠١٠١٢٣٤٥٦٧٨'))->toBe(GuestReviews::HELD_PHONE)
        ->and(GuestReviews::holdReason('Model AX1580, 43 mm, price 4,500'))->toBeNull();
    $p = guestProduct();
    $res = postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['rating' => 5, 'body' => 'Perfect! Buy cheaper at www.other-shop.com']))->assertCreated();
    expect($res->json('status'))->toBe('held')
        ->and(DB::table('product_reviews')->where('product_id', $p['id'])->value('status'))->toBe('pending')
        ->and(DB::table('product_reviews')->where('product_id', $p['id'])->value('held_reason'))->toBe('link');
});

it('attaches a review to the account with that e-mail silently: the same answer with or without an account', function () {
    $p = guestProduct();
    $user = Staff::customer();
    $userId = T::int($user->getKey());
    $email = T::str(DB::table('users')->where('id', $userId)->value('email'));
    $with = postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['email' => strtoupper($email)]))->assertCreated();
    $without = postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id']))->assertCreated();
    expect($with->json())->toEqual($without->json())
        ->and(T::int(DB::table('product_reviews')->where('email_hash', hash('sha256', mb_strtolower($email)))->value('user_id')))->toBe($userId)
        ->and(auth()->check())->toBeFalse();
});

it('marks a Verified buyer when the e-mail has a delivered order of the product, and takes one review per e-mail per product', function () {
    $p = guestProduct();
    $order = T::int(DB::table('orders')->insertGetId([
        'storefront_id' => 1, 'user_id' => null, 'guest_name' => 'Hana', 'guest_email' => 'Hana@Example.test', 'guest_phone' => '01011110000',
        'address_id' => T::int(DB::table('addresses')->insertGetId(['user_id' => null, 'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
            'address_line' => 'Street 2', 'phone_number_one' => '01011110000', 'created_at' => now(), 'updated_at' => now()])),
        'total_price_for_order' => '2500.00', 'payment_method' => 'cash', 'status' => 'delivered', 'order_number' => '94'.random_int(10000, 99999),
        'created_at' => now(), 'updated_at' => now(),
    ]));
    DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $p['id'], 'quantity' => 1, 'piece_price' => '2500.00', 'total_price' => '2500.00',
        'type_stock' => 'Express', 'is_reward' => 0, 'created_at' => now(), 'updated_at' => now()]);

    postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['email' => 'hana@example.test', 'rating' => 5]))->assertCreated();
    expect(T::int(DB::table('product_reviews')->where('product_id', $p['id'])->value('verified')))->toBe(1);
    postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['email' => 'HANA@example.test']))->assertStatus(409);
    $items = T::arr(T::arr(getJson("/api/v2/watchizer/products/{$p['slug']}")->json('product.reviews'))['items']);
    expect(T::arr($items[0])['verified'])->toBeTrue();
});

it('limits reviews per e-mail and per device, and refuses a product that is not on the shop', function () {
    $p = guestProduct();
    $q = guestProduct();
    $r = guestProduct();
    $s = guestProduct();
    $email = 'busy@example.test';
    foreach ([$p, $q, $r] as $prod) {
        postJson('/api/v2/watchizer/reviews/guest', guestBody($prod['id'], ['email' => $email]))->assertCreated();
    }
    postJson('/api/v2/watchizer/reviews/guest', guestBody($s['id'], ['email' => $email]))->assertStatus(429);
    $hidden = CatalogFixture::product('watch', 900.0);
    postJson('/api/v2/watchizer/reviews/guest', guestBody($hidden))->assertNotFound();
});

it('checks Turnstile when its keys are set: a failed check is refused, an unreachable Cloudflare holds the review', function () {
    config(['reviews.turnstile.site_key' => 'site', 'reviews.turnstile.secret_key' => 'secret']);
    $p = guestProduct();
    // Cloudflare answers no, then is down (one sequence: a second Http::fake would not replace the first)
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false], 200)->push('down', 503)]);
    postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['turnstile' => 'bad']))->assertStatus(422);
    expect(postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['turnstile' => 'tok']))->assertCreated()->json('status'))->toBe('held');
    expect(DB::table('product_reviews')->where('product_id', $p['id'])->value('held_reason'))->toBe('unverified');
    // the product page hands the PUBLIC site key to the form
    $form = T::arr(getJson("/api/v2/watchizer/products/{$p['slug']}")->assertOk()->json('product.review_form'));
    expect($form['turnstile_site_key'])->toBe('site');
});

it("sets the branches' Google links by stable key, shows the buttons, and counts each branch's in-store scans", function () {
    SideDdl::run(ReviewBranchesCommand::backupDdl());     // else the command's CREATE commits this test's transaction
    Artisan::call('reviews:branches', ['--apply' => true]);
    $links = Reviews::googleLinks(2);
    expect(array_column($links, 'key'))->toBe(['arkadia', 'heliopolis'])
        ->and(T::arr($links[0])['url'])->toStartWith('https://search.google.com/local/writereview?placeid=ChIJ')
        ->and(count(Reviews::googleLinks(1)))->toBe(1);       // Watchizer: one button, Arkadia

    $store = T::int(DB::table('storefront_pickup_stores')->where('storefront_id', 2)->where('review_key', 'heliopolis')->value('id'));
    $before = T::int(DB::table('storefront_review_scans')->where('store_id', $store)->sum('scans'));
    expect(T::str(getJson('/api/v2/brandfashion/review-link/heliopolis')->assertOk()->json('data.url')))->toContain('ChIJAwftNAA_WBQRfmu-Av2YUwo');
    getJson('/api/v2/brandfashion/review-link/heliopolis?count=0')->assertOk();
    expect(T::int(DB::table('storefront_review_scans')->where('store_id', $store)->sum('scans')))->toBe($before + 1);
    getJson('/api/v2/watchizer/review-link/heliopolis')->assertNotFound();      // Watchizer's Heliopolis has no Google link

    // the thanks carry the buttons
    $p = guestProduct();
    expect(postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id']))->json('google'))->toHaveCount(1);
    expect(ReviewBranchesCommand::googleUrl('X'))->toBe('https://search.google.com/local/writereview?placeid=X');
});

it('lets the team hide any review and show a held one, and save the branches (logged); never edit the words', function () {
    $p = guestProduct();
    postJson('/api/v2/watchizer/reviews/guest', guestBody($p['id'], ['body' => 'Fine, call me 01012345678']))->assertCreated();
    $id = T::int(DB::table('product_reviews')->where('product_id', $p['id'])->value('id'));
    actingAs(Staff::admin())->put("/manage/storefronts/1/reviews/{$id}", ['status' => 'approved', 'body' => 'changed'])->assertSessionHasNoErrors();
    expect(DB::table('product_reviews')->where('id', $id)->value('status'))->toBe('approved')
        ->and(DB::table('product_reviews')->where('id', $id)->value('body'))->toBe('Fine, call me 01012345678');
    actingAs(Staff::admin())->put("/manage/storefronts/1/reviews/{$id}", ['status' => 'rejected']);
    expect(DB::table('product_reviews')->where('id', $id)->value('status'))->toBe('rejected');

    $branch = T::int(DB::table('storefront_pickup_stores')->where('storefront_id', 1)->orderBy('position')->value('id'));
    actingAs(Staff::admin())->put('/manage/storefronts/1/reviews-branches', ['branches' => [['id' => $branch, 'google_review_url' => 'http://evil.test/x', 'review_key' => 'arkadia']]])
        ->assertSessionHasErrors('branches.0.google_review_url');
    actingAs(Staff::admin())->put('/manage/storefronts/1/reviews-branches', ['branches' => [['id' => $branch, 'google_review_url' => 'https://g.page/r/abc/review', 'review_key' => 'arkadia', 'whatsapp_review_template' => 'Thanks {link}']]])
        ->assertSessionHasNoErrors();
    expect(DB::table('storefront_pickup_stores')->where('id', $branch)->value('google_review_url'))->toBe('https://g.page/r/abc/review')
        ->and(DB::table('core_activity_log')->where('subject_type', 'storefront_pickup_stores')->where('subject_id', $branch)->exists())->toBeTrue();
});
