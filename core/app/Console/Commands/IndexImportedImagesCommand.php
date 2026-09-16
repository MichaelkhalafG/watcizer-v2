<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\ImageCache;
use App\Domain\Import\JoyroomSheet;
use App\Domain\Import\WooExport;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover the "which remote URL produced which stored file" mapping — before a rebuild destroys it.
 *
 * ── Why this command exists, and why it is urgent rather than tidy ──────────────────────────
 *
 * `ImageCache` makes a re-run skip images it already fetched. It is keyed by a hash of the remote
 * URL, and it is written at fetch time — so on its own it helps only from the run that introduced
 * it onwards. Meanwhile the shared tree already holds **5 719 master files** from earlier runs,
 * every one of them a download and a ten-file re-encode that nobody wants to pay for twice.
 *
 * The mapping that would let us reuse them is not lost — but it is about to be. It exists as a
 * JOIN that is only possible right now:
 *
 *     catalog_products.import_ref   →  the source row  →  its image URL
 *     catalog_product_images.path   →  the stored file
 *
 * `core:drop-clean` wipes both of those tables and leaves the files. So this command reads that
 * join while it still exists and writes the manifest, converting an hour of re-downloading into a
 * file lookup. Run it BEFORE a rebuild or before deleting imported products, never after.
 *
 * ── What it does NOT claim ──────────────────────────────────────────────────────────────────
 *
 * It records a URL against a file that WAS produced from it. It cannot know whether the supplier
 * has changed the photo at that URL since — see `ImageCache` for that trade and for
 * `--refresh-images`, which takes it back.
 *
 * Writes nothing to the database.
 */
final class IndexImportedImagesCommand extends Command
{
    protected $signature = 'import:index-images
        {file : the source file the products were imported from}
        {--source=woo : woo|joyroom}
        {--dry-run : report what would be recorded and write nothing}';

    protected $description = 'Record remote-url → stored-file for images already imported, so a re-run can reuse them';

    public function handle(ImageCache $cache): int
    {
        $file = Coerce::str($this->argument('file'));
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $source = Coerce::str($this->option('source'), 'woo');
        $dryRun = (bool) $this->option('dry-run');

        /*
         * The join, read once: every imported product that HAS a cover, with the folder/file it
         * points at. `import_ref` is the key back to the source row.
         */
        $stored = [];
        foreach (
            DB::table('catalog_products as p')
                ->join('catalog_product_images as i', 'i.product_id', '=', 'p.id')
                ->whereNotNull('p.import_ref')
                ->where('i.is_cover', 1)
                ->get(['p.import_ref', 'i.path', 'i.width', 'i.height', 'i.renditions']) as $raw
        ) {
            $row = Row::cast($raw);
            $stored[Row::str($row, 'import_ref')] = [
                'path' => Row::str($row, 'path'),
                'width' => Row::int($row, 'width'),
                'height' => Row::int($row, 'height'),
                'renditions' => Row::nstr($row, 'renditions'),
            ];
        }

        $this->line('  imported products with a cover : '.count($stored));
        if ($stored === []) {
            $this->warn('  Nothing to index. If a rebuild has already run, this mapping is gone.');

            return self::SUCCESS;
        }

        $recorded = 0;
        $noUrl = 0;
        $noCover = 0;
        $badPath = 0;

        $rows = $source === 'joyroom' ? new JoyroomSheet($file) : new WooExport($file);

        foreach ($rows->rows() as $row) {
            $entry = $stored[$row->ref] ?? null;
            if ($entry === null) {
                $noCover++;

                continue;
            }
            if ($row->imageUrl === null || trim($row->imageUrl) === '') {
                $noUrl++;

                continue;
            }

            // `<folder>/<file>` is what the column holds; a bare filename is the pre-fix shape and
            // cannot be turned back into a folder, so it is counted rather than guessed at.
            $path = $entry['path'];
            $slash = strrpos($path, '/');
            if ($slash === false) {
                $badPath++;

                continue;
            }

            if (! $dryRun) {
                $cache->remember($row->imageUrl, [
                    'file' => substr($path, $slash + 1),
                    'folder' => substr($path, 0, $slash),
                    'width' => $entry['width'],
                    'height' => $entry['height'],
                    'renditions' => self::renditions($entry['renditions']),
                ]);
            }
            $recorded++;
        }

        $this->line('');
        $this->table(['what', 'how many'], [
            [$dryRun ? 'would record' : 'recorded', (string) $recorded],
            ['source row has no image url', (string) $noUrl],
            ['source row was never imported / has no cover', (string) $noCover],
            ['stored path has no folder (pre-fix rows)', (string) $badPath],
        ]);

        if ($dryRun) {
            $this->info('Dry run — the manifest was not written.');
        } else {
            $this->info('Recorded. A re-run will reuse these instead of downloading them again.');
        }

        return self::SUCCESS;
    }

    /**
     * The stored renditions JSON, back into the shape the cache holds.
     *
     * @return array<int, array<string, string>>
     */
    private static function renditions(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        $out = [];
        foreach (Coerce::arr($decoded) as $width => $formats) {
            $files = [];
            foreach (Coerce::arr($formats) as $format => $file) {
                if (is_string($file) && $file !== '') {
                    $files[(string) $format] = $file;
                }
            }
            if ($files !== []) {
                $out[Coerce::int($width)] = $files;
            }
        }

        return $out;
    }
}
