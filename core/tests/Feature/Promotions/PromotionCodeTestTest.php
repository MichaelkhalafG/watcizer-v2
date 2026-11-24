<?php

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * The offer form's code test ("Try it", 2026-11-24). It did not work: any refusal (an unsaveable draft, an emptied
 * quantity box sent as 0) was read by the panel as a result and the panel broke on the missing totals. Now the server's
 * refusal comes back in words, a pasted SKU joins the test cart, and an account's e-mail can be named for the
 * first-order rule and a code bound to an address.
 */

beforeEach(function () {
    // the shop's own rules stay (orders point at them); every assertion counts against what was there
    PromotionFixture::moneyRewards(true);
});

/** A visible product on shop 1 with a SKU, and its SKU. @return array{id: int, sku: string} */
function ctProduct(int $nth = 0): array
{
    $row = DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('sp.is_visible', 1)
        ->whereNotNull('p.sku')->where('p.sku', '<>', '')->where('sp.effective_price', '>', 100)
        ->orderBy('p.id')->offset($nth)->first(['p.id', 'p.sku']);
    expect($row)->not->toBeNull();

    return ['id' => T::int($row->id ?? null), 'sku' => T::str($row->sku ?? null)];
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function ctBody(array $extra = []): array
{
    return $extra + [
        'storefront_id' => 1,
        'payment_method' => 'cash',
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '1.00']],
        'rewards' => [PromotionFixture::percentDiscount(10.0)],
        'code' => 'TRYME10',
    ];
}

it('refuses a test cart with no product in words, never with an empty result', function () {
    actingAs(Staff::admin());
    $response = postJson('/manage/promotions/preview', ctBody(['lines' => []]));
    $response->assertStatus(422);
    expect($response->json('ok'))->toBeFalse()
        ->and(T::str($response->json('errors.lines.0')))->not->toBe('');
});

it('answers a refused draft with its reasons and ok false, which the panel shows as a list (it used to break)', function () {
    actingAs(Staff::admin());
    $product = ctProduct();
    // a quantity of 0 - what an emptied box used to send
    postJson('/manage/promotions/preview', ctBody(['lines' => [['product_id' => $product['id'], 'quantity' => 0]]]))
        ->assertStatus(422)->assertJsonStructure(['errors']);
    // an unbounded gift - the writer's own refusal
    $refused = postJson('/manage/promotions/preview', ctBody([
        'lines' => [['product_id' => $product['id'], 'quantity' => 1]],
        'rewards' => [['type' => 'free_product', 'product_id' => $product['id'], 'quantity' => 9999]],
    ]));
    $refused->assertStatus(422);
    expect($refused->json('ok'))->toBeFalse()->and($refused->json('errors'))->toBeArray()->not->toBeEmpty();
});

it('puts pasted SKUs into the test cart with their quantities, and names what it could not use and why', function () {
    actingAs(Staff::admin());
    $a = ctProduct(0);
    $b = ctProduct(1);
    $hidden = ctProduct(2);
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $hidden['id'])->update(['is_visible' => 0]);
    $pasted = strtolower($a['sku'])." x2\n{$b['sku']}\nNO-SUCH-SKU-20261124, {$hidden['sku']}";

    $rules = DB::table('promotion_rules')->count();
    $response = postJson('/manage/promotions/preview', ctBody(['lines' => [], 'skus' => $pasted]));
    $response->assertOk();
    expect($response->json('ok'))->toBeTrue()
        ->and(array_map(fn (array $l): array => [$l['product_id'], $l['quantity']], T::rows($response->json('sku_lines'))))
        ->toBe([[$a['id'], 2], [$b['id'], 1]])
        ->and(T::rows($response->json('unknown_skus')))->toBe([
            ['sku' => 'NO-SUCH-SKU-20261124', 'reason' => 'not_found'],
            ['sku' => $hidden['sku'], 'reason' => 'not_on_shop'],
        ]);
    // the cart the engine priced holds both lines: a 10 % code on it applies
    expect($response->json('applies'))->toBeTrue()
        ->and(T::float($response->json('customer.discount')))->toBeGreaterThan(0.0);
    // nothing was saved
    expect(DB::table('promotion_rules')->count())->toBe($rules);
});

it('looks up an account by its e-mail and says when there is none', function () {
    actingAs(Staff::admin());
    $product = ctProduct();
    $email = T::str(DB::table('users')->whereNotNull('email')->where('email', 'like', '%@%')->orderBy('id')->value('email'));
    $found = postJson('/manage/promotions/preview', ctBody(['lines' => [['product_id' => $product['id'], 'quantity' => 1]], 'account' => strtoupper($email)]));
    $found->assertOk();
    expect($found->json('account'))->toBe(['email' => strtolower($email), 'found' => true]);

    $missing = postJson('/manage/promotions/preview', ctBody(['lines' => [['product_id' => $product['id'], 'quantity' => 1]], 'account' => 'nobody-20261124@example.test']));
    $missing->assertOk();
    expect($missing->json('account'))->toBe(['email' => 'nobody-20261124@example.test', 'found' => false]);
});
