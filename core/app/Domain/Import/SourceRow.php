<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * One product, in OUR words, whatever file it came from (wave 4D importers).
 *
 * The two sources could not look less alike — a 52-column WooCommerce CSV and a price list that
 * arrived as a PDF — and neither shape belongs anywhere near {@see ProductImporter}. This is the
 * seam: each reader produces these, the importer consumes only these, and a third source later is
 * a third reader and nothing else.
 *
 * Everything is already OUR vocabulary by the time a row exists: category SLUG PATHS, not Woo
 * taxonomy strings; gender and material NAMES, not category leaves; a family from our seven. The
 * translation happens in the reader, where the source's own dialect is still in scope.
 */
final class SourceRow
{
    /**
     * @param  string  $ref  the idempotency key — `woo:15826`, `joyroom:JR-PBM01`
     * @param  list<string>  $categoryPaths  slug paths, resolved by {@see CategoryMerger}
     * @param  list<string>  $genders  English names from the gender lookup
     * @param  list<string>  $materials  English names from the material lookup
     * @param  list<array{label: string, size: string|null, price: float|null, stock: int|null}>  $variants
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $titleEn,
        public readonly ?string $sku,
        public readonly ?string $modelNumber,
        public readonly ?string $description,
        public readonly float $sellingPrice,
        public readonly ?float $salePrice,
        public readonly float $purchasePrice,
        public readonly ?int $stock,
        public readonly array $categoryPaths,
        public readonly array $genders,
        public readonly array $materials,
        public readonly string $family,
        public readonly ?string $imageUrl,
        public readonly bool $isVisible,
        public readonly array $variants = [],
        /**
         * The brand, when the SOURCE knows it.
         *
         * The WooCommerce export does not — its `Brands` column is empty in all 8 614 rows — so
         * there the brand is read out of the title. A supplier price list is the opposite case:
         * every row in it is that supplier's, and reading the title instead produced "20W
         * Magnetic" and "Screen Protector" as brand names for all 86 Joyroom products.
         */
        public readonly ?string $brand = null,
        /**
         * Source category leaves the map has NO ROW FOR.
         *
         * Non-empty means the importer REFUSES this row (decision 2026-09-15). Not "places it
         * somewhere sensible" and not "drops the leaf quietly" — an unknown category is a question
         * for a person, and the report is where it gets asked.
         *
         * @var list<string>
         */
        public readonly array $unmappedLeaves = [],
    ) {}

    /** Is there anything to sell here? A row with no price is imported but marked. */
    public function hasPrice(): bool
    {
        return $this->sellingPrice > 0;
    }
}
