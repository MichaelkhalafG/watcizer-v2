<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * The storefronts' hosts, read from the ROW — the one place a test takes a shop's domain from
 * (2026-10-13). Forty-eight tests used to pin `brandfashionegy.com` as a literal, so step D (the
 * temporary host) and phase F (the real domain back) would each have turned the suite red for a
 * value that is data. A test that wants "whatever host the second shop has" asks here; a test
 * about the OLD WordPress site (the import source, the archive slugs, the TLS probe) keeps its
 * literal, because that site's address is not the storefront row's.
 */
final class Shops
{
    public static function id(string $code): int
    {
        return T::int(DB::table('storefronts')->where('code', $code)->value('id'));
    }

    /** The row's domain, asserted present: a shop with none cannot be addressed and no URL test means anything. */
    public static function domain(string $code): string
    {
        $domain = DB::table('storefronts')->where('code', $code)->value('domain');
        Assert::assertTrue(is_string($domain) && $domain !== '', "storefront [{$code}] has no domain on its row");

        return (string) $domain;
    }

    /** `https://<domain>` — the shop's own site, as `StorefrontUrls::frontend()` derives it. */
    public static function site(string $code): string
    {
        return 'https://'.self::domain($code);
    }

    /** `https://api.<domain>` — the shop's own API/asset host, as `StorefrontUrls::apiHost()` derives it. */
    public static function api(string $code): string
    {
        return 'https://api.'.self::domain($code);
    }
}
