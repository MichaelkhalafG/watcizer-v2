<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Storefront\StorefrontMailbox;
use Illuminate\Support\Facades\DB;

/**
 * Today's mail count and what is left of it for BULK mail — PER MAILBOX (2026-10-01; per
 * storefront since W4, 2026-10-13).
 *
 * ── The guarantee ────────────────────────────────────────────────────────────────────────────
 *
 * Transactional mail — order confirmations, status updates, admin notifications, verification and
 * password-reset links — NEVER consults this class. It sends exactly as it did before bulk mail
 * existed. Bulk mail (restock alerts, the re-engagement campaign) asks `bulkRemaining($storefront)`
 * before each send and stops at `daily_cap - transactional_reserve - sent today` FOR THAT
 * STOREFRONT'S MAILBOX, so however large a batch is, it leaves the reserve for the day's orders
 * and can never be "first in line" ahead of one.
 *
 * ── Per mailbox, not one cap divided two ways ────────────────────────────────────────────────
 *
 * Each storefront's cap and reserve are on its {@see StorefrontMailbox} row (`MAIL_DAILY_CAP` and
 * `MAIL_TRANSACTIONAL_RESERVE` are retired), and the counter is keyed `(day, bucket, storefront)`.
 * Watchizer using up its allowance does not stop Brand Fashion's restock alerts, and the reverse.
 * A storefront with no mailbox row has NO bulk allowance: it could not send anyway.
 *
 * ── Counting ─────────────────────────────────────────────────────────────────────────────────
 *
 * Every message that actually leaves counts, whichever code sent it: `CountSentMail` listens to
 * Laravel's `MessageSent`. A message is bulk when it carries the `X-WZ-Mail-Bucket: bulk` header
 * (`BULK_HEADER`), which only the bulk mailables set; everything else is transactional. It belongs
 * to the storefront named by `X-WZ-Storefront`, which {@see StorefrontMailer} stamps; a message
 * sent some other way (a `Mail::raw` from a console session) counts under storefront 0.
 *
 * ── Known mismatch, recorded, not fixed (backlog MAIL-PLAN) ──────────────────────────────────
 *
 * Hostinger counts a mailbox's sends per ROLLING 24 hours; this counts per CALENDAR day in the
 * app's timezone and resets at midnight. A day that ends with the allowance used up is followed by
 * a morning the host still counts, so the cap on the row must sit under the host's limit with
 * room: 800 against Hostinger's 1,000, developer's figure (2026-10-12).
 */
final class MailBudget
{
    public const BULK_HEADER = 'X-WZ-Mail-Bucket';

    public const TRANSACTIONAL = 'transactional';

    public const BULK = 'bulk';

    public static function record(string $bucket, int $storefrontId): void
    {
        $bucket = $bucket === self::BULK ? self::BULK : self::TRANSACTIONAL;
        $day = now()->toDateString();
        // One statement, so two processes counting at once cannot lose an increment.
        DB::statement(
            'INSERT INTO core_mail_daily (day, bucket, storefront_id, sent) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE sent = sent + 1',
            [$day, $bucket, $storefrontId],
        );
    }

    public static function sentToday(int $storefrontId, ?string $bucket = null): int
    {
        $query = DB::table('core_mail_daily')->where('day', now()->toDateString())->where('storefront_id', $storefrontId);
        if ($bucket !== null) {
            $query->where('bucket', $bucket);
        }

        return (int) $query->sum('sent');
    }

    /** How many bulk messages this storefront's mailbox may still send today. Never negative. */
    public static function bulkRemaining(int $storefrontId): int
    {
        $mailbox = StorefrontMailbox::query()->where('storefront_id', $storefrontId)->first(['daily_cap', 'transactional_reserve', 'is_enabled']);
        if ($mailbox === null || ! $mailbox->isEnabled()) {
            return 0;
        }

        return max(0, $mailbox->dailyCap() - $mailbox->transactionalReserve() - self::sentToday($storefrontId));
    }
}
