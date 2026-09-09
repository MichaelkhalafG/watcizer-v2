<?php

namespace App\Compat\Diff;

/**
 * Runs every DiffCase against the legacy and compat probes and classifies each finding as
 * byte-identical, sanctioned (absorbed by a DeviationRules entry) or UNEXPLAINED.
 *
 * Compared per case: status, body (structurally for JSON, by `<url>` block for XML), the full
 * Content-Type string, every `Access-Control-*` header and `Vary` (review 🟠-3b — the CORS answer
 * is part of the contract), and the Location path for redirects.
 */
final class DiffRunner
{
    /** Response headers that are part of the contract (compared verbatim, case-insensitive names). */
    public const COMPARED_HEADERS = [
        'content-type', 'vary',
        'access-control-allow-origin', 'access-control-allow-credentials', 'access-control-allow-methods',
        'access-control-allow-headers', 'access-control-expose-headers', 'access-control-max-age',
    ];

    /** Distinguishes one harness run's guest tokens from the next; regenerated per run(). */
    private string $runId = '';

    /**
     * Per-host placeholder values, kept for the life of ONE sequence.
     *
     * @var array{l: array<string, string>, c: array<string, string>}
     */
    private array $vars = ['l' => [], 'c' => []];

    private string $sequence = "\0";

    public function __construct(private readonly Probe $legacy, private readonly Probe $compat) {}

    /**
     * @param  list<DiffCase>  $cases
     * @param  callable(string): void|null  $progress
     * @return array{cases: list<array<string, mixed>>, totals: array<string, int>, rules: array<string, int>, verdict: string}
     */
    public function run(array $cases, ?callable $progress = null): array
    {
        $this->runId = bin2hex(random_bytes(4));
        $this->vars = ['l' => [], 'c' => []];
        $this->sequence = "\0";
        $results = [];
        /** @var array<string, int> $ruleTotals */
        $ruleTotals = [];
        $totals = ['cases' => 0, 'identical' => 0, 'sanctioned_only' => 0, 'unexplained_cases' => 0, 'unexplained_findings' => 0];
        foreach ($cases as $case) {
            // Each host gets its OWN guest token for the sequence, so the two build separate
            // carts in the shared `carts` table instead of overwriting one another's. Cases run
            // in list order, which is what makes a sequence (add → read → validate → order) mean
            // anything.
            $this->openSequence($case);
            $this->legacy->withVariables($this->vars['l']);
            $this->compat->withVariables($this->vars['c']);
            $r = $this->one($case);
            $results[] = $r;
            $totals['cases']++;
            if ($r['identical']) {
                $totals['identical']++;
            } elseif ($r['unexplained'] === []) {
                $totals['sanctioned_only']++;
            } else {
                $totals['unexplained_cases']++;
                $totals['unexplained_findings'] += count($r['unexplained']);
            }
            foreach ($r['sanctioned'] as $rule => $n) {
                $ruleTotals[$rule] = ($ruleTotals[$rule] ?? 0) + $n;
            }
            if ($progress !== null) {
                $progress(sprintf('%-46s %s', $case->name, $r['identical'] ? 'IDENTICAL' : ($r['unexplained'] === [] ? 'sanctioned ('.implode(', ', array_keys($r['sanctioned'])).')' : 'UNEXPLAINED '.count($r['unexplained']))));
            }
        }
        ksort($ruleTotals);

        return [
            'cases' => $results,
            'totals' => $totals,
            'rules' => $ruleTotals,
            'verdict' => $totals['unexplained_cases'] === 0 ? 'PASS — zero unexplained differences' : 'FAIL — '.$totals['unexplained_findings'].' unexplained difference(s) in '.$totals['unexplained_cases'].' case(s)',
        ];
    }

