<?php

use App\Domain\Media\MediaAudit;
use App\Domain\Reviews\Reviews;
use App\Storefront\StorefrontCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * VERIFIED REVIEWS (SEO Phase 5, 2026-11-14): a delivered order gets a review link; its customer reviews each product of
 * the order; nothing shows until the team approves it; only approved reviews reach the product page.
 */
beforeEach(function () {
    DB::table('product_reviews')->delete();
    DB::table('review_requests')->delete();
    // go-live a month ago: the orders below are placed after it
    config(['reviews.requests_enabled' => false, 'reviews.requests_from' => now()->subDays(30)->utc()->toIso8601String()]);
});

/**
 * A delivered guest order on a storefront, delivered `$daysAgo` days ago, of one product.
 *
 * @return array{order: int, product: int, slug: string}
 */
function deliveredOrder(int $storefront, int $daysAgo = 4): array
{
    $product = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($product, true, $storefront);
    $address = DB::table('addresses')->insertGetId([
        'user_id' => null, 'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'address_line' => 'Review Street 1', 'phone_number_one' => '01000000000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = T::int(DB::table('orders')->insertGetId([
        'storefront_id' => $storefront, 'user_id' => null, 'guest_name' => 'Mona', 'guest_email' => 'mona@example.test', 'guest_phone' => '01011112222',
        'address_id' => $address, 'total_price_for_order' => '2000.00', 'payment_method' => 'cash', 'status' => 'delivered',
        'order_number' => '93'.random_int(10000, 99999), 'created_at' => now()->subDays($daysAgo + 2), 'updated_at' => now()->subDays($daysAgo),
    ]));
    DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'quantity' => 1, 'piece_price' => '2000.00', 'total_price' => '2000.00',
        'type_stock' => 'Express', 'is_reward' => 0, 'created_at' => now(), 'updated_at' => now()]);

    return ['order' => $order, 'product' => $product, 'slug' => T::str(DB::table('storefront_product')->where('product_id', $product)->where('storefront_id', $storefront)->value('slug'))];
}

it('asks for a review a few days after delivery, once, and e-mails only while switched on', function () {
    $o = deliveredOrder(1, 4);
    $recent = deliveredOrder(1, 1);                      // too soon
    Artisan::call('reviews:request');
    expect(DB::table('review_requests')->where('order_id', $o['order'])->count())->toBe(1)
        ->and(DB::table('review_requests')->where('order_id', $recent['order'])->count())->toBe(0)
        ->and(DB::table('review_requests')->where('order_id', $o['order'])->value('sent_at'))->toBeNull();   // switched off

    config(['reviews.requests_enabled' => true, 'mail.default' => 'array']);
    Artisan::call('reviews:request');
    Artisan::call('reviews:request');                   // once
    expect(DB::table('review_requests')->where('order_id', $o['order'])->count())->toBe(1)
        ->and(DB::table('review_requests')->where('order_id', $o['order'])->value('sent_at'))->not->toBeNull();
});

it('never asks for an order placed before go-live, nor any order while go-live is not recorded', function () {
    $old = deliveredOrder(1, 4);
    config(['reviews.requests_from' => now()->utc()->toIso8601String()]);       // go-live = now: the order is older
    Artisan::call('reviews:request');
    expect(DB::table('review_requests')->where('order_id', $old['order'])->count())->toBe(0);
    config(['reviews.requests_from' => '']);
    expect(Reviews::requestDue(false)['created'])->toBe(0);
});

