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
     * @param  array<string, mixed>|null  $body  JSON request body (wave 3: the cart/checkout POSTs)
     * @param  string  $sequence  steps sharing this name run in list order against ONE identity per
     *                            host, so a cart can be built up and then read back
     * @param  list<string>  $positional  normalised JSON paths whose arrays are compared element by
     *                                    element in ORDER instead of matched up by row id — needed
     *                                    wherever the two hosts own different rows
     * @param  array<string, string>  $capture  variable => JSON path, read out of EACH host's own
     *                                          response and usable as `{variable}` in later steps of the
     *                                          sequence — the only way to say "delete the row you just made"
     *                                          when the two hosts assign different ids
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
        public readonly ?array $body = null,
        public readonly string $sequence = '',
        public readonly array $capture = [],
        public readonly array $positional = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'method' => $this->method, 'path' => $this->path, 'kind' => $this->kind, 'headers' => $this->headers, 'accept' => $this->accept, 'api_code' => $this->apiCode, 'group' => $this->group, 'sequence' => $this->sequence, 'body' => $this->body, 'capture' => $this->capture, 'positional' => $this->positional];
    }
}
