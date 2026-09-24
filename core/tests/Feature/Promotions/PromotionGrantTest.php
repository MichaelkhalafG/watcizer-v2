<?php

use App\Compat\CompatCart;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\OrderFulfilment;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionSkips;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

/*
 * The grant path, end to end through the real checkout (wave 4D, study §3.16.4/§3.16.5).
 *
 * `PromotionScenarioTest` proves what the engine DECIDES. This file proves what actually lands: a
 * reward line in the shared `order_items`, its units reserved through `InventoryService` with
 * reason `promotion_reward`, the order's total untouched, and every one of those given back when
 * the order is cancelled.
 *
 * ── Deliberately NOT hermetic ────────────────────────────────────────────────────────────────
 *
 * Unlike the scenario file, this one does not clear the promotion tables: a real checkout has to
 * cope with whatever the shop happens to be running, and each test here creates a rule whose
 * priority puts it beyond doubt rather than assuming it is alone.
 */

const PROMO_API_KEY = 'test-api-code';

beforeEach(function () {
    config([
        'compat.api_key' => PROMO_API_KEY,
        // The checkout sends order mail; nothing about promotions needs it, and a real send would
        // be an outward side effect of a test (see AGENTS §3).
        'notifications.send.inline' => false,
    ]);
    Mail::fake();
});

/**
 * A product the checkout can buy: visible, priced, no variants, express stock to spare.
 *
 * @return array{id: int, price: float}
 */
function promoBuyable(): array
{
    $row = T::row(
        DB::table('catalog_products as cp')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('cp.deleted_at')
            ->where('cp.stock_express', '>=', 2)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id')->selectRaw('1');
            })
            ->orderBy('cp.id')
            ->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price'])
    );

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/** A DIFFERENT product with stock, to be the gift — so the reward is not the thing being bought. */
function promoGift(int $notThis): int
{
    $id = DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')
        ->where('cp.is_active', 1)->where('sp.is_visible', 1)
        ->where('cp.stock_express', '>=', 2)
        ->where('cp.id', '!=', $notThis)
        ->whereNotExists(function (Builder $q): void {
            $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id')->selectRaw('1');
        })
        ->orderBy('cp.id')
        ->value('cp.id');

    return T::int($id);
}

/** @return array{id: int, cost: float} */
function promoCity(): array
{
    $row = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return ['id' => Row::int($row, 'id'), 'cost' => round((float) Row::money($row, 'shipping_cost'), 2)];
}

/**
 * A real COD checkout through HTTP.
 *
 * @param  array{id: int, price: float}  $product
 * @param  array{id: int, cost: float}  $city
 * @return array{0: int, 1: TestResponse<Response>}
 */
function promoCheckout(array $product, array $city): array
{
    $total = round($product['price'] + $city['cost'], 2);

    $response = withHeaders(['Api-Code' => PROMO_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'shipping_city_id' => $city['id'],
            'address_line' => 'Promotion Test Street 1',
            'phone' => '01000000000',
            'total_price_for_order' => $total,
            'payment_method' => 'cash',
            'guest_name' => 'Promo Test',
            'guest_email' => 'promo@example.test',
            'items' => [[
                'product_id' => $product['id'], 'quantity' => 1,
                'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
            ]],
        ]);

    if ($response->getStatusCode() !== 200) {
        return [0, $response];
    }
    $number = T::str($response->json('order_number'));

    return [T::int(DB::table('orders')->where('order_number', $number)->value('id')), $response];
}

/** @return list<array{product_id: int|null, quantity: int, piece_price: string, is_reward: int, promotion_rule_id: int|null}> */
function promoLines(int $orderId): array
{
    $out = [];
    foreach (DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get() as $raw) {
        $row = Row::cast($raw);
        $out[] = [
            'product_id' => Row::nint($row, 'product_id'),
            'quantity' => Row::int($row, 'quantity'),
            'piece_price' => Row::money($row, 'piece_price'),
            'is_reward' => Row::int($row, 'is_reward'),
            'promotion_rule_id' => Row::nint($row, 'promotion_rule_id'),
        ];
    }

    return $out;
}

// ── the grant ────────────────────────────────────────────────────────────────────────────────

