<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\StockAlerts;
use Illuminate\Console\Command;

/**
 * stock-alerts:prune — delete stock alerts past keeping (2026-10-01): notified and cancelled rows
 * 30 days on, rows still waiting 180 days after the shopper asked. Daily from the scheduler.
 */
final class StockAlertsPruneCommand extends Command
{
    protected $signature = 'stock-alerts:prune';

    protected $description = 'Delete notified stock alerts after 30 days and unanswered ones after 180';

    public function handle(): int
    {
        $out = StockAlerts::prune();
        $this->line("stock alerts pruned: {$out['notified']} notified or cancelled, {$out['waiting']} unanswered.");

        return self::SUCCESS;
    }
}
