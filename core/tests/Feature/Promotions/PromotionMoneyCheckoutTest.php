<?php

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;

/*
 * A money reward through the REAL checkout (wave 4D, §3.16.3).
 *
 * ── The one property this file exists to protect ─────────────────────────────────────────────
 *
 * `addOrder()` compares the client's `total_price_for_order` against the server's own and answers
 * 422 on a disagreement of one piastre. That guard is wave-3 behaviour, it is in the 126-case
 * harness, and it exists because a tampered client total was a real hazard.
 *
 * Opening the money family did NOT relax it and did not route around it. The comparison still runs,
 * on the UNDISCOUNTED total, before any promotion is evaluated; the discount is applied to the
 * order afterwards. So:
 *
 *   • a client that sends the wrong total is refused exactly as before — asserted below, with a
 *     discount live, because that is the case where a careless implementation would have let the
 *     discount absorb the tampering;
 *   • a client that sends the right total gets an order whose stored total is lower.
 *
 * The second is a disclosure problem on a frontend that cannot show the discount, which is why the
 * family is gated per storefront — and this file turns that gate ON deliberately, for storefront 1,
 * rather than assuming any storefront has it.
 */

const MONEY_API_KEY = 'test-api-code';

beforeEach(function () {
    config([
        'compat.api_key' => MONEY_API_KEY,
        'notifications.send.inline' => false,
    ]);
    Mail::fake();

    // Each test creates a rule at a priority that puts it beyond doubt; the shop's own rules are
    // left alone, because a real checkout has to cope with whatever is running.
    PromotionFixture::moneyRewards(true, 1);
});

/** @return array{id: int, price: float} */
function moneyBuyable(): array
{
    /*
     * The same product the grant tests buy, priced the same way. `catalogPrice()` is the shared
     * rule — a sale price counts only when it is above zero and below the selling price — and
     * asking it here rather than reading `effective_price` directly is what keeps the total this
     * test sends identical to the one the server computes.
     */
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

/** @return array{id: int, cost: float} */
function moneyCity(): array
{
    $row = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return ['id' => Row::int($row, 'id'), 'cost' => round((float) Row::money($row, 'shipping_cost'), 2)];
}

/**
 * A real COD checkout. `$sendTotal` is what the CLIENT claims the order costs.
 *
 * @param  array{id: int, price: float}  $product
 * @param  array{id: int, cost: float}  $city
 * @return TestResponse<Response>
 */
function moneyCheckout(array $product, array $city, ?float $sendTotal = null): TestResponse
{
    return withHeaders(['Api-Code' => MONEY_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('/api/add_order', [
            'shipping_city_id' => $city['id'],
            'address_line' => 'Money Reward Street 1',
            'phone' => '01000000000',
            'total_price_for_order' => $sendTotal ?? round($product['price'] + $city['cost'], 2),
            'payment_method' => 'cash',
            'guest_name' => 'Money Test',
            'guest_email' => 'money@example.test',
            'items' => [[
                'product_id' => $product['id'], 'quantity' => 1,
                'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
            ]],
        ]);
}

/** @param  TestResponse<Response>  $response */
function moneyOrderId(TestResponse $response): int
{
    $number = T::str($response->json('order_number'));

    return T::int(DB::table('orders')->where('order_number', $number)->value('id'));
}

/** @param  TestResponse<Response>  $response */
function moneyOrderTotal(TestResponse $response): string
{
    $number = T::str($response->json('order_number'));

    return T::str(DB::table('orders')->where('order_number', $number)->value('total_price_for_order'));
}

it('still refuses a TAMPERED client total, with a discount live', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    /*
     * The shopper claims the order costs 100 less than it does. The discount happens to be 25, so a
     * checkout that applied the promotion BEFORE the comparison — or that widened the tolerance to
     * accommodate discounts — would have a much easier time accepting this. It is refused.
     */
    $honest = round($product['price'] + $city['cost'], 2);
    $response = moneyCheckout($product, $city, $honest - 100);

    $response->assertStatus(422)->assertJson(['success' => false]);
    expect(T::str($response->json('message')))->toContain('Order total mismatch')
        // The server reports its own UNDISCOUNTED number, which is the one the client got wrong.
        ->and(round(T::float($response->json('server_total')), 2))->toBe($honest);
});

it('takes a FIXED discount off the stored order total, and the client still sends the full one', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    // The client sends the total it computed, with no knowledge of the promotion. That is the whole
    // point: the discount is never negotiable by the caller.
    $response = moneyCheckout($product, $city);
    $response->assertOk()->assertJson(['success' => true]);

    $expected = round($product['price'] + $city['cost'] - 25, 2);
    expect(moneyOrderTotal($response))->toBe(number_format($expected, 2, '.', ''));
});

it('waives the shipping cost for free_shipping', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    /*
     * The shipping cost is SET here, not read and hoped for.
     *
     * A test that skipped itself when the first city happened to be free would be a test that
     * stopped running the day somebody edited the shipping screen — the same shape as a media test
     * that inherited its fixture from the developer's own disk. `DatabaseTransactions` rolls this
     * back, so the shop's real price is untouched (AGENTS §4).
     */
    DB::table('shipping_cities')->where('id', $city['id'])->update(['shipping_cost' => '45.00']);
    $city['cost'] = 45.0;

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::freeShipping()],
        priority: 1000,
    );

    $response = moneyCheckout($product, $city);
    $response->assertOk();

    // Exactly the goods, with the delivery given away.
    expect(moneyOrderTotal($response))->toBe(number_format(round($product['price'], 2), 2, '.', ''));
});

