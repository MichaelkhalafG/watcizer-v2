<?php

namespace App\Console\Commands;

use App\Domain\Content\WeeklyBatch;
use Illuminate\Console\Command;

/**
 * Articles week by week (Michael, 2026-11-14): `blog:week-reminder` (daily 09:00 Cairo — the day-6 reminder) and
 * `blog:next-topics` (the next 7 topics per shop from the map: what "write next week's batch" writes).
 */
final class BlogWeekCommands extends Command
{
    protected $signature = 'blog:week {action : reminder | next-topics} {--shop= : one shop code} {--n=7}';

    protected $description = "The weekly article batch: the day-6 reminder, or next week's topics from the topic map";

    public function handle(): int
    {
        if ($this->argument('action') === 'reminder') {
            foreach (WeeklyBatch::remind() as $sf => $what) {
                $this->line("storefront {$sf}: {$what}");
            }

            return self::SUCCESS;
        }
        $shops = $this->option('shop') ? [(string) $this->option('shop')] : ['watchizer', 'brandfashion'];
        foreach ($shops as $shop) {
            $this->info($shop);
            foreach (WeeklyBatch::nextTopics($shop, max(1, (int) $this->option('n'))) as $t) {
                $this->line(sprintf('  %s  %s  [%s / %s]  supports %s  blocks %s', $t['id'] ?? '', $t['title_en'] ?? '', $t['cluster'] ?? '', $t['type'] ?? '', $t['supports'] ?? '', $t['blocks'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
