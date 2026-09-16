<?php

use App\Domain\Catalog\PatchResult;
use App\Domain\Catalog\ProductPatcher;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

/*
 * The PARTIAL-update path (wave 4D) — the contract, not the happy path.
 *
 * ── What this file is the inverse of ─────────────────────────────────────────────────────────
 *
 * `PUT /manage/storefronts/{s}/products/{p}` is a full-REPLACE endpoint (§2.9.7): the payload IS
 * the row. A five-field payload PUT at a live product on 2026-09-12 took with it `grade_id`, the
 * watch specs, both descriptions, the sale price and every storefront-1 placement, and **nothing
 * on any screen showed a problem** — the compat harness caught it and a rebuild repaired it.
 * AGENTS §3 then forbade non-form callers from using that endpoint at all, which left importers
 * with no supported way to change a column.
 *
 * So the first test below is that defect, inverted and measured: snapshot EVERY column, patch one,
 * and assert that exactly one moved. Everything after it is one clause of the contract.
 */

/**
 * Every column of `catalog_products`, so "only what was named changed" can be asserted literally.
 *
 * @return array<string, mixed>
 */
function patchSnapshot(int $productId): array
{
    $row = T::row(DB::table('catalog_products')->where('id', $productId)->first());

    // `(array)` on a stdClass gives string keys by construction, but the analyser only knows
    // `array` — narrowed explicitly rather than cast, so level 10 stays clean without a suppression.
    $out = [];
    foreach ((array) $row as $column => $value) {
        $out[(string) $column] = $value;
    }

    return $out;
}

function patcher(): ProductPatcher
{
    return app(ProductPatcher::class);
}

function storedSale(int $productId): ?string
{
    $value = DB::table('catalog_products')->where('id', $productId)->value('sale_price');

    return $value === null ? null : T::str($value);
}

// ── clause 1: only what was named ────────────────────────────────────────────────────────────

it('changes ONLY the column it was given, and leaves every other one byte-identical', function () {
    $id = CatalogFixture::product(selling: 500.0);
    $before = patchSnapshot($id);

    $result = patcher()->patch($id, ['model_number' => 'PATCH-001']);

    $after = patchSnapshot($id);

    // `updated_at`/`updated_by` are bookkeeping the write owns; everything else must be untouched.
    $moved = [];
    foreach ($before as $column => $value) {
        if (in_array($column, ['updated_at', 'updated_by'], true)) {
            continue;
        }
        if (($after[$column] ?? null) !== $value) {
            $moved[] = $column;
        }
    }

    expect($moved)->toBe(['model_number'], 'a partial update moved a column it was not given: '.implode(', ', $moved))
        ->and($result->changedFields())->toBe(['model_number'])
        ->and($result->changes['model_number'])->toBe(['from' => null, 'to' => 'PATCH-001'])
        ->and($result->derived)->toBe([])
        ->and($result->isNoop())->toBeFalse();
});

it('leaves the translations, images and placements the full-replace path would have destroyed', function () {
    CatalogFixture::assumeSwitched();
    $id = CatalogFixture::product(selling: 500.0);
    $node = CatalogFixture::root('Patch Target');
    CatalogFixture::place($id, $node['id']);
    CatalogFixture::onStorefront($id);

    $translations = T::int(DB::table('catalog_product_translations')->where('product_id', $id)->count());
    $images = T::int(DB::table('catalog_product_images')->where('product_id', $id)->count());
    $placements = T::int(DB::table('storefront_category_product')->where('product_id', $id)->count());
    $arabic = DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('title');

    // The same shape of payload that caused the damage: a couple of scalar fields and nothing else.
    patcher()->patch($id, ['selling_price' => 650, 'wa_code' => 'patched-code']);

    expect(T::int(DB::table('catalog_product_translations')->where('product_id', $id)->count()))->toBe($translations)
        ->and(T::int(DB::table('catalog_product_images')->where('product_id', $id)->count()))->toBe($images)
        ->and(T::int(DB::table('storefront_category_product')->where('product_id', $id)->count()))->toBe($placements)
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('title'))->toBe($arabic);
});

// ── clause 2: the addressable set is declared ────────────────────────────────────────────────

it('refuses a field it does not declare, names it, and writes NOTHING', function () {
    $id = CatalogFixture::product();
    $before = patchSnapshot($id);

    // A mistyped sheet header. The dangerous outcome is not an error — it is a silent no-op that
    // reports success, so the refusal has to name the field.
    expect(fn () => patcher()->patch($id, ['selling_pirce' => 999, 'model_number' => 'SHOULD-NOT-LAND']))
        ->toThrow(RuntimeException::class, 'selling_pirce');

    expect(patchSnapshot($id))->toBe($before, 'a refused patch wrote something');
});

