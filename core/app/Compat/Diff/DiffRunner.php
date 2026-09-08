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

    public function __construct(private readonly Probe $legacy, private readonly Probe $compat) {}

    /**
     * @param  list<DiffCase>  $cases
     * @param  callable(string): void|null  $progress
     * @return array{cases: list<array<string, mixed>>, totals: array<string, int>, rules: array<string, int>, verdict: string}
     */
    public function run(array $cases, ?callable $progress = null): array
    {
        $results = [];
        /** @var array<string, int> $ruleTotals */
        $ruleTotals = [];
        $totals = ['cases' => 0, 'identical' => 0, 'sanctioned_only' => 0, 'unexplained_cases' => 0, 'unexplained_findings' => 0];
        foreach ($cases as $case) {
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

    /** @return array{name: string, path: string, kind: string, group: string, legacy_status: int, compat_status: int, legacy_bytes: int, compat_bytes: int, identical: bool, content_type_equal: bool, headers: array{legacy: array<string, string>, compat: array<string, string>}, findings: int, sanctioned: array<string, int>, unexplained: list<array{path: string, kind: string, legacy: mixed, compat: mixed}>, note: string} */
    private function one(DiffCase $case): array
    {
        $a = $this->legacy->fetch($case);
        $b = $this->compat->fetch($case);
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
                    $structural = JsonDiff::compare($ja, $jb);
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
