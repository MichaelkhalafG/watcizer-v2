<?php

namespace App\Console\Commands;

use App\Domain\Seo\IndexNow;
use Illuminate\Console\Command;

/** Send the IndexNow queue (SEO Phase 1, 2026-11-14) — scheduled every ten minutes; nothing is sent while it is off. */
final class IndexNowFlushCommand extends Command
{
    protected $signature = 'indexnow:flush';

    protected $description = 'Tell the IndexNow engines about the addresses product, article and page-text saves queued';

    public function handle(IndexNow $indexNow): int
    {
        $r = $indexNow->flush();
        $this->line($r['enabled']
            ? sprintf('indexnow: %d sent, %d failed (kept for the next run), %d waiting', $r['sent'], $r['failed'], $r['waiting'])
            : sprintf('indexnow: off (INDEXNOW_ENABLED) — %d waiting', $r['waiting']));

        return self::SUCCESS;
    }
}
