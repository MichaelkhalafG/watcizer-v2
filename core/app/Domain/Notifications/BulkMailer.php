<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Mail\ReEngagementMail;
use App\Mail\ReEngagementPreviewMail;
use App\Mail\StockAlertMail;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Bulk mail — restock alerts now, the re-engagement campaign next (2026-10-01).
 *
 * The same outbox as order mail (`integration_outbox`: the row is the obligation, a conditional
 * UPDATE claims it, a unique `dedupe_key` refuses a second copy) on its OWN channel, `mail_bulk`,
 * so `mail:drain` never sees these rows and nothing here can delay an order e-mail. Sent only by
 * `bulk-mail:drain` (every five minutes), oldest first, and only while `MailBudget::bulkRemaining()`
 * says the day's bulk allowance is not used up; what does not fit waits for tomorrow.
 *
 * Each row carries a `kind` and whatever that kind needs to build its message AT SEND TIME (a
 * restock alert re-checks the stock then), so a row written at 09:00 and sent at 18:00 says what is
 * true at 18:00.
 */
final class BulkMailer
{
    public const CHANNEL = 'mail_bulk';

    /**
     * @param  array<string, mixed>  $payload
     * @return int|null the row id, or null when this exact message is already queued
     */
    public function enqueue(string $kind, string $recipient, array $payload, string $dedupe): ?int
    {
        $parked = OrderMailer::parking();
        try {
            return (int) DB::table('integration_outbox')->insertGetId([
                'channel' => self::CHANNEL,
                'event' => $kind,
                'dedupe_key' => $dedupe,
                'aggregate_type' => $kind,
                'aggregate_id' => is_int($payload['alert_id'] ?? null) ? $payload['alert_id'] : 0,
                'payload' => (string) json_encode(['kind' => $kind, 'recipient' => $recipient] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $parked ? OrderMailer::STATUS_PARKED : OrderMailer::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'processed_at' => $parked ? now() : null,
                'last_error' => $parked ? 'parked: written by a run whose orders are not real (notifications.send.park)' : null,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                return null;                                   // already queued: the unique key working
            }
            throw $e;
        }
    }

    /**
     * Send what the budget allows, oldest first.
     *
     * @return array{sent: int, skipped: int, failed: int, budget_left: int, waiting: int}
     */
    public function drain(?int $limit = null): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'budget_left' => 0, 'waiting' => 0];
        $limit ??= config()->integer('notifications.bulk.per_run');
        $ids = DB::table('integration_outbox')->where('channel', self::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)
            ->where('available_at', '<=', now())->orderBy('id')->limit(max(0, $limit))->pluck('id');
        foreach ($ids as $id) {
            if (MailBudget::bulkRemaining() <= 0) {
                break;                                        // the rest waits for tomorrow's budget
            }
            $result = $this->deliver((int) (is_numeric($id) ? $id : 0));
            $out[$result]++;
        }
        $out['budget_left'] = MailBudget::bulkRemaining();
        $out['waiting'] = DB::table('integration_outbox')->where('channel', self::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)->count();

        return $out;
    }

    /** @return 'sent'|'skipped'|'failed' */
    private function deliver(int $id): string
    {
        $claimed = DB::table('integration_outbox')->where('id', $id)->where('channel', self::CHANNEL)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->update(['status' => OrderMailer::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1'), 'available_at' => now()]);
        if ($claimed === 0) {
            return 'skipped';
        }
        $row = Row::cast(DB::table('integration_outbox')->where('id', $id)->first() ?? new \stdClass);
        $decoded = json_decode(Row::nstr($row, 'payload') ?? '', true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        $recipient = is_string($payload['recipient'] ?? null) ? $payload['recipient'] : '';

        $mailable = $recipient === '' ? null : $this->mailable($kind, $payload);
        if ($mailable === null) {
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => OrderMailer::STATUS_SKIPPED, 'processed_at' => now(),
                'last_error' => 'nothing to send at send time (sold out again, or the request was withdrawn)',
            ]);

            return 'skipped';
        }

        try {
            Mail::to($recipient)->send($mailable);
            DB::table('integration_outbox')->where('id', $id)->update(['status' => OrderMailer::STATUS_SENT, 'processed_at' => now(), 'last_error' => null]);

            return 'sent';
        } catch (Throwable $e) {
            $attempts = Row::int($row, 'attempts');
            $final = $attempts >= config()->integer('notifications.bulk.max_attempts');
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => $final ? OrderMailer::STATUS_FAILED : OrderMailer::STATUS_PENDING,
                'available_at' => now()->addMinutes(15 * $attempts),
                'processed_at' => $final ? now() : null,
                'last_error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 500),
            ]);
            Log::warning("bulk mail [{$kind}] row {$id} not sent: ".get_class($e));

            return 'failed';
        }
    }

    /**
     * The re-engagement e-mail's unsubscribe link: the send's id and an HMAC of it (APP_KEY), so the
     * link names no address and works on whichever host serves it.
     */
    public static function unsubscribeUrl(int $sendId): string
    {
        return config()->string('notifications.stock_alerts.public_url').'/unsubscribe/'.$sendId.'/'.self::unsubscribeSignature($sendId);
    }

    public static function unsubscribeSignature(int $sendId): string
    {
        return substr(hash_hmac('sha256', 'reengagement-unsubscribe:'.$sendId, config()->string('app.key')), 0, 40);
    }

    /** @param  array<string, mixed>  $payload */
    private function mailable(string $kind, array $payload): ?Mailable
    {
        if ($kind === StockAlerts::KIND) {
            $data = app(StockAlerts::class)->mailData(is_int($payload['alert_id'] ?? null) ? $payload['alert_id'] : 0);

            return $data === null ? null : new StockAlertMail($data);
        }
        if ($kind === ReEngagement::KIND) {
            $sendId = is_int($payload['send_id'] ?? null) ? $payload['send_id'] : 0;
            $data = app(ReEngagement::class)->mailData($sendId);

            return $data === null ? null : new ReEngagementMail($data, self::unsubscribeUrl($sendId));
        }
        if ($kind === ReEngagement::KIND.'_preview') {
            $runId = is_int($payload['run_id'] ?? null) ? $payload['run_id'] : 0;
            $data = app(ReEngagement::class)->previewData($runId);
            $storefront = DB::table('core_reengagement_runs')->where('id', $runId)->value('storefront_id');

            return $data === null ? null : new ReEngagementPreviewMail($data, config()->string('notifications.manage_url').'/manage/storefronts/'.(is_numeric($storefront) ? (int) $storefront : 1).'/reengagement');
        }

        return null;
    }
}
