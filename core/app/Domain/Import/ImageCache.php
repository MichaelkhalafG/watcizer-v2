<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Media\MediaStore;
use App\Support\Coerce;

/**
 * "Have we already downloaded and processed this image?" — the record that survives a rebuild.
 *
 * ── The honest answer to "how do you know it is the same image" ─────────────────────────────
 *
 * **There is no property of the stored file that identifies its source.** `MediaStore` names every
 * file `<unix>_<Y-m-d>_<uniqid>.webp` — deliberately, so an imported cover is indistinguishable
 * from an uploaded one (§5.4) — and `catalog_product_images` has no column for a source URL:
 * `path, is_cover, sort, width, height, alt_en, alt_ar, renditions`. Nothing in the database or on
 * the filesystem can answer the question today.
 *
 * A content hash cannot answer it either, because computing one requires the bytes, and fetching
 * the bytes is the cost we are trying to avoid.
 *
 * So the identity has to be RECORDED at fetch time, and it has to be recorded somewhere the
 * rebuild does not touch. That rules out the database: `core:drop-clean` wipes
 * `catalog_product_images` while the ~50 000 FILES stay exactly where they are — which is the
 * whole shape of the problem. This class is therefore a plain append-only file beside the run
 * reports, keyed by `sha1(remote url)`.
 *
 * ── What it is wrong about, in both directions ──────────────────────────────────────────────
 *
 * **Skipping a download we needed.** The key is the URL, not the bytes. If the supplier replaces
 * the photo at a URL we have already fetched, we reuse the old one and nobody is told. Nothing
 * here can detect that — a conditional `HEAD` for an `ETag` would, at the price of a round trip
 * per image, which is a real option if this ever runs against a moving catalogue rather than a
 * one-off export. For a rehearsal import of a static file it is the right trade, and
 * `--refresh-images` exists to take it back.
 *
 * **Fetching one we had.** The manifest is lost, or a file it names has been removed by
 * `media:prune`. Then we download and process again and store a SECOND copy of the same bytes
 * under a new name — wasted time and an orphan the next prune collects. Note the asymmetry: being
 * wrong this way costs exactly what we pay today. That is why the check is `is_file()` on the
 * master before every reuse, and why a missing file falls through to a fetch rather than
 * attaching a path to nothing.
 *
 * ── What it deliberately does not do ────────────────────────────────────────────────────────
 *
 * It does not make the filename derivable from the URL. That would make the lookup free and would
 * also make imported files visibly different from uploaded ones, which is the one property
 * `CoverImages` exists to preserve.
 */
final class ImageCache
{
    /**
     * `sha1(url) => stored record`, read once.
     *
     * @var array<string, array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}>|null
     */
    private ?array $entries = null;

    private readonly string $path;

    /**
     * Whether to REUSE. Mutable, and bound as a singleton, so `--refresh-images` can turn it off
     * after the container has already built the object graph that holds it — the command's
     * options are not parsed until `handle()` runs, which is after `CoverImages` exists.
     *
     * Turning it off stops reads, never writes: a refreshed run still records what it fetched, so
     * the run after it benefits.
     */
    private bool $reuse = true;

    public function __construct()
    {
        $this->path = storage_path('import/image-cache.jsonl');
    }

    /** `--refresh-images`: fetch everything again, and record it again. */
    public function refreshEverything(): void
    {
        $this->reuse = false;
    }

    /**
     * The stored file for this URL, or null when we have to fetch it.
     *
     * @return array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}|null
     */
    public function find(string $url): ?array
    {
        if (! $this->reuse) {
            return null;
        }

        $this->load();
        $entries = $this->entries ?? [];
        $entry = $entries[self::key($url)] ?? null;
        if ($entry === null) {
            return null;
        }

        /*
         * The manifest is a claim about the filesystem, and the filesystem is the authority.
         * `media:prune` can have removed the file since; a row pointing at a deleted master is a
         * broken image on every screen, which is far worse than paying for the download again.
         */
        $master = MediaStore::directory($entry['folder']).'/'.$entry['file'];
        if (! is_file($master)) {
            return null;
        }

        return $entry;
    }

    /**
     * Record what a fetch produced, so the next run does not repeat it.
     *
     * @param  array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}  $stored
     */
    public function remember(string $url, array $stored): void
    {
        $this->load();

        $entry = [
            'file' => $stored['file'],
            'folder' => $stored['folder'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'renditions' => $stored['renditions'],
        ];
        $this->entries[self::key($url)] = $entry;

        $line = json_encode(['k' => self::key($url), 'at' => now()->toIso8601String()] + $entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        /*
         * Append with an exclusive lock, one short line per fetch. Several shard workers write
         * this file at once, and an append under the lock is the cheapest thing that survives
         * that — a rewritten JSON document would not.
         */
        @file_put_contents($this->path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /** How many images this run did not have to fetch, for the report. */
    public function size(): int
    {
        $this->load();

        return count($this->entries ?? []);
    }

    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }
        $this->entries = [];

        if (! is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (! is_array($decoded)) {
                continue;                       // a torn line from a killed worker: skip it
            }
            $key = Coerce::str($decoded['k'] ?? '');
            $file = Coerce::str($decoded['file'] ?? '');
            $folder = Coerce::str($decoded['folder'] ?? '');
            if ($key === '' || $file === '' || $folder === '') {
                continue;
            }

            // Later lines win: the same URL fetched twice is the newer file.
            $this->entries[$key] = [
                'file' => $file,
                'folder' => $folder,
                'width' => Coerce::int($decoded['width'] ?? null),
                'height' => Coerce::int($decoded['height'] ?? null),
                'renditions' => self::renditionsOf($decoded['renditions'] ?? null),
            ];
        }
        fclose($handle);
    }

    /**
     * The renditions map as it was written: `width => {format => filename}`.
     *
     * Narrowed here rather than trusted, because this file is read back from disk and a torn or
     * hand-edited line must not put a shape into the cache that the row writer cannot use.
     *
     * @return array<int, array<string, string>>
     */
    private static function renditionsOf(mixed $value): array
    {
        $out = [];
        foreach (Coerce::arr($value) as $width => $formats) {
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

    private static function key(string $url): string
    {
        return sha1(trim($url));
    }
}
