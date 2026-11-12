<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Media\MediaStore;
use App\Support\Coerce;
use RuntimeException;
use Throwable;

/**
 * The image pass of the legacy import, apart and in parallel (Block D, 2026-11-12).
 *
 * Processing the originals (0.91 s each on the workstation; ~25,000 of them) is the long part of the switch day. This
 * pass does it BEFORE the product import, in as many processes as the server can carry, and the import then only
 * reuses what was made (its `images_reused`) — the database work alone.
 *
 *   • WHICH files: exactly the ones the import will store — the images of the records it will CREATE (the same plan,
 *     on the same database, in the same order), skipping a product whose featured photo is missing (the import gives
 *     it no images). A file the pass misses is not an error: the import processes it inline, as before.
 *   • SHARDS: file → shard by crc32 of its path, so `--shard=1/4` … `4/4` are disjoint and together are all of them;
 *     no two processes ever touch the same original.
 *   • RE-RUNNABLE: each shard appends `{source, stored}` to `image-map.shard-K-of-N.jsonl` in the import's output folder
 *     as it goes; a file already in ANY image map of that folder (with its master still on disk) is skipped.
 *   • NAMES: {@see MediaStore::storePath()} claims each new file name atomically, so parallel processes never write
 *     one name twice.
 */
final class LegacyImagePass
{
    public function __construct(
        private readonly LegacyImporter $importer,
        private readonly MediaStore $media,
    ) {}

    /**
     * The originals the import will store, in the import's order, de-duplicated.
     *
     * @return list<string>
     */
    public function files(string $productsFile, string $imagesRoot): array
    {
        $this->importer->prepare(null, $productsFile);
        $handle = fopen($productsFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$productsFile}");
        }
        $out = [];
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $record = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            if ($this->importer->plan($record)['action'] !== 'create') {
                continue;
            }
            $files = [];
            foreach (Coerce::arr($record['images'] ?? null) as $i) {
                $file = Coerce::str(Coerce::arr($i)['file'] ?? null);
                if ($file !== '') {
                    $files[] = $file;
                }
            }
            if ($files === [] || ! is_file($imagesRoot.'/'.$files[0])) {
                continue;                                   // no featured photo: the import stores none for it
            }
            foreach ($files as $file) {
                if (is_file($imagesRoot.'/'.$file)) {
                    $out[$file] = true;
                }
            }
        }
        fclose($handle);

        return array_keys($out);
    }

    /** Whether a file belongs to shard K of N (1-based). */
    public static function inShard(string $file, int $k, int $n): bool
    {
        return $n <= 1 || (crc32($file) % $n) === $k - 1;
    }

    /**
     * `K/N` → [K, N], or null when it is not a valid shard.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseShard(string $spec): ?array
    {
        if (preg_match('#^(\d+)/(\d+)$#', trim($spec), $m) !== 1) {
            return null;
        }
        [$k, $n] = [(int) $m[1], (int) $m[2]];

        return $n >= 1 && $k >= 1 && $k <= $n ? [$k, $n] : null;
    }

    /** The map file of a shard, in the import's output folder. */
    public static function mapFile(string $out, int $k, int $n): string
    {
        return rtrim($out, '/\\').'/image-map.shard-'.$k.'-of-'.$n.'.jsonl';
    }

    /**
     * The sources already processed: every image map of the folder whose master is still on disk.
     *
     * @return array<string, true>
     */
    public static function done(string $out): array
    {
        $done = [];
        foreach (glob(rtrim($out, '/\\').'/image-map*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = Coerce::arr(json_decode($line, true));
                $stored = Coerce::arr($entry['stored'] ?? null);
                $folder = Coerce::str($stored['folder'] ?? null);
                $name = Coerce::str($stored['file'] ?? null);
                if ($folder !== '' && $name !== '' && is_file(MediaStore::directory($folder).'/'.$name)) {
                    $done[Coerce::str($entry['source'] ?? null)] = true;
                }
            }
        }

        return $done;
    }

    /**
     * Process this shard's files that no map holds yet, appending each to the shard's map as it is made.
     *
     * @param  list<string>  $files
     * @param  (callable(int, int): void)|null  $progress  (done, of)
     * @return array{shard_files: int, already: int, processed: int, unreadable: int, ms: float}
     */
    public function run(array $files, string $imagesRoot, string $out, int $k, int $n, ?callable $progress = null): array
    {
        $t0 = hrtime(true);
        $mine = array_values(array_filter($files, fn (string $f): bool => self::inShard($f, $k, $n)));
        $done = self::done($out);
        $todo = array_values(array_filter($mine, fn (string $f): bool => ! isset($done[$f])));
        $map = self::mapFile($out, $k, $n);
        $processed = 0;
        $unreadable = 0;
        foreach ($todo as $i => $file) {
            try {
                $s = $this->media->storePath($imagesRoot.'/'.$file, 'product');
            } catch (Throwable) {
                $unreadable++;

                continue;
            }
            $entry = ['file' => $s['file'], 'folder' => $s['folder'], 'width' => $s['width'], 'height' => $s['height'], 'renditions' => $s['renditions']];
            file_put_contents($map, json_encode(['source' => $file, 'stored' => $entry], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
            $processed++;
            if ($progress !== null && ($i + 1) % 100 === 0) {
                $progress($i + 1, count($todo));
            }
        }

        return ['shard_files' => count($mine), 'already' => count($mine) - count($todo), 'processed' => $processed, 'unreadable' => $unreadable, 'ms' => (hrtime(true) - $t0) / 1e6];
    }

    /**
     * Time `$count` originals through the real pipeline into a THROWAWAY media root (deleted after) — the server
     * measurement. Nothing is written to the shop's media or the database.
     *
     * @param  list<string>  $files
     * @return array{images: int, mean_ms: float, median_ms: float, max_ms: float, peak_mb: float}
     */
    public function bench(array $files, string $imagesRoot, int $count): array
    {
        $root = storage_path('app/bf-image-bench-'.getmypid());
        $previous = config('media.root');
        config(['media.root' => $root]);
        $times = [];
        try {
            foreach (array_slice($files, 0, max(1, $count)) as $file) {
                $t = hrtime(true);
                try {
                    $this->media->storePath($imagesRoot.'/'.$file, 'product');
                } catch (Throwable) {
                    continue;
                }
                $times[] = (hrtime(true) - $t) / 1e6;
            }
        } finally {
            config(['media.root' => $previous]);
            self::removeTree($root);
        }
        sort($times);
        $n = count($times);

        return [
            'images' => $n,
            'mean_ms' => $n === 0 ? 0.0 : round(array_sum($times) / $n, 1),
            'median_ms' => $n === 0 ? 0.0 : round($times[intdiv($n, 2)], 1),
            'max_ms' => $n === 0 ? 0.0 : round($times[$n - 1], 1),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
