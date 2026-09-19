<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * A request must leave the transaction stack exactly as it found it (2026-10-05).
 *
 * ── The bug this comes from ─────────────────────────────────────────────────────────────────
 *
 * `PromotionController::preview()` opened one transaction and, on the refusal path, rolled back
 * TWICE: once in the `catch` and once more in a `finally` guarded by `transactionLevel() > 0`. In
 * production that guard reads correctly — after the catch the level is 0, so the second rollback is
 * skipped. It is wrong the moment anything is already open around the call: the catch unwinds the
 * method's own savepoint, and the `finally` then rolls back the CALLER's transaction.
 *
 * The whole suite runs inside exactly such a transaction (`DatabaseTransactions`), so a refused
 * preview drops the suite out of it.
 *
 * ── What that actually costs, measured (2026-10-05) ─────────────────────────────────────────
 *
 * Reproduced with a scratch probe, the bug reintroduced deliberately:
 *
 *   • after the refused preview `DB::transactionLevel()` is 0 — proven;
 *   • a write in the SAME test after that point survives the run permanently — proven, a marker
 *     row was still in the database afterwards;
 *   • a write in a LATER test does NOT survive — also proven; `DatabaseTransactions` opens a
 *     fresh transaction per test, so the next test is protected.
 *
 * The damage is therefore bounded to the offending test. An earlier draft of this note claimed it
 * reached every later test; it does not. It is still worth failing loudly, because the write is
 * permanent, silent, and lands in a database that has no test-only copy.
 *
 * Found by asserting `DB::transactionLevel() >= 1` after every test, which named the culprit on
 * the first run. This file is the permanent version of that check, aimed at the endpoints that
 * manage their own transactions — cheap, and it fails AT the offender.
 */

/** The write endpoints that call `DB::beginTransaction()` themselves. */
it('leaves the transaction stack where it found it — promotion preview, refusal path', function () {
    actingAs(Staff::admin());
    $before = DB::transactionLevel();

    $buy = PromotionFixture::giftableProduct();

    // The refusal path: an unbounded reward, which is what the double rollback lived on.
    postJson('/manage/promotions/preview', [
        'storefront_id' => 1,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $buy, 'quantity' => 1]],
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '1.00']],
        'rewards' => [['type' => 'free_product', 'product_id' => $buy, 'quantity' => 9999]],
    ])->assertStatus(422);

    expect(DB::transactionLevel())->toBe(
        $before,
        'the preview changed the transaction depth — in the suite that means anything this test '
        .'writes from here on is permanent, and in production it means an outer transaction was '
        .'rolled back by a method that did not open it'
    );
});

it('leaves the transaction stack where it found it — promotion preview, success path', function () {
    actingAs(Staff::admin());
    $before = DB::transactionLevel();

    $buy = PromotionFixture::giftableProduct();

    // The other exit: a draft that evaluates. Same requirement, different branch.
    postJson('/manage/promotions/preview', [
        'storefront_id' => 1,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $buy, 'quantity' => 1]],
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '1.00']],
        'rewards' => [['type' => 'percentage', 'value' => '10']],
    ]);

    expect(DB::transactionLevel())->toBe($before, 'the preview changed the transaction depth on its success path');
});

it('names every controller that manages its own transaction, so each one gets a check above', function () {
    /*
     * A grep, for the same reason `ProductNameSeamTest` is one: the next person to write
     * `DB::beginTransaction()` in a controller will not read this file, and the failure mode is
     * invisible until an unrelated test breaks two hours later.
     *
     * When this fails, either add the endpoint to the list AND give it a depth check above, or
     * — better — let the framework manage the transaction with `DB::transaction(fn () => …)`,
     * which cannot be unbalanced.
     */
    $expected = [
        'CheckoutCompatController.php',
        'PromotionController.php',
    ];

    $found = [];
    foreach (glob(app_path('Http/Controllers/**/*.php')) ?: [] as $path) {
        $source = (string) file_get_contents($path);
        if (str_contains($source, 'DB::beginTransaction()')) {
            $found[] = basename($path);
        }
    }

    sort($found);
    sort($expected);

    expect($found)->toBe($expected, "A controller manages its own transaction and is not covered here.\n"
        ."Either use `DB::transaction(fn () => …)`, which cannot be unbalanced, or add a depth check\n"
        .'to this file for every exit path the endpoint has.');
});
