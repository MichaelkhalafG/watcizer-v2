<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * THE UNSUBSCRIBE LINK OF THE ABANDONED-CART E-MAIL AND OF EVERY CAMPAIGN (2026-11-24).
 *
 * `/unsubscribe/{n}/{signature}` on the shop's API host - the SAME path shape as the weekly e-mail's, so the API host's
 * allow-list (runbook §4.1.1) is unchanged. `n` is a row of `core_unsubscribe_links` (one per shop, address and kind);
 * the signature is an HMAC of it under its OWN key (`marketing-unsubscribe:`), so it can never be read as the weekly
 * e-mail's link for the same number, and it names no address. One click: the page submits itself in the browser (a link
 * scanner that runs no script changes nothing) and a mail app's own button posts it (List-Unsubscribe-Post). The address
 * goes on the shop's `core_marketing_optouts`, which every later campaign, reminder and weekly e-mail skips.
 */
final class MarketingLinks
{
    public static function unsubscribeUrl(int $storefrontId, string $email, string $kind): string
    {
        $id = self::linkId($storefrontId, $email, $kind);

        return StorefrontUrls::apiHost($storefrontId).'/unsubscribe/'.$id.'/'.self::signature($id);
    }

    public static function signature(int $id): string
    {
        return substr(hash_hmac('sha256', 'marketing-unsubscribe:'.$id, config()->string('app.key')), 0, 40);
    }

    /** @return array{storefront_id: int, email: string}|null the link's shop and address, when the signature is its own */
    public static function resolve(int $id, string $signature): ?array
    {
        if (! hash_equals(self::signature($id), $signature)) {
            return null;
        }
        $raw = DB::table('core_unsubscribe_links')->where('id', $id)->first(['storefront_id', 'email']);
        if ($raw === null) {
            return null;
        }
        $row = Row::cast($raw);

        return ['storefront_id' => Row::int($row, 'storefront_id'), 'email' => Row::str($row, 'email')];
    }

    private static function linkId(int $storefrontId, string $email, string $kind): int
    {
        $email = mb_strtolower(trim($email));
        $find = fn (): ?int => Coerce::nint(DB::table('core_unsubscribe_links')->where('storefront_id', $storefrontId)->where('email', $email)->where('kind', $kind)->value('id'));
        $id = $find();
        if ($id !== null) {
            return $id;
        }
        try {
            return (int) DB::table('core_unsubscribe_links')->insertGetId(['storefront_id' => $storefrontId, 'email' => $email, 'kind' => $kind, 'created_at' => now()]);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                return (int) $find();                                   // written by a parallel send a moment ago
            }
            throw $e;
        }
    }
}