// Changed deliberately (Michael, 2026-11-17): a review is LIVE at once; held only for a link or a phone number.
it("takes a review only through a link of the path's own shop, once per product, live at once and a verified buyer", function () {
    $o = deliveredOrder(1);
    Reviews::requestDue(false);
    $requestId = T::int(DB::table('review_requests')->where('order_id', $o['order'])->value('id'));
    $token = T::str(Reviews::reissue(1, $requestId));

    getJson("/api/v2/brandfashion/reviews/request/{$token}")->assertNotFound();            // another shop's link
    $products = T::arr(getJson("/api/v2/watchizer/reviews/request/{$token}")->assertOk()->json('data.products'));
    expect(array_column($products, 'id'))->toBe([$o['product']]);

    $body = ['token' => $token, 'product_id' => $o['product'], 'rating' => 5, 'body' => 'Beautiful watch, exactly as in the photos.', 'name' => 'Mona A.', 'locale' => 'en'];
    postJson('/api/v2/brandfashion/reviews', $body)->assertNotFound();
    expect(DB::table('product_reviews')->count())->toBe(0);
    post('/api/v2/watchizer/reviews', $body + ['photos' => [UploadedFile::fake()->image('wrist.jpg', 800, 800)]], ['Accept' => 'application/json'])->assertCreated();
    postJson('/api/v2/watchizer/reviews', $body)->assertStatus(409);

    $row = T::row(DB::table('product_reviews')->where('order_id', $o['order'])->first());
    expect($row->status)->toBe('approved')
        ->and(T::int($row->verified))->toBe(1)
        ->and($row->source)->toBe('request')
        ->and(T::int($row->storefront_id))->toBe(1)
        ->and(count(T::arr(json_decode(T::str($row->photos), true))))->toBe(1);
    // the photo is a REFERENCE to the media prune: `Review` is a scanned folder, so an unlisted photo would be deleted
    $photo = basename(T::str(T::arr(json_decode(T::str($row->photos), true))[0]));
    expect(app(MediaAudit::class)->referencedFiles())->toHaveKey($photo);
    // live on the product page at once, marked a verified buyer
    $reviews = T::arr(getJson("/api/v2/watchizer/products/{$o['slug']}")->assertOk()->json('product.reviews'));
    expect($reviews['count'])->toBe(1)
        ->and(T::arr(T::arr($reviews['items'])[0])['verified'])->toBeTrue();
});

it('holds a review with a link for the team, shows it once approved (logged), and never a hidden one', function () {
    $o = deliveredOrder(1);
    Reviews::requestDue(false);
    $token = T::str(Reviews::reissue(1, T::int(DB::table('review_requests')->where('order_id', $o['order'])->value('id'))));
    // a link in the words holds it (Michael, 2026-11-17): the team decides
    expect(Reviews::submit(1, $token, $o['product'], 4, 'Good watch, see more at www.example.com', 'Ahmed', 'ar'))->toBe('held');
    expect(T::arr(getJson("/api/v2/watchizer/products/{$o['slug']}")->assertOk()->json('product.reviews'))['count'])->toBe(0);
    $id = T::int(DB::table('product_reviews')->value('id'));

    actingAs(Staff::admin())->get('/manage/storefronts/1/reviews')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Manage/Reviews/Index')->where('table.data.0.id', $id));
    actingAs(Staff::admin())->put("/manage/storefronts/1/reviews/{$id}", ['status' => 'approved'])->assertSessionHasNoErrors();
    expect(DB::table('core_activity_log')->where('subject_type', 'product_reviews')->where('subject_id', $id)->count())->toBe(1);

    $reviews = T::arr(getJson("/api/v2/watchizer/products/{$o['slug']}")->assertOk()->json('product.reviews'));
    expect($reviews['count'])->toBe(1)->and($reviews['average'])->toEqual(4.0)
        ->and(T::arr(T::arr($reviews['items'])[0])['name'])->toBe('Ahmed');

    actingAs(Staff::admin())->put("/manage/storefronts/1/reviews/{$id}", ['status' => 'rejected']);
    expect(T::arr(getJson("/api/v2/watchizer/products/{$o['slug']}")->json('product.reviews'))['count'])->toBe(0);
});

it('gives the team a fresh WhatsApp link for a request, with the customer number in international form', function () {
    $o = deliveredOrder(1);
    Reviews::requestDue(false);
    $requestId = T::int(DB::table('review_requests')->where('order_id', $o['order'])->value('id'));
    $res = actingAs(Staff::admin())->postJson("/manage/storefronts/1/reviews/requests/{$requestId}/whatsapp")->assertOk();
    expect(T::str($res->json('url')))->toContain('/review/')
        ->and(T::str($res->json('whatsapp')))->toStartWith('https://wa.me/201011112222?text=');
    // a customer account has no dashboard at all
    actingAs(Staff::customer())->postJson("/manage/storefronts/1/reviews/requests/{$requestId}/whatsapp")->assertForbidden();
});