it('refuses a STOCK column by name — stock moves through InventoryService only', function () {
    $id = CatalogFixture::product();

    foreach (['stock_express', 'stock_market', 'in_stock'] as $column) {
        expect(fn () => patcher()->patch($id, [$column => 5]))
            ->toThrow(RuntimeException::class, $column);
    }

    // …and the columns really are untouched, which is the claim that matters (D-21).
    $row = T::row(DB::table('catalog_products')->where('id', $id)->first(['stock_express', 'stock_market', 'in_stock']));
    expect(Row::int($row, 'stock_express'))->toBe(0)
        ->and(Row::int($row, 'stock_market'))->toBe(0)
        ->and(Row::int($row, 'in_stock'))->toBe(0);
});

it('refuses `family` and `specs`, which are form decisions and not spreadsheet columns', function () {
    $id = CatalogFixture::product('fashion');

    foreach (['family' => 'watch', 'specs' => '{}'] as $field => $value) {
        expect(fn () => patcher()->patch($id, [$field => $value]))
            ->toThrow(RuntimeException::class, $field);
    }

    expect(T::str(DB::table('catalog_products')->where('id', $id)->value('family')))->toBe('fashion');
});

it('publishes the addressable list, so an importer can validate a HEADER before reading a row', function () {
    $fields = ProductPatcher::addressableFields();

    expect($fields)->toContain('selling_price', 'sale_price', 'wa_code', 'is_active', 'title.ar', 'title.en')
        // The three things that must never be addressable.
        ->and($fields)->not->toContain('stock_express')
        ->and($fields)->not->toContain('family')
        ->and($fields)->not->toContain('specs');
});

// ── clause 3: `_complete` is refused ─────────────────────────────────────────────────────────

it('refuses the full-REPLACE completeness marker outright', function () {
    $id = CatalogFixture::product();

    /*
     * `_complete=1` is the declaration the two full-replace endpoints require. A caller sending it
     * here has the two confused, and they have OPPOSITE semantics — there an omitted key is
     * cleared, here it is left alone. Guessing which they meant is how the 2026-09-12 damage
     * happened with a guard already in place.
     */
    expect(fn () => patcher()->patch($id, ['_complete' => 1, 'selling_price' => 100]))
        ->toThrow(RuntimeException::class, '_complete');
});

it('refuses an empty patch instead of reporting a successful no-op', function () {
    $id = CatalogFixture::product();

    // A sheet row that mapped to nothing means the mapping is wrong. "0 changed" would hide it.
    expect(fn () => patcher()->patch($id, []))->toThrow(RuntimeException::class, 'no fields');
});

// ── clause 4: the diff is against STORED values, so the same patch twice is a no-op ──────────

it('is idempotent: the same patch twice changes nothing the second time', function () {
    $id = CatalogFixture::product(selling: 500.0);

    $first = patcher()->patch($id, ['model_number' => 'IDEM-1', 'selling_price' => 777]);
    expect($first->changedCount())->toBe(2);

    $stamp = DB::table('catalog_products')->where('id', $id)->value('updated_at');

    $second = patcher()->patch($id, ['model_number' => 'IDEM-1', 'selling_price' => 777]);

    expect($second->isNoop())->toBeTrue()
        ->and($second->changes)->toBe([])
        /*
         * And `updated_at` did NOT move. A no-op that bumped the timestamp would move the compat
         * payload's `updated_at`, and the harness has no rule for that outside the commerce paths
         * (D-17) — a re-run of an import would read as 300 unexplained differences.
         */
        ->and(DB::table('catalog_products')->where('id', $id)->value('updated_at'))->toBe($stamp);
});

it('does not call `100` a change when the column already holds `100.00`', function () {
    $id = CatalogFixture::product(selling: 500.0);

    // The sheet says 500; the column says 500.00. Reporting that would make every import look
    // like it did something, and would break the idempotency claim above on the first re-run.
    $result = patcher()->patch($id, ['selling_price' => '500']);

    expect($result->isNoop())->toBeTrue();
});

// ── clause 5: the derived change, said out loud ──────────────────────────────────────────────

