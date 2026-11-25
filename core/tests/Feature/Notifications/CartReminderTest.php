<?php

use App\Domain\Campaigns\CampaignWriter;
use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\CartReminders;
use App\Domain\Notifications\MarketingLinks;
use App\Domain\Notifications\ReEngagement;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PersonalCodes;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Mail\CartReminderMail;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\PromotionFixture;
use Tests\Support\Shops;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

/*
 * THE ABANDONED-CART E-MAIL (Michael, 2026-11-24, plan item 1; D1, D3-D5). Every rule: the timing, each exclusion, one per
 * cart and one per address in 30 days, the code bound to its address and single use, the one-offer rule, the one-click
 * unsubscribe, and the two shop routes. No real mail: phpunit's mailer is `array`, and Mail is faked.
 */

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    StorefrontMailboxFixture::ensure();
    StorefrontMailboxFixture::budget(1, 1000, 200);
    PromotionFixture::moneyRewards(true, 1);
    foreach (['core_cart_reminders', 'core_checkout_contacts', 'core_cart_reminder_settings', 'core_marketing_optouts', 'core_mail_daily'] as $t) {
        DB::table($t)->delete();
    }
    DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->delete();
    CartReminders::saveSettings(1, ['enabled' => true, 'delay_hours' => 48, 'with_code' => true, 'percent' => 10.0, 'cap' => 500.0, 'valid_days' => 7], null);
});

/**
 * A visible product on shop 1, priced by the test.
 *
 * @return array{id: int, price: float}
 */
function crProduct(int $nth = 0, float $price = 3000.0): array
{
    $id = T::int(DB::table('catalog_products as p')->join('storefront_product as sp', function (JoinClause $j): void {
        $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
    })->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')->orderBy('p.id')->skip($nth)->value('p.id'));
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['effective_price' => number_format($price, 2, '.', ''), 'effective_sale_price' => null]);

    return ['id' => $id, 'price' => $price];
}

/** A guest cart on shop 1 with one product, idle since $hoursAgo, and the guest's checkout e-mail. Returns the cart id. */
function crGuestCart(string $email, int $hoursAgo, int $nth = 0): int
{
    $at = now()->subHours($hoursAgo);
    $id = (int) DB::table('carts')->insertGetId(['user_id' => null, 'guest_token' => (string) Str::uuid(), 'storefront_id' => 1, 'created_at' => $at, 'updated_at' => $at, 'expires_at' => $at->copy()->addDays(7)]);
    $p = crProduct($nth);
    DB::table('cart_items')->insert(['cart_id' => $id, 'product_id' => $p['id'], 'quantity' => 1, 'piece_price' => $p['price'], 'total_price' => $p['price'], 'type_stock' => 'Express', 'created_at' => $at, 'updated_at' => $at]);
    DB::table('core_checkout_contacts')->insert(['storefront_id' => 1, 'cart_id' => $id, 'email' => $email, 'created_at' => $at, 'updated_at' => $at]);

    return $id;
}

function crEmail(): string
{
    return 'cart-'.Str::lower(Str::random(8)).'@example.test';
}

/** @return list<int> the cart ids due now on shop 1 */
function crDue(): array
{
    return array_map(fn (array $c): int => $c['cart_id'], CartReminders::due(1));
}

it('is due only after the delay and for 7 days after it - never a cart left weeks ago, never on a shop switched off', function () {
    $fresh = crGuestCart(crEmail(), 10);
    $due = crGuestCart(crEmail(), 50);
    $late = crGuestCart(crEmail(), 48 + 24 * 7 - 1);
    $old = crGuestCart(crEmail(), 48 + 24 * 8);
    $ids = crDue();
    expect($ids)->toContain($due, $late)
        ->and(in_array($fresh, $ids, true))->toBeFalse()
        ->and(in_array($old, $ids, true))->toBeFalse();
    CartReminders::saveSettings(1, ['enabled' => false] + CartReminders::settings(1), null);
    expect(CartReminders::run(app(BulkMailer::class)))->toBe(['queued' => 0, 'skipped' => 0]);
});

it('never e-mails a cart whose owner ordered since, an empty cart, an unsubscribed address - and says why on its row', function () {
    $ordered = crGuestCart($a = crEmail(), 60);
    DB::table('orders')->insert(['storefront_id' => 1, 'user_id' => null, 'guest_email' => strtoupper($a), 'guest_name' => 'X', 'status' => 'processing',
        'total_price_for_order' => '100.00', 'payment_method' => 'cash', 'order_number' => 'T'.random_int(100000, 999999), 'created_at' => now()->subHours(20), 'updated_at' => now()]);
    $empty = crGuestCart(crEmail(), 60);
    DB::table('cart_items')->where('cart_id', $empty)->delete();
    $gone = crGuestCart($g = crEmail(), 60);
    ReEngagement::unsubscribe(1, $g);
    $good = crGuestCart(crEmail(), 60);

    $out = CartReminders::run(app(BulkMailer::class));
    $why = DB::table('core_cart_reminders')->pluck('skip_reason', 'cart_id')->all();
    expect($why[$ordered])->toBe('ordered_since')->and($why[$gone])->toBe('unsubscribed')->and($why[$good] ?? null)->toBeNull()
        ->and(isset($why[$empty]))->toBeFalse()                       // a cart with no line is never even due
        ->and($out['queued'])->toBeGreaterThanOrEqual(1);
});

