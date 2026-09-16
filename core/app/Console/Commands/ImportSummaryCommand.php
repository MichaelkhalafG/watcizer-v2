<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandResolver;
use App\Domain\Import\ImportReport;
use App\Domain\Import\JoyroomSheet;
use App\Domain\Import\WooExport;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `php artisan import:summary` — what the imported catalogue actually looks like, in aggregate.
 *
 * ── Why this is a command and not a one-off script ───────────────────────────────────────────
 *
 * The per-run reports (`storage/import/<run>/report.md`) say what HAPPENED during one run. This
 * says what IS THERE now — across every run, after the corrections, the re-imports and the
 * discards — which is the thing a person needs before they open the dashboard and start checking.
 * It is also the record that has to outlive the data: the rehearsal rows die at the next rebuild,
 * and after that this file is the only description of what the real supplier files contained
 * (developer, 2026-09-14).
 *
 * Everything is DERIVED from the catalogue, not from a log, so a row corrected by hand shows up
 * corrected. Nothing here writes.
 */
final class ImportSummaryCommand extends Command
{
    protected $signature = 'import:summary
        {--out= : also write the report to this markdown file}
        {--source= : limit to one source prefix (woo|joyroom)}
        {--file=* : also read these source files and report what they contain and what is out of scope}';

    protected $description = 'Aggregate report of the imported catalogue: families, categories, missing data, covers, brands';

    public function handle(): int
    {
        $source = trim(Coerce::str($this->option('source')));
        $prefix = $source === '' ? null : $source.':';

        $imported = $this->scope($prefix)->count();
        if ($imported === 0) {
            $this->warn('No imported products in this catalogue.'
                .($prefix === null ? '' : " (source filter: {$source})"));

            return self::SUCCESS;
        }

        $lines = [];
        $lines[] = '# Imported catalogue — aggregate report';
        $lines[] = '';
        $lines[] = '- **generated:** '.now()->toDateTimeString();
        $lines[] = '- **imported products in the catalogue:** '.$imported;
        if ($prefix !== null) {
            $lines[] = '- **source filter:** `'.$source.'`';
        }

        $lines = array_merge($lines,
            $this->fileSection(),
            $this->sourceSection($prefix),
            $this->familySection($prefix),
            $this->categorySection($prefix),
            $this->missingSection($prefix),
            $this->translationSection($prefix),
            $this->coverSection($prefix),
            $this->brandSection($prefix),
            $this->variantSection($prefix),
        );

        $markdown = implode("\n", $lines)."\n";
        $this->line($markdown);

        $out = trim(Coerce::str($this->option('out')));
        if ($out !== '') {
            $directory = dirname($out);
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Cannot create [{$directory}].");
            }
            file_put_contents($out, $markdown);
            $this->info('written: '.$out);
        }

