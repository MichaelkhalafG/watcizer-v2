<?php

namespace App\Console\Commands;

use App\Domain\Content\BlogSafety;
use Illuminate\Console\Command;

/** The automatic publishing's daily safety check (SEO Phase 5, 2026-11-14) — scheduled at 08:30; see BlogSafety. */
final class BlogSafetyCommand extends Command
{
    protected $signature = 'blog:safety';

    protected $description = "Alert on a short article queue; pause publishing on a sharp search fall (once Search Console is connected)";

    public function handle(): int
    {
        foreach (BlogSafety::check() as $sf => $found) {
            $this->line(sprintf('storefront %d: %s', $sf, $found === [] ? 'nothing to report' : implode('; ', $found)));
        }

        return self::SUCCESS;
    }
}