    /** @return array{name: string, sequence: string, method: string, path: string, kind: string, group: string, legacy_status: int, compat_status: int, legacy_bytes: int, compat_bytes: int, identical: bool, content_type_equal: bool, headers: array{legacy: array<string, string>, compat: array<string, string>}, findings: int, sanctioned: array<string, int>, unexplained: list<array{path: string, kind: string, legacy: mixed, compat: mixed}>, note: string} */
    private function one(DiffCase $case): array
    {
        $a = $this->legacy->fetch($case);
        $b = $this->compat->fetch($case);
        $this->captureInto($case, $a, 'l');
        $this->captureInto($case, $b, 'c');
        $findings = [];
        $note = '';

        if ($a['status'] !== $b['status']) {
            $findings[] = ['path' => 'status', 'kind' => 'value', 'legacy' => $a['status'], 'compat' => $b['status']];
        }
        foreach (self::COMPARED_HEADERS as $h) {
            $ha = self::header($a['headers'], $h);
            $hb = self::header($b['headers'], $h);
            if ($ha !== $hb) {
                $findings[] = ['path' => "header:{$h}", 'kind' => 'value', 'legacy' => $ha, 'compat' => $hb];
            }
        }

        $bodiesEqual = $a['body'] === $b['body'];
        if ($case->kind === 'redirect') {
            $la = self::locationPath($a['headers']['location'] ?? '');
            $lb = self::locationPath($b['headers']['location'] ?? '');
            if ($la !== $lb) {
                $findings[] = ['path' => 'location', 'kind' => 'value', 'legacy' => $la, 'compat' => $lb];
            }
            $note = 'redirect: status, headers and Location path compared (hosts differ by construction)';
        } elseif (! $bodiesEqual) {
            if ($case->kind === 'xml') {
                $findings = array_merge($findings, XmlDiff::compare($a['body'], $b['body']));
            } elseif ($case->kind === 'json' && $a['status'] === $b['status'] && self::isJson($a['content_type']) && self::isJson($b['content_type'])) {
                $ja = json_decode($a['body'], true);
                $jb = json_decode($b['body'], true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($ja) || ! is_array($jb)) {
                    $findings[] = ['path' => 'body', 'kind' => 'value', 'legacy' => mb_substr($a['body'], 0, 120), 'compat' => mb_substr($b['body'], 0, 120)];
                } else {
                    $structural = JsonDiff::compare($ja, $jb, $case->positional);
                    $findings = array_merge($findings, $structural);
                    if ($structural === []) {
                        $findings[] = ['path' => 'body', 'kind' => 'bytes', 'legacy' => strlen($a['body']), 'compat' => strlen($b['body'])];
                        $note = 'same structure, different bytes (encoding/whitespace)';
                    }
                }
            } else {
                $findings[] = ['path' => 'body', 'kind' => 'value', 'legacy' => mb_substr($a['body'], 0, 120), 'compat' => mb_substr($b['body'], 0, 120)];
            }
        }
        $identical = $findings === [];

        $sanctioned = [];
        $unexplained = [];
        foreach ($findings as $f) {
            $rule = DeviationRules::match($case->name, $f);
            if ($rule === null) {
                $unexplained[] = $f;
            } else {
                $sanctioned[$rule] = ($sanctioned[$rule] ?? 0) + 1;
            }
        }
        ksort($sanctioned);

        return [
            'name' => $case->name,
            'sequence' => $case->sequence,
            'method' => $case->method,
            'path' => $case->path,
            'kind' => $case->kind,
            'group' => $case->group,
            'legacy_status' => $a['status'],
            'compat_status' => $b['status'],
            'legacy_bytes' => strlen($a['body']),
            'compat_bytes' => strlen($b['body']),
            'identical' => $identical,
            'content_type_equal' => self::header($a['headers'], 'content-type') === self::header($b['headers'], 'content-type'),
            'headers' => ['legacy' => self::picked($a['headers']), 'compat' => self::picked($b['headers'])],
            'findings' => count($findings),
            'sanctioned' => $sanctioned,
            'unexplained' => array_slice($unexplained, 0, 50),
            'note' => $note,
        ];
    }

    /**
     * Start a fresh variable scope when the sequence changes. Everything a sequence captured
     * dies with it, so a later case can never accidentally reuse an earlier one's row id.
     */
    private function openSequence(DiffCase $case): void
    {
        $key = $case->sequence !== '' ? $case->sequence : "\0case:".$case->name;
        if ($key === $this->sequence) {
            return;
        }
        $this->sequence = $key;
        $this->vars = [
            'l' => ['guest' => $this->guestToken($case, 'l')],
            'c' => ['guest' => $this->guestToken($case, 'c')],
        ];
    }

    /**
     * Read this case's `capture` paths out of one host's body into that host's variables.
     *
     * @param  array{status: int, content_type: string, headers: array<string, string>, body: string}  $response
     */
    /**
     * @param  array{status: int, content_type: string, headers: array<string, string>, body: string}  $response
     * @param  'l'|'c'  $host
     */
    private function captureInto(DiffCase $case, array $response, string $host): void
    {
        if ($case->capture === []) {
            return;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded)) {
            return;
        }
        foreach ($case->capture as $name => $path) {
            /** @var mixed $value */
            $value = data_get($decoded, self::dotPath($path));
            if (is_scalar($value)) {
                $this->vars[$host][$name] = (string) $value;
            }
        }
    }

    /** `$.cart_item[0].id` → `cart_item.0.id`, the shape data_get() reads. */
    public static function dotPath(string $path): string
    {
        $path = preg_replace('/^\$\.?/', '', $path) ?? $path;
        $path = str_replace(['[', ']'], ['.', ''], $path);

        return trim($path, '.');
    }

    /**
     * A UUID-shaped token, stable for one (sequence, host) pair within one run and different in
     * every other combination. `carts.guest_token` is char(36), so the shape matters.
     */
    private function guestToken(DiffCase $case, string $host): string
    {
        $seed = md5($this->runId.'|'.($case->sequence !== '' ? $case->sequence : $case->name).'|'.$host);

        return substr($seed, 0, 8).'-'.substr($seed, 8, 4).'-4'.substr($seed, 13, 3).'-a'.substr($seed, 17, 3).'-'.substr($seed, 20, 12);
    }

    /** @param  array<string, string>  $headers */
    private static function header(array $headers, string $name): string
    {
        return strtolower(trim($headers[$name] ?? ''));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function picked(array $headers): array
    {
        $out = [];
        foreach (array_merge(self::COMPARED_HEADERS, ['cache-control', 'etag', 'x-ratelimit-limit', 'location']) as $h) {
            if (isset($headers[$h])) {
                $out[$h] = $headers[$h];
            }
        }

        return $out;
    }

    private static function isJson(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'json');
    }

    private static function locationPath(string $location): string
    {
        if ($location === '') {
            return '';
        }
        $path = parse_url($location, PHP_URL_PATH);

        return is_string($path) ? $path : $location;
    }
}
