<?php

namespace App\Domain\Orders;

use App\Domain\Promotions\PromotionDiscounts;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * What an order cost, line by line — the ONE answer the customer's e-mail, the admin's e-mail and
 * the dashboard's order screen all read (2026-09-26).
 *
 * ── Why there is one ─────────────────────────────────────────────────────────────────────────
 *
 * The confirmation e-mail derived its block from "total minus the city's shipping price", so its
 * Subtotal was the price already paid, and its Discount was everything below list price:
 *
 *     Subtotal 999   Discount −501   Shipping 100   Total 1,099     ← adds to 598, reads as overcharged
 *
 * The dashboard derived the same order a second way. Two definitions of one number drift, and
 * this one was already wrong three more ways waiting for promotions: a promotion merged into the
 * sale saving, free shipping shown as charged (the city price, while the order paid 0), and a
 * gift's list price counted as "discount".
 *
 * ── The block, and its invariant ─────────────────────────────────────────────────────────────
 *
 *     list − saleDiscount − promotion + shipping = total          (tested for every shape)
 *
 *  • list          what the PAID lines cost at list price (a variant's price delta included);
 *                  gift lines (`is_reward`) are shown as gifts, never counted here
 *  • saleDiscount  list − what those lines were actually charged — the sale saving
 *  • promotion     a money promotion's amount (`promotion_order_discounts`); 0 for free shipping,
 *                  which is shown on the shipping line instead — the ledger records a waived
 *                  delivery price AS the amount, and "+100 shipping, −100 promotion" helps nobody
 *  • shipping      what delivery was actually CHARGED: the order's stored total minus the goods.
 *                  Never re-read from the city's current price, which may have changed since
 *  • total         `orders.total_price_for_order`, the one money column the order stores
 *
 * `expectedShipping` (the city's price today, or 0 when the order had free shipping) is kept for
 * the dashboard's "unexplained" line — a total nothing above accounts for, which a person should
 * see rather than have hidden.
 */
final readonly class OrderTotals
{
    /**
     * @param  array<int, float>  $lineList  each PAID line's list total, by `order_items.id` — what the e-mail strikes through
     */
    public function __construct(
        public float $list,
        public float $saleDiscount,
        public float $promotion,
        public ?string $promotionName,
        public bool $freeShipping,
        public float $shipping,
        public ?float $expectedShipping,
        public float $total,
        public array $lineList = [],
    ) {}

    /** What the paid lines were charged — list minus the sale saving. */
    public function paid(): float
    {
        return round($this->list - $this->saleDiscount, 2);
    }

    /**
     * What the charged shipping does not account for against the city's price today (or 0 when
     * delivery was free): a price moved since the order, or a total adjusted by hand. With no
     * address there is no expectation, so the whole delivery figure is unexplained.
     */
    public function unexplained(): float
    {
        return round($this->shipping - ($this->expectedShipping ?? 0.0), 2);
    }

    public static function of(int $orderId): ?self
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['total_price_for_order', 'address_id']);
        if ($order === null) {
            return null;
        }
        $order = Row::cast($order);
        $total = round((float) Row::money($order, 'total_price_for_order'), 2);

        $list = 0.0;
        $paid = 0.0;
        $lineList = [];
        $rows = DB::table('order_items as oi')
            ->leftJoin('catalog_products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'oi.variant_id')
            ->leftJoin('offers as f', 'f.id', '=', 'oi.offer_id')
            ->where('oi.order_id', $orderId)
            ->get(['oi.id', 'oi.product_id', 'oi.quantity', 'oi.piece_price', 'oi.total_price', 'oi.is_reward',
                'p.selling_price', 'v.price_delta', 'f.selling_price as offer_selling_price']);
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            if (Row::bool($row, 'is_reward')) {
                continue;                       // a gift: shown as a gift, never a price
            }
            $qty = Row::int($row, 'quantity');
            $unit = (float) Row::money($row, 'piece_price');
            $line = (float) (Row::nmoney($row, 'total_price') ?? '0');
            $line = $line > 0.0 ? $line : $unit * $qty;

            $listUnit = Row::nint($row, 'product_id') !== null
                ? (Row::nmoney($row, 'selling_price') === null ? null : (float) Row::money($row, 'selling_price') + (float) (Row::nmoney($row, 'price_delta') ?? '0'))
                : (Row::nmoney($row, 'offer_selling_price') === null ? null : (float) Row::money($row, 'offer_selling_price'));

            $paid += $line;
            // Never below what was charged: a list price RAISED since the order would otherwise
            // make the saving negative and the column stop adding up.
            $lineListTotal = round(max($listUnit === null ? $line : $listUnit * $qty, $line), 2);
            $lineList[Row::int($row, 'id')] = $lineListTotal;
            $list += $lineListTotal;
        }
        $list = round($list, 2);
        $paid = round($paid, 2);

        $discount = PromotionDiscounts::forOrder($orderId);
        $freeShipping = $discount !== null && $discount['free_shipping'];
        $promotion = $discount === null || $freeShipping ? 0.0 : round((float) $discount['amount'], 2);

        $expected = null;
        $addressId = Row::nint($order, 'address_id');
        if ($freeShipping) {
            $expected = 0.0;
        } elseif ($addressId !== null) {
            $cost = DB::table('addresses as a')->join('shipping_cities as c', 'c.id', '=', 'a.shipping_city_id')
                ->where('a.id', $addressId)->value('c.shipping_cost');
            $expected = is_numeric($cost) ? round((float) $cost, 2) : null;
        }

        return new self(
            list: $list,
            saleDiscount: round($list - $paid, 2),
            promotion: $promotion,
            promotionName: $promotion > 0.0 && $discount !== null ? $discount['rule_name'] : null,
            freeShipping: $freeShipping,
            shipping: round($total - ($paid - $promotion), 2),
            expectedShipping: $expected,
            total: $total,
            lineList: $lineList,
        );
    }
}
