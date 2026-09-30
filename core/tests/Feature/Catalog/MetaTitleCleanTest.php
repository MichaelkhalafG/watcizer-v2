<?php

use App\Compat\CompatServices;
use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\MetaText;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * S-AR stage 2 (2026-10-01): product meta titles without the marketplace junk. About half the
 * English meta titles end in " | Select…" or a tail cut off mid-word; `MetaText::title()` drops the
 * last " | " segment when it ends in an ellipsis, `catalog:clean-meta-titles` applies that to the
 * stored rows (dry run first), and `catalog/product` serves the cleaned meta to the product page.
 */

it('drops a last " | " segment that ends in an ellipsis, and nothing else', function () {
    $cases = [
        'Hugo Boss Watch For Men 1513755 | Select…' => 'Hugo Boss Watch For Men 1513755',
        'Emporio Armani Watch For Men AR2448 — Emporio Armani | Ch...' => 'Emporio Armani Watch For Men AR2448 — Emporio Armani',
        'Emporio Armani Watch For Women AR1925 — Emporio Armani | ...' => 'Emporio Armani Watch For Women AR1925 — Emporio Armani',
        'Guess Watch | Se… | S…' => 'Guess Watch',
        'Casio Edifice | Diver' => 'Casio Edifice | Diver',          // ends in a word: chosen, kept
        'Plain title' => 'Plain title',
        "  Two\n lines  " => 'Two lines',
        ' | Select…' => null,
        '' => null,
    ];
    $wrong = [];
    foreach ($cases as $in => $want) {
        $got = MetaText::title((string) $in);
        if ($got !== $want) {
            $wrong[] = json_encode([$in, $want, $got], JSON_UNESCAPED_UNICODE);
        }
    }
    expect($wrong)->toBe([])
        ->and(MetaText::title(null))->toBeNull()
        // A title copied already cut off is kept in the data but never shown.
        ->and(MetaText::usableTitle("Tommy Hilfiger Men's Quartz Stainless Steel and Bracelet .."))->toBeNull()
        ->and(MetaText::usableTitle("Chronograph Men's Watch 1792157 White..."))->toBeNull()
        ->and(MetaText::usableTitle('Hugo Boss Watch For Men 1513755 | Select…'))->toBe('Hugo Boss Watch For Men 1513755')
        ->and(MetaText::description("<p>Steel\ncase</p>"))->toBe('Steel case');
});

it('catalog:clean-meta-titles writes nothing without --apply, then cleans, logs and keeps real titles', function () {
    $ids = T::arr(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->limit(2)->pluck('id')->all());
    [$dirty, $clean] = [T::int($ids[0]), T::int($ids[1])];
    DB::table('catalog_product_translations')->where('product_id', $dirty)->where('locale', 'en')->update(['meta_title' => 'Junk Test Watch 123 | Select…']);
    DB::table('catalog_product_translations')->where('product_id', $clean)->where('locale', 'en')->update(['meta_title' => 'Real Test Watch | Diver']);
    $metaOf = fn (int $id): string => T::str(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('meta_title'));

    Artisan::call('catalog:clean-meta-titles');
    expect(Artisan::output())->toContain('Would clean', '"Junk Test Watch 123 | Select…" → "Junk Test Watch 123"')
        ->and($metaOf($dirty))->toBe('Junk Test Watch 123 | Select…');

    Artisan::call('catalog:clean-meta-titles', ['--apply' => true]);
    expect($metaOf($dirty))->toBe('Junk Test Watch 123')
        ->and($metaOf($clean))->toBe('Real Test Watch | Diver');

    $log = T::row(DB::table(ActivityLog::TABLE)->where('subject_type', 'catalog_products')->where('subject_id', $dirty)->orderByDesc('id')->first());
    expect(T::str($log->subject_label))->toBe('meta title: junk tail removed');

    // A second run finds nothing left to do.
    Artisan::call('catalog:clean-meta-titles');
    expect(Artisan::output())->not->toContain('Junk Test Watch');
});

it('serves the product page its cleaned meta per language, null where none was written', function () {
    $listing = app(CompatServices::class)->listing;
    $id = $listing->entries()[0]['id'];
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['meta_title' => 'Served Watch 9 | Select…', 'meta_description' => "Line one\nline two"]);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->update(['meta_title' => null, 'meta_description' => null]);

    expect($listing->productMeta($id))->toBe([
        'title' => ['en' => 'Served Watch 9', 'ar' => null],
        'description' => ['en' => 'Line one line two', 'ar' => null],
    ]);
});
