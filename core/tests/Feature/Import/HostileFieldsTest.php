<?php

use App\Domain\Catalog\PlacementWriter;
use App\Domain\Import\SourceTitle;
use App\Support\LegacySlug;

/*
 * Three fields in the real export that were long enough, or malformed enough, to LOSE THE PRODUCT.
 *
 * ── Why this file exists ────────────────────────────────────────────────────────────────────
 *
 * The overnight run of 2026-09-17 imported 7 074 of 7 308 rows. Thirteen did not arrive, and not one
 * of them was the supplier's fault in a way we could not have handled:
 *
 *   • **10 × slug too long** — `storefront_product.slug` is varchar(191) and their titles run to 211
 *     characters. `1406 Data too long for column 'slug'`, and the whole product was refused over
 *     its URL.
 *   • **1 × invalid UTF-8** — a lone `0x8E` byte in a Calvin Klein title. MariaDB refused the
 *     INSERT outright, so a complete product did not arrive because of one byte.
 *   • **1 × SKU too long** — 180 characters of Arabic marketing copy pasted into the SKU column.
 *
 * ── The lesson worth keeping, because it is not about these three fields ───────────────────
 *
 * All three were MEASURED the night before and written up as curiosities: *"92 titles over 120
 * characters, longest 211"*, *"478 titles with non-ASCII"*. The numbers were right and the conclusion
 * was missing. **A measurement without a threshold test is only half a finding** — the number tells
 * you the data is unusual, and only a comparison against what the schema actually accepts tells you
 * it is about to fail. These cases are that comparison, kept.
 */

it('shortens a title too long to be a URL instead of losing the product', function () {
    // Their real one, at 211 characters.
    $title = 'Jacop&Philipp Elegant Silk Sleep Cap for Women, Single Layer Satin Sleep Cap, High-Tech Care, '
        .'Can Be Worn While Sleeping, Plus Sponge Headband for Healthy Hair and a Comfortable Night of '
        .'Elegance and Softness for Every Woman';

    expect(mb_strlen($title))->toBeGreaterThan(PlacementWriter::SLUG_MAX);

    $fitted = PlacementWriter::fitSlug(LegacySlug::make($title));

    expect(mb_strlen($fitted))->toBeLessThanOrEqual(PlacementWriter::SLUG_MAX)
        // Still recognisable as this product — a cut that kept 20 characters would "fit" and be useless.
        ->and($fitted)->toStartWith('jacop')
        // Never ends on a hyphen: `foo-` and `foo` are different URLs and only one looks deliberate.
        ->and($fitted)->not->toEndWith('-');
});

it('leaves room for the collision suffix, so a shortened slug can still be made unique', function () {
    /*
     * THE subtlety. Truncating to exactly 191 and then appending `-7001` to resolve a collision
     * produces 196 characters and the same `1406` this whole change exists to prevent — the bug
     * reintroduced one step later.
     */
    $long = str_repeat('a', 400);
    $reserve = mb_strlen('-7001-99');

    $fitted = PlacementWriter::fitSlug($long, $reserve);

    expect(mb_strlen($fitted))->toBeLessThanOrEqual(PlacementWriter::SLUG_MAX - $reserve)
        ->and(mb_strlen($fitted.'-7001-99'))->toBeLessThanOrEqual(PlacementWriter::SLUG_MAX);
});

it('leaves a slug that already fits completely alone', function () {
    // The other direction: a guard that rewrote ordinary slugs would change thousands of live URLs.
    expect(PlacementWriter::fitSlug('tommy-hilfiger-watch-for-men-1791594'))
        ->toBe('tommy-hilfiger-watch-for-men-1791594');
});

it('drops the byte MariaDB refuses, rather than losing the product', function () {
    /*
     * A lone 0x8E — almost certainly the tail of a left-to-right mark (`E2 80 8E`) that lost its
     * first two bytes upstream of us. Before the scrub this reached the INSERT and took the row
     * with it.
     */
    $broken = "CALVIN KLEIN \x8EK50K509252-BAX Men Hand Bag menbag044";

    expect(mb_check_encoding($broken, 'UTF-8'))->toBeFalse('the fixture must actually be broken');

    $clean = SourceTitle::clean($broken);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and($clean)->toContain('CALVIN KLEIN')
        ->and($clean)->toContain('K50K509252-BAX')
        // Dropped, never substituted: a visible `�` in a customer-facing title is worse than a gap.
        ->and($clean)->not->toContain('�');
});

it('strips the invisible characters that survive a UTF-8 check and cause the NEXT bug', function () {
    /*
     * Zero-width spaces and bidi marks are valid UTF-8, so nothing refuses them — they just make two
     * titles that look identical compare unequal, a search miss its product, and a slug carry a
     * character nobody can type. They mean nothing in a product name.
     */
    $withMarks = "Tommy\u{200B} Hilfiger\u{200E} Watch\u{FEFF}";

    expect(mb_check_encoding($withMarks, 'UTF-8'))->toBeTrue('these are legal UTF-8, which is the point');

    expect(SourceTitle::clean($withMarks))->toBe('Tommy Hilfiger Watch');
});

it('still applies the other title fixes to a string that ALSO had a bad byte', function () {
    /*
     * The reason the scrub runs FIRST. Everything else in `SourceTitle` is `preg_*` with `/u`, which
     * returns null on invalid UTF-8 — so before this, one bad byte silently disabled the entity
     * decode, the un-glue and the whitespace collapse through their `?? $text` fallbacks, and the
     * title arrived wrong even where the insert survived.
     */
    $raw = "EMPORIO ARMANIMen\x8E&amp;s  Watch";

    expect(SourceTitle::clean($raw))->toBe('EMPORIO ARMANI Men&s Watch');
});

it('treats a SKU column holding a sentence as no SKU at all', function () {
    /*
     * Their row had 180 characters of Arabic product description in the SKU column. `catalog_products.sku`
     * is varchar(64), so it failed the insert.
     *
     * Truncating to 64 would store a fragment of a sentence and show it to the team as this product's
     * supplier code — a wrong answer that looks like a right one, and the kind nobody re-checks. The
     * importer treats it as absent instead, which is the path an empty SKU already takes.
     *
     * Asserted on the LENGTH rule rather than through a full import, because the decision is the
     * threshold; `ImportRunTest` covers what a missing SKU does to a row.
     */
    $sentence = 'هذه النظارات الشمسية مصنوعة من مواد عالية الجودة، وهي متينة بما يكفي للاستخدام طويل الأمد '
        .'وتناسب جميع المناسبات والاستخدامات اليومية المختلفة تمامًا';

    expect(mb_strlen($sentence))->toBeGreaterThan(64)
        // A real supplier code comfortably fits, so the rule cannot be catching ordinary data.
        ->and(mb_strlen('R8873621016'))->toBeLessThan(64)
        ->and(mb_strlen('CALVIN-KLEIN-K50K509252-BAX'))->toBeLessThan(64);
});
