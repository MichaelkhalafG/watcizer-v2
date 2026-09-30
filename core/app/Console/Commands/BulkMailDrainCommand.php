<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use Illuminate\Console\Command;

/**
 * bulk-mail:drain — send queued bulk mail (restock alerts; the re-engagement campaign) within the
 * day's bulk budget, oldest first (2026-10-01). Every five minutes from the scheduler.
 *
 * Order and account mail are not here and never wait on this: they have their own sender
 * (`mail:drain` and the in-request flush) and never check the budget. This command stops as soon as
 * `MailBudget::bulkRemaining()` reaches zero; the rest waits for the next day.
 *
 *   php artisan bulk-mail:drain             # send what the budget allows (at most MAIL_BULK_PER_RUN)
 *   php artisan bulk-mail:drain --report    # today's counts and the budget; sends nothing
 */
final class BulkMailDrainCommand extends Command
{
    protected $signature = 'bulk-mail:drain
        {--limit= : most rows to send in this pass (default MAIL_BULK_PER_RUN)}
        {--report : show today\'s counts and the bulk budget; send nothing}';

    protected $description = 'Send queued bulk mail (restock alerts, campaigns) within the daily bulk budget';

    public function handle(BulkMailer $mailer): int
    {
        if ((bool) $this->option('report')) {
            $this->line(sprintf(
                'today: %d sent (%d transactional, %d bulk) · cap %d · reserve %d · bulk left %d',
                MailBudget::sentToday(), MailBudget::sentToday(MailBudget::TRANSACTIONAL), MailBudget::sentToday(MailBudget::BULK),
                config()->integer('notifications.bulk.daily_cap'), config()->integer('notifications.bulk.transactional_reserve'), MailBudget::bulkRemaining(),
            ));

            return self::SUCCESS;
        }
        $limit = $this->option('limit');
        $result = $mailer->drain(is_numeric($limit) ? (int) $limit : null);
        $this->line(sprintf('bulk mail: %d sent, %d skipped, %d failed · %d still queued · bulk budget left today: %d',
            $result['sent'], $result['skipped'], $result['failed'], $result['waiting'], $result['budget_left']));

        return self::SUCCESS;
    }
}
