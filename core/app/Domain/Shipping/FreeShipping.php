<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Support\Coerce;
use Illuminate\Support\Facades\DB;

/**
 * FREE SHIPPING OVER A THRESHOLD, per storefront (2026-10-29, the client via Michael: "free shipping on orders over
 * 10,000 EGP").
 *
 * ── The one rule ───────────────────────────────────────────────────────────────────────────────
 *
 *     shipping is waived when  goods  ≥  settings.shipping.free_over
 *
 *  • `goods` is the merchandise subtotal AT THE PRICES CHARGED (a valid sale price already applied), BEFORE any
 *    cart-level promotion. That is the number the storefront shows as the subtotal, so the cart, the checkout, the
 *    total guard, the stored order and the amount sent to Paymob are all exact. (The alternative — after the
 *    promotion's money discount — would need the storefront to know the promotion before the order exists; core
 *    has no coupons, and money rewards are switched off on both storefronts today, so the two rules give the same
 *    answer until a money promotion runs. The choice is Michael's; see the runbook.)
 *  • `free_over` is read from `storefronts.settings.shipping.free_over`, edited on the dashboard's storefront screen:
 *    a positive amount is the threshold; 0 switches it off; ABSENT means the default, 10,000 (the client's figure
 *    for both storefronts).
 *  • "At the threshold" is free: 10,000.00 on a 10,000 threshold ships free (≥, compared in cents).
 *
 * The checkouts (v2 `StorefrontCheckout`, compat `CheckoutCompatController`) apply it where they compute the
 * shipping, BEFORE the total guard; the order records the threshold that waived it (`orders.free_shipping_over`),
 * which is how `OrderTotals` — the dashboard and both e-mails — knows the delivery was free and not unexplained.
 */
final class FreeShipping
{
    public const DEFAULT_OVER = 10000.0;

    /** The storefront's threshold, or null when free shipping is off. */
    public static function threshold(int $storefrontId): ?float
    {
        // read every time (one indexed row): a memo would outlive a request in a long-running worker and keep a
        // threshold the dashboard has since changed
        $raw = DB::table('storefronts')->where('id', $storefrontId)->value('settings');

        return self::fromSettings(is_string($raw) ? json_decode($raw, true) : $raw);
    }

    /**
     * The threshold out of a storefront's settings already in hand — the cart reads the request's resolved
     * storefront this way rather than paying a query on every cart answer (the resolved row is forgotten when the
     * dashboard saves the storefront, so it never shows a stale threshold for longer than a save).
     */
    public static function fromSettings(mixed $settings): ?float
    {
        $shipping = is_array($settings) ? Coerce::arr($settings['shipping'] ?? null) : [];
        $value = array_key_exists('free_over', $shipping) ? $shipping['free_over'] : self::DEFAULT_OVER;
        $over = is_numeric($value) ? round((float) $value, 2) : self::DEFAULT_OVER;

        return $over > 0.0 ? $over : null;
    }

    /** Does `goods` reach the threshold? Compared in cents, so a float sum never misses an exact match. */
    public static function applies(?float $over, float $goods): bool
    {
        return $over !== null && (int) round($goods * 100) >= (int) round($over * 100);
    }

    /** What delivery costs this cart: 0 when waived, the governorate's price otherwise. */
    public static function charged(int $storefrontId, float $goods, float $cityCost): float
    {
        return self::applies(self::threshold($storefrontId), $goods) ? 0.0 : round($cityCost, 2);
    }

    /**
     * What the storefront shows beside the cart: the threshold, whether this cart ships free, and how much more
     * it needs (0 when it already does; null when free shipping is off).
     *
     * @return array{free_over: float|null, free: bool, remaining: float|null}
     */
    public static function summary(int $storefrontId, float $goods): array
    {
        return self::summaryFor(self::threshold($storefrontId), $goods);
    }

    /**
     * The same summary for a threshold already read (see fromSettings()).
     *
     * @return array{free_over: float|null, free: bool, remaining: float|null}
     */
    public static function summaryFor(?float $over, float $goods): array
    {
        $free = self::applies($over, $goods);

        return [
            'free_over' => $over,
            'free' => $free,
            'remaining' => $over === null ? null : ($free ? 0.0 : round($over - $goods, 2)),
        ];
    }
}
