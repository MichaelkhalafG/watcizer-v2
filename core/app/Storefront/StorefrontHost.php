<?php

namespace App\Storefront;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which storefront a HOST belongs to — the one answer every storefront-facing route shares.
 *
 * ── Why this exists (brand separation, 2026-09-26) ───────────────────────────────────────────
 *
 * The two storefronts must not be seen to share a backend. Compat already resolved its storefront
 * from the request host, but two route families took it from the URL PATH instead:
 *
 *   • `/api/v2/{storefront}/…` — `api.watchizereg.com/api/v2/brandfashion/meta` answered 200 with
 *     Brand Fashion's catalogue, from Watchizer's own API host, to anybody who guessed the code;
 *   • `/api/pay/{storefront}/{provider}/callback` — answered 403 for a storefront that exists and
 *     404 for one that does not, which is enough to confirm the other shop exists.
 *
 * Both now ask {@see self::serves()}: a host that belongs to a storefront may only serve THAT
 * storefront, and a mismatch answers exactly as an unknown storefront does, so nothing can tell
 * "not yours" from "does not exist".
 *
 * The matching rule is the one `CompatStorefront` has always used, moved here unchanged so the
 * three can never disagree: exact or sub-domain against `storefronts.domain`, longest domain wins,
 * `www.` and a trailing dot ignored, never a bare suffix (`notwatchizereg.com` is not Watchizer).
 */
final class StorefrontHost
{
    /** The active storefront this host belongs to, or null when it belongs to none. */
    public static function storefrontIdFor(string $host): ?int
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        // A port never reaches getHost(), but a trailing dot (the fully-qualified form) can.
        $host = rtrim($host, '.');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $best = null;
        $bestLength = 0;

        foreach (self::domains() as $id => $domain) {
            // Exact, or a sub-domain of it — never a bare suffix match, which would let
            // `notwatchizereg.com` resolve to Watchizer.
            $isMatch = $host === $domain || str_ends_with($host, '.'.$domain);

            if ($isMatch && strlen($domain) > $bestLength) {
                $best = $id;
                $bestLength = strlen($domain);
            }
        }

        return $best;
    }

    /**
     * Every active shop's domain, lower-cased, keyed by id — cached for as long as ResolveStorefront
     * caches the storefront row (`storefront.ttl.storefront`), and forgotten with it by
     * StorefrontCache::forgetStorefront(). Uncached, the host binding cost every v2 request one
     * query (V2\QueryBudgetTest caught it: 14 against a budget of 13).
     *
     * @return array<int, string>
     */
    private static function domains(): array
    {
        $cached = Cache::get(StorefrontCache::HOSTS_KEY);
        if (is_array($cached)) {
            $out = [];
            foreach ($cached as $id => $domain) {
                if (is_int($id) && is_string($domain)) {
                    $out[$id] = $domain;
                }
            }

            return $out;
        }

        $out = [];
        foreach (DB::table('storefronts')->where('is_active', 1)->get(['id', 'domain']) as $row) {
            $raw = $row->domain ?? null;
            $domain = is_string($raw) ? strtolower(trim($raw)) : '';
            if ($domain !== '' && is_numeric($row->id)) {
                $out[(int) $row->id] = $domain;
            }
        }
        Cache::put(StorefrontCache::HOSTS_KEY, $out, config()->integer('storefront.ttl.storefront'));

        return $out;
    }

    /**
     * May this request, arriving on its host, serve storefront `$storefrontId`?
     *
     * A host that belongs to a storefront serves only that one. A host that belongs to NONE is
     * refused too, except in local and testing, where requests arrive as `localhost` or `127.0.0.1`
     * (the suite, `artisan serve`, the compat harness). In production such a host cannot reach
     * `/api` in the first place — §4's default `.htaccess` arm closes it — so this is the second
     * lock, not the only one.
     */
    public static function serves(Request $request, int $storefrontId): bool
    {
        $owner = self::storefrontIdFor($request->getHost());

        if ($owner !== null) {
            return $owner === $storefrontId;
        }

        return app()->environment('local', 'testing');
    }
}
