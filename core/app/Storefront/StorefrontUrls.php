<?php

namespace App\Storefront;

use App\Domain\Payment\Landing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 *   1. `storefronts.settings.urls.{frontend|payment_return|asset_base|api_host}` when set — an
 *      explicit override, for a shop whose hosts do not follow the pattern below. No dashboard field
 *      writes these yet: they are set by SQL when a storefront is created (backlog SF-URL-SETTINGS);
 *   2. for the DEFAULT storefront (`compat.storefront_id`, Watchizer), the `.env` values exactly as
 *      they were — `FRONTEND_URL`, `COMPAT_PAYMENT_RETURN_URL`, `STOREFRONT_ASSET_BASE` — so this
 *      change moves nothing for the live shop (`api_host` has no env key: Watchizer's derives);
 *   3. otherwise derived from `storefronts.domain`: `https://{domain}`, `https://{domain}/` and
 *      `https://api.{domain}`, which is how the second shop is being set up;
 *   4. a storefront with NEITHER a setting nor a domain gets {@see StorefrontHasNoHost}, never
 *      another shop's value (A2 no-fallback rule, developer 2026-10-12). Until then it answered
 *      Watchizer's URL as "the only non-broken answer left".
 *
 * The storefront of a REQUEST is its host's (StorefrontHost). A host that names no storefront is
 * refused with 404 in production — the same rule and the same local/testing exception as
 * `StorefrontHost::serves()`, so the pin exists for the suite, `artisan serve` and the harness only
 * (2026-10-12; it used to fall back to the default shop, so a payment return, a reset link or a
 * sign-in landing on an unmatched host would have been Watchizer's).
 */
final class StorefrontUrls
{
    public const CACHE_KEY = 'sf:urls';

    /** Where this storefront's shoppers are sent: the site itself, no trailing slash. */
    public static function frontend(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return rtrim(self::setting($row, 'frontend')
            ?? ($storefrontId === self::defaultId() ? config()->string('customers.storefront_url') : self::derived($row, '', $storefrontId)), '/');
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

    /**
     * This storefront's own API host, no trailing slash — what its e-mails' "stop" and
     * "unsubscribe" links point at and what its sitemap names images on (A2: W6/W7, 2026-10-12).
     * `settings.urls.api_host`, else `https://api.{domain}`; no env key and no default shop: a
     * storefront with neither throws {@see StorefrontHasNoHost}. Watchizer's derives to
     * `https://api.watchizereg.com`, which is what the retired `STOCK_ALERTS_PUBLIC_URL` and
     * `COMPAT_SITEMAP_IMAGE_HOST` keys held.
     */
    public static function apiHost(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return rtrim(self::setting($row, 'api_host') ?? self::derived($row, 'api.', $storefrontId), '/');
    }

    /** The host this storefront's v2 payloads name images on, no trailing slash. */
    public static function assetBase(int $storefrontId): string
    {
        $row = self::row($storefrontId);

        return rtrim(self::setting($row, 'asset_base')
            ?? ($storefrontId === self::defaultId() ? config()->string('storefront.asset_base') : self::derived($row, 'api.', $storefrontId)), '/');
    }

    /**
     * The host the COMPAT payloads name images on — `catalog/meta`'s brand logos, sub-type and
     * banner images, `products/{id}`'s cover, gallery and brand logo (COMPAT-ASSET-HOST, 2026-10-14).
     * For the DEFAULT storefront it is `compat.asset_base` exactly as it always was, so the live
     * shop's payloads do not move a byte; every other storefront gets its own {@see self::assetBase()},
     * which is what its v2 routes already name. Before this every shop's compat payload was built on
     * the one global: Brand Fashion's `catalog/meta` named Watchizer's host 81 times.
     */
    public static function compatAssetBase(int $storefrontId): string
    {
        return $storefrontId === self::defaultId()
            ? rtrim(config()->string('compat.asset_base'), '/')
            : self::assetBase($storefrontId);
    }

    /**
     * The storefront a request belongs to: its host's. A host that names no storefront is a 404 in
     * production; in local and testing it is the default one, as in `StorefrontHost::serves()`.
     */
    public static function storefrontOf(Request $request): int
    {
        $id = StorefrontHost::storefrontIdFor($request->getHost());
        if ($id !== null) {
            return $id;
        }
        if (app()->environment('local', 'testing')) {
            return self::defaultId();
        }

        throw new NotFoundHttpException('Not Found');
    }

    private static function defaultId(): int
    {
        return config()->integer('compat.storefront_id');
    }

    /** The storefront's name — `storefronts.name`, the brand name every e-mail prints (W2). Null when there is no row. */
    public static function nameOf(int $storefrontId): ?string
    {
        return self::row($storefrontId)['name'] ?? null;
    }

    /**
     * The storefront's settings as stored (`storefronts.settings`, decoded), for readers that
     * are not URLs — today {@see StorefrontIdentity}. Cached with the row.
     *
     * @return array<string, mixed>
     */
    public static function settingsOf(int $storefrontId): array
    {
        return self::row($storefrontId)['settings'] ?? [];
    }

    /** @param array{name: string, domain: string, settings: array<string, mixed>}|null $row */
    private static function derived(?array $row, string $prefix, int $storefrontId = 0): string
    {
        $domain = $row['domain'] ?? '';

        // A storefront with no domain cannot be addressed. This used to answer the default shop's
        // URL as "the only non-broken answer left" — which is a Brand Fashion link pointing at
        // Watchizer. Nothing resolves to another shop (A2, 2026-10-12): the caller decides.
        if ($domain === '') {
            throw StorefrontHasNoHost::for($storefrontId);
        }

        return 'https://'.$prefix.$domain;
    }

    /** @param array{name: string, domain: string, settings: array<string, mixed>}|null $row */
    private static function setting(?array $row, string $key): ?string
    {
        $urls = $row['settings']['urls'] ?? null;
        $value = is_array($urls) ? ($urls[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{name: string, domain: string, settings: array<string, mixed>}|null */
    private static function row(int $storefrontId): ?array
    {
        return self::rows()[$storefrontId] ?? null;
    }

    /**
     * Every storefront's domain and settings, cached for as long as the storefront row itself
     * (`storefront.ttl.storefront`) and forgotten with it by StorefrontCache::forgetStorefront().
     *
     * @return array<int, array{name: string, domain: string, settings: array<string, mixed>}>
     */
    private static function rows(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var array<int, array{name: string, domain: string, settings: array<string, mixed>}> $cached */
            return $cached;
        }

        $out = [];
        foreach (DB::table('storefronts')->get(['id', 'name', 'domain', 'settings']) as $raw) {
            if (! is_numeric($raw->id ?? null)) {
                continue;
            }
            $decoded = is_string($raw->settings ?? null) ? json_decode($raw->settings, true) : null;
            $settings = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $settings[(string) $key] = $value;
            }
            $out[(int) $raw->id] = [
                'name' => is_string($raw->name ?? null) ? trim($raw->name) : '',
                'domain' => is_string($raw->domain ?? null) ? strtolower(trim($raw->domain)) : '',
                'settings' => $settings,
            ];
        }
        Cache::put(self::CACHE_KEY, $out, config()->integer('storefront.ttl.storefront'));

        return $out;
    }
}
