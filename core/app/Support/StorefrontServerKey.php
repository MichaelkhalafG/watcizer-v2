<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Is this API request the STOREFRONT SERVER rendering a page (2026-09-30, option b)?
 *
 * Every storefront page is rendered on the storefront's own server, which calls core once per page
 * view — all from ONE address. Under the per-IP 60-a-minute limit that meant about 60 page views a
 * minute for the whole site, shoppers and crawlers together, and past it product pages 404'd. The
 * server now sends `X-Storefront-Server-Key`; a request carrying the right key gets its own, much
 * higher limit (config `compat.server_rate_per_minute`), and every other request keeps the per-IP 60.
 *
 * The key is a SECRET that exists only in the two servers' environments (core `.env`
 * STOREFRONT_SERVER_KEY, the storefront server's STOREFRONT_SERVER_KEY) — never NEXT_PUBLIC_, never in
 * a browser bundle. Unlike the public `Api-Code`, knowing it lifts the rate limit, so: at least 32
 * characters, compared in constant time, and no key configured means nothing ever matches.
 */
final class StorefrontServerKey
{
    public const HEADER = 'X-Storefront-Server-Key';

    public const MIN_LENGTH = 32;

    public static function matches(Request $request): bool
    {
        $expected = config()->string('compat.server_key');
        if (strlen($expected) < self::MIN_LENGTH) {
            return false;
        }
        $sent = $request->header(self::HEADER);

        return is_string($sent) && hash_equals($expected, $sent);
    }
}
