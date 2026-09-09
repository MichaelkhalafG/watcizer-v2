<?php

namespace App\Console\Commands;

use App\Compat\CompatCheckout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * integration:drain — the no-op consumer the study asks for (§4.2): "until the connector exists,
 * rows are marked `skipped` by a no-op consumer so the table does not grow unbounded".
 *
 * Every stock movement writes an `integration_outbox` row for the future Morabaa connector. That
 * connector does not exist, so without this the table would grow one row per sale forever. This
 * marks pending rows `skipped` once they are older than `--older-than` minutes, which keeps a
 * short window of fresh rows visible for debugging while bounding the backlog.
 *
 * It NEVER touches the `mail` channel: those rows record order e-mails core cannot yet send, and
 * skipping them would erase the record of what is owed. They are drained by the mail wave.
 */
final class IntegrationDrainCommand extends Command
{
    protected $signature = 'integration:drain
        {--channel=morabaa : outbox channel to drain}
        {--older-than=60 : only rows created more than this many minutes ago}
        {--limit=5000 : most rows in one pass}';

    protected $description = 'Mark pending integration_outbox rows skipped until a real consumer exists';

    public function handle(): int
    {
        $channel = (string) $this->option('channel');
        if ($channel === CompatCheckout::MAIL_CHANNEL) {
            $this->error('The `mail` channel records order e-mails core does not yet send; draining it would lose them.');

            return self::INVALID;
        }

        $ids = DB::table('integration_outbox')
            ->where('channel', $channel)
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(max(0, (int) $this->option('older-than'))))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id')
            ->map(fn (mixed $v): int => (int) (is_numeric($v) ? $v : 0))
            ->all();

        if ($ids === []) {
            $this->info("integration:drain — nothing pending on channel [{$channel}].");

            return self::SUCCESS;
        }

        DB::table('integration_outbox')->whereIn('id', $ids)->update([
            'status' => 'skipped',
            'processed_at' => now(),
            'last_error' => 'no consumer: marked skipped by integration:drain',
        ]);

        $this->info('integration:drain — '.count($ids)." row(s) on channel [{$channel}] marked skipped (no consumer yet).");

        return self::SUCCESS;
    }
}
