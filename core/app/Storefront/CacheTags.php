<?php

namespace App\Storefront;

/**
 * Request-scoped collector of CDN cache tags (CLEAN_CORE_STUDY §5.2): every v2 response
 * carries `Cache-Tag: sf{id}, sf{id}-p{productId}, sf{id}-c{categoryId} …` so the dashboard's
 * write path can purge by tag.
 */
final class CacheTags
{
    /** @var array<string, true> */
    private array $tags = [];

    public function storefront(int $storefrontId): void
    {
        $this->tags["sf{$storefrontId}"] = true;
    }

    public function product(int $storefrontId, int $productId): void
    {
        $this->tags["sf{$storefrontId}-p{$productId}"] = true;
    }

    public function category(int $storefrontId, int $categoryId): void
    {
        $this->tags["sf{$storefrontId}-c{$categoryId}"] = true;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->tags);
    }

    public function header(): string
    {
        return implode(', ', $this->all());
    }
}