it('writes NO reward line for a money reward — a discount is not an item', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::percentDiscount(10)],
        priority: 1000,
    );

    $response = moneyCheckout($product, $city);
    $response->assertOk();

    $orderId = T::int(DB::table('orders')->where('order_number', T::str($response->json('order_number')))->value('id'));

    /*
     * A gift is a real `order_items` row at zero price, reserved through `InventoryService`. A
     * discount is neither: no line, and no stock movement carrying the promotion's ledger reason.
     * Asserting the ABSENCE matters — a money reward that quietly wrote a zero-priced line would
     * make the legacy application, which reads the same rows, render a phantom product.
     */
    expect(T::int(DB::table('order_items')->where('order_id', $orderId)->count()))->toBe(1)
        ->and(T::int(DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 1)->count()))->toBe(0)
        ->and(T::int(
            DB::table('inventory_movements')
                ->where('reference_type', 'order')->where('reference_id', $orderId)
                ->where('reason', 'promotion_reward')->count()
        ))->toBe(0);
});

// ── the audit trail (M1r) ────────────────────────────────────────────────────────────────────

it('records WHICH rule discounted the order and by how much', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    $response = moneyCheckout($product, $city);
    $response->assertOk();
    $orderId = moneyOrderId($response);

    /*
     * The whole point of the table. A free-item reward explains itself with an `order_items` row;
     * a money reward is only a smaller total, so without this an order whose lines sum to 500 and
     * whose total reads 475 has nothing saying why.
     */
    $row = T::row(DB::table('promotion_order_discounts')->where('order_id', $orderId)->first());

    expect(Row::int($row, 'promotion_rule_id'))->toBe($rule)
        ->and(Row::money($row, 'amount'))->toBe('25.00')
        ->and(Row::bool($row, 'free_shipping'))->toBeFalse();

    // …and the number recorded is exactly the number taken off the order.
    $lines = round($product['price'] + $city['cost'], 2);
    expect(moneyOrderTotal($response))->toBe(number_format($lines - 25, 2, '.', ''));
});

it('marks a waived shipping cost as such, because it reconciles differently', function () {
    $product = moneyBuyable();
    $city = moneyCity();
    DB::table('shipping_cities')->where('id', $city['id'])->update(['shipping_cost' => '45.00']);
    $city['cost'] = 45.0;

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::freeShipping()],
        priority: 1000,
    );

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());
    $row = T::row(DB::table('promotion_order_discounts')->where('order_id', $orderId)->first());

    // EGP 45 off the goods and EGP 45 of free delivery are the same figure and a different
    // transaction — which is why the flag is stored rather than inferred from the amount.
    expect(Row::money($row, 'amount'))->toBe('45.00')
        ->and(Row::bool($row, 'free_shipping'))->toBeTrue();
});

it('writes NOTHING for an order that carried no discount', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());

    // The common case by far, and it must cost nothing: no rule, no row.
    expect(T::int(DB::table('promotion_order_discounts')->where('order_id', $orderId)->count()))->toBe(0);
});

