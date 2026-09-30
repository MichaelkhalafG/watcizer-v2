<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "last seen" core owns (2026-10-01): a customer's most recent signed-in request to a
 * storefront, per storefront. The legacy `users.last_login_at` stopped moving at the storefront
 * flip; the re-engagement e-mail needs to know who has actually been away.
 *
 * Written from `CompatAuth` (every signed-in storefront call passes it) and on sign-in, at most once
 * an hour per customer and storefront — a cache key gates the write — and never allowed to fail a
 * request: a missed stamp only makes someone look an hour more absent than they are.
 */
final class CustomerSeen
{
    public static function touch(int $userId, int $storefrontId): void
    {
        try {
            if (! Cache::add("customer-seen:{$userId}:{$storefrontId}", 1, 3600)) {
                return;                                        // stamped within the hour
            }
            DB::statement(
                'INSERT INTO core_customer_seen (user_id, storefront_id, last_seen_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)',
                [$userId, $storefrontId, now()->toDateTimeString()],
            );
        } catch (Throwable $e) {
            Log::warning("customer last-seen not recorded for {$userId}: ".$e->getMessage());
        }
    }

    /**
     * When a customer was last seen on a storefront: core's own stamp, or — for someone not back
     * since the flip — the legacy `last_login_at`. Null when neither exists.
     */
    public static function lastSeen(int $userId, int $storefrontId): ?string
    {
        $core = DB::table('core_customer_seen')->where('user_id', $userId)->where('storefront_id', $storefrontId)->value('last_seen_at');
        $legacy = DB::table('users')->where('id', $userId)->value('last_login_at');
        $stamps = array_filter([is_string($core) ? $core : null, is_string($legacy) ? $legacy : null]);

        return $stamps === [] ? null : max($stamps);
    }
}
