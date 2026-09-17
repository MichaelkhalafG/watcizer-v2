<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * A-BUG-2 — a bulk action containing a stale id returned HTTP 500 after writing part of the batch.
 *
 * ── What was measured ───────────────────────────────────────────────────────────────────────
 *
 * `ProductController::bulk()` looped with no transaction, and `currentPayload()` throws for an id
 * that is not in the table. Ids `[real, 99999999, real]` produced a 500 with the FIRST product
 * already deactivated and the third untouched — and the operator, looking at a crash page, had no
 * way to know which. The only way to find out was to go and check every row they had selected.
 *
 * It needs nothing exotic to happen: a tab left open while a colleague archives a product is enough.
 *
 * ── The two halves, which fail differently ──────────────────────────────────────────────────
 *
 * Skipping the unknown ids stops the common case throwing. The TRANSACTION is for everything else —
 * a writer refusal, a deadlock, a constraint — so that a batch which cannot finish leaves the
 * catalogue exactly as it was. Both are asserted below, because a fix with only the skip would pass
 * the first test and still corrupt a batch on the second kind of failure.
 */

/**
 * Three live products, ids in order.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function bulkTrio(): array
{
    return [CatalogFixture::product(), CatalogFixture::product(), CatalogFixture::product()];
}

it('applies the real ids and SKIPS a stale one, instead of crashing half way', function () {
    CatalogFixture::assumeSwitched();
    [$first, $second, $third] = bulkTrio();

    foreach ([$first, $second, $third] as $id) {
        DB::table('catalog_products')->where('id', $id)->update(['is_active' => 1]);
    }

    // The review's exact shape: a real id, a stale one, a real one.
    $response = actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'deactivate',
        'ids' => [$first, 99999999, $third],
    ]);

    $response->assertRedirect();
    expect($response->getStatusCode())->toBeLessThan(500);

    // Both real products applied — including the THIRD, which the crash used to leave untouched.
    expect(T::int(DB::table('catalog_products')->where('id', $first)->value('is_active')))->toBe(0)
        ->and(T::int(DB::table('catalog_products')->where('id', $third)->value('is_active')))->toBe(0)
        // …and one not named in the request is not collateral.
        ->and(T::int(DB::table('catalog_products')->where('id', $second)->value('is_active')))->toBe(1);
});

it('TELLS the operator that ids were skipped, so 23-of-25 is not read as a bug', function () {
    /*
     * The number on the screen has to explain itself. An operator who selected three and is told
     * "done on 2" with no reason will either re-run the batch or report the screen as broken.
     */
    CatalogFixture::assumeSwitched();
    [$first, , $third] = bulkTrio();

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'deactivate',
        'ids' => [$first, 99999999, $third],
    ])->assertRedirect();

    $status = T::str(session('status'));

    // The count that was applied, and the count that was not, in one sentence.
    expect($status)->toContain('2')
        ->and($status)->toContain('1')
        // Arabic, like every other operator message — and it says WHY, not just that something went.
        ->and($status)->toContain('لم يعد موجودًا');
});

it('leaves the catalogue untouched when the batch cannot finish — no partial write', function () {
    /*
     * THE half the skip does not cover. A refusal that is not a missing id still has to be
     * all-or-nothing, and the way to prove it is to make the SECOND product refuse: the first has
     * already been written inside the transaction when it happens.
     *
     * The refusal used is a REAL writer rule rather than a mock: the middle product loses its Arabic
     * translation row, so `ProductWriter::writeTranslations()` throws "العنوان العربي مطلوب" — the
     * fallback is off, and a product with no Arabic cannot be rendered. It throws from exactly where
     * any other writer rule would.
     *
     * (The first draft shut the pre-switch gate instead. That proves nothing here: the gate refuses
     * CREATE, and editing is deliberately open before the switch — the screens say so.)
     */
    CatalogFixture::assumeSwitched();
    [$first, $second, $third] = bulkTrio();

    foreach ([$first, $second, $third] as $id) {
        DB::table('catalog_products')->where('id', $id)->update(['is_active' => 1]);
    }

    // The middle product becomes unsaveable, so the batch dies AFTER the first has been written.
    DB::table('catalog_product_translations')->where('product_id', $second)->where('locale', 'ar')->delete();

    $before = DB::table('catalog_products')->whereIn('id', [$first, $second, $third])
        ->orderBy('id')->pluck('is_active', 'id')->all();

    $response = actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'deactivate',
        'ids' => [$first, $second, $third],
    ]);

    // It fails — correctly, because one of the three cannot be saved. What matters is the state it
    // left behind, not the status code.
    expect($response->getStatusCode())->toBeGreaterThanOrEqual(300);

    $after = DB::table('catalog_products')->whereIn('id', [$first, $second, $third])
        ->orderBy('id')->pluck('is_active', 'id')->all();

    expect($after)->toBe($before, 'a batch that could not finish must leave nothing half-applied');
});

it('still applies a clean batch with no stale ids, so the guard is not refusing good work', function () {
    CatalogFixture::assumeSwitched();
    [$first, $second, $third] = bulkTrio();

    foreach ([$first, $second, $third] as $id) {
        DB::table('catalog_products')->where('id', $id)->update(['is_active' => 1]);
    }

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'deactivate',
        'ids' => [$first, $second, $third],
    ])->assertRedirect();

    expect(T::int(DB::table('catalog_products')->whereIn('id', [$first, $second, $third])->where('is_active', 0)->count()))
        ->toBe(3)
        // …and the status says three, with no skipped sentence appended.
        ->and(T::str(session('status')))->not->toContain('لم يعد موجودًا');
});