it('writes the reward as a REAL order line at zero price, and does not move the total', function () {
    $product = promoBuyable();
    $gift = promoGift($product['id']);
    $city = promoCity();

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::freeProduct($gift, 1)],
        priority: 1000,                                 // beyond doubt, whatever else exists
    );

    [$orderId, $response] = promoCheckout($product, $city);

    // The checkout succeeded — which is the first thing a promotion must never break.
    $response->assertOk()->assertJson(['success' => true]);
    expect($orderId)->toBeGreaterThan(0);

    $lines = promoLines($orderId);
    expect($lines)->toHaveCount(2);

    $paid = array_values(array_filter($lines, fn (array $l): bool => $l['is_reward'] === 0));
    $reward = array_values(array_filter($lines, fn (array $l): bool => $l['is_reward'] === 1));

    expect($paid)->toHaveCount(1)
        ->and($paid[0]['product_id'])->toBe($product['id'])
        ->and($paid[0]['promotion_rule_id'])->toBeNull()
        ->and($reward)->toHaveCount(1)
        ->and($reward[0]['product_id'])->toBe($gift)
        ->and($reward[0]['quantity'])->toBe(1)
        // Zero price is what keeps `addOrder()`'s total check satisfied — the reason only the
        // free-item family ships before wave 9 (§3.16.9).
        ->and($reward[0]['piece_price'])->toBe('0.00')
        ->and($reward[0]['promotion_rule_id'])->toBe($rule);

    // …and the order's own total is exactly what the customer was quoted.
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('total_price_for_order')))
        ->toBe(number_format($product['price'] + $city['cost'], 2, '.', ''));
});

it('reserves the reward through InventoryService with its own ledger reason', function () {
    $product = promoBuyable();
    $gift = promoGift($product['id']);
    $city = promoCity();

    $giftBefore = T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express'));
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::freeProduct($gift, 1)],
        priority: 1000,
    );

    [$orderId] = promoCheckout($product, $city);

    // The gift's stock really moved — not just a line written.
    expect(T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express')))->toBe($giftBefore - 1);

    $reasons = [];
    foreach (
        DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $orderId)
            ->orderBy('id')->get(['product_id', 'reason', 'quantity_delta']) as $raw
    ) {
        $row = Row::cast($raw);
        $reasons[Row::int($row, 'product_id')] = [Row::str($row, 'reason'), Row::int($row, 'quantity_delta')];
    }

    /*
     * Two movements, two reasons. The ledger is what an operator reads to answer "where did these
     * units go?", and "a promotion gave them away" is a different answer from "a customer bought
     * them" — the same distinction `order_cancel` and `payment_failed` already make on the way back.
     */
    expect($reasons[$product['id']])->toBe(['order', -1])
        ->and($reasons[$gift])->toBe([PromotionRules::LEDGER_REASON, -1]);
});

it('is exactly-once under a repeated commit, reward included', function () {
    $product = promoBuyable();
    $gift = promoGift($product['id']);
    $city = promoCity();

    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($gift, 1)], priority: 1000);
    [$orderId] = promoCheckout($product, $city);

    $after = T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express'));
    $movements = T::int(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $orderId)->count());

    /*
     * A second commit of the same order — a retry, a double click, a replayed callback.
     *
     * The guarantee is the DATABASE's, and it is a REFUSAL rather than a silent no-op: the
     * ledger's unique key is (reference_type, reference_id, reference_line_id, reason), so a
     * second insert for the same line and reason cannot exist. My first cut of this test expected
     * silent idempotency and got the violation — which is wave 3's exactly-once working exactly as
     * `ExactlyOnceTest` states it ("refuses a second movement for the SAME line and reason at the
     * database level"), not a defect.
     *
     * What matters for promotions: the REWARD line sits inside that guarantee for free, because a
     * reward IS an order line. So the attempt is refused, and nothing moved.
     */
    expect(fn () => app(InventoryService::class)->commitOrder($orderId, Actor::system(), 1))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express')))->toBe($after)
        ->and(T::int(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $orderId)->count()))->toBe($movements);
});

// ── placed, then cancelled ───────────────────────────────────────────────────────────────────

