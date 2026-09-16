<?php

use App\Domain\Import\ExistingCatalogue;
use App\Domain\Import\SourceRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * The gate that stops the importer re-adding a product Watchizer already sells (2026-09-14).
 *
 * ── What this file is really defending ──────────────────────────────────────────────────────
 *
 * Not "does it find duplicates" — the measurement already answered that (43 of 2 255, every sample
 * a true pair). What needs a permanent test is the OTHER direction: the rule must not match two
 * different products. A missed duplicate is one row a person deletes; a wrong match silently drops
 * a product the shop meant to sell, and nobody looks for what is not there.
 *
 * So most of this file is refusals.
 */

/** The rule, exercised through the real class against the real catalogue. */
function gate(): ExistingCatalogue
{
    return app(ExistingCatalogue::class);
}

/** A source row carrying just the fields the gate reads. */
function sourceRow(string $title, ?string $sku = null): SourceRow
{
    return new SourceRow(
        ref: 'probe:'.md5($title.(string) $sku),
        titleEn: $title,
        sku: $sku,
        modelNumber: null,
        description: null,
        sellingPrice: 100.0,
        salePrice: null,
        purchasePrice: 0.0,
        stock: 1,
        categoryPaths: [],
        genders: [],
        materials: [],
        family: 'watch',
        imageUrl: null,
        isVisible: true,
    );
}

// ── the token rule, in isolation ─────────────────────────────────────────────────────────────

it('accepts only model-number-shaped tokens', function () {
    // Long enough, and carries a digit.
    expect(ExistingCatalogue::tokens('Tommy Hilfiger 1791400'))->toContain('1791400')
        ->and(ExistingCatalogue::tokens('Emporio Armani AR11472'))->toContain('AR11472')
        // `Ar 0389` and `AR0389` are the same code written two ways.
        ->and(ExistingCatalogue::tokens('EMPORIO ARMANI Ar 0389 watch'))->toContain('AR0389');
});

it('REFUSES the shapes that would collide with ordinary title text', function () {
    $refused = [
        'Watch 2024 edition' => '2024',          // a year
        'Case 40mm steel' => '40MM',             // a measurement, 4 chars
        'Gold 18K plated' => '18K',              // too short
        'Set of 2PCS' => '2PCS',                 // 4 chars
        'Stainless Steel Analog Watch' => 'STAINLESS',  // no digit
    ];

    foreach ($refused as $title => $token) {
        expect(ExistingCatalogue::tokens($title))->not->toContain($token, "[{$token}] should not be a candidate");
    }
});

it('adds a digit-run as an EXTRA candidate and never replaces the whole token', function () {
    /*
     * `Men1791711` — a word glued to the code, the source defect the brand fix also deals with.
     * Splitting instead of adding would have destroyed `BAGQ93378` and `HWESG951320`, which are
     * genuinely three letters and then digits.
     */
    $tokens = ExistingCatalogue::tokens('Tommy Hilfiger Men1791711');

    expect($tokens)->toContain('MEN1791711')->toContain('1791711');

    // …and a real in-house code keeps its whole form as a candidate.
    expect(ExistingCatalogue::tokens('code BAGQ93378 here'))->toContain('BAGQ93378');
});

it('normalises a code the same way however it was written', function () {
    expect(ExistingCatalogue::normalise('Ar 0389'))->toBe('AR0389')
        ->and(ExistingCatalogue::normalise('ar-0389'))->toBe('AR0389')
        ->and(ExistingCatalogue::normalise('MK 3353'))->toBe('MK3353')
        ->and(ExistingCatalogue::normalise(null))->toBe('');
});

// ── the gate against the real catalogue ──────────────────────────────────────────────────────

it('matches an existing product by its SKU', function () {
    $existing = T::one(DB::table('catalog_products')
        ->whereNull('import_ref')->whereNull('deleted_at')
        ->whereNotNull('sku')->where('sku', '<>', '')
        ->whereRaw('CHAR_LENGTH(sku) >= 5'));

    $match = T::arr(gate()->match(sourceRow('Some Watch', T::str($existing->sku))));

    expect($match['id'])->toBe(T::int($existing->id))
        ->and($match['rule'])->toBe('sku');
});

it('matches an existing product by the model number in its TITLE', function () {
    // The developer's own pair: the imported row had no SKU and the code was in the name.
    $existing = T::one(DB::table('catalog_products')
        ->whereNull('import_ref')->whereNull('deleted_at')
        ->whereNotNull('sku')->whereRaw('CHAR_LENGTH(sku) >= 5'));

    $code = T::str($existing->sku);
    $match = T::arr(gate()->match(sourceRow("Tommy HIlfiger Watch For Men {$code}", null)));

    expect($match['id'])->toBe(T::int($existing->id))
        ->and($match['rule'])->toBe('title-model')
        ->and($match['code'])->toBe(ExistingCatalogue::normalise($code))
        // The report needs BOTH codes, so the match carries the existing row's identity.
        ->and($match['wa_code'])->not->toBe('');
});

it('matches NOTHING for a product the catalogue does not have', function () {
    expect(gate()->match(sourceRow('Generic Sunglasses inspired by Lacoste', 'SN99999XYZ')))->toBeNull()
        ->and(gate()->match(sourceRow('Leather Handbag Black')))->toBeNull()
        // A code-shaped token that belongs to nobody.
        ->and(gate()->match(sourceRow('Watch ZZ99887766')))->toBeNull();
});

it('refuses a title that points at TWO different products', function () {
    $two = T::many(DB::table('catalog_products')
        ->whereNull('import_ref')->whereNull('deleted_at')
        ->whereNotNull('sku')->whereRaw('CHAR_LENGTH(sku) >= 5')
        ->limit(2));

    if (count($two) < 2) {
        expect(true)->toBeTrue('fewer than two coded products in this database');

        return;
    }

    // Both codes in one name. We do not know which product it is, so we do not guess.
    $title = 'Bundle '.T::str($two[0]->sku).' and '.T::str($two[1]->sku);

    expect(gate()->match(sourceRow($title)))->toBeNull();
});

it('never matches a product the IMPORTER created', function () {
    /*
     * `import_ref IS NULL` is the whole definition of "already ours". Without it, the second run
     * of a file would report every row as a duplicate of the row the first run made — and
     * `import_ref` is what already handles that case, properly.
     */
    $imported = DB::table('catalog_products')->whereNotNull('import_ref')
        ->whereNotNull('sku')->where('sku', '<>', '')->first(['id', 'sku']);

    if ($imported === null) {
        expect(true)->toBeTrue('no imported products in this database');

        return;
    }

    $match = gate()->match(sourceRow('Anything', T::str($imported->sku)));

    expect($match === null || T::arr($match)['id'] !== T::int($imported->id))->toBeTrue(
        'the gate matched a row the importer itself created'
    );
});
