<?php

namespace App\Storefront;

use RuntimeException;

/**
 * A storefront that cannot be identified: no name, no `settings.identity`, or a wordmark file that
 * is not on disk (W2, no-fallback rule — developer 2026-10-13). The twin of {@see StorefrontHasNoHost}:
 * the caller decides what "no identity" means for its job — a mail row is PARKED with this message
 * — and nothing ever renders another shop's wordmark, number or copyright in its place.
 */
final class StorefrontHasNoIdentity extends RuntimeException
{
    public static function for(int $storefrontId, string $what): self
    {
        return new self("storefront {$storefrontId} has no identity: {$what} (set storefronts.settings.identity by SQL — backlog SF-IDENTITY-SQL; no fallback to another shop)");
    }
}
