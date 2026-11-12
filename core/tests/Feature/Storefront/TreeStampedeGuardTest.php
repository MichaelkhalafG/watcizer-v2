<?php

use App\Storefront\CategoryTree;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Cache;

/*
 * The 2026-11-12 production incident: the category tree's cache went empty (the import and the switch flushed it) and
 * every request that missed it started its own 150-second build. The guard: ONE build per storefront and entry at a
 * time, the LAST GOOD value served meanwhile; the warm-up builds it under the same lock.
 */

beforeEach(fn () => Cache::flush());

it('builds once on a miss, keeps a last good copy, and serves it while another process holds the build lock', function () {
    $cache = app(StorefrontCache::class);
    $builds = 0;
    $build = function () use (&$builds): array {
        $builds++;

        return ['built' => $builds];
    };

    expect($cache->rememberGuarded(2, 'tree', '', 3600, $build))->toBe(['built' => 1])
        ->and($cache->rememberGuarded(2, 'tree', '', 3600, $build))->toBe(['built' => 1])     // a hit
        ->and($builds)->toBe(1)
        ->and(Cache::get(StorefrontCache::lastGoodKey(2, 'tree')))->toBe(['built' => 1]);

    $cache->flush(2);                                                                        // the import / the switch
    $other = Cache::lock(StorefrontCache::buildLockKey(2, 'tree'), 60);
    expect($other->get())->toBeTrue();                                                       // another request is building
    expect($cache->rememberGuarded(2, 'tree', '', 3600, $build))->toBe(['built' => 1])     // the last good value, at once
        ->and($builds)->toBe(1);                                                             // and NO second build
    $other->release();

    expect($cache->rememberGuarded(2, 'tree', '', 3600, $build))->toBe(['built' => 2])     // the lock free: one rebuild
        ->and($builds)->toBe(2);
});

it('warms under the same lock: a held lock is left to its holder, a free one builds and stores both copies', function () {
    $cache = app(StorefrontCache::class);
    $other = Cache::lock(StorefrontCache::buildLockKey(1, 'tree'), 60);
    expect($other->get())->toBeTrue()
        ->and($cache->refreshGuarded(1, 'tree', '', 3600, fn () => ['x' => 1]))->toBeNull();
    $other->release();

    $tree = CategoryTree::warm(1);
    expect($tree)->toBeArray()->toHaveKeys(['nodes', 'roots'])
        ->and(Cache::get($cache->key(1, 'tree')))->toBe($tree)
        ->and(Cache::get(StorefrontCache::lastGoodKey(1, 'tree')))->toBe($tree);
});
