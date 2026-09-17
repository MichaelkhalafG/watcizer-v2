<?php

use App\Domain\Import\ImportFileError;
use App\Domain\Import\WooExport;
use Illuminate\Support\Facades\Artisan;

/*
 * A malformed export is refused BY LINE NUMBER, and never swallows the row after it (🟡-4).
 *
 * ── The finding ──────────────────────────────────────────────────────────────────────────────
 *
 * The reader padded every short row with blanks (`$record[$index] ?? ''`) and ignored every surplus
 * column. That is the wrong default for this file, because of how the common corruption behaves: an
 * unclosed quote makes `fgetcsv` swallow the FOLLOWING LINES into one field until it finds the next
 * quote. The result was one plausible-looking row with blank fields, a product silently missing from
 * the import, and nothing in the report to say so.
 *
 * And when the reader did refuse — a missing column, an unreadable path — it threw a bare
 * `RuntimeException`, which came out of the console as forty lines of vendor frames for what is
 * usually a missing comma in a hand-edited spreadsheet.
 */

/** Write a CSV to the job's scratch space and return its path. */
function malformedExport(string $body): string
{
    $dir = storage_path('framework/testing/import');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/malformed-'.uniqid().'.csv';
    file_put_contents($path, $body);

    return $path;
}

/** The 12 columns `WooExport` requires, as a header row. */
function wooHeader(): string
{
    return 'ID,Type,SKU,Name,Published,Visibility in catalog,Regular price,Sale price,Categories,Images,Stock,Parent';
}

afterEach(function () {
    foreach (glob(storage_path('framework/testing/import/malformed-*.csv')) ?: [] as $file) {
        @unlink($file);
    }
});

it('refuses a short row by LINE NUMBER instead of padding it with blanks', function () {
    /*
     * Line 3 is one column short. Padded — as it was — this became a product with a blank `Parent`
     * and imported without complaint.
     */
    $path = malformedExport(
        wooHeader()."\n"
        .'1,simple,SKU-1,First,1,visible,100,,Watches,,5,'."\n"
        .'2,simple,SKU-2,Second,1,visible,100,,Watches,,5'."\n"
    );

    $read = function () use ($path): void {
        foreach ((new WooExport($path))->rows() as $row) {
            // drain
        }
    };

    expect($read)->toThrow(ImportFileError::class);

    try {
        $read();
    } catch (ImportFileError $e) {
        // The LINE, the expectation, and what was found — everything needed to open the file.
        expect($e->getMessage())->toContain('line 3')
            ->and($e->getMessage())->toContain('11')
            ->and($e->getMessage())->toContain('12')
            // …and it names the usual cause, because the row that looks wrong is often the one after.
            ->and($e->getMessage())->toContain('unclosed quote');
    }
});

it('refuses the row an unclosed quote swallowed, rather than losing the product silently', function () {
    /*
     * THE case this exists for. The unterminated quote on line 2 makes `fgetcsv` consume line 3 into
     * one field, so "Third" vanishes. Before the fix the reader yielded ONE row and no error, and
     * the import report said nothing at all about the missing product.
     */
    $path = malformedExport(
        wooHeader()."\n"
        .'1,simple,SKU-1,"Unclosed name,1,visible,100,,Watches,,5,'."\n"
        .'3,simple,SKU-3,Third,1,visible,100,,Watches,,5,'."\n"
    );

    $read = function () use ($path): void {
        foreach ((new WooExport($path))->rows() as $row) {
        }
    };

    expect($read)->toThrow(ImportFileError::class);
});

it('reads a well-formed file unchanged, so the guard is not simply refusing everything', function () {
    // The other direction. A width check that refused good files would be the worse bug.
    $path = malformedExport(
        wooHeader()."\n"
        .'1,simple,SKU-1,First,1,visible,100,,Watches,,5,'."\n"
        .'2,simple,SKU-2,Second,1,visible,100,,Watches,,5,'."\n"
    );

    $names = [];
    foreach ((new WooExport($path))->rows() as $row) {
        $names[] = $row->titleEn;
    }

    expect($names)->toBe(['First', 'Second']);
});

it('refuses a file missing a required column, naming the column', function () {
    $path = malformedExport("ID,Type,SKU,Name\n1,simple,SKU-1,First\n");

    $read = function () use ($path): void {
        foreach ((new WooExport($path))->rows() as $row) {
        }
    };

    expect($read)->toThrow(ImportFileError::class);

    try {
        $read();
    } catch (ImportFileError $e) {
        expect($e->getMessage())->toContain('Published')
            ->and($e->getMessage())->toContain('Regular price');
    }
});

it('comes out of the console as a SENTENCE, not a stack trace', function () {
    /*
     * The half an exception-type test cannot show. The operator holding the spreadsheet sees the
     * command's output, and forty lines of vendor frames for a missing comma reads as the importer
     * being broken rather than the file.
     */
    $path = malformedExport(
        wooHeader()."\n"
        .'1,simple,SKU-1,First,1,visible,100,,Watches,,5'."\n"
    );

    $exit = Artisan::call('import:catalogue', [
        'file' => $path,
        '--dry-run' => true,
        '--source' => 'woo',
    ]);

    $output = Artisan::output();

    // A non-zero exit and a readable line — no `#0 /vendor/` frames.
    expect($exit)->not->toBe(0)
        ->and($output)->not->toContain('#0 ')
        ->and($output)->not->toContain('vendor/laravel')
        // …and it still says which line, because that is the whole point of the sentence.
        ->and($output)->toContain('line 2');
});

it('still writes the report when the file breaks part way through', function () {
    /*
     * A malformed row a thousand products into a file does not undo the thousand — they are
     * committed, and some of them carry markers saying which need a person. The command used to
     * return on the error BEFORE writing the report, which left the operator with a database full of
     * half-finished products and nothing to work from: exactly what `report.csv` exists to prevent.
     *
     * A dry run is enough to prove the ordering. Nothing is written to the database, the file still
     * fails on line 3, and the question is only whether the report survives the failure.
     */
    $path = malformedExport(
        wooHeader()."\n"
        .'1,simple,SKU-1,First,1,visible,100,,Watches,,5,'."\n"
        .'2,simple,SKU-2,Second,1,visible,100,,Watches,,5'."\n"   // one column short — refused here
    );

    $exit = Artisan::call('import:catalogue', [
        'file' => $path,
        '--dry-run' => true,
        '--source' => 'woo',
    ]);
    $output = Artisan::output();

    // Still a failure: the ordering changed, the verdict did not.
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('line 3')
        // The report paths are printed, which is how anybody finds report.csv at all.
        ->and($output)->toContain('report.md')
        ->and($output)->toContain('report.csv')
        // …and the run says out loud that the report covers the part that landed.
        ->and($output)->toContain('what DID land');

    // The files are really there, not merely announced.
    foreach (['report.md', 'report.csv'] as $name) {
        $matches = glob(storage_path('import').'/*-woo/'.$name) ?: [];
        expect($matches)->not->toBe([], "no {$name} was written");
    }
});
