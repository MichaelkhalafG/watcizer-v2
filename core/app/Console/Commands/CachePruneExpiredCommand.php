<?php

namespace App\Console\Commands;

use App\Support\Cache\ExpiredCachePrune;
use Illuminate\Console\Command;

/**
 * DELETES the file cache's EXPIRED files with `--force`; without it, a read-only report. The storefront
 * cache strands a whole generation on disk at every version bump and Laravel's FileStore never deletes
 * an expired file nobody reads (backlog C-GROW). The guards — expiry stamp older than `--grace`, never a
 * forever entry, never a live-generation file, a re-check after deleting — are in ExpiredCachePrune.
 *
 *   php artisan cache:prune-expired              # read-only: what it would delete, and what it keeps
 *   php artisan cache:prune-expired --force      # delete, then re-check the live generation
 *
 * NOT `cache:clear`: that evicts the live catalogue too and forces a rebuild under traffic.
 * Scheduled in routes/console.php.
 */
final class CachePruneExpiredCommand extends Command
{
    protected $signature = 'cache:prune-expired
                            {--grace=3600 : only files expired more than this many seconds ago}
                            {--force : delete them (without it, nothing is removed)}';

    protected $description = 'Delete the file cache entries that have expired and that nothing will read again';

    public function handle(ExpiredCachePrune $prune): int
    {
        $delete = (bool) $this->option('force');
        $grace = is_numeric($this->option('grace')) ? (int) $this->option('grace') : 3600;
        $r = $prune->run($delete, $grace);

        $this->line(sprintf('cache prune — %s — %s UTC', $delete ? 'DELETE' : 'READ-ONLY', gmdate('Y-m-d H:i:s')));
        $this->line('directory '.$r['directory']);
        foreach ($r['live'] as $sf => $l) {
            $this->line(sprintf('storefront %d: live version v%d — %d live keys, %d on disk (never deleted)', $sf, $l['version'], $l['keys'], $l['before']));
        }
        $this->line(sprintf('scanned %d files, %.1f MB, in %.1f s', $r['scanned'], $r['bytes'] / 1048576, $r['seconds']));
        $this->line(sprintf('%s %d files, %.1f MB (expired more than %d s ago)',
            $delete ? 'deleted' : 'would delete', $delete ? $r['deleted'] : $r['expired'], $r['expired_bytes'] / 1048576, $r['grace']));
        $k = $r['keep'];
        $this->line(sprintf('kept: %d live-generation, %d not yet expired, %d within the grace, %d forever, %d not a cache file, %d unreadable',
            $k['live'], $k['not_expired'], $k['within_grace'], $k['forever'], $k['not_a_cache_file'], $k['unreadable']));
        foreach ($r['by_day'] as $day => $n) {
            $this->line("  expired {$day}: {$n}");
        }
        if ($r['failed'] > 0) {
            $this->error(sprintf('could not delete %d files (permissions?)', $r['failed']));
        }
        if (! $delete) {
            $this->line('READ-ONLY: nothing was removed. Run with --force to delete the files above.');

            return self::SUCCESS;
        }
        foreach ($r['live'] as $sf => $l) {
            $this->line(sprintf('re-check storefront %d: %d live files on disk (%d before)', $sf, (int) $l['after'], $l['before']));
        }
        if (! $r['ok']) {
            $this->error('A live-generation file is missing after the run — check catalog:warm (it rewrites them).');

            return self::FAILURE;
        }
        $this->info('OK — no live-generation file was removed.');

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
