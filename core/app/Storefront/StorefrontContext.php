<?php

namespace App\Storefront;

use App\Models\Storefront\Storefront;

/**
 * The storefront and locale a v2 request resolved to (ResolveStorefront middleware).
 * Bound into the container per request; controllers and builders receive it by injection.
 */
final class StorefrontContext
{
    public function __construct(
        public readonly Storefront $storefront,
        public readonly string $locale,
        public readonly CacheTags $tags,
    ) {}

    public function id(): int
    {
        return (int) $this->storefront->id;
    }

    /** @return list<string> */
    public function locales(): array
    {
        $locales = $this->storefront->getAttribute('locales');
        $out = [];
        if (is_array($locales)) {
            foreach ($locales as $l) {
                if (is_string($l)) {
                    $out[] = $l;
                }
            }
        }

        return $out === [] ? ['ar', 'en'] : $out;
    }

    public function defaultLocale(): string
    {
        $d = $this->storefront->getAttribute('default_locale');

        return is_string($d) && $d !== '' ? $d : 'ar';
    }
}
