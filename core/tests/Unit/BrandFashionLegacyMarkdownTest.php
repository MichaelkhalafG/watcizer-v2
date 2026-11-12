<?php

use App\Domain\Import\BrandFashion\LegacyMarkdown;

/*
 * The old posts' HTML → the shop's Markdown (D-13, 2026-11-11), against shapes copied from the old
 * site's articles: WordPress stores bodies without <p>, headings inline, links to the old shop and
 * images as size variants.
 */

$keep = fn (string $href): string => $href;
$ours = fn (string $src, string $alt): string => '/Uploads_Images/Banner_home/x.webp';

it('turns headings, line breaks, emphasis and lists into Markdown the shop renders', function () use ($keep, $ours) {
    $html = "<h1>Top 5 Wallet Trends</h1>\nIn the world of fashion, wallets matter.\n\n<h2>\n1. Statement Hardware</h2>\n<strong>Bold</strong> and <em>soft</em>.<ul><li>One</li><li>Two</li></ul>";

    expect(LegacyMarkdown::fromHtml($html, $keep, $ours))->toBe(
        "## Top 5 Wallet Trends\n\nIn the world of fashion, wallets matter.\n\n## 1. Statement Hardware\n\n**Bold** and *soft*.\n\n- One\n- Two"
    );
});

it('rewrites links through the mapper, and keeps only the words when it answers nothing', function () use ($ours) {
    $map = fn (string $href): string => str_contains($href, 'brandfashionegy.com') ? '/category/wallets' : '';
    $html = 'See <a href="https://brandfashionegy.com/product-category/men/men-wallets-men/">our wallets</a> or <a href="javascript:x()">this</a>.';

    expect(LegacyMarkdown::fromHtml($html, $map, $ours))->toBe('See [our wallets](/category/wallets) or this.');
});

it('replaces an old image by ours, and drops one the mapper refuses', function () use ($keep) {
    $html = '<img src="https://www.brandfashionegy.com/wp-content/uploads/2024/10/bs15reo-1024x683.jpg" alt="A bag"> <img src="https://ads.example/pixel.gif">';
    $map = fn (string $src, string $alt): string => str_contains($src, '/wp-content/uploads/') ? '/Uploads_Images/Banner_home/b.webp' : '';

    expect(LegacyMarkdown::fromHtml($html, $keep, $map))->toBe('![A bag](/Uploads_Images/Banner_home/b.webp)');
});

it('drops scripts, keeps Markdown characters as text, and reads an image size variant back to its original', function () use ($keep, $ours) {
    expect(LegacyMarkdown::fromHtml('Price *only* [today]<script>alert(1)</script>', $keep, $ours))->toBe('Price \*only\* \[today\]')
        ->and(LegacyMarkdown::uploadFiles('<img src="/wp-content/uploads/2024/10/bs15reo-1024x683.jpg"><a href="https://x/wp-content/uploads/2024/09/Bag97reo-5.jpg">'))
        ->toBe(['2024/10/bs15reo.jpg', '2024/09/Bag97reo-5.jpg'])
        ->and(LegacyMarkdown::fromHtml('', $keep, $ours))->toBe('');
});
