<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Http\Controllers\V2\RedirectController;
use App\Models\Storefront\StorefrontRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE 404 MONITOR (SEO Phase 1, 2026-11-14; table `seo_not_found`). One row per path a shop answered 404 for. The
 * shops' servers report each one; recording never fails the page and never grows without end: a storefront keeps at
 * most `MAX_OPEN` open paths (a scan of random addresses stops being recorded there, the paths already known keep
 * counting), and `seo:prune-not-found` drops paths nobody asked for in `KEEP_DAYS`.
 */
final class NotFoundLog
{
    public const MAX_OPEN = 20000;

    public const KEEP_DAYS = 90;

    /** Crawlers, by the words their user agents carry. */
    private const BOT = '/bot|crawl|spider|slurp|facebookexternalhit|bingpreview|mediapartners|lighthouse|headless/i';

    public static function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match(self::BOT, $userAgent) === 1;
    }

    /** A referring page without its query string or fragment (they can carry a person's data), at most 512 characters. */
    public static function referer(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $parts = parse_url($raw);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($parts['scheme'].'://'.$parts['host'].($parts['path'] ?? ''), 0, 512);
    }

    /** Record one 404. False when the path is not a path, or the storefront is at its cap and the path is new. */
    public function record(int $storefrontId, string $rawPath, ?string $referer, bool $bot): bool
    {
        $path = RedirectController::normalise($rawPath);
        if ($path === null || mb_strlen($path) > 512) {
            return false;
        }
        try {
            $hash = StorefrontRedirect::hashPath($path);
            $now = now();
            $ref = self::referer($referer);
            $changes = [
                'hits' => DB::raw('hits + 1'),
                'bot_hits' => DB::raw($bot ? 'bot_hits + 1' : 'bot_hits'),
                'last_seen_at' => $now,
                'resolved_at' => null,                                   // asked for again: open again
                'resolved_by' => null,
            ];
            if ($ref !== null) {
                $changes['last_referer'] = $ref;                         // no referer this time: the last one stays
            }
            if (DB::table('seo_not_found')->where('storefront_id', $storefrontId)->where('path_hash', $hash)->update($changes) > 0) {
                return true;
            }
            if (DB::table('seo_not_found')->where('storefront_id', $storefrontId)->whereNull('resolved_at')->count() >= self::MAX_OPEN) {
                return false;
            }
            DB::table('seo_not_found')->insertOrIgnore([
                'storefront_id' => $storefrontId, 'path' => $path, 'path_hash' => $hash, 'hits' => 1, 'bot_hits' => $bot ? 1 : 0,
                'last_referer' => self::referer($referer), 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('not-found: could not record', ['storefront' => $storefrontId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Drop the paths nobody asked for in KEEP_DAYS days. */
    public function prune(): int
    {
        return DB::table('seo_not_found')->where('last_seen_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