it('sends one e-mail per cart and one per address in 30 days, with its own code, the cart, and an honest line about offers', function () {
    $email = crEmail();
    $first = crGuestCart($email, 60, 0);
    $second = crGuestCart($email, 55, 1);
    CartReminders::run(app(BulkMailer::class));
    CartReminders::run(app(BulkMailer::class));                       // a second run changes nothing
    $rows = DB::table('core_cart_reminders')->whereIn('cart_id', [$first, $second])->orderBy('cart_id')->get(['cart_id', 'status', 'skip_reason'])->map(fn ($r): array => (array) $r)->all();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['status'])->toBe('queued')
        ->and($rows[1]['skip_reason'])->toBe('reminded_in_30_days');

    app(BulkMailer::class)->drain(50);
    Mail::assertSent(CartReminderMail::class, function (CartReminderMail $m) use ($email): bool {
        $html = $m->render();

        return $m->hasTo($email)
            && str_contains($html, 'You left something in your cart')
            && str_contains($html, 'Not valid on items already on offer')
            && preg_match('/CART-[A-Z2-9]{4}-[A-Z2-9]{4}/', $html) === 1
            && str_contains($html, '/unsubscribe/');
    });
    expect(DB::table('core_cart_reminders')->where('cart_id', $first)->value('status'))->toBe('sent');
    $code = DB::table('core_personal_codes')->where('email', $email)->get();
    expect($code)->toHaveCount(1);
});

it('checks again at send time: an order placed after planning stops the e-mail', function () {
    $cart = crGuestCart($email = crEmail(), 60);
    CartReminders::run(app(BulkMailer::class));
    DB::table('orders')->insert(['storefront_id' => 1, 'user_id' => null, 'guest_email' => $email, 'guest_name' => 'X', 'status' => 'processing',
        'total_price_for_order' => '100.00', 'payment_method' => 'cash', 'order_number' => 'T'.random_int(100000, 999999), 'created_at' => now(), 'updated_at' => now()]);
    app(BulkMailer::class)->drain(50);
    Mail::assertNotSent(CartReminderMail::class);
    expect(DB::table('core_cart_reminders')->where('cart_id', $cart)->value('skip_reason'))->toBe('ordered_since');
});

