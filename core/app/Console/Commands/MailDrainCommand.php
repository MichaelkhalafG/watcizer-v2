<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\OrderMailer;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * mail:drain — the retry path for order e-mail, and the operator's view of what did not go out.
 *
 * ── Why this exists when sending is already in-request ───────────────────────────────────────
 *
 * Sending inline (the default, and what the legacy app does) is right for the ordinary case: the
 * customer's confirmation arrives while they are still looking at the thank-you page. It is wrong
 * for the case that matters — the relay refused, the network blinked, the process was killed — and
 * that is what this command is for. Every failed inline attempt leaves its row `pending` with a
 * backoff, and a one-minute cron tick picks it up. §2.12: queues run `sync` plus a one-minute
 * cron, and this rides the cron that wave 3 already needs for
 * `inventory:reconcile-cancellations`, so switch night adds no new crontab entry.
 *
 * It is also the whole sender when `ORDER_MAIL_INLINE=false` — the setting a host with a slow or
 * rate-limited SMTP relay should use. Nothing else changes: the obligation rows are written the
 * same way either way, which is the point of writing them at all.
 *
 * ── `--report` is loud on purpose ────────────────────────────────────────────────────────────
 *
 * A `failed` row means a real person was not told something. `--report` lists them and exits
 * NON-ZERO while any exist, the same loudness `payments:findings` has for money, so a deploy
 * check or a cron mail can carry the signal instead of it sitting in a table nobody opens.
 */
final class MailDrainCommand extends Command
{
    protected $signature = 'mail:drain
        {--limit=50 : most rows to send in one pass}
        {--report : list what is pending, failed and skipped; send nothing; exit 1 while any row is failed}
        {--reclaim : also return rows stuck in `sending` (a process killed mid-send) to pending}
        {--order= : restrict to one order id}';

    protected $description = 'Send pending order e-mails, retry deferred ones, and report what did not go out';

    public function __construct(private readonly OrderMailer $mailer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $orderId = $this->option('order');
        $orderId = is_numeric($orderId) ? (int) $orderId : null;

        if ((bool) $this->option('report')) {
            return $this->report($orderId);
        }

        $reclaimed = (bool) $this->option('reclaim') ? $this->reclaim() : 0;
        if ($reclaimed > 0) {
            $this->warn("reclaimed {$reclaimed} row(s) stuck in `sending` (a process died mid-send).");
        }

        $ids = $this->claimable($orderId);

        if ($ids === []) {
            $this->info('mail:drain — nothing due.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($ids as $id) {
            if ($this->mailer->deliver($id)) {
                $sent++;
            }
        }

        $this->info('mail:drain — '.$sent.' of '.count($ids).' due row(s) sent.');

        // Whatever did not send is either deferred for another tick or failed; `--report` says
        // which, and the exit code stays 0 here because a deferred message is not an error.
        return self::SUCCESS;
    }

    /**
     * The ids whose turn it is: pending, and past their backoff.
     *
     * Ordered by `available_at` then id, so the oldest owed message goes first and a burst of new
     * orders cannot starve a retry.
     *
     * @return list<int>
     */
    private function claimable(?int $orderId): array
    {
        $query = DB::table('integration_outbox')
            ->where('channel', OrderMailer::CHANNEL)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')));

        if ($orderId !== null) {
            $query->where('aggregate_type', 'orders')->where('aggregate_id', $orderId);
        }

        $out = [];
        foreach ($query->pluck('id') as $id) {
            $out[] = is_numeric($id) ? (int) $id : 0;
        }

        return $out;
    }

    /**
     * Rows left `sending` longer than the grace period go back to `pending`.
     *
     * `attempts` was already incremented by the claim, so a row that kills its process every time
     * still reaches `max_attempts` and parks itself instead of looping forever.
     */
    private function reclaim(): int
    {
        $minutes = max(1, config()->integer('notifications.send.reclaim_after_minutes'));

        return DB::table('integration_outbox')
            ->where('channel', OrderMailer::CHANNEL)
            ->where('status', OrderMailer::STATUS_SENDING)
            ->where('available_at', '<=', now()->subMinutes($minutes))
            ->update([
                'status' => OrderMailer::STATUS_PENDING,
                'last_error' => 'reclaimed by mail:drain --reclaim: the previous send never finished',
            ]);
    }

    /** What went out, what is waiting, and what a human has to deal with. */
    private function report(?int $orderId): int
    {
        $base = DB::table('integration_outbox')
            ->where('channel', OrderMailer::CHANNEL);

        if ($orderId !== null) {
            $base->where('aggregate_type', 'orders')->where('aggregate_id', $orderId);
        }

        $counts = [];
        foreach ((clone $base)->selectRaw('status, COUNT(*) as c')->groupBy('status')->get() as $raw) {
            $row = Row::cast($raw);
            $counts[Row::str($row, 'status')] = Row::int($row, 'c');
        }

        if ($counts === []) {
            $this->info('mail:drain --report — no order e-mail on record'.($orderId === null ? '.' : " for order {$orderId}."));

            return self::SUCCESS;
        }

        $this->line('order e-mail by status:');
        foreach (['sent', 'pending', 'sending', 'failed', 'skipped'] as $status) {
            if (isset($counts[$status])) {
                $this->line(sprintf('  %-8s %5d', $status, $counts[$status]));
            }
        }

        $failed = (clone $base)->where('status', OrderMailer::STATUS_FAILED)
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'event', 'aggregate_id', 'attempts', 'last_error', 'created_at']);

        if ($failed->isEmpty()) {
            $this->info('no failed order e-mail.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('FAILED — a real person was not told something:');
        $rows = [];
        foreach ($failed as $raw) {
            $row = Row::cast($raw);
            $rows[] = [
                Row::int($row, 'id'),
                Row::str($row, 'event'),
                Row::int($row, 'aggregate_id'),
                Row::int($row, 'attempts'),
                mb_substr(Row::nstr($row, 'last_error') ?? '', 0, 80),
            ];
        }
        $this->table(['outbox', 'event', 'order', 'tries', 'last error'], $rows);

        // Non-zero while anything is failed — the same contract `payments:findings` has.
        return self::FAILURE;
    }
}
