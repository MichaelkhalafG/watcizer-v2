<?php

namespace App\Compat\Diff;

/**
 * One request the harness sends to both hosts. `kind` decides how bodies are compared:
 * json (structural + byte), xml (sitemap `<url>` blocks), redirect (status + Location path),
 * status (status + byte body only). `accept` is the Accept header to send — the axios default
 * (`application/json, text/plain, *\/*`), a native-fetch `*\/*`, or null for no header at all
 * (review 🟠-3a: the legacy error pages change shape with it). `origin` adds an Origin header so
 * the CORS answer is compared (review 🔴-1 / 🟠-3b).
 */
final class DiffCase
{
    public const ACCEPT_AXIOS = 'application/json, text/plain, */*';

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
        public readonly ?string $accept = self::ACCEPT_AXIOS,
        public readonly string $method = 'GET',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'method' => $this->method, 'path' => $this->path, 'kind' => $this->kind, 'headers' => $this->headers, 'accept' => $this->accept, 'api_code' => $this->apiCode, 'group' => $this->group];
    }
}
