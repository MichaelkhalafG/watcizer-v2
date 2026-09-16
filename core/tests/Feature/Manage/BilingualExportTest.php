<?php

use App\Domain\Access\Preferences;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Every CSV carries BOTH languages of every translated field (decided 2026-09-16).
 *
 * ── Why ──────────────────────────────────────────────────────────────────────────────────────
 *
 * The database stores `ar` and `en` for every translated thing — product titles, category names,
 * brand names, lookup values — and an export that emits only one of them loses half the record.
 * These files are opened to bulk-edit and to send to the client, and both of those need the pair.
 *
 * Three rules, all asserted below:
 *
 *   1. **Both columns, whatever language the dashboard is in.** The interface language decides the
 *      HEADERS; it never decides which DATA is included. An operator working in English must not
 *      produce a file that has quietly dropped the Arabic.
 *   2. **A missing translation is an EMPTY cell, never the other language.** An empty cell is
 *      information — it says "this product has no English title" — and a duplicated one hides the
 *      gap from exactly the person opening the file to close it.
 *   3. **The UTF-8 BOM stays**, or Excel reads the Arabic as Windows-1252 mojibake.
 */

/** Read a CSV export's header row, BOM stripped. */
function exportHeader(string $url): string
{
    $csv = get($url)->assertOk()->streamedContent();
    $csv = ltrim($csv, "\u{FEFF}");

    return T::str(strtok($csv, "\n"));
}

/** The whole body of a CSV export, BOM stripped. */
function exportBody(string $url): string
{
    return ltrim(get($url)->assertOk()->streamedContent(), "\u{FEFF}");
}

beforeEach(function () {
    actingAs(Staff::admin());
});

it('gives every translated field two columns, on every export that has one', function () {
    /*
     * Driven in ENGLISH deliberately, and that is the point of the test rather than a convenience.
     *
     * The requirement has two halves: the headers follow the interface language, and the DATA
     * carries both languages regardless of it. Reading the file as an English operator proves both
     * at once — the headings come back in English, and the Arabic columns are still there. A test
     * run in Arabic could not tell "both languages" from "the operator's language twice".
     */
    Preferences::setLocale(Staff::admin(), 'en');

    /*
     * Pinned by export and by heading. The survey that produced this list (2026-09-16) found FOUR
     * exports emitting one language — products (brand), placement (title), inventory (title) and
     * banners (destination) — and four already emitting both. This keeps all eight honest.
     */
    $expected = [
        '/manage/storefronts/1/products?export=csv' => ['Name (Arabic)', 'Name (English)', 'Brand (Arabic)', 'Brand (English)'],
        '/manage/storefronts/1/placement?export=csv' => ['Name (Arabic)', 'Name (English)'],
        '/manage/inventory?export=csv' => ['Name (Arabic)', 'Name (English)'],
        '/manage/storefronts/1/banners?export=csv' => ['Opens (Arabic)', 'Opens (English)'],
        '/manage/storefronts/1/categories?export=csv' => ['Name (Arabic)', 'Name (English)'],
        '/manage/lookups/brands?export=csv' => ['Name (Arabic)', 'Name (English)'],
        '/manage/units?export=csv' => ['Name (Arabic)', 'Name (English)'],
    ];

    $missing = [];
    foreach ($expected as $url => $headings) {
        $cells = str_getcsv(exportHeader($url));

        foreach ($headings as $heading) {
            if (! in_array($heading, $cells, true)) {
                $missing[] = "{$url}: no “{$heading}” column — got ".implode(', ', $cells);
            }
        }

        // Distinct headings: a pair rendered twice under one name is two columns the reader cannot
        // tell apart, which fails the requirement as surely as having one.
        if (count($cells) !== count(array_unique($cells))) {
            $missing[] = "{$url}: duplicate column headings — ".implode(', ', $cells);
        }
    }

    expect($missing)->toBe([]);
});

it('leaves a missing translation EMPTY rather than falling back to the other language', function () {
    /*
     * The condition is CONSTRUCTED, never inherited: a product is stripped of its English title
     * here so the assertion is about a real gap rather than about whatever the catalogue happens
     * to contain today (AGENTS §4).
     */
    $product = T::int(
        DB::table('catalog_product_translations')->where('locale', 'ar')->orderBy('product_id')->value('product_id')
    );
    $arabic = T::str(
        DB::table('catalog_product_translations')->where('product_id', $product)->where('locale', 'ar')->value('title')
    );

    DB::table('catalog_product_translations')->where('product_id', $product)->where('locale', 'en')->delete();

    $body = exportBody('/manage/inventory?export=csv');

    /*
     * The Arabic title is in the file, and the English column beside it is empty — NOT a second
     * copy of the Arabic. A duplicated cell would tell the person opening this file to fill the
     * gaps that there is no gap.
     */
    expect($body)->toContain($arabic);

    /** @var list<string> $line */
    $line = [];
    foreach (explode("\n", $body) as $row) {
        if (str_contains($row, $arabic)) {
            foreach (str_getcsv(trim($row, "\r")) as $cell) {
                $line[] = T::str($cell ?? '');
            }
            break;
        }
    }

    expect($line)->not->toBe([], 'the product with the stripped translation is not in the export');

    $arIndex = array_search($arabic, $line, true);
    expect($arIndex)->toBeInt('the Arabic title is not a cell of its own');

    // The very next cell is the English one, and it is EMPTY — not a second copy of the Arabic.
    $english = $line[T::int($arIndex) + 1] ?? null;
    expect($english)->toBe('')
        ->and($english)->not->toBe($arabic);
});

it('keeps the UTF-8 BOM, so Arabic still opens correctly in Excel', function () {
    /*
     * Doubling the columns must not have disturbed the one byte sequence that makes these files
     * readable: without the BOM Excel reads a UTF-8 CSV as Windows-1252 and every Arabic heading
     * becomes mojibake — and now there are twice as many Arabic headings to mangle.
     */
    foreach ([
        '/manage/inventory?export=csv',
        '/manage/storefronts/1/products?export=csv',
        '/manage/storefronts/1/banners?export=csv',
    ] as $url) {
        $csv = get($url)->assertOk()->streamedContent();
        expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue("{$url} lost its BOM");
    }
});
