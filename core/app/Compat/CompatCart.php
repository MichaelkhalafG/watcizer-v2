<?php

namespace App\Compat;

use App\Support\Sql;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The cart half of the wave-3 compat layer: the legacy `carts` / `cart_items` behaviour, with
 * every catalog read moved onto the clean tables.
 *
 * `carts`, `cart_items`, `orders`, `order_items`, `addresses` and `payment_statuses` are SHARED
 * tables, not legacy ones (study §2.6): they were reworked in June–July 2026, they are
 * structurally sound, and duplicating `orders` would break the order-number lock the admin
 * reports depend on. Core writes them on the `default` connection; the read-only `legacy`
 * connection is still only ever used to READ, and never for a commerce table this class writes.
 *
 * Everything here reproduces `backend/app/Http/Controllers/Api/OrderController.php` expression by
 * expression, including the parts that look wrong (`emptyTotals()` emitting integer zeros while
 * `calculateTotals()` emits floats, so `0` and `0.0` appear in the same field across two
 * responses). The harness compares bytes; a tidier shape would be a difference.
 */
final class CompatCart
{
    public function __construct(private readonly int $storefrontId) {}

    // ── identity → cart row ──────────────────────────────────────────────────

    /** `Cart::where($identity)->with('cart_item')->first()` — the row or null. */
    public function find(CartIdentity $identity): ?stdClass
    {
        $row = DB::table('carts')->where($identity->column(), $identity->value())->orderBy('id')->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * `Cart::firstOrCreate($identity, $defaults)`. Guest carts expire after 7 days; user carts
     * never expire.
     */
    public function resolve(CartIdentity $identity): stdClass
    {
        $existing = $this->find($identity);
        if ($existing !== null) {
            return $existing;
        }
        $now = now();
        DB::table('carts')->insert([
            $identity->column() => $identity->value(),
            'expires_at' => $identity->isGuest() ? $now->copy()->addDays(7) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = $this->find($identity);
        if ($row === null) {
            throw new \RuntimeException('Cart insert did not produce a row.');
        }

        return $row;
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function items(int $cartId): Collection
    {
        /** @var Collection<int, stdClass> $rows */
        $rows = DB::table('cart_items')->where('cart_id', $cartId)->orderBy('id')->get();

        return $rows;
    }

    // ── writes ───────────────────────────────────────────────────────────────

    /**
     * `$cart->cart_item()->updateOrCreate([line key], [quantity, prices])`.
     *
     * The line key is the `uniq_cart_line` index: (cart, product, offer, band, dial, type). NULL
     * is part of the key, so the lookup uses `IS NULL` exactly as Eloquent's `where(col, null)`
     * does. Quantity is REPLACED, not incremented — only the merge path adds.
     *
     * @param  array{product_id: int|null, offer_id: int|null, quantity: int, piece_price: string, total_price: string, type_stock: string|null, color_band: string|null, color_dial: string|null}  $line
     */
    public function upsertItem(int $cartId, array $line): void
    {
        DB::transaction(function () use ($cartId, $line): void {
            $now = now();
            $query = DB::table('cart_items')->where('cart_id', $cartId);
            foreach (['product_id', 'offer_id', 'color_band', 'color_dial', 'type_stock'] as $key) {
                $value = $line[$key];
                $value === null ? $query->whereNull($key) : $query->where($key, $value);
            }
            $existing = $query->orderBy('id')->first();
            $values = ['quantity' => $line['quantity'], 'piece_price' => $line['piece_price'], 'total_price' => $line['total_price'], 'updated_at' => $now];

            if ($existing instanceof stdClass) {
                DB::table('cart_items')->where('id', Row::int($existing, 'id'))->update($values);

                return;
            }
            DB::table('cart_items')->insert($values + [
                'cart_id' => $cartId,
                'product_id' => $line['product_id'],
                'offer_id' => $line['offer_id'],
                'color_band' => $line['color_band'],
                'color_dial' => $line['color_dial'],
                'type_stock' => $line['type_stock'],
                'created_at' => $now,
            ]);
        });
    }

    /** `CartItem::whereHas('cart', …)->findOrFail($id)->delete()` — false when it is not this caller's. */
    public function deleteItem(CartIdentity $identity, int $itemId): bool
    {
        $cart = $this->find($identity);
        if ($cart === null) {
            return false;
        }

        return DB::table('cart_items')->where('id', $itemId)->where('cart_id', Row::int($cart, 'id'))->delete() > 0;
    }

    /** `RemoveFromCart` — delete EVERY line of this cart for one product (or one offer). Idempotent. */
    public function removeLine(CartIdentity $identity, ?int $productId, ?int $offerId): void
    {
        $cart = $this->find($identity);
        if ($cart === null) {
            return;
        }
        $query = DB::table('cart_items')->where('cart_id', Row::int($cart, 'id'));
        $productId !== null ? $query->where('product_id', $productId) : $query->where('offer_id', (int) $offerId);
        $query->delete();
    }

    /** Empty and drop a cart (checkout success). */
    public function destroy(int $cartId): void
    {
        DB::table('cart_items')->where('cart_id', $cartId)->delete();
        DB::table('carts')->where('id', $cartId)->delete();
    }

    /**
     * `App\Services\MergeGuestCart::merge()` — fold a guest cart into the user's, ADDING
     * quantities on a colliding line, then delete the guest cart.
     */
    public function merge(int $userId, string $guestToken): void
    {
        DB::transaction(function () use ($userId, $guestToken): void {
            $guestCart = $this->find(CartIdentity::guest($guestToken));
            if ($guestCart === null) {
                return;
            }
            $guestCartId = Row::int($guestCart, 'id');
            $userCart = $this->resolve(CartIdentity::user($userId));
            $userCartId = Row::int($userCart, 'id');

            foreach ($this->items($guestCartId) as $item) {
                $now = now();
                $query = DB::table('cart_items')->where('cart_id', $userCartId);
                foreach (['product_id', 'offer_id', 'color_band', 'color_dial', 'type_stock'] as $key) {
                    $value = $item->{$key} ?? null;
                    $value === null ? $query->whereNull($key) : $query->where($key, $value);
                }
                $existing = $query->orderBy('id')->first();
                $quantity = Row::int($item, 'quantity');

                if ($existing instanceof stdClass) {
                    DB::table('cart_items')->where('id', Row::int($existing, 'id'))->update([
                        'quantity' => Sql::delta('quantity', $quantity),
                        'piece_price' => Row::money($item, 'piece_price'),
                        'total_price' => Row::money($item, 'total_price'),
                        'updated_at' => $now,
                    ]);

                    continue;
                }
                DB::table('cart_items')->insert([
                    'cart_id' => $userCartId,
                    'product_id' => Row::nint($item, 'product_id'),
                    'offer_id' => Row::nint($item, 'offer_id'),
                    'quantity' => $quantity,
                    'piece_price' => Row::money($item, 'piece_price'),
                    'total_price' => Row::money($item, 'total_price'),
                    'type_stock' => Row::nstr($item, 'type_stock'),
                    'color_band' => Row::nstr($item, 'color_band'),
                    'color_dial' => Row::nstr($item, 'color_dial'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->destroy($guestCartId);
        });
    }

    // ── catalog (clean tables) ───────────────────────────────────────────────

    /**
     * Price + stock for the products and offers a set of cart lines refers to.
     *
     * Products come from `catalog_products` joined to this storefront's `storefront_product` row;
     * offers stay on the legacy `offers` table (read-only connection) until offers are cleaned.
     * A soft-deleted clean product is absent, which is what makes "Product no longer available"
     * reachable — the legacy table had no soft deletes, so the message only ever fired on a hard
     * delete there.
     *
     * @param  list<int>  $productIds
     * @param  list<int>  $offerIds
     * @return array{products: array<int, array{selling: string, sale: string|null, express: int, market: int}>, offers: array<int, array{selling: string, sale: string|null, stock: int}>}
     */
    public function catalog(array $productIds, array $offerIds): array
    {
        $products = [];
        if ($productIds !== []) {
            $rows = DB::table('catalog_products as cp')
                ->leftJoin('storefront_product as sp', function (JoinClause $join): void {
                    $join->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', $this->storefrontId);
                })
                ->whereIn('cp.id', $productIds)
                ->whereNull('cp.deleted_at')
                ->select(['cp.id', 'cp.selling_price', 'cp.sale_price', 'cp.stock_express', 'cp.stock_market', 'sp.effective_price', 'sp.effective_sale_price', 'sp.id as sp_id'])
                ->get();
            foreach ($rows as $row) {
                $onStorefront = Row::nint($row, 'sp_id') !== null;
                $products[Row::int($row, 'id')] = [
                    // The storefront row is the price when the product is placed there; the
                    // catalog row is the fallback for a product that is not (which the transform
                    // never produces today — asserted by StorefrontPriceMirrorTest).
                    'selling' => $onStorefront ? Row::money($row, 'effective_price') : Row::money($row, 'selling_price'),
                    'sale' => $onStorefront ? Row::nmoney($row, 'effective_sale_price') : Row::nmoney($row, 'sale_price'),
                    'express' => Row::int($row, 'stock_express'),
                    'market' => Row::int($row, 'stock_market'),
                ];
            }
        }

        $offers = [];
        if ($offerIds !== []) {
            $rows = DB::connection('legacy')->table('offers')
                ->whereIn('id', $offerIds)
                ->select(['id', 'selling_price', 'sale_price_after_discount', 'stock'])
                ->get();
            foreach ($rows as $row) {
                $offers[Row::int($row, 'id')] = [
                    'selling' => Row::money($row, 'selling_price'),
                    'sale' => Row::nmoney($row, 'sale_price_after_discount'),
                    'stock' => Row::int($row, 'stock'),
                ];
            }
        }

        return ['products' => $products, 'offers' => $offers];
    }

    /**
     * `OrderController::catalogPrice()` — the sale price ONLY when it is a real discount
     * (0 < sale < selling), otherwise the selling price, rounded to the DECIMAL(12,2) the money
     * columns hold. The frontend prices the same way; the two diverging is what used to reject
     * every checkout with "Order total mismatch".
     */
    public static function catalogPrice(string $selling, ?string $sale): float
    {
        $s = (float) $selling;
        $d = (float) $sale;                                     // (float) null === 0.0, as in legacy

        return round(($d > 0 && $d < $s) ? $d : $s, 2);
    }

    // ── payloads ─────────────────────────────────────────────────────────────

    /**
     * `cartPayload()`: the cart row (legacy column order) plus its items, then totals and
     * warnings. A caller with no cart yet gets the safe empty shape.
     *
     * @return array<string, mixed>
     */
    public function payload(?stdClass $cart): array
    {
        if ($cart === null) {
            return ['cart_item' => [], 'totals' => $this->emptyTotals(), 'warnings' => []];
        }
        $items = $this->items(Row::int($cart, 'id'));

        return [
            'id' => Row::int($cart, 'id'),
            'user_id' => Row::nint($cart, 'user_id'),
            'guest_token' => Row::nstr($cart, 'guest_token'),
            'created_at' => LegacyJson::ts(Row::nstr($cart, 'created_at')),
            'updated_at' => LegacyJson::ts(Row::nstr($cart, 'updated_at')),
            // NOT a cast attribute on the legacy model, so it is emitted raw from the column.
            'expires_at' => Row::nstr($cart, 'expires_at'),
            'cart_item' => $this->itemRows($items),
            'totals' => $this->totals($items),
            'warnings' => $this->warnings($items),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $items
     * @return list<array<string, mixed>>
     */
    public function itemRows(Collection $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id' => Row::int($item, 'id'),
                'cart_id' => Row::int($item, 'cart_id'),
                'product_id' => Row::nint($item, 'product_id'),
                'offer_id' => Row::nint($item, 'offer_id'),
                'quantity' => Row::int($item, 'quantity'),
                'piece_price' => Row::money($item, 'piece_price'),
                'total_price' => Row::money($item, 'total_price'),
                'type_stock' => Row::nstr($item, 'type_stock'),
                'color_band' => Row::nstr($item, 'color_band'),
                'color_dial' => Row::nstr($item, 'color_dial'),
                'created_at' => LegacyJson::ts(Row::nstr($item, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($item, 'updated_at')),
            ];
        }

        return $out;
    }

    /**
     * `cartWarnings()` — per-line stock and price checks against the live catalog.
     *
     * @param  Collection<int, stdClass>  $items
     * @return list<array{item_id: int, message: string}>
     */
    public function warnings(Collection $items): array
    {
        $catalog = $this->catalogFor($items);
        $warnings = [];

        foreach ($items as $item) {
            $message = null;
            $productId = Row::nint($item, 'product_id');
            $offerId = Row::nint($item, 'offer_id');
            $quantity = Row::int($item, 'quantity');
            $piece = (float) Row::money($item, 'piece_price');

            if ($productId !== null) {
                $product = $catalog['products'][$productId] ?? null;
                if ($product === null) {
                    $message = 'Product no longer available';
                } else {
                    $currentStock = Row::nstr($item, 'type_stock') === 'Express' ? $product['express'] : $product['market'];
                    $currentPrice = self::catalogPrice($product['selling'], $product['sale']);
                    if ($currentStock < $quantity) {
                        $message = "Only {$currentStock} available";
                    } elseif (abs($currentPrice - $piece) > 0.01) {
                        $message = "Price changed to {$currentPrice}";
                    }
                }
            } elseif ($offerId !== null) {
                $offer = $catalog['offers'][$offerId] ?? null;
                if ($offer === null) {
                    $message = 'Offer no longer available';
                } else {
                    $currentPrice = self::catalogPrice($offer['selling'], $offer['sale']);
                    if ($offer['stock'] < $quantity) {
                        $message = "Only {$offer['stock']} available";
                    } elseif (abs($currentPrice - $piece) > 0.01) {
                        $message = "Price changed to {$currentPrice}";
                    }
                }
            }

            if ($message !== null) {
                $warnings[] = ['item_id' => Row::int($item, 'id'), 'message' => $message];
            }
        }

        return $warnings;
    }

    /**
     * `calculateTotals()`. There is no free-shipping rule: shipping depends on the governorate,
     * which is only known once an address is chosen, so it is 0 here and added by the checkout.
     *
     * @param  Collection<int, stdClass>  $items
     * @return array<string, mixed>
     */
    public function totals(Collection $items): array
    {
        $subtotal = 0.0;
        $count = 0;
        foreach ($items as $item) {
            $subtotal += Row::int($item, 'quantity') * (float) Row::money($item, 'piece_price');
            $count += Row::int($item, 'quantity');
        }

        return [
            'subtotal' => round($subtotal, 2),
            'shipping' => 0,
            'shipping_note' => 'Shipping calculated at checkout by governorate',
            'tax' => 0,
            'total' => round($subtotal, 2),
            'item_count' => $count,
            'savings' => $this->savings($items),
        ];
    }

    /**
     * `calculateSavings()` — total saved against the ORIGINAL price, over product and offer lines.
     *
     * @param  Collection<int, stdClass>  $items
     */
    public function savings(Collection $items): float
    {
        $catalog = $this->catalogFor($items);
        $savings = 0;

        foreach ($items as $item) {
            $productId = Row::nint($item, 'product_id');
            $entity = $productId !== null
                ? ($catalog['products'][$productId] ?? null)
                : ($catalog['offers'][Row::nint($item, 'offer_id') ?? 0] ?? null);

            if ($entity !== null) {
                $original = (float) $entity['selling'];
                $sale = (float) $entity['sale'];
                if ($sale > 0 && $sale < $original) {
                    $savings += ($original - $sale) * Row::int($item, 'quantity');
                }
            }
        }

        return round($savings, 2);
    }

    /**
     * `emptyTotals()`. The zeros here are INTEGERS while `calculateTotals()` returns floats —
     * legacy shape, reproduced deliberately (`"subtotal":0` vs `"subtotal":0.0`).
     *
     * @return array<string, mixed>
     */
    public function emptyTotals(): array
    {
        return [
            'subtotal' => 0,
            'shipping' => 0,
            'shipping_note' => 'Shipping calculated at checkout by governorate',
            'tax' => 0,
            'total' => 0,
            'item_count' => 0,
            'savings' => 0,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $items
     * @return array{products: array<int, array{selling: string, sale: string|null, express: int, market: int}>, offers: array<int, array{selling: string, sale: string|null, stock: int}>}
     */
    private function catalogFor(Collection $items): array
    {
        $productIds = [];
        $offerIds = [];
        foreach ($items as $item) {
            $productId = Row::nint($item, 'product_id');
            if ($productId !== null) {
                $productIds[$productId] = true;
            }
            $offerId = Row::nint($item, 'offer_id');
            if ($offerId !== null) {
                $offerIds[$offerId] = true;
            }
        }

        return $this->catalog(array_keys($productIds), array_keys($offerIds));
    }
}
