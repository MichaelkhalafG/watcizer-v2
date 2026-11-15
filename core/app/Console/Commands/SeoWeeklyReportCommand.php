<?php

namespace App\Console\Commands;

use App\Domain\Seo\SeoReport;
use Illuminate\Console\Command;

/** The weekly SEO report of every active shop (SEO Phase 5, 2026-11-14) — scheduled Mondays at 07:00; see SeoReport. */
final class SeoWeeklyReportCommand extends Command
{
    protected $signature = 'seo:weekly-report';

    protected $description = 'Write the weekly SEO report of every active shop (articles, 404s, sitemaps, IndexNow, search engines)';

    public function handle(): int
    {
        foreach (SeoReport::writeAll() as $file) {
            $this->line('written: '.$file);
        }

        return self::SUCCESS;
    }
}
