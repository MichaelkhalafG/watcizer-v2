<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Today's mail count and what is left of it for BULK mail (2026-10-01).
 *
 * ── The guarantee ────────────────────────────────────────────────────────────────────────────
 *
 * Transactional mail — order confirmations, status updates, admin notifications, verification and
 * password-reset links — NEVER consults this class. It sends exactly as it did before bulk mail
 * existed. Bulk mail (restock alerts, the re-engagement campaign) asks `bulkRemaining()` before each
 * send and stops at `daily_cap - transactional_reserve - sent today`, so however large a batch is,
 * it leaves the reserve for the day's orders and can never be "first in line" ahead of one.
 *
 * ── Counting ─────────────────────────────────────────────────────────────────────────────────
 *
 * Every message that actually leaves counts, whichever code sent it: `CountSentMail` listens to
 * Laravel's `MessageSent`. A message is bulk when it carries the `X-WZ-Mail-Bucket: bulk` header
 * (`BULK_HEADER`), which only the bulk mailables set; everything else is transactional.
 */
final class MailBudget
{
    public const BULK_HEADER = 'X-WZ-Mail-Bucket';

    public const TRANSACTIONAL = 'transactional';

    public const BULK = 'bulk';

    public static function record(string $bucket): void
    {
        $bucket = $bucket === self::BULK ? self::BULK : self::TRANSACTIONAL;
        $day = now()->toDateString();
        // One statement, so two processes counting at once cannot lose an increment.
        DB::statement(
            'INSERT INTO core_mail_daily (day, bucket, sent) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE sent = sent + 1',
            [$day, $bucket],
        );
    }

    public static function sentToday(?string $bucket = null): int
    {
        $query = DB::table('core_mail_daily')->where('day', now()->toDateString());
        if ($bucket !== null) {
            $query->where('bucket', $bucket);
        }

        return (int) $query->sum('sent');
    }

    /** How many bulk messages may still go out today. Never negative. */
    public static function bulkRemaining(): int
    {
        $cap = config()->integer('notifications.bulk.daily_cap');
        $reserve = config()->integer('notifications.bulk.transactional_reserve');

        return max(0, $cap - $reserve - self::sentToday());
    }
}
