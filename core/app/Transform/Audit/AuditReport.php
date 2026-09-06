<?php

namespace App\Transform\Audit;

final class AuditReport
{
    /** @var array<string, AuditFinding> keyed by code, insertion order */
    private array $findings = [];

    /** @var array<string, string> environment facts worth printing with the report */
    public array $context = [];

    public function add(AuditFinding $finding): AuditFinding
    {
        $this->findings[$finding->code] = $finding;

        return $finding;
    }

    public function get(string $code): ?AuditFinding
    {
        return $this->findings[$code] ?? null;
    }

    /** @return list<AuditFinding> */
    public function all(): array
    {
        return array_values($this->findings);
    }

    /** @return list<AuditFinding> */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, fn (AuditFinding $f) => $f->blocks()));
    }

    public function isBlocked(): bool
    {
        return $this->blocking() !== [];
    }

    public function nonZero(): int
    {
        return count(array_filter($this->findings, fn (AuditFinding $f) => $f->count() > 0));
    }

    /** audit.csv: one row per finding row; zero-count codes get a single "count=0" row so the file always lists every code. */
    public function toCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['code', 'blocking', 'title', 'handling', 'entity', 'id', 'detail'], ',', '"', '');
        foreach ($this->findings as $f) {
            if ($f->rows === []) {
                fputcsv($out, [$f->code, $f->blocking ? 'yes' : 'no', $f->title, $f->handling, '-', '', 'count=0'.($f->caveat !== '' ? ' ('.$f->caveat.')' : '')], ',', '"', '');

                continue;
            }
            foreach ($f->rows as $row) {
                fputcsv($out, [$f->code, $f->blocking ? 'yes' : 'no', $f->title, $f->handling, $row['entity'], $row['id'], $row['detail']], ',', '"', '');
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    /** audit.md: the human-readable report — summary table, then every non-zero code with its rows. */
    public function toMarkdown(string $title): string
    {
        $md = "# $title\n\n";
        foreach ($this->context as $k => $v) {
            $md .= "- **$k:** $v\n";
        }
        $md .= "\n## Summary\n\n| Code | Count | Blocking | Check | Handling |\n|---|---:|---|---|---|\n";
        foreach ($this->findings as $f) {
            $flag = $f->blocks() ? '**BLOCKS**' : ($f->blocking ? 'yes (0)' : 'no');
            $md .= sprintf("| %s | %d | %s | %s | %s |\n", $f->code, $f->count(), $flag, $f->title, $f->handling);
        }
        $md .= "\n";

        $blocking = $this->blocking();
        $md .= $blocking === []
            ? "**Verdict: no blocking finding.**\n\n"
            : '**Verdict: BLOCKED by '.implode(', ', array_map(fn (AuditFinding $f) => $f->code, $blocking))."** — fix in legacy first.\n\n";

        $md .= "## Details (every non-zero code, row by row)\n\n";
        foreach ($this->findings as $f) {
            if ($f->count() === 0 && $f->caveat === '') {
                continue;
            }
            $md .= sprintf("### %s — %s (%d)\n\n", $f->code, $f->title, $f->count());
            if ($f->note !== '') {
                $md .= $f->note."\n\n";
            }
            if ($f->caveat !== '') {
                $md .= '> Caveat: '.$f->caveat."\n\n";
            }
            if ($f->rows !== []) {
                $md .= "| Entity | Id | Detail |\n|---|---|---|\n";
                foreach ($f->rows as $row) {
                    $md .= sprintf("| %s | %s | %s |\n", $row['entity'], $row['id'], str_replace('|', '\\|', $row['detail']));
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /** @return array<string, array{count: int, blocking: bool, blocks: bool, title: string}> */
    public function toSummaryArray(): array
    {
        $out = [];
        foreach ($this->findings as $f) {
            $out[$f->code] = ['count' => $f->count(), 'blocking' => $f->blocking, 'blocks' => $f->blocks(), 'title' => $f->title];
        }

        return $out;
    }
}
