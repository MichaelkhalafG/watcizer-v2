<?php

namespace App\Compat\Diff;

/**
 * Runs every DiffCase against the legacy and compat probes and classifies each finding as
 * byte-identical, sanctioned (absorbed by a DeviationRules entry) or UNEXPLAINED.
 *
 * @phpstan-type CaseResult array{name: string, path: string, kind: string, group: string, legacy_status: int, compat_status: int, legacy_bytes: int, compat_bytes: int, identical: bool, content_type_equal: bool, cache_control: array{legacy: string, compat: string}, findings: int, sanctioned: array<string, int>, unexplained: list<array{path: string, kind: string, legacy: mixed, compat: mixed}>, note: string}
 */
final class DiffRunner
{
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

    /** @return array{name: string, path: string, kind: string, group: string, legacy_status: int, compat_status: int, legacy_bytes: int, compat_bytes: int, identical: bool, content_type_equal: bool, cache_control: array{legacy: string, compat: string}, findings: int, sanctioned: array<string, int>, unexplained: list<array{path: string, kind: string, legacy: mixed, compat: mixed}>, note: string} */
    private function one(DiffCase $case): array
    {
        $a = $this->legacy->fetch($case);
        $b = $this->compat->fetch($case);
        $identical = $a['status'] === $b['status'] && $a['body'] === $b['body'] && self::mime($a['content_type']) === self::mime($b['content_type']);
        $findings = [];
        $note = '';
        if (! $identical) {
            if ($a['status'] !== $b['status']) {
                $findings[] = ['path' => 'status', 'kind' => 'value', 'legacy' => $a['status'], 'compat' => $b['status']];
            }
            if (self::mime($a['content_type']) !== self::mime($b['content_type'])) {
                $findings[] = ['path' => 'content_type', 'kind' => 'value', 'legacy' => $a['content_type'], 'compat' => $b['content_type']];
            }
            if ($case->kind === 'redirect') {
                $la = self::locationPath($a['headers']['location'] ?? '');
                $lb = self::locationPath($b['headers']['location'] ?? '');
                if ($la !== $lb) {
                    $findings[] = ['path' => 'location', 'kind' => 'value', 'legacy' => $la, 'compat' => $lb];
                }
                $identical = $findings === [];
                $note = 'redirect: status + Location path compared (hosts differ by construction)';
            } elseif ($case->kind === 'xml') {
                $findings = array_merge($findings, XmlDiff::compare($a['body'], $b['body']));
            } elseif ($case->kind === 'json' && $a['status'] === $b['status']) {
                $ja = json_decode($a['body'], true);
                $jb = json_decode($b['body'], true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($ja) || ! is_array($jb)) {
                    $findings[] = ['path' => 'body', 'kind' => 'value', 'legacy' => mb_substr($a['body'], 0, 120), 'compat' => mb_substr($b['body'], 0, 120)];
                } else {
                    $structural = JsonDiff::compare($ja, $jb);
                    $findings = array_merge($findings, $structural);
                    if ($structural === [] && $a['body'] !== $b['body']) {
                        $findings[] = ['path' => 'body', 'kind' => 'bytes', 'legacy' => strlen($a['body']), 'compat' => strlen($b['body'])];
                        $note = 'same structure, different bytes (encoding/whitespace)';
                    }
                }
            } else {
                $findings[] = ['path' => 'body', 'kind' => 'value', 'legacy' => mb_substr($a['body'], 0, 120), 'compat' => mb_substr($b['body'], 0, 120)];
            }
        }
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
            'content_type_equal' => self::mime($a['content_type']) === self::mime($b['content_type']),
            'cache_control' => ['legacy' => $a['headers']['cache-control'] ?? '', 'compat' => $b['headers']['cache-control'] ?? ''],
            'findings' => count($findings),
            'sanctioned' => $sanctioned,
            'unexplained' => array_slice($unexplained, 0, 50),
            'note' => $note,
        ];
    }

    private static function mime(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0]));
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
