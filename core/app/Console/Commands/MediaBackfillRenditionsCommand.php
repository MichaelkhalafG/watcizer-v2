<?php

namespace App\Console\Commands;

use App\Domain\Media\ImagePipeline;
use App\Domain\Media\MediaStore;
use App\Domain\Media\UnreadableUpload;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * media:backfill-renditions — the ladder (160/320/480/640/960, AVIF + WebP) for every product image
 * that has none, written beside its master through the SAME code path an upload uses.
 *
 * ── Why there are masters without renditions ─────────────────────────────────────────────────
 *
 * `ImagePipeline` has written a ladder beside every master since wave 4A (2026-09-11; first on the
 * live host 2026-09-19 with the catalogue import), and recorded it on the row in
 * `catalog_product_images.renditions`. The images that came through the transform from the legacy
 * app (`Step09CoverImages`, `Step10GalleryImages`) never went through the pipeline — the legacy
 * ImageService made one file per upload — so their rows carry `renditions = NULL` and their folders
 * hold the master alone. Measured 2026-10-22 on the dev copy: 626 of 7,687 covers and 2,332 of
 * 3,878 gallery images; on the live tree the owner counted ~93% of covers and about half of the
 * gallery with renditions, which is the same picture.
 *
 * ── Where the truth about "this rendition exists" lives ───────────────────────────────────────
 *
 * In the row: `catalog_product_images.renditions`, `width => {format => filename}`, written by the
 * pipeline only after the encoder returned AND the file has bytes. Checked 2026-10-22 against the
 * Product folder on disk: 70,610 recorded files, 0 missing; the 626 NULL rows have no rendition
 * files at all. This command writes the same record, so the storefronts never have to GUESS a URL
 * from the naming convention — a guessed URL that 404s is a broken photo on a live shop.
 *
 * ── What it promises ─────────────────────────────────────────────────────────────────────────
 *
 *  • `--dry-run` writes nothing and says how many rows it would process per folder, how many of
 *    their masters are on disk, and the disk it would add — estimated from the AVERAGE size of the
 *    renditions that already exist in that folder (measured from the rows that carry them).
 *  • Idempotent and restartable: a row with a record is never a candidate again; a rendition file
 *    already on disk with bytes is recorded, not re-encoded. A run killed halfway leaves files the
 *    next run completes.
 *  • Gentle: `--limit` rows per run (default 200), `--pause-ms` between images (default 200), and
 *    the process asks for a lower CPU priority where the host allows it.
 *  • It never writes a master. The master is opened read-only, and its size and mtime are compared
 *    before and after each image — a change aborts the run (a tripwire, not a hope).
 *
 *   php artisan media:backfill-renditions --dry-run
 *   php artisan media:backfill-renditions --limit=200
 *   php artisan media:backfill-renditions --type=product_gallery --limit=500 --pause-ms=100
 */
final class MediaBackfillRenditionsCommand extends Command
{
    protected $signature = 'media:backfill-renditions
        {--dry-run : count what would be processed per folder and estimate the disk it adds; write nothing}
        {--limit=200 : process at most this many image rows in this run (0 = every candidate)}
        {--type=* : product and/or product_gallery (default: both)}
        {--id=* : only these catalog_product_images ids}
        {--pause-ms=200 : rest this long after each image, so a shared host stays responsive}
        {--retry-failed : also process rows whose renditions_failed marker is set}';

    protected $description = 'Write the missing renditions for existing product images, through the upload pipeline, and record them on the rows';

    /** The media types whose rows live in catalog_product_images, by the folder their paths start with. */
    private const TYPES = ['product' => 'Product', 'product_gallery' => 'Product_image'];

    /** Rendition bytes per master, measured 2026-10-22 on the Product folder (10 files per master): the fallback estimate. */
    private const MEASURED_BYTES_PER_MASTER = 41097;

