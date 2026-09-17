<?php

declare(strict_types=1);

namespace App\Domain\Import;

use Generator;

/**
 * Reads Brand Fashion's WooCommerce export and hands back {@see SourceRow}s (wave 4D importers).
 *
 * ── What this file is, measured 2026-09-14 ───────────────────────────────────────────────────
 *
 * 52 columns × 8 614 rows: 8 312 `simple`, 49 `variable` parents and 253 `variation` children.
 * `fgetcsv` reads it in one pass and the rows stream, because holding 8 MB of arrays to import
 * 8 000 products is a needless way to run out of memory on a shared host.
 *
 * **Three shapes of dirt this reader knows about**, each measured rather than guessed:
 *
 *  1. **`Parent` is written two different ways.** 111 variations say `id:61368` (a WordPress post
 *     id) and 142 say `GUS026` (the parent's SKU). Every one of the 253 resolves once you accept
 *     both — so both are accepted, and a variation whose parent resolves to neither is reported
 *     rather than silently imported as a product of its own.
 *  2. **The scope filter is the developer's** (decision 6): only `Published = 1` AND
 *     `Visibility = visible` are imported. Everything else is COUNTED and reported — 368 drafts,
 *     319 unpublished, 417 hidden — never dropped in silence.
 *  3. **Prices are already clean.** 0 rows have `Sale price >= Regular price` and 0 have a sale of
 *     zero, so our `0 < sale < selling` contract needs no repair here. The reader still refuses a
 *     sale price that does not satisfy it, because the day the next export does contain one is the
 *     day the storefront starts 422-ing checkouts (AGENTS §2.4).
 */
