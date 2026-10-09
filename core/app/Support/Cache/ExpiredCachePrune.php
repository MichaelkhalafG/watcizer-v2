<?php

namespace App\Support\Cache;

use App\Compat\CompatListing;
use App\Storefront\StorefrontCache;
use FilesystemIterator;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * DELETES FILES from the file cache's directory (with `delete: true`) — the expired ones the storefront
 * cache strands there. Read-only otherwise. Shared by `cache:prune-expired` and nothing else.
 *
 * ── Why the files exist ──────────────────────────────────────────────────────────────────────────
 *
 * Every storefront key embeds the storefront's version (`StorefrontCache::key()`); a dashboard save or a
 * stock movement bumps it, the warm-up writes a whole new generation under the new keys, and the old
 * generation is never read again. Laravel's FileStore deletes an expired file ONLY when that key is read
 * (`FileStore::getPayload`), so an orphaned generation stays on disk for ever. Measured on production
 * 2026-10-07: 343,699 files / 7.6 GB in nine days (backlog C-GROW).
 *
 * ── What it deletes, and the guards ──────────────────────────────────────────────────────────────
 *
 *  - ONLY a file whose own expiry stamp (its first 10 bytes, `FileStore::put`) is more than `grace`
 *    seconds in the past. Laravel already treats such a file as a miss and would delete it on the next
 *    read — removing it cannot change what the shop serves. That is the guard; the others are on top.
 *  - NEVER a "forever" entry (stamp 9999999999 — the storefront version counters).
 *  - NEVER a file whose name is not a 40-hex sha1, or that is not under the cache directory.
 *  - NEVER a file of the LIVE generation: the current version's keys (every visible product's card, the
 *    listing index, nav, meta in both languages, names, the version counter) are computed with
 *    StorefrontCache::keysFor() and FileStore::path() and refused by FILE NAME (the sha1 of the key) —
 *    whatever their stamp says. After deleting, every one of them is checked again.
 *
 * It decides "dead" by the expiry stamp, not by version: the file name is a hash, so a file's version
 * cannot be read back without enumerating every old key — while the stamp is in the file itself.
 */
final class ExpiredCachePrune
{
    public const FOREVER = 9999999999;

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * @return array{directory: string, delete: bool, grace: int, seconds: float, scanned: int, bytes: int,
     *   expired: int, expired_bytes: int, deleted: int, failed: int, keep: array<string, int>,
     *   by_day: array<string, int>, live: array<int, array{version: int, keys: int, before: int, after: int|null}>, ok: bool}
     */
    public function run(bool $delete, int $grace = 3600, ?int $now = null): array
    {
        $store = Cache::store('file')->getStore();
        if (! $store instanceof FileStore) {
            throw new RuntimeException('The file cache store is not a FileStore.');
        }
        $dir = realpath($store->getDirectory());
        if ($dir === false || ! is_dir($dir)) {
            throw new RuntimeException('The file cache directory does not exist.');
        }
        $now ??= time();
        $grace = max(0, $grace);

        // ── The live generation, by file name ──────────────────────────────────────────────────
        $live = [];
        $protected = [];
        foreach ($this->liveKeys() as $sf => [$version, $keys]) {
            $before = 0;
            foreach ($keys as $key) {
                $path = $store->path($key);
                $protected[basename($path)] = ['sf' => $sf, 'path' => $path];
                if (is_file($path)) {
                    $before++;
                }
            }
            $live[$sf] = ['version' => $version, 'keys' => count($keys), 'before' => $before, 'after' => null];
        }

        // ── Scan ───────────────────────────────────────────────────────────────────────────────
        $t0 = microtime(true);
        $r = ['scanned' => 0, 'bytes' => 0, 'expired' => 0, 'expired_bytes' => 0, 'deleted' => 0, 'failed' => 0];
        $keep = ['live' => 0, 'not_expired' => 0, 'within_grace' => 0, 'forever' => 0, 'not_a_cache_file' => 0, 'unreadable' => 0];
        $byDay = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $size = (int) $file->getSize();
            $r['scanned']++;
            $r['bytes'] += $size;
            $real = realpath($path);
            if (preg_match('/^[0-9a-f]{40}$/', $file->getFilename()) !== 1 || $real === false || ! str_starts_with($real, $dir.DIRECTORY_SEPARATOR)) {
                $keep['not_a_cache_file']++;

                continue;
            }
            if (isset($protected[$file->getFilename()])) {
                $keep['live']++;

                continue;
            }
            $stamp = self::stamp($path);
            if ($stamp === null) {
                $keep['unreadable']++;

                continue;
            }
            if ($stamp === self::FOREVER) {
                $keep['forever']++;

                continue;
            }
            if ($stamp > $now) {
                $keep['not_expired']++;

                continue;
            }
            if ($stamp > $now - $grace) {
                $keep['within_grace']++;

                continue;
            }
            $r['expired']++;
            $r['expired_bytes'] += $size;
            $day = gmdate('Y-m-d', $stamp);
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
            if ($delete) {
                if (@unlink($path)) {
                    $r['deleted']++;
                } else {
                    $r['failed']++;
                }
            }
        }
        ksort($byDay);

        // ── Re-check the live generation ───────────────────────────────────────────────────────
        $ok = true;
        if ($delete) {
            foreach ($live as $sf => $l) {
                $after = 0;
                foreach ($protected as $p) {
                    if ($p['sf'] === $sf && is_file($p['path'])) {
                        $after++;
                    }
                }
                $live[$sf]['after'] = $after;
                $ok = $ok && $after >= $l['before'];
            }
        }

        return ['directory' => $dir, 'delete' => $delete, 'grace' => $grace, 'seconds' => microtime(true) - $t0]
            + $r + ['keep' => $keep, 'by_day' => $byDay, 'live' => $live, 'ok' => $ok];
    }

    /**
     * The current version's keys per storefront — the generation that must never be touched.
     *
     * @return array<int, array{0: int, 1: list<string>}>
     */
    public function liveKeys(): array
    {
        $locale = config()->string('compat.pinned_locale');
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->pluck('id') as $id) {
            $sf = (int) (is_numeric($id) ? $id : 0);
            if ($sf <= 0) {
                continue;
            }
            $keys = [
                StorefrontCache::versionKeyFor($sf),
                $this->cache->key($sf, 'compat_listing', CompatListing::INDEX_SHAPE),
                $this->cache->key($sf, 'compat_nav'),
                $this->cache->key($sf, 'compat_names'),
            ];
            foreach (array_unique([$locale, 'en', 'ar']) as $l) {
                $keys[] = $this->cache->key($sf, 'compat_meta', $l);
            }
            $version = $this->cache->version($sf);
            $suffixes = [];
            foreach (DB::table('storefront_product')->where('storefront_id', $sf)->where('is_visible', 1)->pluck('product_id') as $pid) {
                $suffixes[] = $locale.':'.(int) (is_numeric($pid) ? $pid : 0);
            }
            // The same key builder CompatListing::cards() / warm() write through (one version read).
            array_push($keys, ...array_values($this->cache->keysFor($sf, 'compat_card', $suffixes, $version)));
            $out[$sf] = [$version, array_values(array_unique($keys))];
        }

        return $out;
    }

    /** The expiry stamp FileStore writes as a file's first 10 bytes; null when it is not one. */
    public static function stamp(string $path): ?int
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return null;
        }
        $head = fread($h, 10);
        fclose($h);

        return is_string($head) && strlen($head) === 10 && ctype_digit($head) ? (int) $head : null;
    }
}
