<?php

namespace App\Console\Commands;

use App\Domain\Seo\NotFoundLog;
use Illuminate\Console\Command;

/** The 404 monitor's housekeeping (SEO Phase 1, 2026-11-14) — scheduled daily. */
final class SeoPruneNotFoundCommand extends Command
{
    protected $signature = 'seo:prune-not-found';

    protected $description = 'Drop the not-found addresses nobody asked for in 90 days';

    public function handle(NotFoundLog $log): int
    {
        $this->line(sprintf('not-found: %d addresses not asked for in %d days dropped', $log->prune(), NotFoundLog::KEEP_DAYS));

        return self::SUCCESS;
    }
}
