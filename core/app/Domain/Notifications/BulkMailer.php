<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Mail\ReEngagementMail;
use App\Mail\ReEngagementPreviewMail;
use App\Mail\StockAlertMail;
use App\Storefront\StorefrontHasNoHost;
use App\Storefront\StorefrontHasNoIdentity;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontUrls;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * PER STOREFRONT (W3/W4, 2026-10-13): each row belongs to the storefront its alert, send or run
 * names; it leaves from that storefront's mailbox ({@see StorefrontMailer}) and counts against that
 * mailbox's budget. One shop's allowance used up leaves the other shop's rows sending; a row whose
 * storefront has no usable mailbox WAITS (pending, an hour at a time) rather than failing — a
 * mailbox is minutes to fix, and the row is the obligation.
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
     * Send what each storefront's budget allows, oldest first.
     *
     * `budget_left` is per storefront id — what each mailbox may still send today after this pass.
     * A row whose storefront's allowance is used up is left where it is (not claimed, not counted)
     * and the next row, which may be the other shop's, is tried.
     *
     * @return array{sent: int, skipped: int, failed: int, budget_left: array<int, int>, waiting: int}
     */
    public function drain(?int $limit = null): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'budget_left' => [], 'waiting' => 0];
        $limit ??= config()->integer('notifications.bulk.per_run');
        $rows = DB::table('integration_outbox')->where('channel', self::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)
            ->where('available_at', '<=', now())->orderBy('id')->limit(max(0, $limit))->get(['id', 'payload']);
        /** @var array<int, int> $remaining */
        $remaining = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $storefrontId = self::storefrontOf(self::decode(Row::nstr($row, 'payload')));
            $remaining[$storefrontId] ??= MailBudget::bulkRemaining($storefrontId);
            if ($remaining[$storefrontId] <= 0) {
                continue;                                     // this shop's rest waits for tomorrow's budget; the other shop's rows still go
            }
            $result = $this->deliver(Row::int($row, 'id'), $storefrontId);
            $out[$result]++;
            if ($result === 'sent') {
                $remaining[$storefrontId]--;
            }
        }
        foreach (array_keys($remaining) as $storefrontId) {
            $out['budget_left'][$storefrontId] = MailBudget::bulkRemaining($storefrontId);
        }
        $out['waiting'] = DB::table('integration_outbox')->where('channel', self::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)->count();

        return $out;
    }

    /**
     * Which storefront a queued row belongs to — the alert's, the send's run's, the preview's
     * run's. 0 when it names none, which no mailbox matches, so such a row never sends.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function storefrontOf(array $payload): int
    {
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        $value = null;
        if ($kind === StockAlerts::KIND) {
            $value = DB::table('core_stock_alerts')->where('id', is_int($payload['alert_id'] ?? null) ? $payload['alert_id'] : 0)->value('storefront_id');
        } elseif ($kind === ReEngagement::KIND) {
            $value = DB::table('core_reengagement_sends as s')->join('core_reengagement_runs as r', 'r.id', '=', 's.run_id')
                ->where('s.id', is_int($payload['send_id'] ?? null) ? $payload['send_id'] : 0)->value('r.storefront_id');
        } elseif ($kind === ReEngagement::KIND.'_preview') {
            $value = DB::table('core_reengagement_runs')->where('id', is_int($payload['run_id'] ?? null) ? $payload['run_id'] : 0)->value('storefront_id');
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private static function decode(?string $payload): array
    {
        $decoded = json_decode($payload ?? '', true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /** @return 'sent'|'skipped'|'failed' */
    private function deliver(int $id, int $storefrontId): string
    {
        $claimed = DB::table('integration_outbox')->where('id', $id)->where('channel', self::CHANNEL)
            ->where('status', OrderMailer::STATUS_PENDING)
            ->update(['status' => OrderMailer::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1'), 'available_at' => now()]);
        if ($claimed === 0) {
            return 'skipped';
        }
        $row = Row::cast(DB::table('integration_outbox')->where('id', $id)->first() ?? new \stdClass);
        $payload = self::decode(Row::nstr($row, 'payload'));
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        $recipient = is_string($payload['recipient'] ?? null) ? $payload['recipient'] : '';

        try {
            $mailable = $recipient === '' ? null : $this->mailable($kind, $payload);
        } catch (StorefrontHasNoHost|StorefrontHasNoIdentity $e) {
            /*
             * No fallback (A2, developer 2026-10-12; identity W2, 2026-10-13): the storefront this
             * row belongs to has no host for its link, or no identity for its chrome. The row is
             * PARKED with that reason — never sent carrying another shop's host or wordmark.
             */
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => OrderMailer::STATUS_PARKED, 'processed_at' => now(),
                'last_error' => 'parked: '.$e->getMessage(),
            ]);

            return 'skipped';
        }
        if ($mailable === null) {
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => OrderMailer::STATUS_SKIPPED, 'processed_at' => now(),
                'last_error' => 'nothing to send at send time (sold out again, or the request was withdrawn)',
            ]);

            return 'skipped';
        }

        try {
            StorefrontMailer::send($storefrontId, $recipient, $mailable);
            DB::table('integration_outbox')->where('id', $id)->update(['status' => OrderMailer::STATUS_SENT, 'processed_at' => now(), 'last_error' => null]);

            return 'sent';
        } catch (StorefrontHasNoMailbox $e) {
            // The storefront's mailbox is missing, disabled or incomplete: the row WAITS — back to
            // pending in an hour, the attempt not counted, the reason on the row. Never another
            // shop's mailbox. (A fixed mailbox sends it on the next drain after the hour.)
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => OrderMailer::STATUS_PENDING, 'attempts' => DB::raw('GREATEST(attempts - 1, 0)'),
                'available_at' => now()->addHour(), 'last_error' => mb_substr('waiting: '.$e->getMessage(), 0, 500),
            ]);
            Log::warning("bulk mail [{$kind}] row {$id} waiting: ".$e->getMessage());

            return 'skipped';
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
     * link names no address — on the STOREFRONT'S OWN API host (W6, 2026-10-12; it was one global
     * host for both shops). Throws {@see StorefrontHasNoHost} when the storefront has none.
     */
    public static function unsubscribeUrl(int $sendId, int $storefrontId): string
    {
        return StorefrontUrls::apiHost($storefrontId).'/unsubscribe/'.$sendId.'/'.self::unsubscribeSignature($sendId);
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

            return $data === null ? null : new StockAlertMail($data, StorefrontUrls::apiHost($data['storefront_id']).'/stock-alerts/stop/'.$data['token'], StorefrontIdentity::for($data['storefront_id']));
        }
        if ($kind === ReEngagement::KIND) {
            $sendId = is_int($payload['send_id'] ?? null) ? $payload['send_id'] : 0;
            $data = app(ReEngagement::class)->mailData($sendId);

            return $data === null ? null : new ReEngagementMail($data, self::unsubscribeUrl($sendId, $data['storefront_id']), StorefrontIdentity::for($data['storefront_id']));
        }
        if ($kind === ReEngagement::KIND.'_preview') {
            $runId = is_int($payload['run_id'] ?? null) ? $payload['run_id'] : 0;
            $data = app(ReEngagement::class)->previewData($runId);
            $storefront = DB::table('core_reengagement_runs')->where('id', $runId)->value('storefront_id');

            $storefrontId = is_numeric($storefront) ? (int) $storefront : 0;

            return $data === null ? null : new ReEngagementPreviewMail($data, config()->string('notifications.manage_url').'/manage/storefronts/'.$storefrontId.'/reengagement', StorefrontIdentity::for($storefrontId));
        }

        return null;
    }
}