it('writes nothing for a FREE-ITEM reward, which explains itself already', function () {
    $product = moneyBuyable();
    $city = moneyCity();
    $gift = T::int(
        DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('sp.is_visible', 1)
            ->where('p.stock_express', '>=', 2)->where('p.id', '!=', $product['id'])
            ->orderBy('p.id')->value('p.id')
    );

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::freeProduct($gift, 1)],
        priority: 1000,
    );

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());

    /*
     * A gift is already traceable through `order_items.promotion_rule_id`. Writing a discount row
     * of zero beside it would put the same order in two places and invite a settlement export to
     * count it twice.
     */
    expect(T::int(DB::table('promotion_order_discounts')->where('order_id', $orderId)->count()))->toBe(0)
        ->and(T::int(DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 1)->count()))->toBe(1);
});

it('KEEPS the discount record when the order is cancelled', function () {
    actingAs(Staff::admin());

    $product = moneyBuyable();
    $city = moneyCity();

    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());
    expect(T::int(DB::table('promotion_order_discounts')->where('order_id', $orderId)->count()))->toBe(1);

    post("/manage/orders/{$orderId}/cancel", ['note' => 'cancelled by telephone'])->assertRedirect();

    /*
     * The row STAYS, and that is the contract — the same one the inventory ledger and the activity
     * log keep. The promotion really did apply, the customer really was quoted that total, and a
     * cancelled order whose discount record had been erased would be unexplainable afterwards:
     * a refund of 475 against a 500 order with nothing saying where the 25 went.
     */
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('cancelled')
        ->and(T::int(DB::table('promotion_order_discounts')->where('order_id', $orderId)->count()))->toBe(1)
        ->and(T::str(DB::table('promotion_order_discounts')->where('order_id', $orderId)->value('amount')))->toBe('25.00');
});

it('shows the discount and the rule that gave it on the order screen', function () {
    actingAs(Staff::admin());

    $product = moneyBuyable();
    $city = moneyCity();

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
        name: 'عرض اختبار الخصم',
    );

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());

    // The NAME, not just the amount: an amount alone replaces one unexplained number with two.
    get("/manage/orders/{$orderId}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('discount.rule_id', $rule)
            ->where('discount.amount', '25.00')
            ->where('discount.free_shipping', false)
            ->where('discount.rule_name', 'عرض اختبار الخصم')
    );
});

it('carries the discount into the settlement export finance reconciles against', function () {
    actingAs(Staff::admin());

    $product = moneyBuyable();
    $city = moneyCity();

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    $orderId = moneyOrderId(moneyCheckout($product, $city)->assertOk());
    $number = T::str(DB::table('orders')->where('id', $orderId)->value('order_number'));

    /*
     * A COD order writes no `payment_statuses` row, and the settlement export is built from that
     * table — so the row has to exist for this assertion to mean anything. Created here rather
     * than assumed, which is the §4 law: a test constructs the state it asserts on.
     */
    DB::table('payment_statuses')->insert([
        'order_id' => $orderId,
        'provider' => 'paymob',
        'method' => 'card',
        // An INTEGER column in the legacy schema, not a reference string.
        'pay_transaction_id' => 987654321,
        'amount_cents' => 1,
        'success' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $csv = get('/manage/orders/export/settlement')->assertOk()->streamedContent();

    expect($csv)->toContain('discount')
        ->and($csv)->toContain('discount_rule')
        // The order's own row carries the figure and the rule that produced it.
        ->and($csv)->toContain($number)
        ->and($csv)->toContain('25.00')
        ->and($csv)->toContain((string) $rule);
});

it('leaves the total alone when the storefront has not enabled the money family', function () {
    $product = moneyBuyable();
    $city = moneyCity();

    PromotionFixture::moneyRewards(false, 1);
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1)],
        [PromotionFixture::fixedDiscount(25)],
        priority: 1000,
    );

    $response = moneyCheckout($product, $city);
    $response->assertOk();

    // Byte-identical to a checkout with no promotion at all — the requirement for every storefront
    // that has not opted in, which today is all of them.
    expect(moneyOrderTotal($response))
        ->toBe(number_format(round($product['price'] + $city['cost'], 2), 2, '.', ''));
});
