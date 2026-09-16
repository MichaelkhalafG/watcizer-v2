<?php

declare(strict_types=1);

namespace App\Domain\Import;

use Generator;
use RuntimeException;

/**
 * Reads the T.JOY (Joyroom) price list — after it has been turned into a CSV (wave 4D importers).
 *
 * ── Why there is no PDF code anywhere in this application ────────────────────────────────────
 *
 * The price list arrived as an 18-page PDF that Excel 2010 printed. It has a real table and 238
 * embedded photos, and a one-off Python script (`new branding/scripts/extract_joyroom.py`,
 * PyMuPDF) turns it into `joyroom.csv` plus an `images/` folder. The developer approved exactly
 * that shape: *"one-off extraction to a reviewed CSV; no PDF-specific code in the importer."*
 *
 * The reason is not squeamishness about PDFs. It is that a PDF parser in `app/` would be permanent
 * code owned by this application for a file we hope never to receive again, and it would sit
 * between the operator and the data at the exact moment they most need to SEE the data. A CSV can
 * be opened, read, corrected and re-run. So the extraction is a script beside the sheets, its
 * output is reviewed by a person, and this reader only ever sees columns.
 *
 * ── What the file says, measured 2026-09-14 ──────────────────────────────────────────────────
 *
 * 138 rows, 135 with a model number (86 distinct), 132 priced, 92 with a photo, 73 distinct item
 * names. One row per (product, colour) — `JR-PBM01 Black` and `JR-PBM01 White` are two rows and
 * ONE product with two colours, which is why this reader groups by model.
 *
 * **It is the only source that carries a cost price.** `RDP` is the dealer price and `RRP` the
 * retail price, and the median margin is 2.61×. `purchase_price` therefore arrives populated here
 * and nowhere else — the WooCommerce export has no cost column at all.
 *
 * **It carries no category and no stock**, which is the developer's decision 7 case: *"anything
 * the PDF doesn't give us, leave empty with the missing-data marker — the team fills it in."* The
 * category is DERIVED from the product name against the new Electronics tree, because "Power Bank"
 * in the title is not a guess; the stock is left absent and marked.
 */
final class JoyroomSheet
{
    /**
     * Every row in this file is Joyroom's.
     *
     * Their titles are descriptions, not names — "20W Magnetic Wireless Power Bank JR-PBM01" — so
     * a title-derived brand reads "20W Magnetic". The supplier IS the brand, and the file knows it
     * even though no column says so.
     */
    public const BRAND = 'Joyroom';

    private const REQUIRED = ['item', 'model', 'color', 'rdp', 'rrp'];

    /**
     * Item-name keywords → the Electronics child they belong in. First match wins, so the specific
     * term comes before the general one.
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'power bank' => 'electronics/power-banks',
        'powerbank' => 'electronics/power-banks',
        'screen protector' => 'electronics/screen-protectors',
        'tempered' => 'electronics/screen-protectors',
        'privacy' => 'electronics/screen-protectors',
        'glass' => 'electronics/screen-protectors',
        'earphone' => 'electronics/audio',
        'earbud' => 'electronics/audio',
        'headphone' => 'electronics/audio',
        'headset' => 'electronics/audio',
        'speaker' => 'electronics/audio',
        'holder' => 'electronics/phone-holders',
        'stand' => 'electronics/phone-holders',
        'mount' => 'electronics/phone-holders',
        'charger' => 'electronics/chargers',
        'charging' => 'electronics/chargers',
        'cable' => 'electronics/chargers',
        'adapter' => 'electronics/chargers',
        'hub' => 'electronics/chargers',
    ];

    /** @var array<string, int> */
    private array $counts = ['rows' => 0, 'products' => 0, 'colour_rows' => 0, 'no_model' => 0, 'no_price' => 0];

    public function __construct(private readonly string $path) {}

