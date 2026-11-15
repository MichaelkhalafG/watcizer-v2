<?php

use App\Domain\Content\QualityGate;
use App\Domain\Content\SitePaths;

/*
 * THE AUTOMATED QUALITY GATE (SEO Phases 2 and 4, 2026-11-14). A page text that meets every rule passes in both
 * languages; each kind of fault holds it with a reason a person can act on.
 */

/**
 * A page text that meets every rule: intro, 5 sections of prose with numbers, 4 questions with full answers, a meta description.
 *
 * @return array{intro: string|null, description: string|null, meta_description: string|null}
 */
function qgText(string $locale, string $extra = '', int $guideSentences = 60): array
{
    $en = $locale === 'en';
    $sentence = fn (int $i, int $j): string => $en
        ? "In part {$i} we look at point {$j}, which matters to buyers comparing ".($i * 7 + $j)." options in our two stores."
        : "في الجزء {$i} نتناول النقطة {$j} التي تهم من يقارن بين ".($i * 7 + $j).' خيارا في متجرينا بالقاهرة.';
    $body = '';
    $per = intdiv($guideSentences, 5);
    for ($s = 1; $s <= 5; $s++) {
        $body .= ($en ? "## Part {$s} of the guide\n\n" : "## الجزء {$s} من الدليل\n\n");
        for ($j = 1; $j <= $per; $j++) {
            $body .= $sentence($s, $j).' ';
        }
        $body .= "\n\n";
    }
    $body .= $extra."\n\n";                                       // a case's extra words, before the questions
    $body .= $en ? "## Questions and answers\n\n" : "## الأسئلة والأجوبة\n\n";
    for ($q = 1; $q <= 4; $q++) {
        $body .= ($en ? "### Question number {$q} about this page?\n" : "### السؤال رقم {$q} عن هذه الصفحة؟\n");
        $body .= str_repeat($sentence(9, $q).' ', 4)."\n\n";
    }
    $intro = trim(str_repeat($sentence(0, 1).' ', 4));

    return ['intro' => $intro, 'description' => $body,'meta_description' => $en ? 'A short, honest description of this page.' : 'وصف قصير وصادق لهذه الصفحة.'];
}

/**
 * @param  array{intro: string|null, description: string|null, meta_description: string|null}|null  $en
 * @param  array{intro: string|null, description: string|null, meta_description: string|null}|null  $ar
 * @return array{en: array{passed: bool, reasons: list<string>, stats: array<string, int>}, ar: array{passed: bool, reasons: list<string>, stats: array<string, int>}}
 */
function qgCheck(?array $en, ?array $ar): array
{
    SitePaths::forget();

    return app(QualityGate::class)->checkPage(2, 'landing', 'qg-test/men', $en, $ar, []);
}

it('passes a text that meets every rule, in both languages', function () {
    $r = qgCheck(qgText('en'), qgText('ar'));
    expect($r['en']['reasons'])->toBe([])->and($r['ar']['reasons'])->toBe([])
        ->and($r['en']['passed'])->toBeTrue()->and($r['ar']['passed'])->toBeTrue()
        ->and($r['en']['stats']['guide_words'])->toBeGreaterThanOrEqual(800);
});

it('holds a text for each kind of fault, with the reason', function () {
    $held = fn (string $extraEn, string $needle) => expect(implode(' | ', qgCheck(qgText('en', $extraEn), qgText('ar'))['en']['reasons']))->toContain($needle);

    $held("\n\nIn today's fast-paced world, a watch says a lot.", 'banned phrase');
    // an authorized-dealer claim for a brand the shop has not switched on (Michael, 2026-11-14: per brand, never blanket)
    $held("\n\nWe are an authorized dealer of every brand here.", 'authorized-dealer claim');
    $held("\n\nOrders over EGP 10,000 ship with free shipping on every order.", 'never make');
    $held("\n\nYou can return any item within 30 days.", 'return window');
    $held("\n\nDelivery takes 48 hours to most governorates.", 'delivery time');
    $held("\n\nThe ZZ98765 is our favourite model.", 'model number');
    $held("\n\nSee [this brand](/brand/no-such-brand-anywhere).", 'does not have');
    $held("\n\nRead more on [a blog](https://example.com/post).", 'outside the approved sources');
    $held("\n\nThe brand was founded in 1985 in New York.", 'no Sources section');
    expect(qgCheck(qgText('en', '', 30), qgText('ar'))['en']['reasons'][0] ?? '')->toContain('the guide is');
    expect(qgCheck(null, qgText('ar'))['en']['reasons'])->toBe(['no text in this language']);
});

it('accepts an external fact cited to an approved source, and holds English and Arabic that name different amounts', function () {
    $cited = "\n\nThe brand was founded in 1985 in New York.\n\n## Sources\n\n- [The brand's history](https://www.britannica.com/topic/example)\n";
    expect(qgCheck(qgText('en', $cited), qgText('ar', "\n\nتأسست الماركة عام ١٩٨٥.\n\n## المصادر\n\n- [المصدر](https://www.britannica.com/topic/example)\n"))['en']['reasons'])->toBe([]);

    $r = qgCheck(qgText('en', "\n\nMost cost about 2,500 a piece."), qgText('ar', "\n\nيبلغ سعر أغلبها نحو ٣٬٥٠٠ للقطعة."));
    expect(implode(' | ', $r['en']['reasons']))->toContain('English and Arabic name different')
        ->and($r['ar']['passed'])->toBeFalse();
});
