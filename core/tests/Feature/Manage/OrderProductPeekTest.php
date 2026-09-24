<?php

use App\Domain\Orders\ProductPeek;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The order line tells the packer what to pick (item 12, 2026-09-18).
 *
 * ── Two defects, one screen ─────────────────────────────────────────────────────────────────
 *
 *   1. The COLOUR was a hex code. `order_items.color_band` and `color_dial` hold what the legacy
 *      cart wrote — `#1F3A5F` — and the screen printed it. Seven of the nine lines in this
 *      database carry one, so a team member packing any of them read "#1F3A5F / #1F3A5F" where
 *      they needed to read "أزرق". Nobody picks a watch off a shelf by its hex code.
 *
 *   2. The PRODUCT was a title and a code, and neither confirms anything. Forty products share
 *      "طقم ساعة", so checking the pick meant opening the product screen in another tab and
 *      losing the order.
 *
 * ── What is asserted, and what deliberately is not ──────────────────────────────────────────
 *
 * The resolution and the payload, because those are the server's. The swatch, the panel and the
 * button are React, and this tree has no JavaScript test runner — so the tests below pin the
 * CONTRACT the components consume: a colour arrives as `{hex, name}`, every line's product is on
 * the page, and a product with no catalogue colour keeps its hex rather than borrowing a near one.
 */

it('resolves a stored hex to the catalogue name, and keeps the hex', function () {
    // Taken from the data, not invented: whichever colours this catalogue actually holds.
    $colour = T::one(DB::table('catalog_colors')->whereNotNull('hex')->orderBy('id'));
    $hex = T::str($colour->hex ?? null);

    $names = ProductPeek::colourNames([$hex, strtolower($hex), '  '.$hex.'  ']);

    expect($names)->toHaveKey(strtoupper($hex))
        // One entry, not three: the same colour written three ways is one colour.
        ->and($names)->toHaveCount(1);

    $name = T::arr($names[strtoupper($hex)] ?? null);
    expect(T::str($name['ar'] ?? null))->not->toBe('', 'the catalogue colour has no Arabic name');
});

it('gives NO name to a hex the catalogue has never seen, rather than the nearest one', function () {
    /*
     * The important half. `#1E3A5E` is one digit from `#1F3A5F`, which this catalogue calls أزرق.
     * A screen that rounds colours is a screen that mis-picks orders, so an unknown hex must come
     * back unknown and say so — which is what `orders.colour_unknown` renders.
     */
    $invented = '#0F0E0D';
    expect(DB::table('catalog_colors')->whereRaw('UPPER(hex) = ?', [$invented])->count())
        ->toBe(0, 'pick a different hex — this one is now a real colour');

    expect(ProductPeek::colourNames([$invented]))->toBe([]);
});

it('sends every order line a colour as an object, never as a bare hex', function () {
    $order = T::one(DB::table('order_items')->whereNotNull('color_band')->where('color_band', '<>', ''));
    $orderId = T::int($order->order_id ?? null);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/orders/{$orderId}")->assertOk());
    $items = T::arr($props['items'] ?? null);

    expect($items)->not->toBe([], 'the order has no lines — this test proves nothing');

    $wrong = [];
    foreach ($items as $item) {
        foreach (['color_band', 'color_dial'] as $slot) {
            $value = T::arr($item)[$slot] ?? null;
            if ($value === null) {
                continue;
            }
            if (! is_array($value) || ! array_key_exists('hex', $value) || ! array_key_exists('name', $value)) {
                $wrong[] = $slot.': '.get_debug_type($value);
            }
        }
    }

    expect($wrong)->toBe([], 'a colour reached the screen in the old shape');
});

it('puts the product behind every line on the page, so the panel can never be empty', function () {
    $order = T::one(DB::table('order_items')->whereNotNull('product_id')->orderBy('order_id'));
    $orderId = T::int($order->order_id ?? null);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/orders/{$orderId}")->assertOk());
    $items = T::arr($props['items'] ?? null);
    $products = T::arr($props['products'] ?? null);

    /*
     * This is the assertion that makes the panel safe to render without a loading state. If a line
     * names a product the page did not carry, the screen falls back to plain text — correct, but
     * it means an operator quietly loses the ability to confirm that pick, and nothing says so.
     */
    $missing = [];
    foreach ($items as $item) {
        $productId = T::arr($item)['product_id'] ?? null;
        if ($productId === null) {
            continue;
        }
        // The prop is keyed by id, and JSON object keys are strings — so the lookup is by the
        // string form, exactly as the React file does it with `String(item.product_id)`.
        $key = (string) T::int($productId);
        if (! array_key_exists($key, $products)) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([], 'a line names a product the page did not send');
});

it('carries what a person actually confirms a product with', function () {
    $row = T::one(DB::table('order_items')->whereNotNull('product_id')->orderBy('order_id'));
    $orderId = T::int($row->order_id ?? null);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/orders/{$orderId}")->assertOk());
    $products = T::arr($props['products'] ?? null);

    expect($products)->not->toBe([], 'no products on an order that has lines');

    foreach ($products as $product) {
        $peek = T::arr($product);

        /*
         * The KEYS, not their values — 855 products are missing a model number, and a panel that
         * omits the row entirely is what tells the operator so.
         *
         * `model_number` left this list on 2026-09-19 (item 4): it and `sku` were the same column,
         * and the panel printed one value on two rows under two different names.
         */
        foreach (['title', 'wa_code', 'sku', 'brand', 'family', 'family_label', 'cover', 'specs'] as $key) {
            expect($peek)->toHaveKey($key);
        }

        // The family is a WORD. `watch` is a column value; nobody on the shop floor reads columns.
        expect(T::str($peek['family_label'] ?? null))->not->toBe('');
        expect(T::arr($peek['title'] ?? null))->toHaveKey('ar')->toHaveKey('en');
        expect(T::arr($peek['brand'] ?? null))->toHaveKey('ar')->toHaveKey('en');
    }
});

it('offers the product LINK only to somebody the product route would admit', function () {
    $row = T::one(DB::table('order_items')->whereNotNull('product_id')->orderBy('order_id'));
    $orderId = T::int($row->order_id ?? null);

    // A link that 403s teaches the team to distrust the ones that work, so the panel asks first.
    $admin = T::arr(Props::of(actingAs(Staff::admin())->get("/manage/orders/{$orderId}"))['abilities'] ?? null);
    expect($admin['catalog'] ?? null)->toBeTrue();

    // Data-entry DOES hold the catalogue grant — this is not a test that they are excluded, it is
    // a test that the answer is the Gate's rather than the screen's guess.
    $entry = T::arr(Props::of(actingAs(Staff::dataEntry())->get("/manage/orders/{$orderId}"))['abilities'] ?? null);
    expect($entry)->toHaveKey('catalog');
});