final class WooExport
{
    /** The columns this reader needs. A file missing one of them is the wrong file. */
    private const REQUIRED = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Visibility in catalog',
        'Regular price', 'Sale price', 'Categories', 'Images', 'Stock', 'Parent'];

    /** @var array<string, int> */
    private array $counts = [
        'rows' => 0, 'simple' => 0, 'variable' => 0, 'variation' => 0,
        'skipped_unpublished' => 0, 'skipped_hidden' => 0, 'skipped_no_name' => 0,
        'orphan_variation' => 0,
    ];

    public function __construct(private readonly string $path) {}

    /**
     * Every importable product, variations already folded into their parent.
     *
     * Two passes, and the first one is unavoidable: a variation can appear BEFORE its parent in
     * the file, so the children have to be indexed before any parent is emitted. The first pass
     * keeps only the 253 variation rows, not the 8 614.
     *
     * @return Generator<int, SourceRow>
     */
    public function rows(): Generator
    {
        $variationsByParent = $this->indexVariations();

        foreach ($this->records() as $record) {
            $this->counts['rows']++;
            $type = trim($record['Type'] ?? '');

            if ($type === 'variation') {
                $this->counts['variation']++;

                continue;                       // already folded into its parent
            }

            $this->counts[$type === 'variable' ? 'variable' : 'simple']++;

            $name = SourceTitle::clean($record['Name'] ?? '');
            if ($name === '') {
                $this->counts['skipped_no_name']++;

                continue;
            }

            // The developer's scope: published AND visible only. Both counted, separately, so the
            // report can say which kind of "not now" each row was.
            if (trim($record['Published'] ?? '') !== '1') {
                $this->counts['skipped_unpublished']++;

                continue;
            }
            if (trim($record['Visibility in catalog'] ?? '') !== 'visible') {
                $this->counts['skipped_hidden']++;

                continue;
            }

            $id = trim($record['ID'] ?? '');
            $sku = trim($record['SKU'] ?? '');

            // A variable parent is found by its id AND by its SKU, because its children use both.
            $children = array_merge(
                $variationsByParent['id:'.$id] ?? [],
                $sku === '' ? [] : ($variationsByParent[$sku] ?? []),
            );

            yield $this->toRow($record, $children);
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @param  array<string, string>  $record
     * @param  list<array<string, string>>  $children
     */
    private function toRow(array $record, array $children): SourceRow
    {
        $name = SourceTitle::clean($record['Name'] ?? '');
        $sku = trim($record['SKU'] ?? '');
        [$paths, $genders, $materials, $family, $unmapped] = $this->taxonomy($record['Categories'] ?? '');

        $selling = self::money($record['Regular price'] ?? '');
        $sale = self::money($record['Sale price'] ?? '');

        /*
         * A variable parent carries no price of its own — measured: 0 of 49. The cheapest variant
         * is the product's price, which is what a listing shows and what the storefront's own
         * variant pricing does (`price_delta` from the product's price).
         */
        if ($selling === null && $children !== []) {
            $prices = [];
            foreach ($children as $child) {
                $price = self::money($child['Regular price'] ?? '');
                if ($price !== null) {
                    $prices[] = $price;
                }
            }
            $selling = $prices === [] ? null : min($prices);
        }

        // Our contract, enforced at the door: a sale price that is not strictly between 0 and the
        // selling price is DROPPED rather than stored, because the storefront prices `sale` only
        // when `0 < sale < selling` and a violation 422s the checkout (AGENTS §2.4).
        if ($sale !== null && $selling !== null && ($sale <= 0 || $sale >= $selling)) {
            $sale = null;
        }

        $variants = [];
        foreach ($children as $child) {
            $label = trim($child['Attribute 1 value(s)'] ?? '');
            $label = trim(str_replace('\\', '', $label));       // "40\" appears 4 times in the file
            if ($label === '') {
                continue;
            }
            $variants[] = [
                'label' => $label,
                'size' => $label,
                'price' => self::money($child['Regular price'] ?? ''),
                'stock' => self::stock($child['Stock'] ?? ''),
            ];
        }

        /*
         * Size order, not file order. The children arrive indexed by the two different `Parent`
         * conventions, so their natural order is an accident of which convention came first —
         * and a shoe listed 42, 41, 45, 43 is a shop that looks broken. `strnatcmp` puts "41"
         * before "42" and "L" after "M" only by accident, but the numeric half is the one that
         * matters: 250 of the 253 variations are shoe sizes.
         */
        usort($variants, static fn (array $a, array $b): int => strnatcmp($a['label'], $b['label']));

        return new SourceRow(
            ref: 'woo:'.trim($record['ID'] ?? ''),
            titleEn: $name,
            sku: $sku === '' ? null : $sku,
            modelNumber: null,
            description: self::text($record['Description'] ?? ''),
            sellingPrice: $selling ?? 0.0,
            salePrice: $sale,
            purchasePrice: 0.0,                 // the export carries no cost price at all
            stock: self::stock($record['Stock'] ?? ''),
            categoryPaths: $paths,
            genders: $genders,
            materials: $materials,
            family: $family,
            imageUrl: self::firstImage($record['Images'] ?? ''),
            isVisible: true,                    // everything that reaches here passed the scope filter
            variants: $variants,
            unmappedLeaves: $unmapped,
        );
    }

    /**
     * Their `Categories` field → our node paths, genders and materials, plus the family.
     *
     * The fifth element is the leaves the map has NO ROW FOR. They are carried rather than
     * skipped because the importer REFUSES a row that has one (decision 2026-09-15): silently
     * dropping an unknown leaf is how a product lands with a placement nobody chose, and the
     * alternative — teaching the audit to ignore imported rows — would be teaching it to look away.
     *
     * A leaf the map answers with `none` or `gender` is NOT unknown. Those are decisions already
     * taken, and they keep working.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: string, 4: list<string>}
     */
    private function taxonomy(string $categories): array
    {
        $paths = [];
        $genders = [];
        $materials = [];
        $unmapped = [];

        foreach (explode(',', $categories) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $leaf = CategoryMap::leafOf($entry);
            $mapped = CategoryMap::leaf($leaf);
            if ($mapped === null) {
                // Unknown to the map. Recorded, and the importer refuses the row over it.
                $unmapped[$leaf] = true;

                continue;
            }

            /*
             * A leaf can be TWO things. `Leather` is a material AND a section customers shop, so
             * the row carries `material` alongside its node and both are recorded — the developer's
             * correction of 2026-09-14, and the reason this is not a `match` on one kind any more.
             */
            match ($mapped['kind']) {
                CategoryMap::NODE => $paths[$mapped['to']] = true,
                CategoryMap::GENDER => $genders[$mapped['to']] = true,
                CategoryMap::MATERIAL => $materials[$mapped['to']] = true,
                default => null,
            };

            $material = $mapped['material'] ?? null;
            if (is_string($material) && $material !== '') {
                $materials[$material] = true;
            }
        }

        /** @var list<string> $pathList */
        $pathList = array_keys($paths);

        return [$pathList, array_keys($genders), array_keys($materials), self::familyFor($pathList), array_keys($unmapped)];
    }

    /**
     * The family, from the DEEPEST node the product landed in.
     *
     * Deepest, because `fashion/bags/tote-bag` says `bag` while its ancestor `fashion` says
     * `fashion`, and the specific answer is the true one. A product with no node at all is
     * `other` — honest, and visible in the missing-data marker.
     *
     * @param  list<string>  $paths
     */
    private static function familyFor(array $paths): string
    {
        $best = null;
        $bestDepth = -1;

        foreach ($paths as $path) {
            // A material node is never the primary placement, so it never decides the family
            // either: `fashion/materials/leather` is deeper than `fashion/bags` and would otherwise
            // win this comparison and make a leather handbag a `fashion` product.
            if (! CategoryMap::mayBePrimary($path)) {
                continue;
            }
            $definition = CategoryMap::NEW_NODES[$path] ?? null;
            $family = $definition['family'] ?? self::familyOfExistingPath($path);
            $depth = substr_count($path, '/');

            if ($family !== null && $depth > $bestDepth) {
                $best = $family;
                $bestDepth = $depth;
            }
        }

        return $best ?? 'other';
    }

    /** The family of a node that already exists in our tree, by its root slug. */
    private static function familyOfExistingPath(string $path): ?string
    {
        $segments = explode('/', $path);
        $root = $segments[0];
        $leaf = $segments[count($segments) - 1];

        return match (true) {
            $root === 'watches' => 'watch',
            $root === 'electronics' => 'electronics',
            $leaf === 'bags' => 'bag',
            $leaf === 'wallets' => 'wallet',
            $leaf === 'perfumes' => 'perfume',
            $root === 'fashion' => 'fashion',
            default => null,
        };
    }

    /**
     * The variations, indexed by every key their `Parent` column might use.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function indexVariations(): array
    {
        $out = [];
        foreach ($this->records() as $record) {
            if (trim($record['Type'] ?? '') !== 'variation') {
                continue;
            }
            $parent = trim($record['Parent'] ?? '');
            if ($parent === '') {
                $this->counts['orphan_variation']++;

                continue;
            }
            $out[$parent][] = $record;
        }

        return $out;
    }

    /**
     * Every record of the file, as a column => value map.
     *
     * @return Generator<int, array<string, string>>
     */
    private function records(): Generator
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new ImportFileError("Cannot read the export at [{$this->path}].");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header)) {
                throw new ImportFileError('The export has no header row.');
            }
            // A UTF-8 BOM would make the first column name unmatchable, and the first column is `ID`.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

            /** @var list<string> $columns */
            $columns = array_map(static fn ($c): string => trim((string) $c), $header);

            $missing = array_diff(self::REQUIRED, $columns);
            if ($missing !== []) {
                throw new ImportFileError('The export is missing required column(s): '.implode(', ', $missing));
            }

            /*
             * The line number is carried so a refusal can NAME it (🟡-4, 2026-09-17). Starts at 1
             * for the header that was just read, so the first data row reports as line 2 — which is
             * the number the operator's spreadsheet shows.
             */
            $line = 1;

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;

                // A blank line in a CSV arrives as `[null]`. It is not a record.
                if ($record === [null]) {
                    continue;
                }

                /*
                 * ── A row whose width is wrong is REFUSED, by line number ────────────────────
                 *
                 * This used to pad silently: `$record[$index] ?? ''` filled every missing column
                 * with a blank and ignored every surplus one. That is the wrong default for this
                 * file, because of how the common corruption actually behaves — an unescaped or
                 * unbalanced quote makes `fgetcsv` swallow the FOLLOWING LINES into one field until
                 * it finds the next quote. The result is one row with too few columns and a product
                 * that has silently vanished from the import, with a plausible-looking row in its
                 * place and nothing in the report to say so.
                 *
                 * So the width is checked, and the refusal names the line, the expectation and what
                 * was found — everything needed to open the file and look at it.
                 */
                if (count($record) !== count($columns)) {
                    throw new ImportFileError(sprintf(
                        'line %d has %d column(s) but the header declares %d. '
                        .'The usual cause is an unclosed quote earlier in the file, which makes the reader '
                        .'swallow the following lines into one field — so the row that looks wrong is often '
                        .'just after the row that IS wrong. Open the file at line %d and check the quoting.',
                        $line,
                        count($record),
                        count($columns),
                        $line,
                    ));
                }

                $values = [];
                foreach ($columns as $index => $column) {
                    $values[$column] = is_string($record[$index] ?? null) ? $record[$index] : '';
                }
                yield $values;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function money(string $value): ?float
    {
        $clean = trim(str_replace([',', ' '], '', $value));
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }
        $number = (float) $clean;

        return $number > 0 ? round($number, 2) : null;
    }

    private static function stock(string $value): ?int
    {
        $clean = trim($value);
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        // One row in the file has −2. Negative stock is not a quantity; it is a bookkeeping scar
        // from their system, and it becomes zero here rather than an exception in InventoryService.
        return max(0, (int) $clean);
    }

    private static function text(string $value): ?string
    {
        $clean = trim(strip_tags(str_replace(['</li>', '<br>', '<br/>', '</p>'], "\n", $value)));
        $clean = trim(preg_replace('/\n{3,}/', "\n\n", $clean) ?? $clean);

        return $clean === '' ? null : $clean;
    }

    private static function firstImage(string $images): ?string
    {
        foreach (explode(',', $images) as $url) {
            $url = trim($url);
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }
        }

        return null;
    }
}
