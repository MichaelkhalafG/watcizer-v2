<?php

namespace App\Compat\Diff;

/**
 * One request the harness sends to both hosts. `kind` decides how bodies are compared:
 * json (structural + byte), xml (sitemap `<url>` blocks), redirect (status + Location path),
 * status (status + byte body only).
 *
 * @phpstan-type Headers array<string, string>
 */
final class DiffCase
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $kind = 'json',
        public readonly array $headers = [],
        public readonly bool $apiCode = true,
        public readonly string $group = 'compat',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'path' => $this->path, 'kind' => $this->kind, 'headers' => $this->headers, 'api_code' => $this->apiCode, 'group' => $this->group];
    }
}