    public function handle(ImagePipeline $pipeline): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, Coerce::int($this->option('limit')));
        $pauseMs = max(0, Coerce::int($this->option('pause-ms')));
        $ids = Coerce::intList($this->option('id'));
        $types = $this->types();
        if ($types === []) {
            $this->error('No known type selected. Types: '.implode(', ', array_keys(self::TYPES)));

            return self::INVALID;
        }

        if (! $dryRun && function_exists('proc_nice')) {
            @proc_nice(10);
        }

        $this->info($dryRun ? 'media:backfill-renditions — DRY RUN, nothing is written' : 'media:backfill-renditions');

        $report = [];
        $processed = 0;
        $recorded = 0;
        $filesWritten = 0;
        $bytesAdded = 0;
        $reused = 0;
        $failed = 0;
        $missing = 0;
        $notWebp = 0;
        $startedAt = time();

        foreach ($types as $type) {
            $folder = self::TYPES[$type];
            $config = MediaStore::typeConfig($type);
            $directory = MediaStore::directory($folder);

            $candidates = $this->candidates($folder, $ids);
            $total = (int) $candidates->clone()->count();

            if ($dryRun) {
                $present = 0;
                $absent = 0;
                $other = 0;
                $candidates->clone()->orderBy('id')->chunk(500, function (Collection $rows) use (&$present, &$absent, &$other, $directory): void {
                    foreach ($rows as $row) {
                        $file = basename(Row::str($row, 'path'));
                        if (! str_ends_with(strtolower($file), '.webp')) {
                            $other++;
                        } elseif (is_file($directory.'/'.$file)) {
                            $present++;
                        } else {
                            $absent++;
                        }
                    }
                });
                [$perMaster, $sampled] = $this->averageRenditionBytes($folder, $directory);
                $report[] = [$type, $folder, number_format($total), number_format($present), number_format($absent), number_format($other),
                    number_format($present * 10), self::humanBytes($present * $perMaster), $sampled > 0 ? "{$sampled} rows" : 'the 2026-10-22 measure'];

                continue;
            }

            $remaining = $limit === 0 ? PHP_INT_MAX : $limit - $processed;
            if ($remaining <= 0) {
                $report[] = [$type, $folder, number_format($total), '—', 'limit reached before this folder'];

                continue;
            }

            $done = 0;
            $candidates->orderBy('id')->chunkById(100, function (Collection $rows) use (&$done, &$remaining, &$processed, &$recorded, &$filesWritten, &$bytesAdded, &$reused, &$failed, &$missing, &$notWebp, $pipeline, $config, $directory, $pauseMs, $startedAt): bool {
                foreach ($rows as $row) {
                    if ($remaining <= 0) {
                        return false;
                    }
                    $remaining--;
                    $processed++;
                    $done++;

                    $file = basename(Row::str($row, 'path'));
                    $master = $directory.'/'.$file;
                    if (! str_ends_with(strtolower($file), '.webp')) {
                        $notWebp++;

                        continue;
                    }
                    if (! is_file($master)) {
                        $missing++;

                        continue;
                    }

                    $outcome = $this->one($pipeline, $row, $master, $config, $startedAt);
                    $recorded += $outcome['recorded'] ? 1 : 0;
                    $filesWritten += $outcome['written'];
                    $bytesAdded += $outcome['bytes'];
                    $reused += $outcome['reused'];
                    $failed += $outcome['failed'] ? 1 : 0;

                    if ($pauseMs > 0) {
                        usleep($pauseMs * 1000);
                    }
                }

                return true;
            });

            $left = (int) $this->candidates($folder, $ids)->count();
            $report[] = [$type, $folder, number_format($total), number_format($done), number_format($left)];
        }

        $this->newLine();
        if ($dryRun) {
            $this->table(['type', 'folder', 'rows to process', 'master on disk', 'master missing', 'not webp', 'files to write', 'disk to add', 'estimate from'], $report);
            $this->line('A row is a candidate when its renditions column is NULL'.((bool) $this->option('retry-failed') ? '' : ' and it carries no failure marker').'.');
            $this->line('"master missing" rows are skipped by a real run and left NULL; nothing is written for them.');
            $this->info('DRY RUN — nothing was written. Re-run without --dry-run, with --limit, to write in batches.');

            return self::SUCCESS;
        }

        $this->table(['type', 'folder', 'candidates at start', 'processed this run', 'still to do'], $report);
        $this->line(sprintf('processed %s row(s): %s recorded, %s rendition file(s) written (%s), %s already on disk and reused, %s master(s) missing, %s not webp, %s failed',
            number_format($processed), number_format($recorded), number_format($filesWritten), self::humanBytes($bytesAdded), number_format($reused), number_format($missing), number_format($notWebp), number_format($failed)));
        if ($failed > 0) {
            $this->warn('Failed rows carry a renditions_failed marker: the products list shows them under flag=image_problem.');
        }
        $this->line('Run the same command again to continue; a row that is recorded is never processed twice.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One image: the ladder beside its master, then the row.
     *
     * @param  array{folder: string, master: array{width: int, height: int, quality: int, pad_square: bool}, widths: list<int>, thumbnails: list<int>}  $config
     * @return array{recorded: bool, written: int, bytes: int, reused: int, failed: bool}
     */
    private function one(ImagePipeline $pipeline, stdClass $row, string $master, array $config, int $startedAt): array
    {
        $id = Row::int($row, 'id');
        clearstatcache(true, $master);
        $before = [(int) (@filesize($master) ?: 0), (int) (@filemtime($master) ?: 0)];

        try {
            $result = $pipeline->renditionsOf($master, $config);
        } catch (UnreadableUpload $e) {
            // The FILE is bad, not the host: mark the row so a person sees it, and carry on.
            DB::table('catalog_product_images')->where('id', $id)->update([
                'renditions_failed' => mb_substr(ImagePipeline::FAILED_PREFIX.'master unreadable: '.$e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
            $this->warn("  #{$id} ".basename($master).': '.$e->getMessage());

            return ['recorded' => false, 'written' => 0, 'bytes' => 0, 'reused' => 0, 'failed' => true];
        }

        clearstatcache(true, $master);
        $after = [(int) (@filesize($master) ?: 0), (int) (@filemtime($master) ?: 0)];
        if ($after !== $before) {
            throw new RuntimeException("The master [{$master}] changed while its renditions were written — aborting. Nothing here may touch an original.");
        }

        $written = 0;
        $bytes = 0;
        $reused = 0;
        $directory = dirname($master);
        foreach ($result['renditions'] as $formats) {
            foreach ($formats as $file) {
                $path = $directory.'/'.$file;
                clearstatcache(true, $path);
                if ((int) (@filemtime($path) ?: 0) >= $startedAt) {
                    $written++;
                    $bytes += (int) (@filesize($path) ?: 0);
                } else {
                    $reused++;
                }
            }
        }

        $failures = array_values(array_filter($result['skipped'], fn (string $s): bool => str_starts_with($s, ImagePipeline::FAILED_PREFIX)));
        $update = [
            // `[]` when the picture is narrower than every rung: recorded, so the row is never a candidate again.
            'renditions' => json_encode($result['renditions']) ?: '[]',
            'updated_at' => now(),
        ];
        if (Row::nint($row, 'width') === null && $result['width'] > 0) {
            $update['width'] = $result['width'];
            $update['height'] = $result['height'];
        }
        if ($failures !== []) {
            $update['renditions_failed'] = mb_substr(implode('; ', $failures), 0, 1000);
        }
        DB::table('catalog_product_images')->where('id', $id)->update($update);

        return ['recorded' => true, 'written' => $written, 'bytes' => $bytes, 'reused' => $reused, 'failed' => $failures !== []];
    }

    /**
     * The rows this run may process: this folder, no record yet.
     *
     * @param  list<int>  $ids
     */
    private function candidates(string $folder, array $ids): Builder
    {
        $query = DB::table('catalog_product_images')
            ->select(['id', 'path', 'width', 'height'])
            ->where('path', 'like', $folder.'/%')
            ->whereNull('renditions');
        if (! (bool) $this->option('retry-failed')) {
            $query->whereNull('renditions_failed');
        }
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    /**
     * The average size of one master's ladder in this folder, from up to 200 rows that carry a record
     * whose files are on disk — the dry run's unit of estimate.
     *
     * @return array{0: int, 1: int} bytes per master, rows sampled
     */
    private function averageRenditionBytes(string $folder, string $directory): array
    {
        $rows = DB::table('catalog_product_images')->select(['renditions'])
            ->where('path', 'like', $folder.'/%')->whereNotNull('renditions')->orderByDesc('id')->limit(200)->get();
        $total = 0;
        $sampled = 0;
        foreach ($rows as $row) {
            $decoded = json_decode(Row::str($row, 'renditions'), true);
            if (! is_array($decoded) || $decoded === []) {
                continue;
            }
            $sum = 0;
            $complete = true;
            foreach ($decoded as $formats) {
                foreach (Coerce::arr($formats) as $file) {
                    $size = is_string($file) ? (int) (@filesize($directory.'/'.$file) ?: 0) : 0;
                    if ($size === 0) {
                        $complete = false;
                        break 2;
                    }
                    $sum += $size;
                }
            }
            if ($complete) {
                $total += $sum;
                $sampled++;
            }
        }

        return $sampled > 0 ? [(int) round($total / $sampled), $sampled] : [self::MEASURED_BYTES_PER_MASTER, 0];
    }

    /** @return list<string> */
    private function types(): array
    {
        $requested = [];
        foreach ($this->option('type') as $type) {
            if (is_string($type) && $type !== '') {
                $requested[] = $type;
            }
        }
        if ($requested === []) {
            return array_keys(self::TYPES);
        }

        return array_values(array_intersect($requested, array_keys(self::TYPES)));
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 1).' KB';
    }
}
