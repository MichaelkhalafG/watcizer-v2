<?php

use App\Compat\Diff\DeviationRules;
use App\Compat\Diff\JsonDiff;
use App\Compat\Diff\XmlDiff;

/*
 * The compat-diff engine must catch a real difference and absorb only what a rule names.
 */

it('finds value, type, missing, extra, order and key-order differences', function () {
    $legacy = ['a' => 1, 'b' => 'x', 'list' => [['id' => 1, 'v' => 1], ['id' => 2, 'v' => 2]], 'obj' => ['k' => null]];
    $compat = ['b' => 'y', 'a' => 1, 'list' => [['id' => 2, 'v' => 2], ['id' => 3, 'v' => 3]], 'obj' => ['k' => 'now']];
    $kinds = array_map(fn (array $f) => $f['kind'].'@'.$f['path'], JsonDiff::compare($legacy, $compat));

    expect($kinds)->toContain('key_order@$')->toContain('value@$.b')->toContain('missing_in_compat@$.list[0]')->toContain('extra_in_compat@$.list[1]')->toContain('type@$.obj.k');
});

it('keys translation rows by locale so synthetic ids surface as id diffs, not as missing rows', function () {
    $legacy = [['id' => 7, 'locale' => 'ar', 'name' => 'x'], ['id' => 8, 'locale' => 'en', 'name' => 'y']];
    $compat = [['id' => 1, 'locale' => 'en', 'name' => 'y'], ['id' => 2, 'locale' => 'ar', 'name' => 'x']];
    $findings = JsonDiff::compare(['translations' => $legacy], ['translations' => $compat]);
    $kinds = array_map(fn (array $f) => $f['kind'].'@'.JsonDiff::normalise($f['path']), $findings);

    expect($kinds)->toBe(['order@$.translations', 'value@$.translations[*].id', 'value@$.translations[*].id']);
    foreach ($findings as $f) {
        expect(DeviationRules::match('all_product', $f))->toBeIn(['D-02', 'D-03']);
    }
});

it('absorbs only listed deviations and fails anything else', function () {
    expect(DeviationRules::match('all_product', ['path' => '$[3].percentage_discount', 'kind' => 'value', 'legacy' => '20.80', 'compat' => '21.00']))->toBe('D-01')
        ->and(DeviationRules::match('all_product', ['path' => '$[3].selling_price', 'kind' => 'value', 'legacy' => '1', 'compat' => '2']))->toBeNull()
        ->and(DeviationRules::match('meta', ['path' => '$.tables.subTypes[4]', 'kind' => 'missing_in_compat', 'legacy' => 'id=5', 'compat' => null]))->toBe('D-04')
        ->and(DeviationRules::match('meta', ['path' => '$.tables.brands[4]', 'kind' => 'missing_in_compat', 'legacy' => 'id=5', 'compat' => null]))->toBeNull()
        ->and(DeviationRules::match('product:5', ['path' => '$.related[2].slug', 'kind' => 'value', 'legacy' => 'a', 'compat' => 'b']))->toBe('D-06')
        ->and(DeviationRules::match('product:5', ['path' => '$.related[2].price', 'kind' => 'value', 'legacy' => 1, 'compat' => 2]))->toBeNull()
        ->and(DeviationRules::match('meta', ['path' => '$.tables.colors[18].color_value', 'kind' => 'value', 'legacy' => '#ff80c0', 'compat' => '#FF80C0']))->toBe('D-07')
        ->and(DeviationRules::match('meta', ['path' => '$.tables.colors[18].color_value', 'kind' => 'value', 'legacy' => '#ff80c0', 'compat' => '#000000']))->toBeNull()
        ->and(DeviationRules::match('gone:all_brand', ['path' => 'status', 'kind' => 'value', 'legacy' => 200, 'compat' => 410]))->toBe('D-08')
        ->and(DeviationRules::match('all_product', ['path' => 'status', 'kind' => 'value', 'legacy' => 200, 'compat' => 410]))->toBeNull();
});

it('matches path patterns literally on array indices', function () {
    expect(DeviationRules::pathMatches('$[*].is_cover', '$[*].is_cover'))->toBeTrue()
        ->and(DeviationRules::pathMatches('$[*].is_cover', '$[*].is_covered'))->toBeFalse()
        ->and(DeviationRules::pathMatches('**translations[*].id', '$[*].gender[*].translations[*].id'))->toBeTrue()
        ->and(DeviationRules::pathMatches('**translations', '$.tables.brands[*].translations'))->toBeTrue()
        ->and(DeviationRules::pathMatches('**translations', '$.tables.brands[*].translations[*].id'))->toBeFalse()
        ->and(DeviationRules::pathMatches('url:/subtypes/*', 'url:/subtypes/diver'))->toBeTrue()
        ->and(DeviationRules::pathMatches('url:/subtypes/*', 'url:/product/diver'))->toBeFalse();
});

it('diffs sitemaps by url block and keeps twin urls apart', function () {
    $block = fn (string $loc, string $extra = '') => "    <url>\n        <loc>{$loc}</loc>{$extra}\n    </url>";
    $legacy = "<?xml?>\n<urlset>\n".$block('https://x/product/a').$block('https://x/product/a').$block('https://x/subtypes/diver')."\n</urlset>";
    $compat = "<?xml?>\n<urlset>\n".$block('https://x/product/a').$block('https://x/product/a', "\n        <lastmod>1</lastmod>")."\n</urlset>";
    $findings = XmlDiff::compare($legacy, $compat);
    $paths = array_map(fn (array $f) => $f['kind'].'@'.$f['path'], $findings);

    expect($paths)->toContain('value@url:/product/a #2')->toContain('missing_in_compat@url:/subtypes/diver');
    expect(DeviationRules::match('sitemap:en', $findings[1]))->toBe('D-04');
});
