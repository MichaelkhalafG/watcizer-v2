<?php

namespace App\Storefront;

use App\Domain\Campaigns\CampaignPrices;
use App\Domain\Inventory\InventoryService;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\RewardLine;
use App\Domain\Shipping\FreeShipping;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The v2 CART of one storefront (B7 step S1, round 5, 2026-10-18) — the write half of v2, built beside the compat
 * cart and never through it: the compat layer takes Watchizer's real orders, so nothing here edits or calls into
 * `App\Compat`. The tables are the same (`carts`, `cart_items`); every read and write here is scoped to the
 * storefront of the request PATH (`StorefrontContext`), and compat's own lookups are scoped the same way
 * (`CompatCart::find` → `storefront_id`), so a cart made here is invisible to the other storefront's cart.
 *
 * What it adds to what compat can do: a line carries a VARIANT. A product that sells through variants (any active
 * `catalog_product_variants` row) is only buyable as one of them; a product that has none is only buyable as itself
 * — the same rule `ProductDetail` states for the product page (wave 3.5). The checks, in order, at the door:
 *   1. the product is placed, visible, published and active on THIS storefront (the listing's own visibility);
 *   2. a variant exactly when the product has variants; the variant belongs to the product and is active;
 *   3. the quantity fits the chosen stock bucket (Express / Market) of the variant, or of the product.
 * The price is never the client's: `StorefrontPricing` on the storefront's effective price (and the variant's
 * `price_delta`, as on the product page), re-resolved on every read so a stale line never shows an old price.
 *
 * A line is keyed by (cart, product, variant, bucket); setting a line replaces its quantity, as compat's
 * `add_to_cart` does. Lines are re-checked on every read: a line that can no longer be bought says why
 * (`problem`) and is left out of the subtotal — the checkout (S2) refuses it again whatever this said.
 *
 * OFFER rows (compat's, in the same shared table) are not items here and never have been, but they are
 * COUNTED and reported as `blocked` — see {@see self::offerLines()} for why silence was a money bug.
 */
final class StorefrontCart
{
    public const BUCKETS = ['Express', 'Market'];

    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly InventoryService $inventory,
        private readonly ProductCards $cards,
    ) {}

    /** The cart of a signed-in shopper or of a guest token on THIS storefront, or null. */
    public function find(?int $userId, ?string $guestToken): ?stdClass
    {
        $q = DB::table('carts')->where('storefront_id', $this->ctx->id());
        if ($userId !== null) {
            $q->where('user_id', $userId);
        } elseif ($guestToken !== null && $guestToken !== '') {
            $q->where('guest_token', $guestToken)->whereNull('user_id');
        } else {
            return null;
        }
        $row = $q->orderBy('id')->first();

        return $row === null ? null : Row::cast($row);
    }

    /** Find, or create, the cart for that identity on this storefront. */
    public function resolve(?int $userId, string $guestToken): stdClass
    {
        $found = $this->find($userId, $guestToken);
        if ($found !== null) {
            return $found;
        }
        $id = DB::table('carts')->insertGetId([
            'user_id' => $userId,
            'guest_token' => $userId === null ? $guestToken : null,
            'storefront_id' => $this->ctx->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Row::cast((object) ['id' => $id, 'user_id' => $userId, 'guest_token' => $userId === null ? $guestToken : null, 'storefront_id' => $this->ctx->id()]);
    }

    /**
     * The door's checks for a line, WITHOUT writing anything — so a refused add never leaves an empty cart behind.
     * `bucket` null picks Express when it covers the quantity, else Market (how the storefront chooses today).
     * Refusals: `unavailable`, `variant_required`, `variant_not_allowed`, `variant_mismatch`, `insufficient_stock`.
     *
     * @return array{refusal: ?string, bucket: string, price: float}
     */
    public function check(int $productId, ?int $variantId, ?string $bucket, int $quantity): array
    {
        $refuse = fn (string $why): array => ['refusal' => $why, 'bucket' => $bucket ?? 'Express', 'price' => 0.0];
        $product = $this->buyable([$productId])[$productId] ?? null;
        if ($product === null) {
            return $refuse('unavailable');
        }
        $hasVariants = $this->inventory->hasVariants($productId);
        if ($hasVariants && $variantId === null) {
            return $refuse('variant_required');
        }
        if (! $hasVariants && $variantId !== null) {
            return $refuse('variant_not_allowed');
        }
        $stock = $product['stock'];
        $delta = 0.0;
        if ($variantId !== null) {
            $variant = $this->variants([$variantId])[$variantId] ?? null;
            if ($variant === null || $variant['product_id'] !== $productId) {
                return $refuse('variant_mismatch');
            }
            $stock = $variant['stock'];
            $delta = $variant['delta'];
        }
        $bucket ??= $stock['Express'] >= $quantity ? 'Express' : 'Market';
        if ($quantity > $stock[$bucket === 'Market' ? 'Market' : 'Express']) {
            return ['refusal' => 'insufficient_stock', 'bucket' => $bucket, 'price' => 0.0];
        }

        return ['refusal' => null, 'bucket' => $bucket, 'price' => $this->unitPrice($product, $delta)];
    }

    /**
     * Set the quantity of the line (product, variant, bucket) — null when done, else the refusal code of check().
     */
    public function set(stdClass $cart, int $productId, ?int $variantId, ?string $bucket, int $quantity): ?string
    {
        $check = $this->check($productId, $variantId, $bucket, $quantity);
        if ($check['refusal'] !== null) {
            return $check['refusal'];
        }
        $bucket = $check['bucket'];
        $price = $check['price'];
        $cartId = Row::int($cart, 'id');
        $line = DB::table('cart_items')->where('cart_id', $cartId)->where('product_id', $productId)
            ->where('type_stock', $bucket)
            ->where(fn (Builder $q) => $variantId === null ? $q->whereNull('variant_id') : $q->where('variant_id', $variantId))
            ->whereNull('offer_id');
        $values = [
            'quantity' => $quantity,
            'piece_price' => number_format($price, 2, '.', ''),
            'total_price' => number_format($price * $quantity, 2, '.', ''),
            'updated_at' => now(),
        ];
        if ((clone $line)->exists()) {
            $line->update($values);
        } else {
            DB::table('cart_items')->insert($values + [
                'cart_id' => $cartId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'type_stock' => $bucket,
                'created_at' => now(),
            ]);
        }
        DB::table('carts')->where('id', $cartId)->update(['updated_at' => now()]);

        return null;
    }

    /** Change one line's quantity, by its id, inside this cart only. Same refusal codes as set(); `not_found`. */
    public function update(stdClass $cart, int $itemId, int $quantity): ?string
    {
        $item = DB::table('cart_items')->where('id', $itemId)->where('cart_id', Row::int($cart, 'id'))->first();
        if ($item === null) {
            return 'not_found';
        }
        $item = Row::cast($item);
        $bucket = Row::nstr($item, 'type_stock') ?? 'Express';

        return $this->set($cart, Row::int($item, 'product_id'), Row::nint($item, 'variant_id'), $bucket, $quantity);
    }

    /**
     * The cart's lines as the checkout reads them (B7 S2): product, variant, bucket and quantity — never a price,
     * which the checkout resolves again itself.
     *
     * @return list<array{id: int, product_id: int, variant_id: ?int, bucket: string, quantity: int}>
     */
    public function lines(stdClass $cart): array
    {
        $out = [];
        foreach (DB::table('cart_items')->where('cart_id', Row::int($cart, 'id'))->whereNull('offer_id')->orderBy('id')
            ->get(['id', 'product_id', 'variant_id', 'type_stock', 'quantity']) as $raw) {
            $r = Row::cast($raw);
            $out[] = [
                'id' => Row::int($r, 'id'),
                'product_id' => Row::int($r, 'product_id'),
                'variant_id' => Row::nint($r, 'variant_id'),
                'bucket' => Row::nstr($r, 'type_stock') ?? 'Express',
                'quantity' => Row::int($r, 'quantity'),
            ];
        }

        return $out;
    }

    /** Empty and remove the cart after an order (B7 S2) — so an ordered line can never inflate the next order. */
    public function clear(stdClass $cart): void
    {
        $id = Row::int($cart, 'id');
        DB::table('cart_items')->where('cart_id', $id)->delete();
        DB::table('carts')->where('id', $id)->where('storefront_id', $this->ctx->id())->delete();
    }

    /** Remove one line, by its id, inside this cart only. */
    public function remove(stdClass $cart, int $itemId): bool
    {
        return DB::table('cart_items')->where('id', $itemId)->where('cart_id', Row::int($cart, 'id'))->delete() > 0;
    }

    /**
     * The cart's promotion PREVIEW (M1u, 2026-10-30): the automatic promotions and the typed code, priced by the same
     * engine the checkout runs, on the buyable lines, before delivery (unknown until the address — a free-delivery
     * reward is therefore never in the preview). Only a preview: the checkout prices the order again and is the
     * only number that is ever charged.
     *
     * Asked only where the storefront's money rewards are switched on (from the resolved row, no query) or a code
     * was typed — the cart is the hottest read in the shop and must not pay for promotions it cannot show.
     *
     * @param  list<CartLine>  $lines
     * @param  list<int>  $itemIds  the cart item id of each line, by index
     *                              `rewards` (2026-11-09): the free ITEMS the winning rule grants — a gift won on the wheel — named so the cart and the
     *                              checkout can show "Gift: …, free" before the order; the order path adds the line itself at EGP 0.
     * @return array{discount: float, applied: list<array<string, mixed>>, lines: list<array{item_id: int, amount: float}>, coupon: array<string, mixed>|null, rewards: list<array{product_id: int, quantity: int, title: array{en: string|null, ar: string|null}}>}
     */
    private function promotionPreview(array $lines, array $itemIds, float $subtotal, ?string $coupon, ?int $userId, ?string $phone = null): array
    {
        $none = ['discount' => 0.0, 'applied' => [], 'lines' => [], 'coupon' => null, 'rewards' => []];
        $settings = $this->ctx->storefront->getAttribute('settings');
        $enabled = is_array($settings) && (Coerce::arr($settings['promotions'] ?? null)['money_rewards'] ?? false) === true;
        if (! $enabled && $coupon === null) {
            return $none;
        }
        $outcome = app(PromotionEngine::class)->evaluate(new CartSnapshot(
            storefrontId: $this->ctx->id(),
            lines: $lines,
            subtotal: $subtotal,
            shippingCost: 0.0,
            paymentMethod: '',
            code: $coupon,
            // the phone only when the checkout page sends the one it has typed (a wheel code's binding, 2026-11-07)
            customerKeys: PromotionDiscounts::customerKeys($userId, null, $phone),
        ));
        $goods = [];
        foreach ($outcome->lines as $line) {
            $itemId = $itemIds[$line['index']] ?? null;
            if ($itemId !== null) {
                $goods[$itemId] = round(($goods[$itemId] ?? 0.0) + $line['amount'], 2);
            }
        }
        $perItem = [];
        foreach ($goods as $itemId => $amount) {
            $perItem[] = ['item_id' => $itemId, 'amount' => $amount];
        }
        $applied = [];
        foreach ($outcome->applied as $rule) {
            $applied[] = ['rule_id' => $rule['rule_id'], 'code' => $rule['code'], 'label_en' => $rule['label_en'],
                'label_ar' => $rule['label_ar'], 'amount' => $rule['amount']];
        }

        return ['discount' => round(array_sum($goods), 2), 'applied' => $applied, 'lines' => $perItem, 'coupon' => $outcome->coupon,
            'rewards' => self::rewardsNamed($outcome->rewards)];
    }

    /**
     * The granted items with their names (one query, only when a rule granted any).
     *
     * @param  list<RewardLine>  $rewards
     * @return list<array{product_id: int, quantity: int, title: array{en: string|null, ar: string|null}}>
     */
    private static function rewardsNamed(array $rewards): array
    {
        $ids = [];
        foreach ($rewards as $reward) {
            if ($reward->productId !== null) {
                $ids[] = $reward->productId;
            }
        }
        if ($ids === []) {
            return [];
        }
        $titles = [];
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', array_values(array_unique($ids)))->whereIn('locale', ['en', 'ar'])->get(['product_id', 'locale', 'title']) as $raw) {
            $row = Row::cast($raw);
            $titles[Row::int($row, 'product_id')][Row::str($row, 'locale')] = Row::nstr($row, 'title');
        }
        $out = [];
        foreach ($rewards as $reward) {
            if ($reward->productId === null) {
                continue;
            }
            $out[] = ['product_id' => $reward->productId, 'quantity' => $reward->quantity,
                'title' => ['en' => $titles[$reward->productId]['en'] ?? null, 'ar' => $titles[$reward->productId]['ar'] ?? null]];
        }

        return $out;
    }

    /** The free-shipping threshold of the request's storefront, from the row already resolved (no query). */
    private function freeOver(): ?float
    {
        return FreeShipping::fromSettings($this->ctx->storefront->getAttribute('settings'));
    }

    /**
     * The cart as the storefront shows it: each line with the product's card, its variant, bucket, quantity, the
     * unit price and line total re-resolved NOW, the stock of its bucket, and `problem` when it cannot be bought
     * as it stands; the subtotal counts the buyable lines only.
     *
     * @return array{id: int|null, items: list<array<string, mixed>>, totals: array{count: int, subtotal: float, currency: string, shipping: array{free_over: float|null, free: bool, remaining: float|null}}}
     */
    public function payload(?stdClass $cart, ?string $coupon = null, ?int $userId = null, ?string $phone = null): array
    {
        $currency = $this->cards->currency();
        if ($cart === null) {
            return ['id' => null, 'items' => [], 'totals' => ['count' => 0, 'subtotal' => 0.0, 'currency' => $currency, 'shipping' => FreeShipping::summaryFor($this->freeOver(), 0.0),
                'promotion' => $this->promotionPreview([], [], 0.0, $coupon, $userId, $phone)]];
        }
        $rows = [];
        foreach (DB::table('cart_items')->where('cart_id', Row::int($cart, 'id'))->whereNull('offer_id')->orderBy('id')
            ->get(['id', 'product_id', 'variant_id', 'quantity', 'type_stock']) as $raw) {
            $rows[] = Row::cast($raw);
        }
        $productIds = array_values(array_unique(array_map(fn (stdClass $r) => Row::int($r, 'product_id'), $rows)));
        $variantIds = array_values(array_filter(array_map(fn (stdClass $r) => Row::nint($r, 'variant_id'), $rows), fn (?int $v) => $v !== null));
        $buyable = $this->buyable($productIds);
        $variants = $this->variants($variantIds);
        $cards = [];
        if ($productIds !== []) {
            foreach ($this->cards->build($this->cards->base()->whereIn('sp.product_id', $productIds)->select(ProductCards::columns())->get()->all()) as $card) {
                $cards[is_numeric($card['id'] ?? null) ? (int) $card['id'] : 0] = $card;
            }
        }

        // ONE query for every plain line, not one per line: a cart of ten products paid ten `exists` reads
        // (CartQueryBudgetTest, 2026-10-21). The same question as `hasVariants()` — any variant row, active or not.
        $plainIds = array_values(array_unique(array_map(
            fn (stdClass $r) => Row::int($r, 'product_id'),
            array_filter($rows, fn (stdClass $r) => Row::nint($r, 'variant_id') === null),
        )));
        $withVariants = array_fill_keys($this->inventory->productsWithVariants($plainIds), true);

        $items = [];
        $count = 0;
        $subtotal = 0.0;
        $promoLines = [];
        $promoItemIds = [];
        foreach ($rows as $r) {
            $productId = Row::int($r, 'product_id');
            $variantId = Row::nint($r, 'variant_id');
            $bucket = Row::nstr($r, 'type_stock') ?? 'Express';
            $quantity = Row::int($r, 'quantity');
            $product = $buyable[$productId] ?? null;
            $variant = $variantId === null ? null : ($variants[$variantId] ?? null);
            $problem = null;
            $stock = 0;
            $unit = null;
            if ($product === null) {
                $problem = 'unavailable';
            } elseif ($variantId === null && isset($withVariants[$productId])) {
                $problem = 'variant_required';
            } elseif ($variantId !== null && ($variant === null || $variant['product_id'] !== $productId)) {
                $problem = 'variant_unavailable';
            } else {
                $stock = ($variant ?? $product)['stock'][$bucket] ?? 0;
                $unit = $this->unitPrice($product, $variant['delta'] ?? 0.0);
                if ($quantity > $stock) {
                    $problem = 'insufficient_stock';
                }
            }
            $line = $unit === null ? null : round($unit * $quantity, 2);
            if ($problem === null && $line !== null) {
                $count += $quantity;
                $subtotal += $line;
                $promoLines[] = new CartLine(productId: $productId, variantId: $variantId, offerId: null,
                    quantity: $quantity, unitPrice: (float) $unit, typeStock: $bucket);
                $promoItemIds[] = Row::int($r, 'id');
            }
            $items[] = [
                'id' => Row::int($r, 'id'),
                'product' => $cards[$productId] ?? ['id' => $productId],
                'variant' => $variant === null ? null : ['id' => $variantId, 'label' => $variant['label'], 'size_id' => $variant['size_id'], 'color_id' => $variant['color_id']],
                'bucket' => $bucket,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'line_total' => $line,
                'available' => $stock,
                'problem' => $problem,
            ];
        }

        return [
            'id' => Row::int($cart, 'id'),
            'items' => $items,
            // Offer rows are not items here (v2 cannot price one), but their COUNT is reported so the cart can
            // say so. Silence would mean a shopper sees a total that does not include something they added.
            'blocked' => $this->offerLines($cart),
            // `shipping`: the storefront's free-shipping threshold, whether THIS cart ships free, how much more it needs
            'totals' => ['count' => $count, 'subtotal' => round($subtotal, 2), 'currency' => $currency, 'shipping' => FreeShipping::summaryFor($this->freeOver(), round($subtotal, 2)),
                // `promotion`: what core would take off these lines today (M1u) — the page SHOWS it; the checkout recomputes it
                'promotion' => $this->promotionPreview($promoLines, $promoItemIds, round($subtotal, 2), $coupon, $userId, $phone)],
        ];
    }

    /**
     * How many lines of this cart are OFFER lines — rows v2 cannot price, sell or check out (2026-10-21).
     *
     * ── Why this exists ─────────────────────────────────────────────────────────────────────────
     *
     * `cart_items` is shared with compat, and compat sells offers: a row may carry `offer_id` instead of
     * `product_id`. v2 has no offer in its cart at all — `store()` takes a product, `lines()` and `payload()
     * both filtered offer rows out with `whereNull('offer_id')`, and `StorefrontCheckout` hard-codes
     * `'offer_id' => null` on every line it writes.
     *
     * Filtering them out READ-SIDE is harmless. Filtering them out of {@see self::lines()} was not: the
     * checkout reads that method, so a cart holding an offer would have been checked out with the offer
     * missing from the order and never charged — a line lost silently at the exact moment money is taken.
     *
     * Nothing can hit it today: production holds ZERO offers, none in any cart, none in any order ever
     * (measured 2026-10-21). But the dashboard can create one, and then it would have been a trap. So v2
     * now SEES these rows and refuses, instead of pretending they are not there. Selling offers on v2 is
     * slice 6's work and is not invented here.
     */
    public function offerLines(stdClass $cart): int
    {
        return DB::table('cart_items')->where('cart_id', Row::int($cart, 'id'))->whereNotNull('offer_id')->count();
    }

    /**
     * The products of `$ids` that are buyable on THIS storefront (placed, visible, published, active, not deleted),
     * with their effective price pair and their two stock buckets.
     *
     * @param  list<int>  $ids
     * @return array<int, array{price: string, sale: ?string, stock: array{Express: int, Market: int}}>
     */
    private function buyable(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->cards->base()->whereIn('sp.product_id', $ids)
            ->get(['sp.product_id', 'sp.effective_price', 'sp.effective_sale_price', 'p.stock_express', 'p.stock_market']) as $raw) {
            $r = Row::cast($raw);
            $productId = Row::int($r, 'product_id');
            $out[$productId] = [
                'price' => Row::money($r, 'effective_price'),
                // the campaign price while a priced campaign is live (2026-11-10): the cart shows and the checkout charges it
                'sale' => CampaignPrices::saleFor($this->ctx->id(), $productId, Row::money($r, 'effective_price'), Row::nmoney($r, 'effective_sale_price'))['sale'],
                'stock' => ['Express' => Row::int($r, 'stock_express'), 'Market' => Row::int($r, 'stock_market')],
            ];
        }

        return $out;
    }

    /**
     * ACTIVE variants by id (an inactive variant is not buyable — ProductDetail omits it too).
     *
     * @param  list<int>  $ids
     * @return array<int, array{product_id: int, label: string, size_id: ?int, color_id: ?int, delta: float, stock: array{Express: int, Market: int}}>
     */
    private function variants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('catalog_product_variants')->whereIn('id', $ids)->where('is_active', 1)
            ->get(['id', 'product_id', 'label', 'size_id', 'color_id', 'price_delta', 'stock_express', 'stock_market']) as $raw) {
            $r = Row::cast($raw);
            $out[Row::int($r, 'id')] = [
                'product_id' => Row::int($r, 'product_id'),
                'label' => Row::str($r, 'label'),
                'size_id' => Row::nint($r, 'size_id'),
                'color_id' => Row::nint($r, 'color_id'),
                'delta' => (float) Row::money($r, 'price_delta'),
                'stock' => ['Express' => Row::int($r, 'stock_express'), 'Market' => Row::int($r, 'stock_market')],
            ];
        }

        return $out;
    }

    /**
     * What one unit costs: the valid sale when there is one, else the price — through `StorefrontPricing`, with the
     * variant's delta added to both sides exactly as `ProductDetail::variants()` does for the product page.
     *
     * @param  array{price: string, sale: ?string, stock: array{Express: int, Market: int}}  $product
     */
    private function unitPrice(array $product, float $delta): float
    {
        $p = StorefrontPricing::resolve(
            number_format((float) $product['price'] + $delta, 2, '.', ''),
            $product['sale'] === null ? null : number_format((float) $product['sale'] + $delta, 2, '.', ''),
            'EGP',
        );

        return $p['has_sale'] && $p['sale_amount'] !== null ? $p['sale_amount'] : $p['amount'];
    }
}
