<?php

namespace App\Storefront;

use App\Domain\Payment\Landing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The addresses that belong to ONE storefront — where its shoppers land, and which host its images
 * are named on (brand-separation review L5, 2026-09-26: FUNCTIONAL, not privacy).
 *
 * Each of these was a single global, which is correct only while there is one shop. With two, a
 * Brand Fashion shopper who paid by card came back to `watchizereg.com`, a password-reset link and
 * a Google sign-in both landed on Watchizer's site, and Brand Fashion's v2 payloads named
 * Watchizer's image host. That is a broken second shop, so the values are now per storefront.
 *
 * Resolution, per value:
 *   1. `storefronts.settings.urls.{frontend|payment_return|asset_base}` when set — an explicit
 *      override, for a shop whose hosts do not follow the pattern below;
 *   2. for the DEFAULT storefront (`compat.storefront_id`, Watchizer), the `.env` values exactly as
 *      they were — `FRONTEND_URL`, `COMPAT_PAYMENT_RETURN_URL`, `STOREFRONT_ASSET_BASE` — so this
 *      change moves nothing for the live shop;
 *   3. otherwise derived from `storefronts.domain`: `https://{domain}`, `https://{domain}/` and
 *      `https://api.{domain}`, which is how the second shop is being set up.
 *
 * The storefront of a REQUEST is its host's (StorefrontHost), falling back to the default one — the
 * same answer compat gives, so a payment return, a reset link and a sign-in landing can never
 * disagree with the shop that served the request.
 */
final class StorefrontUrls
{
    public const CACHE_KEY = 'sf:urls';

    /** Where this storefront's shoppers are sent: the site itself, no trailing slash. */
    public static function frontend(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return rtrim(self::setting($row, 'frontend')
            ?? ($storefrontId === self::defaultId() ? config()->string('customers.storefront_url') : self::derived($row, '')), '/');
    }

    /** Where a shopper lands after paying by card (the provider's return GET). */
    public static function paymentReturn(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return self::setting($row, 'payment_return')
            ?? ($storefrontId === self::defaultId() ? config()->string('compat.payment_return_url') : self::frontend($storefrontId).'/');
    }

    /**
     * The page a shopper's browser is sent to after the payment callback, and what it is told
     * ({@see Landing}):
     * `<return base>/order-confirmation?order=<number>&status=<paid|failed|pending>`.
     *
     * The base is {@see self::paymentReturn()} -- the same per-storefront value as before, so the
     * `.env` key and the settings override move nothing; the page path and the query are appended
     * to it. The storefront's cash path already renders `/order-confirmation`, so this reconnects a
     * page rather than inventing one. The order number is omitted when the callback could not
     * prove which order it was about (a refusal), and nothing a shopper could not already know
     * goes in the URL: no amount, no e-mail, no transaction id -- and never the signed payload.
     *
     * The page renders what this query says. It is a confirmation MESSAGE, not a receipt and not
     * proof of payment (option A, developer decision 2026-10-12): support reads the order, never
     * the page.
     */
    public static function paymentLanding(int $storefrontId, string $status, ?string $orderNumber): string
    {
        $query = ['status' => $status];
        if ($orderNumber !== null && trim($orderNumber) !== '') {
            $query = ['order' => trim($orderNumber)] + $query;
        }

        return rtrim(self::paymentReturn($storefrontId), '/').'/order-confirmation?'.http_build_query($query);
    }

    /** The host this storefront's v2 payloads name images on, no trailing slash. */
    public static function assetBase(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return rtrim(self::setting($row, 'asset_base')
            ?? ($storefrontId === self::defaultId() ? config()->string('storefront.asset_base') : self::derived($row, 'api.')), '/');
    }

    /** The storefront a request belongs to: its host's, else the default one. */
    public static function storefrontOf(Request $request): int
    {
        return StorefrontHost::storefrontIdFor($request->getHost()) ?? self::defaultId();
    }

    private static function defaultId(): int
    {
        return config()->integer('compat.storefront_id');
    }

    /** @param array{domain: string, settings: array<string, mixed>}|null $row */
    private static function derived(?array $row, string $prefix): string
    {
        $domain = $row['domain'] ?? '';

        // A storefront with no domain cannot be addressed; the default one's value is the only
        // non-broken answer left, and it is what every caller used before this existed.
        return $domain === '' ? config()->string('customers.storefront_url') : 'https://'.$prefix.$domain;
    }

    /** @param array{domain: string, settings: array<string, mixed>}|null $row */
    private static function setting(?array $row, string $key): ?string
    {
        $urls = $row['settings']['urls'] ?? null;
        $value = is_array($urls) ? ($urls[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{domain: string, settings: array<string, mixed>}|null */
    private static function row(int $storefrontId): ?array
    {
        return self::rows()[$storefrontId] ?? null;
    }

    /**
     * Every storefront's domain and settings, cached for as long as the storefront row itself
     * (`storefront.ttl.storefront`) and forgotten with it by StorefrontCache::forgetStorefront().
     *
     * @return array<int, array{domain: string, settings: array<string, mixed>}>
     */
    private static function rows(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var array<int, array{domain: string, settings: array<string, mixed>}> $cached */
            return $cached;
        }

        $out = [];
        foreach (DB::table('storefronts')->get(['id', 'domain', 'settings']) as $raw) {
            if (! is_numeric($raw->id ?? null)) {
                continue;
            }
            $decoded = is_string($raw->settings ?? null) ? json_decode($raw->settings, true) : null;
            $settings = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $settings[(string) $key] = $value;
            }
            $out[(int) $raw->id] = [
                'domain' => is_string($raw->domain ?? null) ? strtolower(trim($raw->domain)) : '',
                'settings' => $settings,
            ];
        }
        Cache::put(self::CACHE_KEY, $out, config()->integer('storefront.ttl.storefront'));

        return $out;
    }
}
