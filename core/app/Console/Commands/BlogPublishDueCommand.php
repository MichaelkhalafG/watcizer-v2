<?php

namespace App\Console\Commands;

use App\Domain\Content\BlogPublisher;
use Illuminate\Console\Command;

/** Publish the articles whose time has come (SEO Phase 3, 2026-11-14) — scheduled every ten minutes; safe to re-run. */
final class BlogPublishDueCommand extends Command
{
    protected $signature = 'blog:publish-due';

    protected $description = 'Publish the scheduled articles that are due (skipping shops whose publishing is paused)';

    public function handle(BlogPublisher $publisher): int
    {
        $result = $publisher->publishDue();
        if ($result === []) {
            $this->line('blog: nothing due');
        }
        foreach ($result as $sf => $r) {
            $this->line(sprintf('blog: storefront %d — %d published, %d failed (retried next run), %d waiting (publishing paused)', $sf, $r['published'], $r['failed'], $r['paused']));
        }

        return self::SUCCESS;
    }
}
