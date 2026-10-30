<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PromotionFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * SEARCH ON THE LISTS (M1x, 2026-10-30, Michael's item 4): the order queue finds an order by the customer's NAME and
 * E-MAIL as well as its number and phone; the promotions list finds a rule by its coupon code. (Products, the
 * activity log and the bulk price filters have their own tests: BulkPriceTest, ActivityFindTest.)
 */

function lsOrder(string $name, string $email): string
{
    $number = 'LS-'.Str::upper(Str::random(8));
    DB::table('orders')->insert([
        'storefront_id' => 1, 'status' => 'pending', 'payment_method' => 'cash', 'order_number' => $number,
        'address_id' => T::int(DB::table('addresses')->orderBy('id')->value('id')),
        'total_price_for_order' => '100.00', 'guest_name' => $name, 'guest_email' => $email, 'guest_phone' => '01099998877',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $number;
}

/** @return list<string> */
function lsOrders(string $query): array
{
    return array_map(fn ($row): string => T::str(T::arr($row)['order_number'] ?? null),
        Props::rows(Props::table(get('/manage/orders?q='.rawurlencode($query)))));
}

it('finds an order by the customer\'s name, a part of the e-mail, the number and the phone', function () {
    $number = lsOrder('Nadia Search-Target', 'nadia.search@example.invalid');
    actingAs(Staff::admin());

    expect(lsOrders('Search-Targ'))->toContain($number)
        ->and(lsOrders('nadia.search@'))->toContain($number)
        ->and(lsOrders($number))->toContain($number)
        ->and(lsOrders('01099998877'))->toContain($number)
        ->and(lsOrders('nobody-matches-this-xyz'))->not->toContain($number);
});

it('finds a promotion by its coupon code', function () {
    $rule = PromotionFixture::rule([], [PromotionFixture::fixedDiscount(10)], [1]);
    DB::table('promotion_rules')->where('id', $rule)->update(['name' => 'A rule with a code', 'code' => 'FINDME42']);
    actingAs(Staff::admin());

    $ids = array_map(fn ($row): int => T::int(T::arr($row)['id'] ?? null), Props::rows(Props::table(get('/manage/promotions?q=FINDME'))));

    expect($ids)->toContain($rule);
});
