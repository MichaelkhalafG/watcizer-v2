<?php

namespace App\Storefront;

/**
 * Resolved storefront price (CLEAN_CORE_STUDY §3.5.1 native `price` object, D3).
 *
 * `storefront_product.effective_price` / `effective_sale_price` are service-maintained
 * (the price-override gate stays OFF: effective = catalog price). One helper for every
 * emission point so a price is never recomputed in a resource or on the frontend.
 */
final class StorefrontPricing
{
    /**
     * @return array{amount: float, sale_amount: float|null, currency: string, discount_pct: int, has_sale: bool}
     */
    public static function resolve(string $effectivePrice, ?string $effectiveSalePrice, string $currency): array
    {
        $amount = (float) $effectivePrice;
        $sale = $effectiveSalePrice === null ? null : (float) $effectiveSalePrice;
        $hasSale = $sale !== null && $sale > 0 && $sale < $amount;
        if (! $hasSale) {
            $sale = null;
        }

        return [
            'amount' => $amount,
            'sale_amount' => $sale,
            'currency' => $currency,
            'discount_pct' => self::discountPct($effectivePrice, $effectiveSalePrice),
            'has_sale' => $hasSale,
        ];
    }

    /** round((selling - sale) / selling * 100), 0 when there is no valid sale (study §2.2, A-22). */
    public static function discountPct(string $selling, ?string $sale): int
    {
        $s = (float) $selling;
        $d = $sale === null ? null : (float) $sale;
        if ($d === null || $s <= 0 || $d <= 0 || $d >= $s) {
            return 0;
        }

        return (int) round(($s - $d) / $s * 100);
    }
}
