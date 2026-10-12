<?php

namespace App\Storefront;

use RuntimeException;

/**
 * A storefront that cannot be addressed: it has no `settings.urls.api_host`, and no `domain` to
 * derive one from (A2, no-fallback rule, developer 2026-10-12).
 *
 * Before this, {@see StorefrontUrls} answered such a storefront with WATCHIZER'S own URL — "the only
 * non-broken answer left". It was the quietest of the cross-shop leaks: a Brand Fashion stock-alert
 * e-mail whose "stop" link pointed at Watchizer's API host, a sitemap naming Watchizer's images.
 * Now nothing resolves to another shop: the caller decides what "no host" means for its own job —
 * a bulk-mail row is PARKED with this message, a sitemap omits its images, a request answers 404.
 */
final class StorefrontHasNoHost extends RuntimeException
{
    public static function for(int $storefrontId): self
    {
        return new self("storefront {$storefrontId} has no host: set settings.urls.api_host or storefronts.domain (no fallback to another shop)");
    }
}
