<?php

use App\Domain\Import\BrandFashion\LegacyImporter;
use App\Domain\Import\BrandFashion\LegacyRedirects;
use App\Domain\Import\BrandFashion\LegacySpecs;
use App\Domain\Import\BrandFashion\LegacyText;

/*
 * The cleaning rules of the Brand Fashion legacy import (2026-11-11), each against a shape that is
 * really in the old site's database (`bf-legacy.sql`, 9 Nov) — the inputs are copied from it.
 */

it('decodes HTML entities, once or twice encoded', function (): void {
    expect(LegacyText::title('Dolce &amp; Gabbana K EDP For Men 100ml'))->toBe('Dolce & Gabbana K EDP For Men 100ml')
        ->and(LegacyText::title('Men&#8217;s Watch'))->toBe('Men’s Watch')
        ->and(LegacyText::title('Men&amp;#8217;s Watch'))->toBe('Men’s Watch');
});

it('removes the WordPress copy marker from titles and slugs, and nothing else', function (): void {
    expect(LegacyText::title('EMPORIO ARMANI Men\'s Water Resistant Chronograph Watch AR5988 Copy'))
        ->toBe('EMPORIO ARMANI Men\'s Water Resistant Chronograph Watch AR5988')
        ->and(LegacyText::slug('emporio-armanimens-water-resistant-chronograph-watch-ar5988-2-copy'))
        ->toBe('emporio-armanimens-water-resistant-chronograph-watch-ar5988-2')
        ->and(LegacyText::slug('hugo-boss-watch-copy-2'))->toBe('hugo-boss-watch')
        ->and(LegacyText::slug('copywriter-pen'))->toBe('copywriter-pen')
        ->and(LegacyText::wasCopy('Fossil ME3062', 'fossilmens-townsman-copy'))->toBeTrue()
        ->and(LegacyText::wasCopy('Fossil ME3062', 'fossilmens-townsman'))->toBeFalse();
});

it('removes wrapping quotes from a typed title', function (): void {
    expect(LegacyText::title('"GUESS Women\'s Leather Shoes With Logo Guess GUS027"'))
        ->toBe('GUESS Women\'s Leather Shoes With Logo Guess GUS027');
});

it('turns descriptive HTML into plain paragraphs', function (): void {
    $html = '<p>First&nbsp;line<br>second line</p>[vc_row]<ul><li>One</li><li>Two</li></ul><script>x()</script><h3>Care</h3>Keep dry';

    // A list's end and a block end meet as ONE blank line — a paragraph break, never two or more.
    expect(LegacyText::paragraphs($html))->toBe("First line\nsecond line\n• One\n• Two\n\nCare\nKeep dry")
        ->and(LegacyText::hadMarkup($html))->toBeTrue()
        ->and(LegacyText::hadMarkup('plain text'))->toBeFalse()
        ->and(LegacyText::paragraphs(''))->toBe('');
});

it('derives the short description from the long one, across line breaks, never inventing words', function (): void {
    expect(LegacyText::shortFrom('Skeleton dial details keep this phoenix on the rise. Featuring a white barrel shaped case.'))
        ->toBe('Skeleton dial details keep this phoenix on the rise.')
        ->and(LegacyText::shortFrom("OverviewWater\nResistance- 5 ATM, Case Depth- 10.00mm"))
        ->toBe('OverviewWater. Resistance- 5 ATM, Case Depth- 10.00mm.')
        ->and(LegacyText::shortFrom("Highlights\nCrafted with mineral glass\nWater resistant"))
        ->toBe('Crafted with mineral glass.')                       // its first sentence
        ->and(LegacyText::shortFrom('Highlights'))->toBe('Highlights.')        // a heading ALONE is all there is: kept
        ->and(LegacyText::shortFrom("يسلط الضوء\nزجاج معدني مقاوم للخدش"))->toBe('زجاج معدني مقاوم للخدش.')
        ->and(LegacyText::shortFrom('تفاصيل القرص الهيكلي تحافظ على طائر الفينيق هذا في الارتفاع. تتميز بعلبة بيضاء.'))
        ->toBe('تفاصيل القرص الهيكلي تحافظ على طائر الفينيق هذا في الارتفاع.')
        ->and(LegacyText::shortFrom(''))->toBe('');

    $long = str_repeat('word ', 80);
    $short = LegacyText::shortFrom($long);
    expect(mb_strlen($short))->toBeLessThanOrEqual(LegacyText::SHORT_MAX + 1)
        ->and(str_ends_with($short, '…'))->toBeTrue()
        ->and(str_starts_with($long, rtrim($short, '…')))->toBeTrue();
});

