<?php

namespace App\Compat\Diff;

/**
 * Renders a DiffRunner result as Markdown (human sign-off document) and JSON (machine record).
 */
final class DiffReport
{
    /**
     * @param  array{cases: list<array<string, mixed>>, totals: array<string, int>, rules: array<string, int>, verdict: string}  $result
     * @param  array<string, string>  $context
     */
    public static function markdown(array $result, array $context): string
    {
        $md = "# Compat-diff report\n\n";
        foreach ($context as $k => $v) {
            $md .= "- **{$k}:** {$v}\n";
        }
        $md .= "\n## Verdict\n\n**{$result['verdict']}**\n\n";
        $t = $result['totals'];
        $md .= "| Cases | Byte-identical | Sanctioned deviations only | Unexplained cases | Unexplained findings |\n|---|---|---|---|---|\n";
        $md .= "| {$t['cases']} | {$t['identical']} | {$t['sanctioned_only']} | {$t['unexplained_cases']} | {$t['unexplained_findings']} |\n\n";

        $md .= "## Cases\n\n| Case | Path | Legacy | Compat | Bytes (legacy / compat) | Result | Rules absorbed |\n|---|---|---|---|---|---|---|\n";
        foreach ($result['cases'] as $c) {
            $sanctioned = [];
            foreach (self::intMap($c['sanctioned'] ?? []) as $rule => $n) {
                $sanctioned[] = "{$rule}×{$n}";
            }
            $unexplained = self::listOf($c['unexplained'] ?? []);
            $res = ($c['identical'] ?? false) === true ? '✅ identical' : ($unexplained === [] ? '🟡 sanctioned' : '❌ UNEXPLAINED ('.count($unexplained).')');
            $md .= sprintf("| `%s` | `%s` | %s | %s | %s / %s | %s | %s |\n", self::str($c, 'name'), self::str($c, 'path'), self::str($c, 'legacy_status'), self::str($c, 'compat_status'), self::str($c, 'legacy_bytes'), self::str($c, 'compat_bytes'), $res, $sanctioned === [] ? '—' : implode(', ', $sanctioned));
        }

        $md .= "\n## Sanctioned deviations absorbed\n\n| Rule | Findings | Why |\n|---|---|---|\n";
        $why = [];
        foreach (DeviationRules::all() as $rule) {
            $why[$rule['id']] ??= $rule['why'];
        }
        foreach ($result['rules'] as $rule => $n) {
            $md .= "| {$rule} | {$n} | ".($why[$rule] ?? '')." |\n";
        }
        foreach (DeviationRules::all() as $rule) {
            if (! isset($result['rules'][$rule['id']])) {
                $md .= "| {$rule['id']} | 0 (rule present, nothing to absorb on this data) | {$rule['why']} |\n";
            }
        }

        $md .= "\n## Unexplained differences\n\n";
        $any = false;
        foreach ($result['cases'] as $c) {
            $unexplained = self::listOf($c['unexplained'] ?? []);
            if ($unexplained === []) {
                continue;
            }
            $any = true;
            $md .= '### `'.self::str($c, 'name')."`\n\n| Path | Kind | Legacy | Compat |\n|---|---|---|---|\n";
            foreach ($unexplained as $f) {
                if (! is_array($f)) {
                    continue;
                }
                $md .= sprintf("| `%s` | %s | `%s` | `%s` |\n", self::str($f, 'path'), self::str($f, 'kind'), self::cell($f['legacy'] ?? null), self::cell($f['compat'] ?? null));
            }
            $md .= "\n";
        }
        if (! $any) {
            $md .= "None.\n";
        }

        return $md;
    }

    /** @param  array<mixed>  $a */
    private static function str(array $a, string $key): string
    {
        $v = $a[$key] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            return str_replace(['|', "\n"], ['\\|', ' '], (string) $v);
        }
        $json = json_encode($v, JSON_UNESCAPED_UNICODE);

        return is_string($json) ? str_replace('|', '\\|', $json) : '?';
    }

    /** @return array<string, int> */
    private static function intMap(mixed $v): array
    {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $k => $n) {
                if (is_int($n)) {
                    $out[(string) $k] = $n;
                }
            }
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }
}
