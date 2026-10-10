<?php

use App\Compat\CompatListing;
use App\Storefront\StorefrontCache;
use App\Support\Cache\ExpiredCachePrune;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * ── cache:prune-expired (2026-10-09, backlog C-GROW) ─────────────────────────────────────────────
 *
 * Every version bump strands a storefront generation on disk; FileStore deletes an expired file only when
 * its key is read. The prune deletes files whose OWN expiry stamp is over an hour past — never a forever
 * entry, never a live-generation file — and re-checks the live generation. Each guard is a case below.
 * The cache directory is a throwaway one; nothing outside it is touched.
 */

/** @return array{dir: string, store: FileStore, path: Closure(string): string, write: Closure(string, int): string} */
function pruneLab(): array
{
    $dir = sys_get_temp_dir().'/prune-test-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    config()->set('cache.stores.file.path', $dir);
    Cache::forgetDriver('file');
    $store = Cache::store('file')->getStore();
    assert($store instanceof FileStore);
    $path = fn (string $key): string => $store->path($key);
    // A file exactly as FileStore::put writes it, with the stamp we choose.
    $write = function (string $key, int $stamp) use ($path): string {
        $p = $path($key);
        @mkdir(dirname($p), 0777, true);
        file_put_contents($p, str_pad((string) $stamp, 10, '0', STR_PAD_LEFT).serialize(['x' => $key]));

        return $p;
    };

    return ['dir' => $dir, 'store' => $store, 'path' => $path, 'write' => $write];
}

afterEach(function () {
    Cache::forgetDriver('file');
});

/** A product that is visible on storefront 1 — its card is part of the live generation. */
function liveProductId(): int
{
    return T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('is_visible', 1)->orderBy('product_id')->value('product_id'));
}

