<?php

namespace App\Compat\Diff;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches one DiffCase from a host. A base URL is fetched over HTTP (no redirects followed,
 * no exceptions on 4xx/5xx); the literal `inproc` base runs the request through this
 * application's kernel — the compat side needs no server for a local run.
 *
 * @phpstan-type Response array{status: int, content_type: string, headers: array<string, string>, body: string}
 */
final class Probe
{
    public function __construct(private readonly string $base, private readonly string $apiKey, private readonly int $paceMs = 0) {}

    public function isInProcess(): bool
    {
        return $this->base === 'inproc';
    }

    /** @return array{status: int, content_type: string, headers: array<string, string>, body: string} */
    public function fetch(DiffCase $case): array
    {
        $headers = ['Accept' => 'application/json'] + $case->headers;
        if ($case->apiCode) {
            $headers['Api-Code'] = $this->apiKey;
        }
        if ($case->kind === 'xml' || $case->kind === 'redirect') {
            $headers['Accept'] = '*/*';
        }

        return $this->isInProcess() ? $this->inProcess($case->path, $headers) : $this->http($case->path, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, content_type: string, headers: array<string, string>, body: string}
     */
    private function http(string $path, array $headers): array
    {
        $url = rtrim($this->base, '/').'/'.ltrim($path, '/');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->paceMs > 0) {
                usleep($this->paceMs * 1000);
            }
            $response = Http::withHeaders($headers)
                ->withOptions(['http_errors' => false, 'allow_redirects' => false, 'decode_content' => true])
                ->timeout(60)
                ->get($url);
            if ($response->status() === 429 && $attempt < 3) {
                $retry = $response->header('Retry-After');
                sleep(max(1, min(65, is_numeric($retry) ? (int) $retry : 5)));

                continue;
            }
            $flat = [];
            foreach ($response->headers() as $name => $values) {
                if (is_array($values)) {
                    $flat[strtolower((string) $name)] = implode(', ', array_filter($values, 'is_string'));
                }
            }

            return ['status' => $response->status(), 'content_type' => $flat['content-type'] ?? '', 'headers' => $flat, 'body' => $response->body()];
        }

        throw new RuntimeException("Unreachable: {$url}");
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, content_type: string, headers: array<string, string>, body: string}
     */
    private function inProcess(string $path, array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('/'.ltrim($path, '/'), 'GET', [], [], [], $server);
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $flat = [];
        foreach ($response->headers->all() as $name => $values) {
            $flat[strtolower($name)] = implode(', ', array_filter($values, 'is_string'));
        }
        $content = $response->getContent();

        return ['status' => $response->getStatusCode(), 'content_type' => $flat['content-type'] ?? '', 'headers' => $flat, 'body' => $content === false ? '' : $content];
    }
}