it('nulls a sale price that lowering the selling price invalidated, and reports it as DERIVED', function () {
    $id = CatalogFixture::product(selling: 500.0);
    patcher()->patch($id, ['sale_price' => 400]);
    expect(storedSale($id))->toBe('400.00');

    // The caller names ONLY the selling price. 400 is no longer a discount on 300.
    $result = patcher()->patch($id, ['selling_price' => 300]);

    expect($result->changedFields())->toEqualCanonicalizing(['selling_price', 'sale_price'])
        // The whole point: a column the caller never mentioned moved, and the report says so.
        ->and($result->derived)->toBe(['sale_price'])
        ->and($result->changes['sale_price'])->toBe(['from' => '400.00', 'to' => null])
        ->and(storedSale($id))->toBeNull()
        ->and($result->lines())->toContain('sale_price: 400.00 → — (derived)');
});

it('refuses an impossible sale price with a reason, and invents no change when none was stored', function () {
    $id = CatalogFixture::product(selling: 500.0);
    expect(storedSale($id))->toBeNull();

    // A sale ABOVE the selling price. Nothing was stored, so nothing changes — and the refusal
    // must still be reported, or a price import looks successful and moved nothing.
    $result = patcher()->patch($id, ['sale_price' => 600]);

    expect($result->changes)->toBe([], 'a null → null phantom change was reported')
        ->and($result->isNoop())->toBeTrue()
        ->and($result->ignored)->toHaveKey('sale_price')
        ->and($result->ignored['sale_price'])->toContain('refused')
        ->and(storedSale($id))->toBeNull();
});

it('replaces a stored sale with none when the new one is impossible, and says both things', function () {
    $id = CatalogFixture::product(selling: 500.0);
    patcher()->patch($id, ['sale_price' => 450]);

    $result = patcher()->patch($id, ['sale_price' => 600]);

    expect($result->changes['sale_price'])->toBe(['from' => '450.00', 'to' => null])
        // NOT derived: the caller named the column. `derived` must keep meaning "you did not ask".
        ->and($result->derived)->toBe([])
        ->and($result->ignored)->toHaveKey('sale_price')
        ->and(storedSale($id))->toBeNull();
});

it('keeps a sale price the contract accepts', function () {
    $id = CatalogFixture::product(selling: 500.0);

    $result = patcher()->patch($id, ['sale_price' => 399.5]);

    expect($result->ignored)->toBe([])
        ->and($result->derived)->toBe([])
        ->and(storedSale($id))->toBe('399.50');
});

// ── clause 6: it never creates ───────────────────────────────────────────────────────────────

it('refuses a product that does not exist instead of inserting one', function () {
    $before = T::int(DB::table('catalog_products')->count());

    expect(fn () => patcher()->patch(0, ['model_number' => 'GHOST']))
        ->toThrow(RuntimeException::class, 'never creates');

    expect(T::int(DB::table('catalog_products')->count()))->toBe($before);
});

it('WORKS with the write-switch flag in its blocked default, because a patch is an EDIT', function () {
    // Deliberately no `assumeSwitched()`. §2.23: the dashboard may EDIT a transform-output row
    // before the switch and may not CREATE one. An importer that only ever patches is therefore
    // allowed to run pre-switch — and its edits are REVERTED by switch night's rebuild, which is
    // the caveat the importer's report has to carry, not a reason to block it here.
    expect(config('transform.write_switch_completed'))->toBeFalse();

    $id = CatalogFixture::product();
    $result = patcher()->patch($id, ['model_number' => 'PRE-SWITCH']);

    expect($result->changedFields())->toBe(['model_number'])
        ->and(T::str(DB::table('catalog_products')->where('id', $id)->value('model_number')))->toBe('PRE-SWITCH');
});

// ── clause 7: translations, per locale, per column ───────────────────────────────────────────

it('patches one locale and leaves the other alone', function () {
    $id = CatalogFixture::product();
    $arabic = DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('title');

    $result = patcher()->patch($id, ['title.en' => 'Patched English title']);

    expect($result->changedFields())->toBe(['title.en'])
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('title'))->toBe('Patched English title')
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('title'))->toBe($arabic);
});

it('refuses to empty the ARABIC title, because fallback is off and the product would vanish', function () {
    $id = CatalogFixture::product();

    expect(fn () => patcher()->patch($id, ['title.ar' => '']))
        ->toThrow(RuntimeException::class, 'العنوان العربي');

    expect(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('title'))->not->toBeNull();
});

