<?php

use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelPrizeWriter;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

/*
 * THE WHEEL AUDIT (Michael, 2026-11-24): every prize option the dashboard offers must work end to end on the shop -
 * drawn on the disc with something to read, a code issued at the spin, applied in the cart, and checked again at order
 * time (Wheel::refusal, what the checkout asks). WheelTest covers the client's seven prizes (free shipping, the 2nd
 * watch, better luck, wallets, the invite, the gifts) and single use through the checkout; OfferBuilderTest the builder
 * offer. This file covers the options that were not: a percentage on the whole order with and without a cap, a scoped
 * percentage, the cheapest-item mode, a fixed amount - and the one option that could not work: a money prize on a shop
 * whose money rewards are off, which the dashboard now refuses.
 */

beforeEach(function () {
    Mail::fake();
    PromotionFixture::moneyRewards(true, 1);
    Wheel::setEnabled(1, true);
    Wheel::setTerms(1, Wheel::DEFAULT_TTL_HOURS, null);
});

/** A wheel of the subject prize (odds 97, so roll 1 draws it) and three "better luck" segments. @param array<string, mixed> $subject */
function waWheel(array $subject): void
{
    $luck = fn (string $c): array => ['label_en' => 'Better luck', 'label_ar' => 'حظًا أوفر', 'type' => 'none', 'value' => null, 'odds' => 1, 'color' => $c, 'cap' => null,
        'scope_category_id' => null, 'min_quantity' => null, 'apply_mode' => 'order', 'gift_product_ids' => []];
    WheelPrizeWriter::save(1, [
        $subject + ['odds' => 97, 'color' => '#1F4FD8', 'cap' => null, 'scope_category_id' => null, 'min_quantity' => null, 'apply_mode' => 'order', 'gift_product_ids' => []],
        $luck('#6B7280'), $luck('#6B7281'), $luck('#6B7282'),
    ], ['ttl_hours' => Wheel::DEFAULT_TTL_HOURS, 'min_order' => null], null);
    app(StorefrontCache::class)->forgetStorefront(1, T::str(DB::table('storefronts')->where('id', 1)->value('code')));
}

/** The n-th visible product of a category on Watchizer, priced by the test. @return array{id: int, price: float} */
function waProduct(string $slug, int $nth, float $price): array
{
    $path = T::str(DB::table('storefront_categories')->where('id', T::int(WheelPrizes::seededCategory(1, $slug)))->value('path'));
    $id = T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
        })
        ->where('sp.is_visible', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->whereExists(fn ($q) => $q->select(DB::raw('1'))->from('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->whereColumn('scp.product_id', 'p.id')->where('c.path', 'like', $path.'%'))
        ->orderBy('p.id')->skip($nth)->value('p.id'));
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['effective_price' => number_format($price, 2, '.', ''), 'effective_sale_price' => null]);

    return ['id' => $id, 'price' => $price];
}

/** @param list<array{id: int, price: float}> $items */
function waCart(array $items, string $code, string $phone, float $shipping = 85.0): CartSnapshot
{
    $lines = array_map(fn (array $i): CartLine => new CartLine(productId: $i['id'], variantId: null, offerId: null, quantity: 1, unitPrice: $i['price'], typeStock: 'Express'), $items);

    return new CartSnapshot(storefrontId: 1, lines: $lines, subtotal: array_sum(array_column($items, 'price')), shippingCost: $shipping, paymentMethod: 'cash',
        code: $code, customerKeys: PromotionDiscounts::customerKeys(null, null, $phone));
}

