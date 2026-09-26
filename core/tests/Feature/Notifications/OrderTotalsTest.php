<?php

use App\Domain\Notifications\OrderEmailData;
use App\Domain\Orders\OrderTotals;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * ── What an order cost: one definition, and a column that adds up (2026-09-26) ──────────────
 *
 * Live, order #000013's confirmation read "Subtotal 999 · Discount −501 · Shipping 100 ·
 * Total 1,099": the Subtotal was already the sale price, the Discount was off LIST price, and the
 * column added to 598. OrderTotals is now the one answer both e-mails and the dashboard read, and
 * every shape below must satisfy
 *
 *     list − saleDiscount − promotion + shipping = total
 */

/**
 * An order for one sellable product listed at 1,500, sold at `$paid`, delivered to a city whose
 * price is `$cityPrice`. Returns [orderId, productId].
 *
 * @return array{0: int, 1: int}
 */
function totalsOrder(float $paid, float $total, float $cityPrice = 100.0): array
{
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')
        ->whereNotExists(fn (Builder $q) => $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1'))
        ->orderBy('id')->value('id'));
    DB::table('catalog_products')->where('id', $productId)->update(['selling_price' => '1500.00']);

    $orderId = PaymentFixture::order(total: $total, status: 'processing');
    $cityId = T::int(DB::table('addresses')->where('id', DB::table('orders')->where('id', $orderId)->value('address_id'))->value('shipping_city_id'));
    DB::table('shipping_cities')->where('id', $cityId)->update(['shipping_cost' => number_format($cityPrice, 2, '.', '')]);

    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null, 'quantity' => 1,
        'piece_price' => number_format($paid, 2, '.', ''), 'total_price' => number_format($paid, 2, '.', ''),
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$orderId, $productId];
}

function totalsPromotion(int $orderId, float $amount, bool $freeShipping): void
{
    $ruleId = DB::table('promotion_rules')->insertGetId([
        'name' => $freeShipping ? 'Free delivery week' : 'Autumn 100 off',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('promotion_order_discounts')->insert([
        'order_id' => $orderId, 'promotion_rule_id' => $ruleId,
        'amount' => number_format($amount, 2, '.', ''), 'free_shipping' => $freeShipping,
    ]);
}

function totalsOf(int $orderId): OrderTotals
{
    return OrderTotals::of($orderId) ?? throw new RuntimeException("order {$orderId} has no totals");
}

function adds(OrderTotals $t): float
{
    return round($t->list - $t->saleDiscount - $t->promotion + $t->shipping, 2);
}

it('reads the live order back as a column that adds up — 1,500 − 501 + 100 = 1,099', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 1099.0);
    $t = totalsOf($orderId);

    expect([$t->list, $t->saleDiscount, $t->promotion, $t->shipping, $t->total])->toBe([1500.0, 501.0, 0.0, 100.0, 1099.0])
        ->and(adds($t))->toBe(1099.0);
});

it('puts a money promotion on its OWN line instead of inside the sale saving', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 999.0);   // 999 goods + 100 delivery − 100 promotion
    totalsPromotion($orderId, 100.0, freeShipping: false);
    $t = totalsOf($orderId);

    expect([$t->saleDiscount, $t->promotion, $t->promotionName, $t->shipping])->toBe([501.0, 100.0, 'Autumn 100 off', 100.0])
        ->and(adds($t))->toBe($t->total);
});

it('shows free shipping as FREE — not as the city price plus a matching discount', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 999.0);
    totalsPromotion($orderId, 100.0, freeShipping: true);  // the ledger records the waived price as the amount
    $t = totalsOf($orderId);

    expect([$t->freeShipping, $t->promotion, $t->shipping, $t->expectedShipping, $t->unexplained()])->toBe([true, 0.0, 0.0, 0.0, 0.0])
        ->and(adds($t))->toBe($t->total);
});

it('never counts a gift as discount', function () {
    [$orderId, $productId] = totalsOrder(paid: 999.0, total: 1099.0);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null, 'quantity' => 1,
        'piece_price' => '0.00', 'total_price' => '0.00', 'is_reward' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $t = totalsOf($orderId);

    expect([$t->list, $t->saleDiscount])->toBe([1500.0, 501.0])
        ->and(adds($t))->toBe($t->total);
});

it('uses what delivery COST, not the city price today — and the dashboard sees the difference', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 1099.0, cityPrice: 120.0);   // the city's price rose since
    $t = totalsOf($orderId);

    expect([$t->shipping, $t->expectedShipping, $t->unexplained()])->toBe([100.0, 120.0, -20.0])
        ->and(adds($t))->toBe($t->total);
});

it('never shows a negative saving when the list price rose after the order', function () {
    [$orderId, $productId] = totalsOrder(paid: 999.0, total: 1099.0);
    DB::table('catalog_products')->where('id', $productId)->update(['selling_price' => '900.00']);
    $t = totalsOf($orderId);

    expect([$t->list, $t->saleDiscount])->toBe([999.0, 0.0])
        ->and(adds($t))->toBe($t->total);
});

it('renders the customer e-mail from those figures, with the list price struck through on the row', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 1099.0);
    $data = OrderEmailData::for($orderId) ?? throw new RuntimeException('no e-mail data');
    $html = view('emails.order-confirmation', $data)->render();

    expect($html)->toContain('1,500 EGP')                  // Subtotal, at list
        ->and($html)->toContain('&minus;501 EGP')          // the saving
        ->and($html)->toContain('100 EGP')                 // delivery
        ->and($html)->toContain('1,099 EGP')               // total
        ->and($html)->toContain('text-decoration:line-through')
        ->and($html)->not->toContain('- 501');             // the old minus-and-space form is gone
});

it('shows the dashboard the SAME answer, promotion included, so its column adds up too', function () {
    [$orderId] = totalsOrder(paid: 999.0, total: 999.0);
    totalsPromotion($orderId, 100.0, freeShipping: false);

    $totals = Props::of(actingAs(Staff::admin())->get('/manage/orders/'.$orderId)->assertOk())['totals'] ?? null;
    expect($totals)->toBeArray();
    /** @var array<string, mixed> $totals */
    expect([$totals['items'], $totals['promotion'], $totals['shipping'], $totals['unexplained'], $totals['total']])
        ->toBe(['999.00', '100.00', '100.00', '0.00', '999.00']);
});