it('builds identity keys that ignore case, spacing and punctuation — and only those', function (): void {
    expect(LegacyText::skuKey('  gw 0203g2 '))->toBe('GW 0203G2')
        ->and(LegacyText::skuKey(''))->toBeNull()
        ->and(LegacyText::skuKey('Ar 5988'))->not->toBe(LegacyText::skuKey('AR5988'))
        ->and(LegacyText::nameKey('Men’s Watch Copy'))->toBe(LegacyText::nameKey("Men's   watch"))
        ->and(LegacyText::nameKey('Watch 40'))->not->toBe(LegacyText::nameKey('Watch 41'));
});

it('matches a brand whatever its case, spacing or accents, and reads the confirmed aliases', function (): void {
    expect(LegacyImporter::canonicalBrandKey('HERMES'))->toBe(LegacyImporter::brandKeyOf('Hermès'))
        ->and(LegacyImporter::canonicalBrandKey('Dolce &amp; Gabbana'))->toBe(LegacyImporter::brandKeyOf('Dolce & Gabbana'))
        ->and(LegacyImporter::canonicalBrandKey('AP'))->toBe(LegacyImporter::brandKeyOf('Audemars Piguet'))
        ->and(LegacyImporter::canonicalBrandKey('patek philip'))->toBe(LegacyImporter::brandKeyOf('Patek Philippe'))
        ->and(LegacyImporter::canonicalBrandKey('Guess'))->not->toBe(LegacyImporter::brandKeyOf('Gucci'));
});

it('maps only the spec labels whose meaning is not in doubt', function (): void {
    expect(LegacySpecs::forLabel("Dial Colour\t"))->toBe([LegacySpecs::COLOUR, 'dial', ''])
        ->and(LegacySpecs::forLabel('Band Material'))->toBe([LegacySpecs::LOOKUP, 'band_material_id', 'materials'])
        ->and(LegacySpecs::forLabel('Lens Size'))->toBeNull()
        ->and(LegacySpecs::forLabel('field_62d5e19580c76'))->toBeNull();
});

it('reads a measure only with a unit core has', function (): void {
    expect(LegacySpecs::measure('43 Mm'))->toBe([43.0, 'mm'])
        ->and(LegacySpecs::measure('48 millimeters'))->toBe([48.0, 'mm'])
        ->and(LegacySpecs::measure('5 ATM'))->toBe([5.0, 'ATM'])
        ->and(LegacySpecs::measure('50 M'))->toBe([50.0, LegacySpecs::METRES])   // metres: the D-19 unit (water resistance only)
        ->and(LegacySpecs::measure('-- Mm'))->toBeNull()
        ->and(LegacySpecs::volume('100 ml'))->toBe(100)
        ->and(LegacySpecs::volume('100ml EDP'))->toBeNull()
        ->and(LegacySpecs::gender(' Men'))->toBe('Men')
        ->and(LegacySpecs::gender('For Woman'))->toBe('Women')
        ->and(LegacySpecs::gender('Kids'))->toBeNull();
});

it('slugs a category name exactly as the Brand Fashion app does (toSlug, src/utils/slugs.js)', function (): void {
    expect(LegacyRedirects::appSlug('Chargers & Cables'))->toBe('chargers-cables')
        ->and(LegacyRedirects::appSlug('Earphones & Headphones'))->toBe('earphones-headphones')
        ->and(LegacyRedirects::appSlug('Men’s Watches'))->toBe('mens-watches')
        ->and(LegacyRedirects::appSlug('  --Shop  by material-- '))->toBe('shop-by-material')
        ->and(LegacyRedirects::appSlug('Quartz'))->toBe('quartz')
        ->and(LegacyRedirects::appSlug('ساعات'))->toBe('');
});
