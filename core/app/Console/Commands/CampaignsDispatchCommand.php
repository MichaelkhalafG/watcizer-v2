<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Campaigns\EmailCampaigns;
use App\Domain\Notifications\BulkMailer;
use Illuminate\Console\Command;

/**
 * campaigns:dispatch - every five minutes (2026-11-24, EmailCampaigns::dispatch). Starts the scheduled campaigns whose time
 * has come, hands the bulk outbox only what each shop's bulk allowance can send in this pass, and closes the campaigns
 * with nothing left. WRITES campaign and recipient rows and outbox rows.
 */
final class CampaignsDispatchCommand extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Start due campaigns and queue the next throttled batch of campaign e-mails';

    public function handle(BulkMailer $mailer): int
    {
        $out = EmailCampaigns::dispatch($mailer);
        $this->line(sprintf('campaigns:dispatch: %d started, %d queued, %d finished.', $out['started'], $out['queued'], $out['finished']));

        return self::SUCCESS;
    }
}
