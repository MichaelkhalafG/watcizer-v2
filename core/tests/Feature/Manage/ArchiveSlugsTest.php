<?php

use App\Console\Commands\ArchiveSlugsCommand;

/*
 * The slug taken out of an archived URL (item 7, 2026-09-18).
 *
 * ── Why this one function gets its own file ─────────────────────────────────────────────────
 *
 * Everything else the command does is counting, and the counts are reported. This is the only part
 * that DECIDES something, and it decides it 7,087 times without anybody reading the result.
 *
 * Both directions matter and the second matters more. Extracting the wrong slug puts a product at
 * the wrong URL; refusing to extract one leaves it at the derived slug, which is where it already
 * is. So the rule is deliberately narrow — an ASCII slug, or nothing — because a slug this command
 * cannot vouch for is worse than the one already in place.
 */

it('takes the last path segment of a WordPress product URL', function () {
    expect(ArchiveSlugsCommand::slugFromUrl('https://www.brandfashionegy.com/ysl-perfume-original/'))
        ->toBe('ysl-perfume-original');

    // Without the trailing slash, which the crawl records for some rows.
    expect(ArchiveSlugsCommand::slugFromUrl('https://www.brandfashionegy.com/ysl-perfume-original'))
        ->toBe('ysl-perfume-original');

    // A deeper path: WordPress serves products at the root here, but `/product/<slug>/` is the
    // other common shape and the LAST segment is the slug in both.
    expect(ArchiveSlugsCommand::slugFromUrl('https://www.brandfashionegy.com/product/mk-6268/'))
        ->toBe('mk-6268');
});

it('refuses anything it cannot vouch for, rather than writing a guess', function () {
    $refused = [
        // No path at all.
        'https://www.brandfashionegy.com',
        'https://www.brandfashionegy.com/',
        // An encoded Arabic title — a real shape on this site, and not a slug we should install.
        'https://www.brandfashionegy.com/%D8%B3%D8%A7%D8%B9%D8%A9-%D9%86%D8%B3%D8%A7%D8%A6%D9%8A/',
        // Upper case and underscores are not what the storefront serves.
        'https://www.brandfashionegy.com/YSL-Perfume/',
        'https://www.brandfashionegy.com/ysl_perfume/',
        // A stray double dash or a leading/trailing dash is a malformed slug, not a tidy one.
        'https://www.brandfashionegy.com/ysl--perfume/',
        'https://www.brandfashionegy.com/-ysl-perfume/',
        'https://www.brandfashionegy.com/ysl-perfume-/',
    ];

    $accepted = [];
    foreach ($refused as $url) {
        $slug = ArchiveSlugsCommand::slugFromUrl($url);
        if ($slug !== null) {
            $accepted[] = $url.' => '.$slug;
        }
    }

    expect($accepted)->toBe([], 'a URL this command cannot vouch for produced a slug anyway');
});

it('accepts the shapes the live site actually used', function () {
    // Digits, single dashes, and a mix — everything brandfashionegy.com served.
    $cases = [
        'https://www.brandfashionegy.com/18216-2/' => '18216-2',
        'https://www.brandfashionegy.com/mk6268/' => 'mk6268',
        'https://www.brandfashionegy.com/calvin-klein-wallet-1119/' => 'calvin-klein-wallet-1119',
    ];

    $wrong = [];
    foreach ($cases as $url => $expected) {
        $slug = ArchiveSlugsCommand::slugFromUrl($url);
        if ($slug !== $expected) {
            $wrong[] = $url.' => '.var_export($slug, true).', wanted '.$expected;
        }
    }

    expect($wrong)->toBe([]);
});