it('refuses to bring a locale into existence without its title', function () {
    $id = CatalogFixture::product();
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->delete();

    // `title` is NOT NULL. Refusing with the reason beats a database error in an import report.
    expect(fn () => patcher()->patch($id, ['short_description.en' => 'No title anywhere']))
        ->toThrow(RuntimeException::class, 'title.en');

    expect(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->exists())->toBeFalse();
});

it('creates a missing locale when the patch carries its title', function () {
    $id = CatalogFixture::product();
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->delete();

    $result = patcher()->patch($id, ['title.en' => 'Brought back', 'short_description.en' => 'With a body']);

    expect($result->changedFields())->toEqualCanonicalizing(['title.en', 'short_description.en'])
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('title'))->toBe('Brought back');
});

// ── clause 8: the derived work a change implies ──────────────────────────────────────────────

it('pushes a price change out to every storefront row', function () {
    CatalogFixture::assumeSwitched();
    $id = CatalogFixture::product(selling: 500.0);
    CatalogFixture::onStorefront($id);

    expect(T::str(DB::table('storefront_product')->where('product_id', $id)->value('effective_price')))->toBe('500.00');

    patcher()->patch($id, ['selling_price' => 880]);

    /*
     * `effective_price` is a maintained mirror of the catalog price while the override gate is off
     * (§2.4, D3). A patch that skipped this would leave the storefront selling at yesterday's
     * price — invisible on every screen, and caught only by a customer or the harness.
     */
    expect(T::str(DB::table('storefront_product')->where('product_id', $id)->value('effective_price')))->toBe('880.00');
});

/** The index body for one product, both locales joined — the text a search actually matches. */
function patchIndexBody(int $productId): string
{
    $joined = '';
    foreach (DB::table('catalog_product_search')->where('product_id', $productId)->pluck('body') as $value) {
        $joined .= T::str($value).' ';
    }

    return $joined;
}

it('re-indexes when a field the INDEXER reads changes', function () {
    $id = CatalogFixture::product();

    /*
     * `model_number` and `search_keywords`, not `wa_code`: the assertion is against what
     * `ProductIndexer` really puts in `catalog_product_search.body`. Writing this test against a
     * field the indexer ignores is what caught the guessed `SEARCHABLE` list in the patcher.
     */
    patcher()->patch($id, ['model_number' => 'FINDABLE-42', 'search_keywords' => 'زجاج سفير']);

    expect(patchIndexBody($id))->toContain('FINDABLE-42')->toContain('زجاج سفير');
});

it('re-indexes when the BRAND changes, because the brand NAME is in the index', function () {
    $id = CatalogFixture::product();
    $current = T::int(DB::table('catalog_products')->where('id', $id)->value('brand_id'));

    $other = DB::table('catalog_brands')->where('id', '!=', $current)->orderBy('id')->value('id');
    if ($other === null) {
        // Derived from the data, never assumed: with one brand there is nothing to move to.
        expect(T::int(DB::table('catalog_brands')->count()))->toBe(1);

        return;
    }
    $otherId = T::int($other);
    $otherName = T::str(DB::table('catalog_brand_translations')->where('brand_id', $otherId)->where('locale', 'en')->value('name'));

    patcher()->patch($id, ['brand_id' => $otherId]);

    expect(patchIndexBody($id))->toContain($otherName);
});

it('re-indexes when a TITLE changes', function () {
    $id = CatalogFixture::product();

    patcher()->patch($id, ['title.en' => 'Sapphire Reindexed Watch']);

    expect(patchIndexBody($id))->toContain('Sapphire Reindexed Watch');
});

// ── the report an importer prints ────────────────────────────────────────────────────────────

it('produces an operator-readable report of exactly what moved', function () {
    $id = CatalogFixture::product(selling: 500.0);

    $result = patcher()->patch($id, [
        'model_number' => 'REPORT-1',
        'selling_price' => 620,
        'title.en' => 'Reported title',
    ]);

    expect($result->lines())->toEqualCanonicalizing([
        'model_number: — → REPORT-1',
        'selling_price: 500.00 → 620.00',
        'title.en: Wave 4B test product → Reported title',
    ])
        ->and($result->toArray()['noop'])->toBeFalse();
});

it('reports a no-op as a no-op, with nothing in it', function () {
    $id = CatalogFixture::product();
    $result = new PatchResult;

    expect($result->isNoop())->toBeTrue()
        ->and($result->lines())->toBe([])
        ->and($result->changedCount())->toBe(0)
        ->and($id)->toBeGreaterThan(0);
});
