<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;

/*
 * B-BUG-1 — adjusting stock on a product that has SIZES returned HTTP 500.
 *
 * ── Two defects in one refusal ───────────────────────────────────────────────────────────────
 *
 * `InventoryService` is right to refuse a product-level movement on a product that has variants
 * (§2.5): the product's stock is the maintained SUM of its variants, and a movement written at the
 * product would make the aggregate mean nothing. That rule is not in question.
 *
 * What was wrong is what happened next. The service throws `InvalidArgumentException`, and
 * `InventoryController::adjust()` caught only `InsufficientStock` and `RuntimeException` —
 * `InvalidArgumentException` is neither, so a correct domain refusal escaped as a Server Error page.
 *
 * And the message it carried was written for a programmer:
 *
 *     "Product 5593 has variants, so its stock moves through a variant, never through the product.
 *      Use StockTarget::variant()."
 *
 * English prose naming a PHP class and method, in front of somebody holding a stock sheet. So the
 * controller does NOT echo it the way it echoes the service's operator-readable `RuntimeException`s;
 * it answers with its own sentence naming the ACTION.
 *
 * Brand Fashion sells shoes and clothing in sizes, so this is a first-week event.
 */

it('refuses a product-level stock change on a product with sizes as a FIELD ERROR, not a 500', function () {
    $fixture = VariantFixture::synthetic(count: 2, express: 5, market: 3);
    $productId = $fixture['product'];

    $response = actingAs(Staff::admin())->post('/manage/inventory/adjust', [
        'product_id' => $productId,
        // No `variant_id` — the operator adjusting the PRODUCT, which is the reported action.
        'bucket' => 'express',
        'mode' => 'adjust',
        'quantity' => 3,
        'reason' => 'adjustment',
    ]);

    // A field error the form can render beside the input, never a crash page.
    $response->assertSessionHasErrors('variant_id');
    expect($response->getStatusCode())->toBeLessThan(500);
});

it('tells the operator what to DO, and never shows them the developer message', function () {
    $fixture = VariantFixture::synthetic(count: 2);
    $productId = $fixture['product'];

    actingAs(Staff::admin())->post('/manage/inventory/adjust', [
        'product_id' => $productId,
        'bucket' => 'express',
        'mode' => 'adjust',
        'quantity' => 3,
        'reason' => 'adjustment',
    ])->assertSessionHasErrors('variant_id');

    $message = T::err('variant_id');

    /*
     * The half a status-code assertion cannot see. Not one of these fragments may reach a screen —
     * they are the domain's contract message, addressed to whoever wrote the caller.
     */
    foreach (['StockTarget', 'variant()', 'Product '.$productId, 'InvalidArgument'] as $developerText) {
        expect($message)->not->toContain($developerText);
    }

    // …and it says the thing the operator can act on: there are sizes, and stock lives on them.
    expect($message)->toContain('مقاس')
        // The COUNT, because "it has sizes" is less useful than "it has two".
        ->and($message)->toContain('2');
});

it('still lets the same operator adjust the SIZE, which is the action it points at', function () {
    /*
     * The other direction. A refusal that also blocked the correct path would be a worse bug than
     * the crash — the operator would be told what to do and then prevented from doing it.
     */
    $fixture = VariantFixture::synthetic(count: 2, express: 5, market: 3);
    $productId = $fixture['product'];
    $variantId = $fixture['variants'][0];

    actingAs(Staff::admin())->post('/manage/inventory/adjust', [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'bucket' => 'express',
        'mode' => 'adjust',
        'quantity' => 4,
        'reason' => 'adjustment',
    ])->assertSessionHasNoErrors();

    // The variant moved, and the product aggregate followed it — the rule the refusal protects.
    expect(T::int(DB::table('catalog_product_variants')->where('id', $variantId)->value('stock_express')))->toBe(9)
        ->and(T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express')))->toBe(14);
});