dataset('options', [
    'a percentage on the whole order, no cap' => [['label_en' => '5% off', 'label_ar' => 'خصم 5%', 'type' => 'percent_discount', 'value' => 5], [['watches', 0, 4000.0]], 200.0],
    'a percentage on the whole order, capped' => [['label_en' => '5% off, up to EGP 100', 'label_ar' => 'خصم 5% حتى 100', 'type' => 'percent_discount', 'value' => 5, 'cap' => 100], [['watches', 0, 4000.0]], 100.0],
    'a percentage on one category' => [['label_en' => '10% off wallets', 'label_ar' => 'خصم 10% على المحافظ', 'type' => 'percent_discount', 'value' => 10, 'scope_slug' => 'wallets'], [['wallets', 0, 2000.0], ['watches', 0, 4000.0]], 200.0],
    'a percentage off the cheapest of two' => [['label_en' => '10% off the 2nd watch', 'label_ar' => 'خصم 10% على الساعة الثانية', 'type' => 'percent_discount', 'value' => 10, 'scope_slug' => 'watches', 'min_quantity' => 2, 'apply_mode' => 'cheapest'], [['watches', 0, 4000.0], ['watches', 1, 5000.0]], 400.0],
    'a fixed amount' => [['label_en' => 'EGP 150 off', 'label_ar' => 'خصم 150 ج.م', 'type' => 'fixed_discount', 'value' => 150], [['watches', 0, 4000.0]], 150.0],
    'free shipping' => [['label_en' => 'Free shipping', 'label_ar' => 'شحن مجاني', 'type' => 'free_shipping', 'value' => null], [['watches', 0, 4000.0]], 85.0],
]);

it('works end to end: on the disc with something to read, a code at the spin, applied in the cart, and checked at order time', function (array $subject, array $cart, float $expected) {
    $scope = $subject['scope_slug'] ?? null;
    unset($subject['scope_slug']);
    waWheel($subject + ['scope_category_id' => $scope === null ? null : WheelPrizes::seededCategory(1, $scope)]);

    // the disc: the segment is drawn, with a number or words - never empty
    $segment = WheelPrizes::segments(1)[0];
    expect($segment['type'])->toBe($subject['type'])->and($segment['display_type'])->toBe($subject['type']);
    if (in_array($subject['type'], ['percent_discount', 'fixed_discount'], true)) {
        expect($segment['value'])->toBe((float) $subject['value']);
    }

    // the spin issues a code
    $phone = '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $spin = Wheel::spin(1, $phone, null, '203.0.113.21', [], 'en', 1)['spin'];
    expect(Row::str($spin, 'prize_type'))->toBe($subject['type']);
    $code = T::str(Row::nstr($spin, 'code'));
    expect($code)->not->toBe('');

    // the cart applies it
    $items = array_map(fn (array $c): array => waProduct($c[0], $c[1], $c[2]), $cart);
    $snapshot = waCart($items, $code, $phone);
    $out = app(PromotionEngine::class)->evaluate($snapshot);
    expect($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)->and($out->discount)->toBe($expected);

    // the order-time check passes for the winner's phone and refuses another phone
    expect(Wheel::refusal($spin, $snapshot, now()))->toBeNull();
    $other = waCart($items, $code, '0109'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT));
    expect(app(PromotionEngine::class)->evaluate($other)->coupon['status'] ?? null)->not->toBe(CouponStatus::APPLIED);
})->with('options');

it('refuses on the dashboard a money prize on a shop whose money rewards are off, and still takes the prizes that need none', function () {
    PromotionFixture::moneyRewards(false, 1);
    foreach ([['type' => 'percent_discount', 'value' => 5], ['type' => 'fixed_discount', 'value' => 100], ['type' => 'referral', 'value' => 15]] as $money) {
        try {
            waWheel(['label_en' => 'A prize', 'label_ar' => 'جائزة'] + $money);
            $this->fail("the writer took a {$money['type']} prize with money rewards off");
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain('segments.0.type');
        }
    }
    waWheel(['label_en' => 'Free shipping', 'label_ar' => 'شحن مجاني', 'type' => 'free_shipping', 'value' => null]);
    expect(WheelPrizes::segments(1)[0]['type'])->toBe('free_shipping');
});