it('gives a code that works only for its address, once, and never with an item on offer', function () {
    $email = crEmail();
    $issued = PersonalCodes::issue(1, $email, 'cart_reminder', null, 10.0, 500.0, 7);
    $other = PersonalCodes::issue(1, crEmail(), 'cart_reminder', null, 10.0, 500.0, 7);
    expect($issued['code'])->not->toBe($other['code'])->and($issued['rule_id'])->toBe($other['rule_id']);   // one rule, two codes
    $p = crProduct(0, 3000.0);
    $cart = fn (?string $mail): CartSnapshot => new CartSnapshot(storefrontId: 1, lines: [new CartLine(productId: $p['id'], variantId: null, offerId: null, quantity: 1, unitPrice: 3000.0, typeStock: 'Express')],
        subtotal: 3000.0, shippingCost: 0.0, paymentMethod: 'cash', code: $issued['code'], customerKeys: PromotionDiscounts::customerKeys(null, $mail, null));
    $engine = app(PromotionEngine::class);
    $mine = $engine->evaluate($cart($email));
    expect($mine->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)->and($mine->discount)->toBe(300.0);
    // before the checkout form a guest's cart names no address yet: not refused there, checked at the order
    expect($engine->evaluate($cart(null))->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
    expect($engine->evaluate($cart('someone-else@example.test'))->coupon['status'] ?? null)->toBe(CouponStatus::EMAIL_MISMATCH);
    // used by an order: single use
    PersonalCodes::markUsed($issued['code'], 999999001, 1);
    expect($engine->evaluate($cart($email))->coupon['status'] ?? null)->toBe(CouponStatus::WHEEL_USED);
    // expired
    DB::table('core_personal_codes')->where('id', $other['id'])->update(['expires_at' => now()->subMinute()]);
    $expired = new CartSnapshot(storefrontId: 1, lines: [new CartLine(productId: $p['id'], variantId: null, offerId: null, quantity: 1, unitPrice: 3000.0, typeStock: 'Express')],
        subtotal: 3000.0, shippingCost: 0.0, paymentMethod: 'cash', code: $other['code'], customerKeys: []);
    expect($engine->evaluate($expired)->coupon['status'] ?? null)->toBe(CouponStatus::EXPIRED);
});

it('is refused on a cart holding an item on a live campaign: one offer per order', function () {
    $email = crEmail();
    $issued = PersonalCodes::issue(1, $email, 'campaign', null, 10.0, null, 7);
    $p = crProduct(3, 3000.0);
    DB::table('storefront_campaigns')->delete();
    CampaignWriter::create(1, [
        'name' => 'Hot deals test', 'presentation' => 'hot_deals',
        'starts_at' => now()->subHour()->format('Y-m-d H:i:00'), 'ends_at' => now()->addDay()->format('Y-m-d H:i:00'),
        'is_active' => true, 'product_ids' => [$p['id']], 'prices' => [T::int($p['id']) => ['mode' => 'percent', 'value' => 10.0]], 'confirm_prices' => true,
    ], null);
    $cart = new CartSnapshot(storefrontId: 1, lines: [new CartLine(productId: $p['id'], variantId: null, offerId: null, quantity: 1, unitPrice: 2700.0, typeStock: 'Express')],
        subtotal: 2700.0, shippingCost: 0.0, paymentMethod: 'cash', code: $issued['code'], customerKeys: PromotionDiscounts::customerKeys(null, $email, null));
    expect(app(PromotionEngine::class)->evaluate($cart)->coupon['status'] ?? null)->toBe(CouponStatus::ON_OFFER);
});

it('unsubscribes in one click: the page submits itself, the POST records it, and the next run skips the address', function () {
    $email = crEmail();
    $url = MarketingLinks::unsubscribeUrl(1, $email, CartReminders::KIND);
    $path = (string) parse_url($url, PHP_URL_PATH);
    expect($path)->toMatch('#^/unsubscribe/\d+/[a-f0-9]{40}$#');      // the API host's allow-listed shape
    $page = get($path)->assertOk()->getContent();
    expect((string) $page)->toContain('<script>document.getElementById(\'unsubscribe\').submit();</script>')->toContain('Unsubscribe');
    expect(ReEngagement::optedOut(1, $email))->toBeFalse();            // opening the link alone changes nothing
    post($path)->assertOk()->assertSee('unsubscribed', false);
    expect(ReEngagement::optedOut(1, $email))->toBeTrue();
    crGuestCart($email, 60);
    CartReminders::run(app(BulkMailer::class));
    expect(DB::table('core_cart_reminders')->where('email', $email)->value('skip_reason'))->toBe('unsubscribed');
    // a link with another link's signature is a 404
    get(preg_replace('#/[a-f0-9]{40}$#', '/'.str_repeat('a', 40), $path) ?? '')->assertNotFound();
});

it('keeps a guest\'s checkout e-mail on its cart, and restores the cart from the e-mail\'s signed link', function () {
    $api = Shops::api('watchizer');
    $token = (string) Str::uuid();
    $cart = (int) DB::table('carts')->insertGetId(['user_id' => null, 'guest_token' => $token, 'storefront_id' => 1, 'created_at' => now()->subHours(60), 'updated_at' => now()->subHours(60)]);
    postJson($api.'/api/v2/watchizer/checkout/contact', ['email' => 'Guest.Typed@Example.test'], ['X-Guest-Token' => $token])->assertNoContent();
    expect(DB::table('core_checkout_contacts')->where('cart_id', $cart)->value('email'))->toBe('guest.typed@example.test');
    // a bad address or no cart: the same 204, nothing written
    postJson($api.'/api/v2/watchizer/checkout/contact', ['email' => 'not-an-email'], ['X-Guest-Token' => $token])->assertNoContent();
    postJson($api.'/api/v2/watchizer/checkout/contact', ['email' => 'x@example.test'], ['X-Guest-Token' => (string) Str::uuid()])->assertNoContent();
    expect(DB::table('core_checkout_contacts')->where('cart_id', $cart)->value('email'))->toBe('guest.typed@example.test');

    $reminder = (int) DB::table('core_cart_reminders')->insertGetId(['storefront_id' => 1, 'cart_id' => $cart, 'email' => 'guest.typed@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
    postJson($api.'/api/v2/watchizer/cart/restore', ['r' => $reminder, 's' => CartReminders::restoreSignature($reminder)])->assertOk()->assertJsonPath('data.guest_token', $token);
    postJson($api.'/api/v2/watchizer/cart/restore', ['r' => $reminder, 's' => str_repeat('0', 40)])->assertNotFound();
    // older than 14 days, or on the other shop: no
    DB::table('core_cart_reminders')->where('id', $reminder)->update(['created_at' => now()->subDays(15)]);
    postJson($api.'/api/v2/watchizer/cart/restore', ['r' => $reminder, 's' => CartReminders::restoreSignature($reminder)])->assertNotFound();
});
