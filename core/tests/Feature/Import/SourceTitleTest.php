<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\BrandResolver;
use App\Domain\Import\SourceTitle;

/*
 * The two source-data defects the wave-4D import surfaced, fixed at the one point that fixes them
 * for every reader (2026-09-14).
 *
 * Measured on the real export before the fix:
 *   • 60 titles with a glued word; 55 of them imported with the brand `Generic`
 *   • 13 titles carrying a raw `&amp;`
 *
 * The half of this file that matters most is the LEAVE-ALONE half. A normaliser that fixes 60
 * titles and quietly breaks 600 is a worse defect than the one it replaced, so the cases it must
 * not touch are asserted beside the ones it must.
 */

it('unglues an ALL-CAPS brand from the word after it', function () {
    // The exact strings from the export, not invented ones.
    expect(SourceTitle::clean("EMPORIO ARMANIMen's Stainless Steel Analog Watch Ar11352"))
        ->toBe("EMPORIO ARMANI Men's Stainless Steel Analog Watch Ar11352");

    expect(SourceTitle::clean("ALBAWomen's Stainless Steel Analog Watch AN8068X"))
        ->toBe("ALBA Women's Stainless Steel Analog Watch AN8068X");

    // Same defect, nothing to do with brands — which is why the rule does not consult a brand list.
    expect(SourceTitle::clean('USBCable 2m'))->toBe('USB Cable 2m')
        ->and(SourceTitle::clean('XLShirt Black'))->toBe('XL Shirt Black');
});

it('leaves alone every shape that is NOT the defect', function () {
    $untouched = [
        // Already spaced — the overwhelming majority of the file.
        "Emporio Armani Men's Sport Silver Watch AR6091",
        // One capital before the lowercase run: not two, so not the pattern.
        'IPhone 15 Case',
        // Capitals with no lowercase after them: a model code, not a glued word.
        'BUNDLE BIG SALE MK4222-MKW1',
        'Generic Men Sunglasses inspired by RAYBAN Sn852',
        // Arabic, which has no letter case at all.
        'ساعة أنالوج ستانلس ستيل',
        // An all-caps word at the very end has nothing after it to be glued to.
        'Watch For Men AR11078',
    ];

    foreach ($untouched as $title) {
        expect(SourceTitle::clean($title))->toBe($title, "[{$title}] was changed and should not have been");
    }
});

it('decodes the HTML entities the export writes, including a double encoding', function () {
    expect(SourceTitle::clean('Cap inspired By DOLCE&amp;GABBANA cap131'))
        ->toBe('Cap inspired By DOLCE&GABBANA cap131');

    // Woo double-encodes on some paths; one pass would leave `&amp;` on screen.
    expect(SourceTitle::clean('A &amp;amp; B'))->toBe('A & B')
        ->and(SourceTitle::clean('Tom &quot;Ford&quot;'))->toBe('Tom "Ford"');
});

it('collapses the double spaces the source also has', function () {
    // `Maserati  Watch For Men R8873612009` — two spaces, in the real file.
    expect(SourceTitle::clean('Maserati  Watch For Men R8873612009'))
        ->toBe('Maserati Watch For Men R8873612009')
        ->and(SourceTitle::clean("  padded  \n title "))->toBe('padded title');
});

it('does the two fixes in the order that makes both work', function () {
    /*
     * Entities FIRST. A decoded entity can expose a glued word behind it; un-gluing first would
     * leave `&amp;` sitting between two words and the second fix would never see the seam.
     */
    expect(SourceTitle::clean('DOLCE&amp;GABBANAMen\'s Bag'))
        ->toBe("DOLCE&GABBANA Men's Bag");
});

it('RECOVERS the brand that the glued title was hiding', function () {
    /*
     * The point of the whole change, asserted end to end against the real matcher rather than
     * against the string. Before this, `ARMANIMen's` contained no word `ARMANI` and 55 products
     * took the `Generic` fallback with their real brand in plain sight.
     */
    $resolver = app(BrandResolver::class);

    $raw = "EMPORIO ARMANIMen's Stainless Steel Analog Watch Ar11352";
    $cleaned = SourceTitle::clean($raw);

    /*
     * The UNMATCHED path falls back to the `Generic` brand, and creating a lookup row is refused
     * before the write-switch — so this needs the same scoped exemption `--allow-preswitch` opens
     * for the real run. It passed without one only because an earlier import had left a `Generic`
     * brand behind; the clean rebuild removed it and the test started asserting the gate instead of
     * the brand. Faithful to production, and no longer dependent on residue.
     */
    [$fromRaw, $fromCleaned] = PreSwitch::allowing(
        ['lookup'],
        fn (): array => [$resolver->forTitle($raw), $resolver->forTitle($cleaned)],
    );

    // `forTitle()` answers `{id, matched, name}` — `matched` is the honest flag, and it was FALSE
    // for these 55 rows, which is how they ended up on the `Generic` fallback.
    expect($fromRaw['matched'])->toBeFalse('the glued title already matched, so there was nothing to fix')
        ->and($fromCleaned['matched'])->toBeTrue('the cleaned title still does not find its brand')
        ->and(mb_strtolower($fromCleaned['name']))->toContain('armani');
});
