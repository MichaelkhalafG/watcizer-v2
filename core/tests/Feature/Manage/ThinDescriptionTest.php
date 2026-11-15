<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * THE THIN-DESCRIPTION FLAG (SEO Phase 1, 2026-11-14): the products list's `filters[flag]=thin_description` selects a
 * product whose short + long description, tags removed, is under `catalog.thin_description_chars` (300) characters in
 * English OR in Arabic. Derived from the text, so it clears itself once the description is written.
 */

/**
 * The ids the list answers for a search term, with the flag.
 *
 * @return list<int>
 */
function thinFlagged(string $term): array
{
    $props = Props::of(
        actingAs(Staff::admin())
            ->get('/manage/storefronts/1/products?filters[flag]=thin_description&filters[scope]=all&per_page=100&search='.urlencode($term))
            ->assertOk()
    );
    $ids = [];
    foreach (T::arr(T::arr($props['table'] ?? null)['data'] ?? null) as $row) {
        $ids[] = T::int(T::arr($row)['id'] ?? null);
    }

    return $ids;
}

it('flags a description under the line in either language, never one that is long enough in both', function () {
    $token = 'THINDESC'.strtoupper(bin2hex(random_bytes(3)));
    $long = str_repeat('A full sentence about the watch, its case, its dial and its strap. ', 6);   // ~400 characters
    $longAr = str_repeat('جملة كاملة عن الساعة وعلبتها وميناها وسوارها. ', 8);

    $rich = CatalogFixture::product('watch');
    $thinAr = CatalogFixture::product('watch');
    $tags = CatalogFixture::product('watch');
    $set = function (int $id, string $en, string $ar, string $suffix) use ($token): void {
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')
            ->update(['title' => "{$token} {$suffix}", 'short_description' => null, 'long_description' => $en]);
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')
            ->update(['short_description' => null, 'long_description' => $ar]);
    };
    $set($rich, $long, $longAr, 'rich');
    $set($thinAr, $long, 'وصف قصير', 'thin-ar');
    // markup is not words: 400 characters of tags around a short text are still thin
    $set($tags, '<p class="'.str_repeat('x', 300).'">Short.</p>', $longAr, 'tags');

    $ids = thinFlagged($token);
    expect($ids)->toContain($thinAr)
        ->and($ids)->toContain($tags)
        ->and($ids)->not->toContain($rich);
});
