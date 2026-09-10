<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WHAT a stock movement moves: a product, or one variant of a product (wave 3.5).
 *
 * The seam stays a single door. Rather than growing a second `adjustVariant()` beside
 * `adjust()` — two doors, two sets of guards, two places for the rules to drift — the target
 * became a type. A movement addresses exactly one of:
 *
 *   • `StockTarget::product($id)`            a product with NO variants; the product columns are
 *                                            authoritative, exactly as before wave 3.5
 *   • `StockTarget::variant($id, $variantId)` one variant; the VARIANT columns are authoritative
 *                                            and the product columns are a maintained aggregate
 *
 * Which of the two is legal for a given product is not the caller's choice — it is a property of
 * the data, and {@see InventoryService::assertTarget()} enforces it. A product that has variants
 * can only be moved through a variant, and a product that has none can only be moved directly.
 * That is what keeps `catalog_products.stock_*` equal to the sum of its variants at all times.
 */
final class StockTarget
{
    private function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
    ) {}

    public static function product(int $productId): self
    {
        if ($productId < 1) {
            throw new InvalidArgumentException("StockTarget needs a product id, got [{$productId}].");
        }

        return new self($productId, null);
    }

    public static function variant(int $productId, int $variantId): self
    {
        if ($productId < 1 || $variantId < 1) {
            throw new InvalidArgumentException("StockTarget::variant needs both ids, got [{$productId}, {$variantId}].");
        }

        return new self($productId, $variantId);
    }

    /** Build from a row that may or may not carry a variant — an order line, a cart line. */
    public static function fromLine(int $productId, ?int $variantId): self
    {
        return $variantId === null ? self::product($productId) : self::variant($productId, $variantId);
    }

    public function isVariant(): bool
    {
        return $this->variantId !== null;
    }

    /** The table and row the AUTHORITATIVE stock columns live on for this target. */
    public function table(): string
    {
        return $this->isVariant() ? 'catalog_product_variants' : 'catalog_products';
    }

    public function rowId(): int
    {
        return $this->variantId ?? $this->productId;
    }

    /** Does a product have at least one variant? Not "active" — see the aggregate rule in §3.10. */
    public static function productHasVariants(int $productId): bool
    {
        return DB::table('catalog_product_variants')->where('product_id', $productId)->exists();
    }

    public function describe(): string
    {
        return $this->isVariant()
            ? "product {$this->productId} variant {$this->variantId}"
            : "product {$this->productId}";
    }
}
