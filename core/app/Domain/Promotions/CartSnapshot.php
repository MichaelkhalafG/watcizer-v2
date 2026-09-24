<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * The priced cart, frozen — the only input `PromotionEngine::evaluate()` takes (wave 4D, §3.16.1).
 *
 * ── Why a snapshot and not the cart itself ───────────────────────────────────────────────────
 *
 * Three of the brief's non-negotiables are properties of this type rather than promises about the
 * engine:
 *
 *  - **"idempotent evaluation: the same cart evaluated twice yields the same result; nothing
 *    accumulates."** A readonly value object cannot accumulate, and an engine that can only see
 *    this cannot reach a database row that moved between the two calls.
 *  - **"a cart with no matching rule is byte-identical to today."** The engine is handed a
 *    snapshot and returns an outcome; it never touches the cart it was built from, so the
 *    no-match path has nothing to leave behind.
 *  - **evaluation happens on two paths with different rights** — the cart display PREVIEWS and
 *    the checkout GRANTS. One input type means the preview and the grant cannot disagree about
 *    what the cart was, which is the bug that would otherwise show a gift in the cart and not
 *    deliver it.
 *
 * Reward lines are deliberately NOT in here. A snapshot is what the customer chose; a reward is
 * what a rule granted. Feeding a granted reward back into a snapshot is how a rule that rewards a
 * product would start satisfying its own condition.
 */
final readonly class CartSnapshot
{
    /**
     * @param  int  $storefrontId  the cart's storefront — only rules enabled for it are candidates
     * @param  list<CartLine>  $lines  the customer's own lines, priced server-side
     * @param  float  $subtotal  sum of the lines, excluding shipping
     * @param  float  $shippingCost  the resolved city's cost, so a `free_shipping` rule has something to read when it is unlocked
     * @param  string  $paymentMethod  cash | paymob | whatsapp
     */
    public function __construct(
        public int $storefrontId,
        public array $lines,
        public float $subtotal,
        public float $shippingCost,
        public string $paymentMethod,
    ) {}

    /** Total units of one product across every line, whatever its variant or bucket. */
    public function quantityOfProduct(int $productId): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if ($line->productId === $productId) {
                $total += $line->quantity;
            }
        }

        return $total;
    }

    public function quantityOfVariant(int $variantId): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if ($line->variantId === $variantId) {
                $total += $line->quantity;
            }
        }

        return $total;
    }

    /** @param  list<int>  $productIds */
    public function quantityOfProducts(array $productIds): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if ($line->productId !== null && in_array($line->productId, $productIds, true)) {
                $total += $line->quantity;
            }
        }

        return $total;
    }

    /**
     * How many DISTINCT things are in the cart — the "buy any 3 items" shape.
     *
     * Counted by product (or offer) rather than by line, so two sizes of one shirt are one
     * distinct item. Counting lines would let a shopper satisfy "any 3" by picking three sizes of
     * the same thing, which is not what the rule means.
     */
    public function distinctItems(): int
    {
        $seen = [];
        foreach ($this->lines as $line) {
            $key = $line->productId !== null ? 'p'.$line->productId : 'o'.($line->offerId ?? 0);
            $seen[$key] = true;
        }

        return count($seen);
    }

    /** @return list<int> every product id in the cart, for a category or brand lookup */
    public function productIds(): array
    {
        $ids = [];
        foreach ($this->lines as $line) {
            if ($line->productId !== null) {
                $ids[$line->productId] = true;
            }
        }

        /** @var list<int> $out */
        $out = array_keys($ids);

        return $out;
    }
}
