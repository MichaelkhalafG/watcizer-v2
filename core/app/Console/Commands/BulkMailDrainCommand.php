<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use App\Models\Storefront\StorefrontMailbox;
use Illuminate\Console\Command;

/**
 * bulk-mail:drain — send queued bulk mail (restock alerts; the re-engagement campaign) within the
 * day's bulk budget, oldest first (2026-10-01). Every five minutes from the scheduler.
 *
 * Order and account mail are not here and never wait on this: they have their own sender
 * (`mail:drain` and the in-request flush) and never check the budget. A storefront's rows stop as
 * soon as ITS mailbox's `MailBudget::bulkRemaining()` reaches zero (W4, per mailbox); the rest
 * waits for the next day, and the other storefront's rows still go.
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
            foreach (StorefrontMailbox::query()->orderBy('storefront_id')->get() as $mailbox) {
                $id = $mailbox->storefrontId();
                $this->line(sprintf(
                    'storefront %d · today: %d sent (%d transactional, %d bulk) · cap %d · reserve %d · bulk left %d%s',
                    $id, MailBudget::sentToday($id), MailBudget::sentToday($id, MailBudget::TRANSACTIONAL), MailBudget::sentToday($id, MailBudget::BULK),
                    $mailbox->dailyCap(), $mailbox->transactionalReserve(), MailBudget::bulkRemaining($id),
                    $mailbox->isEnabled() ? '' : ' · DISABLED',
                ));
            }
            $unattributed = MailBudget::sentToday(0);
            if ($unattributed > 0) {
                $this->line("unattributed (sent without a storefront header): {$unattributed}");
            }

            return self::SUCCESS;
        }
        $limit = $this->option('limit');
        $result = $mailer->drain(is_numeric($limit) ? (int) $limit : null);
        $left = [];
        foreach ($result['budget_left'] as $id => $n) {
            $left[] = "storefront {$id}: {$n}";
        }
        $this->line(sprintf('bulk mail: %d sent, %d skipped, %d failed · %d still queued · bulk budget left today: %s',
            $result['sent'], $result['skipped'], $result['failed'], $result['waiting'], $left === [] ? '—' : implode(', ', $left)));

        return self::SUCCESS;
    }
}