        return self::SUCCESS;
    }

    private function scope(?string $prefix): Builder
    {
        $query = DB::table('catalog_products')->whereNotNull('import_ref')->whereNull('deleted_at');

        return $prefix === null ? $query : $query->where('import_ref', 'like', $prefix.'%');
    }

    /**
     * What the SOURCE FILES contain, and what never entered the catalogue.
     *
     * Read-only, one pass each, the same readers the import uses — so "skipped" here means exactly
     * what it means during a run. The counts cannot be derived from the database: a row that was
     * never imported leaves nothing behind, which is why they have to be reported rather than
     * counted afterwards.
     *
     * @return list<string>
     */
    private function fileSection(): array
    {
        $files = [];
        foreach ((array) $this->option('file') as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = $file;
            }
        }

        if ($files === []) {
            return [];
        }

        $out = ['', '## What the source files contain', '',
            'Read from the files themselves, not from the catalogue: a row that was never imported leaves',
            'nothing behind to count.', ''];

        foreach ($files as $file) {
            if (! is_file($file)) {
                $out[] = '- `'.basename($file).'` — **not found**';

                continue;
            }

            $reader = str_contains(mb_strtolower($file), 'joyroom')
                ? new JoyroomSheet($file)
                : new WooExport($file);

            $products = 0;
            foreach ($reader->rows() as $ignored) {
                $products++;
            }

            $out[] = '### `'.basename($file).'`';
            $out[] = '';
            $out[] = '| what | rows |';
            $out[] = '|---|---|';
            $out[] = '| **products in scope** | '.$products.' |';

            foreach ($reader->counts() as $key => $value) {
                $out[] = '| `'.$key.'` | '.$value.' |';
            }
            $out[] = '';
        }

        return $out;
    }

    /** @return list<string> */
    private function sourceSection(?string $prefix): array
    {
        if ($prefix !== null) {
            return [];
        }

        $rows = DB::table('catalog_products')
            ->whereNotNull('import_ref')->whereNull('deleted_at')
            ->selectRaw("SUBSTRING_INDEX(import_ref, ':', 1) as source")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('source')->orderByDesc('total')->get();

        $out = ['', '## By source', '', '| source | products |', '|---|---|'];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $out[] = '| `'.Row::str($row, 'source').'` | '.Row::int($row, 'total').' |';
        }

        return $out;
    }

    /** @return list<string> */
    private function familySection(?string $prefix): array
    {
        $rows = $this->scope($prefix)
            ->select('family')->selectRaw('COUNT(*) as total')
            ->groupBy('family')->orderByDesc('total')->get();

        $out = ['', '## Products per family', '',
            'The family is derived from the PRIMARY CATEGORY, so this table is also a check on the category',
            'mapping: a watch filed as `other` means a leaf reached no node.', '',
            '| family | products |', '|---|---|'];

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $out[] = '| `'.Row::str($row, 'family').'` | '.Row::int($row, 'total').' |';
        }

        return $out;
    }

    /** @return list<string> */
    private function categorySection(?string $prefix): array
    {
        $out = ['', '## Products per category node', '',
            'Counted where the placement is PRIMARY — the node a breadcrumb would show. A product placed in',
            'several nodes appears once, under its deepest.', ''];

        foreach ([1 => 'Watchizer', 2 => 'Brand Fashion'] as $storefrontId => $name) {
            $rows = DB::table('storefront_category_product as scp')
                ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
                ->join('catalog_products as p', 'p.id', '=', 'scp.product_id')
                ->leftJoin('storefront_category_translations as t', function (JoinClause $join): void {
                    $join->on('t.storefront_category_id', '=', 'c.id')->where('t.locale', '=', 'en');
                })
                ->where('scp.storefront_id', $storefrontId)
                ->where('scp.is_primary', 1)
                ->whereNotNull('p.import_ref')
                ->whereNull('p.deleted_at')
                ->when($prefix !== null, fn ($q) => $q->where('p.import_ref', 'like', $prefix.'%'))
                ->groupBy('c.id', 'c.slug', 'c.depth', 't.name')
                ->select('c.slug', 'c.depth', 't.name')
                ->selectRaw('COUNT(*) as total')
                ->orderByDesc('total')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $out[] = '### '.$name.' (storefront '.$storefrontId.')';
            $out[] = '';
            $out[] = '| node | depth | products |';
            $out[] = '|---|---|---|';
            foreach ($rows as $raw) {
                $row = Row::cast($raw);
                $out[] = '| `'.Row::str($row, 'slug').'` — '.(Row::nstr($row, 'name') ?? '—')
                    .' | '.Row::int($row, 'depth').' | '.Row::int($row, 'total').' |';
            }
            $out[] = '';
        }

        return $out;
    }

    /**
     * The six missing-data tokens, counted the same way the products list derives them.
     *
     * @return list<string>
     */
    private function missingSection(?string $prefix): array
    {
        $generic = DB::table('catalog_brands')->where('slug', 'generic')->value('id');
        $genericId = is_numeric($generic) ? (int) $generic : -1;

        $counts = [
            ImportReport::MISSING_SKU => (clone $this->scope($prefix))->whereNull('sku')->count(),
            ImportReport::MISSING_PRICE => (clone $this->scope($prefix))->where('selling_price', '<=', 0)->count(),
            ImportReport::MISSING_BRAND => (clone $this->scope($prefix))->where('brand_id', $genericId)->count(),
            ImportReport::MISSING_IMAGE => (clone $this->scope($prefix))
                ->whereNotExists(function (Builder $sub): void {
                    $sub->from('catalog_product_images as i')->whereColumn('i.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
                })->count(),
            ImportReport::MISSING_ARABIC => (clone $this->scope($prefix))
                ->whereNotExists(function (Builder $sub): void {
                    $sub->from('catalog_product_translations as t')
                        ->whereColumn('t.product_id', 'catalog_products.id')
                        ->where('t.locale', 'ar')->whereNotNull('t.title')->where('t.title', '!=', '')
                        ->selectRaw('1')->limit(1);
                })->count(),
            ImportReport::MISSING_CATEGORY => (clone $this->scope($prefix))
                ->whereNotExists(function (Builder $sub): void {
                    $sub->from('storefront_category_product as scp')->whereColumn('scp.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
                })->count(),
        ];

        arsort($counts);

        $out = ['', '## Missing-data markers', '',
            'Derived exactly as the products list derives them, so these are the counts the',
            '`flag=missing_data` filter selects. A product can carry several.', '',
            '| token | products |', '|---|---|'];

        foreach ($counts as $token => $total) {
            $out[] = '| `'.$token.'` | '.$total.' |';
        }

        $any = (clone $this->scope($prefix))->count() - $this->wholeCount($prefix, $genericId);
        $out[] = '';
        $out[] = '**Carrying at least one marker:** '.$any;

        return $out;
    }

    private function wholeCount(?string $prefix, int $genericId): int
    {
        return (clone $this->scope($prefix))
            ->whereNotNull('sku')
            ->where('selling_price', '>', 0)
            ->where('brand_id', '!=', $genericId)
            ->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_images as i')->whereColumn('i.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
            })
            ->whereExists(function (Builder $sub): void {
                $sub->from('storefront_category_product as scp')->whereColumn('scp.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
            })
            ->count();
    }

    /** @return list<string> */
    private function translationSection(?string $prefix): array
    {
        $machine = DB::table('catalog_product_translations as t')
            ->join('catalog_products as p', 'p.id', '=', 't.product_id')
            ->whereNotNull('p.import_ref')->whereNull('p.deleted_at')
            ->when($prefix !== null, fn ($q) => $q->where('p.import_ref', 'like', $prefix.'%'))
            ->where('t.locale', 'ar')->where('t.is_machine', 1)->count();

        $human = DB::table('catalog_product_translations as t')
            ->join('catalog_products as p', 'p.id', '=', 't.product_id')
            ->whereNotNull('p.import_ref')->whereNull('p.deleted_at')
            ->when($prefix !== null, fn ($q) => $q->where('p.import_ref', 'like', $prefix.'%'))
            ->where('t.locale', 'ar')->where('t.is_machine', 0)->count();

        return ['', '## Arabic titles', '',
            '| kind | products |', '|---|---|',
            '| machine-written, awaiting review (`flag=machine_ar`) | '.$machine.' |',
            '| edited by a person since | '.$human.' |'];
    }

    /** @return list<string> */
    private function coverSection(?string $prefix): array
    {
        $with = (clone $this->scope($prefix))
            ->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_images as i')->whereColumn('i.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
            })->count();
        $total = (clone $this->scope($prefix))->count();

        return ['', '## Cover images', '',
            '| | products |', '|---|---|',
            '| with a cover | '.$with.' |',
            '| **with none** | '.($total - $with).' |'];
    }

    /** @return list<string> */
    private function brandSection(?string $prefix): array
    {
        $rows = $this->scope($prefix)
            ->join('catalog_brands as b', 'b.id', '=', 'catalog_products.brand_id')
            ->leftJoin('catalog_brand_translations as t', function (JoinClause $join): void {
                $join->on('t.brand_id', '=', 'b.id')->where('t.locale', '=', 'en');
            })
            ->groupBy('b.id', 'b.slug', 't.name')
            ->select('b.slug', 't.name')->selectRaw('COUNT(*) as total')
            ->orderByDesc('total')->limit(25)->get();

        $out = ['', '## Brands the import assigned (top 25)', '',
            '`'.BrandResolver::GENERIC.'` is the bucket for a title whose first words matched none of our brands —',
            'those products carry the `brand` marker and are the reassignment backlog.', '',
            '| brand | products |', '|---|---|'];

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $out[] = '| '.(Row::nstr($row, 'name') ?? Row::str($row, 'slug')).' | '.Row::int($row, 'total').' |';
        }

        return $out;
    }

    /** @return list<string> */
    private function variantSection(?string $prefix): array
    {
        $products = $this->scope($prefix)
            ->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1')->limit(1);
            })->count();

        $variants = DB::table('catalog_product_variants as v')
            ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->whereNotNull('p.import_ref')->whereNull('p.deleted_at')
            ->when($prefix !== null, fn ($q) => $q->where('p.import_ref', 'like', $prefix.'%'))
            ->count();

        $onCompat = DB::table('catalog_product_variants as v')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'v.product_id')->where('sp.storefront_id', '=', 1);
            })
            ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->whereNotNull('p.import_ref')
            ->distinct()->count('v.product_id');

        return ['', '## Variants', '',
            '| | count |', '|---|---|',
            '| products that sell through variants | '.$products.' |',
            '| variant rows | '.$variants.' |',
            '| of those products, reachable on Watchizer (storefront 1) | '.$onCompat.' |',
            '',
            $onCompat > 0
                ? '> Those '.$onCompat.' are **browsable and NOT buyable through the legacy API**: both compat doors'
                    ."\n".'> answer 422 while the storefront is still on the legacy frontend. They are fully buyable'
                    ."\n".'> through the v2 API and the dashboard.'
                : '> No imported variant product is reachable through the compat layer.'];
    }
}