it('gives the reward stock back when the order is cancelled', function () {
    $product = promoBuyable();
    $gift = promoGift($product['id']);
    $city = promoCity();

    $giftBefore = T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express'));
    $paidBefore = T::int(DB::table('catalog_products')->where('id', $product['id'])->value('stock_express'));

    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($gift, 1)], priority: 1000);
    [$orderId] = promoCheckout($product, $city);

    expect(T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express')))->toBe($giftBefore - 1);

    $admin = Staff::admin();
    actingAs($admin)->post("/manage/orders/{$orderId}/cancel", ['note' => 'promotion cancel test'])->assertRedirect();

    /*
     * Both back. `releaseOrder()` walks `order_items`, and a reward line is one — so the gift
     * returns with no promotion-specific code at all, which is the whole reason the reward was
     * modelled as a line rather than as something beside the order.
     */
    expect(T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express')))->toBe($giftBefore)
        ->and(T::int(DB::table('catalog_products')->where('id', $product['id'])->value('stock_express')))->toBe($paidBefore)
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled');

    // The release is ledgered for both lines, so `inventory:verify` still reconciles.
    expect(T::int(DB::table('inventory_movements')
        ->where('reference_type', 'orders')->where('reference_id', $orderId)
        ->where('reason', 'order_cancel')->count()))->toBe(2);
});

// ── the reward is out of stock at checkout time ──────────────────────────────────────────────

it('completes the checkout and counts the skip when the gift is out of stock', function () {
    $product = promoBuyable();
    $city = promoCity();
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(true)->toBeTrue('this catalogue has no zero-stock visible product');

        return;
    }

    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($empty, 1)], priority: 1000);

    [$orderId, $response] = promoCheckout($product, $city);

    // The customer's side: nothing failed, nothing missing, no mention of a promotion.
    $response->assertOk()->assertJson(['success' => true]);
    expect(promoLines($orderId))->toHaveCount(1);

    /*
     * The admin's side: the rule matched, could not be delivered, and the count says so. This is
     * the developer's addition — an admin advertising a gift that never applies is the failure
     * mode that actually costs the client, and this counter is the only thing that reports it.
     */
    $report = PromotionSkips::forRule($rule);
    expect($report['stock'])->toBe(1)
        ->and($report['visibility'])->toBe([])
        ->and($report['last_at'])->not->toBeNull();
});

it('counts one skip per checkout, not one per evaluation', function () {
    $product = promoBuyable();
    $city = promoCity();
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(true)->toBeTrue('this catalogue has no zero-stock visible product');

        return;
    }

    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($empty, 1)], priority: 1000);

    promoCheckout($product, $city);
    promoCheckout($product, $city);

    // Two checkouts, two misses. The cart READS in between — of which there are many — must not
    // have counted anything, which is why `evaluate()` writes nothing.
    expect(PromotionSkips::forRule($rule)['stock'])->toBe(2);
});

// ── no rule configured: the path that must cost nothing ──────────────────────────────────────

it('changes nothing about a checkout when no rule matches', function () {
    $product = promoBuyable();
    $city = promoCity();

    // A rule that cannot match this cart (an impossible subtotal).
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(9_000_000)], [PromotionFixture::freeProduct(promoGift($product['id']), 1)], priority: 1000);

    [$orderId, $response] = promoCheckout($product, $city);

    $response->assertOk();
    $lines = promoLines($orderId);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['is_reward'])->toBe(0)
        ->and($lines[0]['promotion_rule_id'])->toBeNull()
        // No skip either: the cart did not qualify, which is not a refusal.
        ->and(T::int(DB::table('promotion_rule_skips')->count()))->toBe(0);
});

// ── the fulfilment flow is unaffected ────────────────────────────────────────────────────────

it('lets an order carrying a reward move through the fulfilment flow', function () {
    $product = promoBuyable();
    $gift = promoGift($product['id']);
    $city = promoCity();

    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($gift, 1)], priority: 1000);
    [$orderId] = promoCheckout($product, $city);

    $fulfilment = app(OrderFulfilment::class);
    foreach (['shipped', 'delivered', 'completed'] as $to) {
        $fulfilment->advance($orderId, $to, Actor::system());
        expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe($to);
    }

    // …and the reward line is still there, still zero-priced, still attributed.
    $reward = array_values(array_filter(promoLines($orderId), fn (array $l): bool => $l['is_reward'] === 1));
    expect($reward)->toHaveCount(1)->and($reward[0]['piece_price'])->toBe('0.00');
});
