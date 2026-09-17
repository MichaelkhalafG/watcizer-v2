<?php

use App\Support\ArabicSearch;

/*
 * The fixtures are the UX review's own measurements (A-UX-1, 2026-09-17), which is the point: each
 * row below is a word somebody typed into the real dashboard and got nothing back for, next to how
 * many products were actually there.
 */

it('finds the word however the ending is spelled — ta-marbuta and ha are the same word', function () {
    // `ساعه` returned 0 against 4,674 watches. Both spellings must reduce to one key.
    expect(ArabicSearch::normalise('ساعه'))->toBe(ArabicSearch::normalise('ساعة'));

    // …and the other two the review measured, at 770 and 1,239 products.
    expect(ArabicSearch::normalise('حقيبه'))->toBe(ArabicSearch::normalise('حقيبة'));
    expect(ArabicSearch::normalise('نظاره'))->toBe(ArabicSearch::normalise('نظارة'));
});

it('treats a hamza-carrying alef as the alef people actually type', function () {
    // `اسود` found 1 of 506: the catalogue holds `أسود`, the operator types `اسود`.
    expect(ArabicSearch::normalise('اسود'))->toBe(ArabicSearch::normalise('أسود'));

    foreach (['إسود', 'آسود', 'ٱسود'] as $variant) {
        expect(ArabicSearch::normalise($variant))->toBe(ArabicSearch::normalise('اسود'), "[{$variant}] should fold to bare alef");
    }
});

it('treats alef maqsura and ya as the same letter', function () {
    // `الرجالى` returned 0 against 13.
    expect(ArabicSearch::normalise('الرجالى'))->toBe(ArabicSearch::normalise('الرجالي'));
});

it('ignores tatweel, which is decoration rather than a letter', function () {
    // `سـاعة` returned 0 against 4,674 — one stretching character between two letters.
    expect(ArabicSearch::normalise('سـ__ـاعة'))->toBe(ArabicSearch::normalise('س__اعه'))
        ->and(ArabicSearch::normalise('سـاعة'))->toBe(ArabicSearch::normalise('ساعه'));
});

it('ignores diacritics, so a vowelled title and a bare one are one key', function () {
    expect(ArabicSearch::normalise('سَاعَة'))->toBe(ArabicSearch::normalise('ساعه'));
});

it('folds the remaining hamza carriers', function () {
    expect(ArabicSearch::normalise('مسؤول'))->toBe(ArabicSearch::normalise('مسوول'))
        ->and(ArabicSearch::normalise('قائمة'))->toBe(ArabicSearch::normalise('قايمه'));
});

it('leaves English completely alone, byte for byte', function () {
    /*
     * The catalogue is roughly half English, and its FULLTEXT behaviour must not move at all because
     * of an Arabic fix. A Latin string takes the early return and is not rewritten.
     */
    foreach ([
        'Tommy Hilfiger Watch For Men 1791594',
        'SEIKO Men\'s Hand Watch ASTRON Stainless Steel Band, Blue Dial SSE043J1',
        'Calvin Klein K50K509252-BAX',
        '',
    ] as $english) {
        expect(ArabicSearch::normalise($english))->toBe($english);
    }
});

it('leaves the Arabic letters that carry meaning where they are', function () {
    /*
     * The fold must not be a blunt instrument. These pairs are DIFFERENT words, and collapsing them
     * would make the search find the wrong product — worse than finding none, because nobody checks.
     */
    expect(ArabicSearch::normalise('جلد'))->not->toBe(ArabicSearch::normalise('جلة'))
        ->and(ArabicSearch::normalise('رجل'))->not->toBe(ArabicSearch::normalise('رجال'))
        ->and(ArabicSearch::normalise('ذهب'))->not->toBe(ArabicSearch::normalise('دهب'));
});

it('is idempotent, because the index is rebuilt on every edit', function () {
    // `ProductIndexer` re-runs on every save and the transform re-runs whole. A normaliser that
    // changed its own output would make each pass produce a different row.
    foreach (['ساعة رجالي أسود', 'سـاعَة', 'Tommy Hilfiger'] as $text) {
        $once = ArabicSearch::normalise($text);
        expect(ArabicSearch::normalise($once))->toBe($once, "[{$text}] is not stable under a second pass");
    }
});

it('keeps word boundaries, so FULLTEXT still tokenises the same number of words', function () {
    /*
     * The index is word-based InnoDB FULLTEXT with a fixed 3-character minimum (AGENTS §2.3). If the
     * fold merged or split words, terms would silently drop under that floor.
     */
    $before = 'ساعة رجالي أسود من الجلد';
    $after = ArabicSearch::normalise($before);

    $wordsBefore = explode(' ', $before);
    $wordsAfter = explode(' ', $after);

    expect($wordsAfter)->toHaveCount(count($wordsBefore), 'the fold merged or split a word');

    /*
     * The assertion is RELATIVE, and the first draft of it was wrong in a way worth keeping a note
     * about: it demanded every word be at least 3 characters, and failed on `من` — a two-letter word
     * that was already under the floor before normalisation and that this class never touched. The
     * invariant is not "every word is searchable", which was never true; it is "normalising does not
     * make a searchable word unsearchable".
     */
    foreach ($wordsBefore as $i => $word) {
        if (mb_strlen($word) >= 3) {
            expect(mb_strlen($wordsAfter[$i]))->toBeGreaterThanOrEqual(
                3,
                "[{$word}] was searchable and [{$wordsAfter[$i]}] is not",
            );
        }
    }
});
