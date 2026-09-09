<?php

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/*
 * The wave-3 cart and checkout price a line from `storefront_product.effective_price` /
 * `effective_sale_price`, while the legacy code priced it from `products.selling_price` /
 * `sale_price_after_discount`. Those two agree today because the price-override gate is OFF and
 * the transform writes `effective_* = catalog_*`.
 *
 * "Agree today" is an assumption, and an assumption about money is worth asserting: if wave 4
 * opens the override gate, this test fails and whoever opens it has to decide, deliberately,
 * what the compat checkout should charge.
 */

it('mirrors the catalog price into every storefront row', function () {
    $mismatched = DB::table('storefront_product as sp')
        ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
        ->whereRaw('sp.effective_price <> cp.selling_price')
        ->count();

    expect($mismatched)->toBe(0);
});

it('mirrors the sale price, keeping only a REAL discount', function () {
    // effective_sale_price is the catalog sale price when 0 < sale < selling, and NULL otherwise.
    $wrong = DB::table('storefront_product as sp')
        ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
        ->whereRaw('NOT (
            (cp.sale_price IS NOT NULL AND cp.sale_price > 0 AND cp.sale_price < cp.selling_price
                AND sp.effective_sale_price IS NOT NULL AND sp.effective_sale_price = cp.sale_price)
            OR
            ((cp.sale_price IS NULL OR cp.sale_price <= 0 OR cp.sale_price >= cp.selling_price)
                AND sp.effective_sale_price IS NULL)
        )')
        ->count();

    expect($wrong)->toBe(0);
});

it('prices a line the same way whichever source it reads', function () {
    $rows = DB::table('storefront_product as sp')
        ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
        ->orderBy('cp.id')->limit(200)
        ->get(['cp.selling_price', 'cp.sale_price', 'sp.effective_price', 'sp.effective_sale_price']);

    foreach ($rows as $row) {
        $fromCatalog = CompatCart::catalogPrice(Row::money($row, 'selling_price'), Row::nmoney($row, 'sale_price'));
        $fromStorefront = CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'));
        expect($fromStorefront)->toBe($fromCatalog);
    }
});

it('applies the legacy rule: a sale price is used ONLY when it is a real discount', function (string $selling, ?string $sale, float $expected) {
    expect(CompatCart::catalogPrice($selling, $sale))->toBe($expected);
})->with([
    ['1000.00', '800.00', 800.0],       // a real discount
    ['1000.00', '1000.00', 1000.0],     // equal is not a discount
    ['1000.00', '1200.00', 1000.0],     // higher is not a discount
    ['1000.00', '0.00', 1000.0],        // zero is not a discount
    ['1000.00', null, 1000.0],          // (float) null === 0.0, as in legacy
    ['999.99', '499.995', 500.0],       // rounded to the DECIMAL(12,2) the columns hold
]);