function pruneRun(string $command): PendingCommand
{
    $pending = artisan($command);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

it('deletes only files expired more than the grace ago, and only with delete', function () {
    $lab = pruneLab();
    $now = time();
    $old = ($lab['write'])('sf:1:compat_card:en:999999:v1', $now - 7200);      // dead, expired 2 h ago
    $recent = ($lab['write'])('sf:1:compat_card:en:999998:v1', $now - 600);    // expired 10 min ago
    $future = ($lab['write'])('sf:1:compat_card:en:999997:v1', $now + 600);    // still valid
    $forever = ($lab['write'])('some:forever:key', ExpiredCachePrune::FOREVER); // never expires

    $dry = app(ExpiredCachePrune::class)->run(false, 3600, $now);
    expect($dry['expired'])->toBe(1)
        ->and($dry['deleted'])->toBe(0)
        ->and(is_file($old))->toBeTrue();

    $r = app(ExpiredCachePrune::class)->run(true, 3600, $now);
    expect($r['deleted'])->toBe(1)
        ->and(is_file($old))->toBeFalse()
        ->and(is_file($recent))->toBeTrue()
        ->and(is_file($future))->toBeTrue()
        ->and(is_file($forever))->toBeTrue()
        ->and($r['keep']['within_grace'])->toBe(1)
        ->and($r['keep']['not_expired'])->toBe(1)
        ->and($r['keep']['forever'])->toBe(1)
        ->and($r['ok'])->toBeTrue();
});

it('never deletes a live-generation file, even an expired one, and re-checks them afterwards', function () {
    $lab = pruneLab();
    $now = time();
    $cache = app(StorefrontCache::class);
    $pid = liveProductId();
    $locale = config()->string('compat.pinned_locale');
    $liveCard = ($lab['write'])($cache->key(1, 'compat_card', "{$locale}:{$pid}"), $now - 86400);
    $liveIndex = ($lab['write'])($cache->key(1, 'compat_listing', CompatListing::INDEX_SHAPE), $now - 86400);
    $oldGeneration = ($lab['write'])("sf:1:compat_card:{$locale}:{$pid}:v".($cache->version(1) - 1 ?: 999), $now - 86400);

    $r = app(ExpiredCachePrune::class)->run(true, 3600, $now);
    expect(is_file($liveCard))->toBeTrue()
        ->and(is_file($liveIndex))->toBeTrue()
        ->and(is_file($oldGeneration))->toBeFalse()
        ->and($r['keep']['live'])->toBe(2)
        ->and($r['live'][1]['before'])->toBe(2)
        ->and($r['live'][1]['after'])->toBe(2)
        ->and($r['ok'])->toBeTrue();
});

it('leaves anything that is not a cache file alone', function () {
    $lab = pruneLab();
    $stray = $lab['dir'].'/notes.txt';
    file_put_contents($stray, '0000000001 not a cache entry');
    $short = $lab['dir'].'/ab/cd/abcdef';
    @mkdir(dirname($short), 0777, true);
    file_put_contents($short, '0000000001x');

    $r = app(ExpiredCachePrune::class)->run(true, 0, time());
    expect(is_file($stray))->toBeTrue()
        ->and(is_file($short))->toBeTrue()
        ->and($r['keep']['not_a_cache_file'])->toBe(2)
        ->and($r['deleted'])->toBe(0);
});

it('reads the stamp exactly as FileStore writes it', function () {
    $lab = pruneLab();
    $lab['store']->put('stamp:probe', 'v', 600);
    $stamp = ExpiredCachePrune::stamp(($lab['path'])('stamp:probe'));
    expect($stamp)->toBeGreaterThan(time() + 590)->toBeLessThanOrEqual(time() + 600);
    $lab['store']->forever('stamp:forever', 'v');
    expect(ExpiredCachePrune::stamp(($lab['path'])('stamp:forever')))->toBe(ExpiredCachePrune::FOREVER);
});

it('is read-only without --force, and says so', function () {
    $lab = pruneLab();
    $old = ($lab['write'])('sf:1:compat_card:en:999999:v1', time() - 7200);
    pruneRun('cache:prune-expired')
        ->expectsOutputToContain('would delete 1 files')
        ->expectsOutputToContain('READ-ONLY')
        ->assertSuccessful()
        ->run();
    expect(is_file($old))->toBeTrue();
    pruneRun('cache:prune-expired --force')
        ->expectsOutputToContain('deleted 1 files')
        ->expectsOutputToContain('OK — no live-generation file was removed.')
        ->assertSuccessful()
        ->run();
    expect(is_file($old))->toBeFalse();
});

it('still loads the schedule, nightly, when compat.warm_storefronts is missing', function () {
    // routes/console.php runs on EVERY artisan boot — schedule:run every minute, catalog:warm, config:cache
    // mid-deploy. A throw there stops all of them, so the cadence read must degrade, never throw
    // (2026-10-10: a typed accessor without its default would have done exactly that).
    config()->set('compat', array_diff_key(T::arr(config('compat')), ['warm_storefronts' => true]));
    expect(config()->has('compat.warm_storefronts'))->toBeFalse();

    require base_path('routes/console.php');

    $expressions = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'cache:prune-expired')) {
            $expressions[] = $event->expression;
        }
    }
    expect(end($expressions))->toBe('25 3 * * *');
});

it('leaves one log line per run: examined, deleted, bytes, protected and the grace', function () {
    $lab = pruneLab();
    $log = $lab['dir'].'-scheduled.log';
    config()->set('logging.channels.scheduled.path', $log);
    $now = time();
    ($lab['write'])('sf:1:compat_card:en:999999:v1', $now - 7200);   // one dead file
    $cache = app(StorefrontCache::class);
    ($lab['write'])($cache->key(1, 'compat_listing', CompatListing::INDEX_SHAPE), $now - 7200);   // one live file, expired

    pruneRun('cache:prune-expired')->assertSuccessful()->run();
    pruneRun('cache:prune-expired --force')->assertSuccessful()->run();
    $lines = array_values(array_filter(explode('
', (string) file_get_contents($log)), fn (string $l): bool => str_contains($l, 'cache:prune-expired')));
    @unlink($log);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('.INFO: cache:prune-expired read-only examined=2 would_delete=1 bytes=')
        ->and($lines[1] ?? '')->toMatch('/cache:prune-expired force examined=2 deleted=1 bytes=\d+ protected=1 grace=3600s failed=0 ok=yes(\s|$)/');
});

it('runs nightly at 03:25 while only Watchizer is warmed, hourly once a second storefront is', function () {
    $find = function (): ?string {
        $expr = null;
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'cache:prune-expired')) {
                $expr = $event->expression; // the last registration wins below
            }
        }

        return $expr;
    };
    expect(config('compat.warm_storefronts'))->toBe([1])
        ->and($find())->toBe('25 3 * * *');

    config()->set('compat.warm_storefronts', [1, 2]);
    require base_path('routes/console.php');
    expect($find())->toBe('25 * * * *');
});
