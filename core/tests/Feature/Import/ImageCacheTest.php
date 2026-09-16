<?php

use App\Domain\Import\ImageCache;
use App\Domain\Media\MediaStore;
use Tests\Support\T;

/*
 * The record that lets a re-run skip an image it already fetched and processed (2026-09-14).
 *
 * ── Why this needs a test at all ────────────────────────────────────────────────────────────
 *
 * Because both ways of being wrong are silent. A stale hit attaches yesterday's photo and says
 * nothing; a lost manifest re-downloads and says nothing. The first is a correctness bug and the
 * second is only slow — so the tests that matter are the ones proving the cache prefers to be
 * slow: it verifies the FILE before it trusts itself, and a miss falls through to a fetch.
 */

/** A fresh cache over a scratch manifest, so the real one is never touched. */
function cacheOverScratch(): ImageCache
{
    $path = storage_path('import/image-cache.jsonl');
    if (is_file($path)) {
        // Never clobber a real run's manifest: this suite writes only when there isn't one.
        @rename($path, $path.'.testbak');
    }

    return new ImageCache;
}

afterEach(function () {
    $path = storage_path('import/image-cache.jsonl');
    @unlink($path);
    if (is_file($path.'.testbak')) {
        @rename($path.'.testbak', $path);
    }
});

it('answers NOTHING for a url it has never seen', function () {
    expect(cacheOverScratch()->find('https://example.test/never-fetched.jpg'))->toBeNull();
});

it('remembers a fetch and finds it again — the whole point', function () {
    $cache = cacheOverScratch();
    $url = 'https://example.test/photo-'.uniqid().'.jpg';

    // A file that really is on disk, because `find()` verifies before it trusts.
    $folder = 'Product';
    $name = 'imagecache-test-'.uniqid().'.webp';
    $absolute = MediaStore::directory($folder).'/'.$name;
    file_put_contents($absolute, 'not really a webp, but it exists');

    try {
        $cache->remember($url, [
            'file' => $name,
            'folder' => $folder,
            'width' => 1200,
            'height' => 1200,
            'renditions' => [320 => ['webp' => $name.'-320.webp']],
        ]);

        // The same instance…
        $found = T::arr($cache->find($url));
        expect($found['file'])->toBe($name)
            ->and($found['folder'])->toBe($folder)
            ->and($found['renditions'])->toBe([320 => ['webp' => $name.'-320.webp']]);

        // …and a FRESH one, which is the case that matters: the next run is a new process.
        $reloaded = T::arr((new ImageCache)->find($url));
        expect($reloaded['file'])->toBe($name);
    } finally {
        @unlink($absolute);
    }
});

it('falls through to a fetch when the FILE is gone, even though it remembers it', function () {
    /*
     * `media:prune` can remove a master between runs. A row pointing at a deleted file is a broken
     * image on every screen — far worse than paying for the download again — so the filesystem is
     * the authority and the manifest is only a claim.
     */
    $cache = cacheOverScratch();
    $url = 'https://example.test/deleted-'.uniqid().'.jpg';

    $cache->remember($url, [
        'file' => 'this-file-does-not-exist-'.uniqid().'.webp',
        'folder' => 'Product',
        'width' => 1200,
        'height' => 1200,
        'renditions' => [],
    ]);

    expect($cache->find($url))->toBeNull();
});

it('stops READING when --refresh-images is on, and keeps WRITING', function () {
    $cache = cacheOverScratch();
    $url = 'https://example.test/refresh-'.uniqid().'.jpg';

    $folder = 'Product';
    $name = 'imagecache-refresh-'.uniqid().'.webp';
    $absolute = MediaStore::directory($folder).'/'.$name;
    file_put_contents($absolute, 'exists');

    try {
        $cache->remember($url, ['file' => $name, 'folder' => $folder, 'width' => 1, 'height' => 1, 'renditions' => []]);
        expect($cache->find($url))->not->toBeNull();

        $cache->refreshEverything();

        // Reuse is off…
        expect($cache->find($url))->toBeNull();

        // …but the run still records what it re-fetches, so the NEXT run benefits.
        $second = 'https://example.test/refresh2-'.uniqid().'.jpg';
        $cache->remember($second, ['file' => $name, 'folder' => $folder, 'width' => 1, 'height' => 1, 'renditions' => []]);
        expect((new ImageCache)->find($second))->not->toBeNull();
    } finally {
        @unlink($absolute);
    }
});

it('survives a torn line without losing the rest of the manifest', function () {
    // A worker killed mid-append leaves half a line. One bad line must not cost the whole file.
    $cache = cacheOverScratch();
    $path = storage_path('import/image-cache.jsonl');

    $folder = 'Product';
    $name = 'imagecache-torn-'.uniqid().'.webp';
    $absolute = MediaStore::directory($folder).'/'.$name;
    file_put_contents($absolute, 'exists');

    try {
        $good = 'https://example.test/good-'.uniqid().'.jpg';
        $cache->remember($good, ['file' => $name, 'folder' => $folder, 'width' => 1, 'height' => 1, 'renditions' => []]);

        file_put_contents($path, '{"k":"half-a-lin'."\n", FILE_APPEND);

        expect((new ImageCache)->find($good))->not->toBeNull('a torn line swallowed the good entries');
    } finally {
        @unlink($absolute);
    }
});

it('is keyed by the URL, so a different url is a different image', function () {
    $cache = cacheOverScratch();

    $folder = 'Product';
    $name = 'imagecache-key-'.uniqid().'.webp';
    $absolute = MediaStore::directory($folder).'/'.$name;
    file_put_contents($absolute, 'exists');

    try {
        $cache->remember('https://example.test/a.jpg', ['file' => $name, 'folder' => $folder, 'width' => 1, 'height' => 1, 'renditions' => []]);

        expect($cache->find('https://example.test/a.jpg'))->not->toBeNull()
            ->and($cache->find('https://example.test/b.jpg'))->toBeNull()
            // …and whitespace around the same url is the same url.
            ->and($cache->find(' https://example.test/a.jpg '))->not->toBeNull();
    } finally {
        @unlink($absolute);
    }
});
