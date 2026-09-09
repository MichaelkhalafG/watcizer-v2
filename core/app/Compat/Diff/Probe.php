<?php

namespace App\Compat\Diff;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches one DiffCase from a host over real HTTP (no redirects followed, no exceptions on
 * 4xx/5xx, every response header kept). The literal `inproc` base runs the request through this
 * application's kernel instead — it is BLIND to everything the web server and the global HTTP
 * stack add (CORS answers, Vary, rate-limit headers), so it exists for local debugging only
 * (review 🟠-3b); the command requires an explicit --inproc flag to use it.
 *
 * @phpstan-type Response array{status: int, content_type: string, headers: array<string, string>, body: string}
 */
final class Probe
{
    /** @var array<string, string> per-host placeholder values ({guest}, {jwt}) */
    private array $variables = [];

    public function __construct(private readonly string $base, private readonly string $apiKey, private readonly int $paceMs = 0) {}

    /**
     * Bind this host's placeholder values. Each host gets its OWN guest token, so a stateful
     * sequence builds a separate cart on each side instead of both hosts fighting over one row
     * in the shared `carts` table.
     *
     * @param  array<string, string>  $variables
     */
    public function withVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    public function isInProcess(): bool
    {
        return $this->base === 'inproc';
    }

    /** @return array{status: int, content_type: string, headers: array<string, string>, body: string} */
    public function fetch(DiffCase $case): array
    {
        $headers = $case->headers;
        if ($case->accept !== null) {
            $headers = ['Accept' => $case->accept] + $headers;
        }
        if ($case->apiCode) {
            $headers['Api-Code'] = $this->apiKey;
        }

        $headers = array_map(fn (string $v): string => $this->substitute($v), $headers);
        $path = $this->substitute($case->path);
        $body = $case->body === null ? null : $this->substituteDeep($case->body);
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->isInProcess() ? $this->inProcess($case->method, $path, $headers, $body) : $this->http($case->method, $path, $headers, $body);
    }

    /** Replace `{guest}` / `{jwt}` with this host's values. */
    private function substitute(string $value): string
    {
        foreach ($this->variables as $name => $replacement) {
            $value = str_replace('{'.$name.'}', $replacement, $value);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function substituteDeep(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $out[$key] = $this->substitute($item);
            } elseif (is_array($item)) {
                $out[$key] = $this->substituteDeep($item);
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<mixed>|null  $body
     * @return array{status: int, content_type: string, headers: array<string, string>, body: string}
     */
    private function http(string $method, string $path, array $headers, ?array $body = null): array
    {
        $url = rtrim($this->base, '/').'/'.ltrim($path, '/');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->paceMs > 0) {
                usleep($this->paceMs * 1000);
            }
            $request = Http::withHeaders($headers)
                ->withOptions(['http_errors' => false, 'allow_redirects' => false, 'decode_content' => true])
                ->timeout(60);
            $response = $body === null
                ? $request->send($method, $url)
                : $request->send($method, $url, ['json' => $body]);
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
     * @param  array<mixed>|null  $body
     * @return array{status: int, content_type: string, headers: array<string, string>, body: string}
     */
    private function inProcess(string $method, string $path, array $headers, ?array $body = null): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create('/'.ltrim($path, '/'), $method, [], [], [], $server, $body === null ? null : (string) json_encode($body));
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