    /**
     * One {@see SourceRow} per MODEL, its colours folded in as variants.
     *
     * @return Generator<int, SourceRow>
     */
    public function rows(): Generator
    {
        $byModel = [];

        foreach ($this->records() as $record) {
            $this->counts['rows']++;

            $model = trim($record['model'] ?? '');
            $model = trim(str_replace(["\n", "\r"], ' ', $model));
            if ($model === '') {
                $this->counts['no_model']++;

                continue;
            }

            $byModel[$model][] = $record;
        }

        foreach ($byModel as $model => $records) {
            $first = $records[0];
            $item = trim($first['item'] ?? '');
            if ($item === '') {
                $item = $model;
            }

            $rrp = self::money($first['rrp'] ?? '');
            $rdp = self::money($first['rdp'] ?? '');
            if ($rrp === null) {
                $this->counts['no_price']++;
            }

            // Colours become variants only when there is more than one — a single-colour product
            // is simply a product, and a variant row that offers no choice is noise in the UI.
            $variants = [];
            if (count($records) > 1) {
                foreach ($records as $record) {
                    $colour = self::colour($record['color'] ?? '');
                    if ($colour === null) {
                        continue;
                    }
                    $variants[] = [
                        'label' => $colour,
                        'size' => null,
                        'price' => self::money($record['rrp'] ?? ''),
                        'stock' => null,
                    ];
                    $this->counts['colour_rows']++;
                }
            }

            $this->counts['products']++;

            yield new SourceRow(
                ref: 'joyroom:'.$model,
                // Through the same normaliser as the Woo reader: this sheet is extracted from a
                // PDF rather than exported from HTML, so entities are unlikely — but a glued
                // `USBCable` is exactly the shape its item names take, and one normalisation point
                // beats two readers that disagree about what a title is.
                titleEn: SourceTitle::clean($item.' '.$model),
                sku: $model,                        // the model number IS their SKU
                modelNumber: $model,
                description: self::description($first),
                sellingPrice: $rrp ?? 0.0,
                purchasePrice: $rdp ?? 0.0,         // the ONLY source that carries a cost price
                salePrice: null,
                stock: null,                        // the price list has no stock column at all
                categoryPaths: [self::section($item.' '.$model)],
                genders: [],
                materials: [],
                family: 'electronics',
                imageUrl: self::image($first, $this->path),
                isVisible: true,
                variants: $variants,
                brand: self::BRAND,
            );
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** The Electronics child a product belongs in, from its name. */
    private static function section(string $name): string
    {
        $haystack = mb_strtolower($name);
        foreach (self::SECTIONS as $needle => $path) {
            if (str_contains($haystack, $needle)) {
                return $path;
            }
        }

        // Everything Joyroom sells is an accessory of some kind, so the fallback is a real section
        // rather than nothing — the product is still findable while the team sorts it.
        return 'electronics/accessories';
    }

    /**
     * The COLOR column is not always a colour: `HD` (23 rows) and `Privacy` (19) are screen
     * protector TYPES that their sheet files under the same heading. Both are kept as the variant's
     * label, because "HD" and "Privacy" really are the choice a customer makes between two
     * versions of that product — they are simply not colours, and nothing here pretends they are
     * by looking them up in the colour table.
     */
    private static function colour(string $value): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $value)) ?? '');

        return $clean === '' || strtoupper($clean) === 'COLOR' ? null : $clean;
    }

    /** @param  array<string, string>  $record */
    private static function description(array $record): ?string
    {
        $parts = [];
        foreach (['specification', 'description'] as $key) {
            $value = trim($record[$key] ?? '');
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * The extracted photo, as an absolute path beside the CSV.
     *
     * A local path rather than a URL, because these images came out of the PDF and were never on a
     * web server. {@see CoverImages} accepts both.
     *
     * @param  array<string, string>  $record
     */
    private static function image(array $record, string $csvPath): ?string
    {
        $name = trim($record['image'] ?? '');
        if ($name === '') {
            return null;
        }

        $path = dirname($csvPath).'/images/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function records(): Generator
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot read the extracted price list at [{$this->path}].");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header)) {
                throw new RuntimeException('The extracted price list has no header row.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            /** @var list<string> $columns */
            $columns = array_map(static fn ($c): string => mb_strtolower(trim((string) $c)), $header);

            $missing = array_diff(self::REQUIRED, $columns);
            if ($missing !== []) {
                throw new RuntimeException(
                    'The extracted price list is missing column(s): '.implode(', ', $missing)
                    .'. Re-run new branding/scripts/extract_joyroom.py.'
                );
            }

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                // A blank line in a CSV arrives as `[null]`. It is not a record.
                if ($record === [null]) {
                    continue;
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
        $number = round((float) $clean, 2);

        return $number > 0 ? $number : null;
    }
}
