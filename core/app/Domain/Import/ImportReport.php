<?php

declare(strict_types=1);

namespace App\Domain\Import;

use RuntimeException;

/**
 * What the import did, and every row it could not do completely (wave 4D importers).
 *
 * ── "Do not silently drop anything" ──────────────────────────────────────────────────────────
 *
 * The developer's instruction, twice over: a duplicate SKU is imported-first-and-reported-rest, a
 * draft is skipped-and-counted, a missing field is marked-not-refused. So this class has exactly
 * two jobs and no opinions: **count what happened**, and **name every row that is not whole**.
 *
 * It writes two files, because they answer different questions:
 *
 *   • `report.md` — what a person reads: the counts, the skipped kinds, the brands we could not
 *     match and how often, the new categories the merge created.
 *   • `report.csv` — what a person WORKS from: one line per imperfect row, with its reference, its
 *     title and the exact list of what is missing, so it can be sorted and shared.
 *
 * Nothing here is a log line. A log is what you read after somebody complains; this is the thing
 * handed over with the data.
 */
final class ImportReport
{
    /** The markers a row can carry. The same tokens the dashboard filters on. */
    public const MISSING_SKU = 'sku';

    public const MISSING_IMAGE = 'image';

    public const MISSING_ARABIC = 'arabic';

    public const MISSING_CATEGORY = 'category';

    public const MISSING_BRAND = 'brand';

    public const MISSING_PRICE = 'price';

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array{ref: string, title: string, kind: string, detail: string}> */
    private array $rows = [];

    /** @var array<string, int> */
    private array $notes = [];

    public function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    /**
     * One row that is not whole. `kind` groups them; `detail` says what specifically.
     */
    public function row(string $ref, string $title, string $kind, string $detail = ''): void
    {
        $this->rows[] = ['ref' => $ref, 'title' => mb_substr($title, 0, 120), 'kind' => $kind, 'detail' => $detail];
        $this->count('flagged_'.$kind);
    }

    /** A free-form tally — unmatched brand names, created nodes. */
    public function note(string $bucket, string $value, int $by = 1): void
    {
        $key = $bucket.'|'.$value;
        $this->notes[$key] = ($this->notes[$key] ?? 0) + $by;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function get(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /** @return list<array{ref: string, title: string, kind: string, detail: string}> */
    public function flagged(): array
    {
        return $this->rows;
    }

    /**
     * Write both files into $directory and return their paths.
     *
     * @return array{md: string, csv: string}
     */
    public function write(string $directory, string $title): array
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the report directory [{$directory}].");
        }

        $md = $directory.'/report.md';
        $csv = $directory.'/report.csv';

        file_put_contents($md, $this->markdown($title));

        $handle = fopen($csv, 'w');
        if ($handle === false) {
            throw new RuntimeException("Cannot write [{$csv}].");
        }
        // A BOM, so Excel opens the Arabic titles as UTF-8 instead of mojibake — the team opens
        // this file in Excel, and a report nobody can read is not a report.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['reference', 'title', 'kind', 'detail'], ',', '"', '');
        foreach ($this->rows as $row) {
            fputcsv($handle, [$row['ref'], $row['title'], $row['kind'], $row['detail']], ',', '"', '');
        }
        fclose($handle);

        return ['md' => $md, 'csv' => $csv];
    }

    private function markdown(string $title): string
    {
        $out = "# {$title}\n\n- **run:** ".now()->toDateTimeString()."\n\n## Counts\n\n| what | how many |\n|---|---|\n";

        $counts = $this->counts;
        ksort($counts);
        foreach ($counts as $key => $value) {
            $out .= '| `'.$key.'` | '.$value." |\n";
        }

        foreach (['brand_unmatched' => 'Brand names we could not match (add these, or leave them Generic)',
            'category_created' => 'Category nodes the merge created',
            'size_created' => 'Sizes the merge created',
            'image_failure' => 'Why a cover image did not arrive'] as $bucket => $heading) {
            $rows = [];
            foreach ($this->notes as $key => $value) {
                [$b, $v] = explode('|', $key, 2);
                if ($b === $bucket) {
                    $rows[$v] = $value;
                }
            }
            if ($rows === []) {
                continue;
            }
            arsort($rows);
            $out .= "\n## {$heading}\n\n| value | rows |\n|---|---|\n";
            foreach (array_slice($rows, 0, 40, true) as $value => $n) {
                $out .= '| '.$value.' | '.$n." |\n";
            }
        }

        if ($this->rows !== []) {
            $byKind = [];
            foreach ($this->rows as $row) {
                $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + 1;
            }
            arsort($byKind);
            $out .= "\n## Rows that need a person\n\nEvery one of these is in `report.csv`, with its reference.\n\n| kind | rows |\n|---|---|\n";
            foreach ($byKind as $kind => $n) {
                $out .= '| '.$kind.' | '.$n." |\n";
            }
        }

        return $out;
    }
}
