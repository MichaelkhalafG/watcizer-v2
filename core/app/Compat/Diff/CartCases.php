<?php

namespace App\Compat\Diff;

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The wave-3 harness cases: cart, checkout and account.
 *
 * These endpoints WRITE, which changes what a byte-diff can mean, so the list is built on three
 * deliberate rules:
 *
 *  1. **Guest sequences are per-host.** Every stateful cart/checkout script runs under its own
 *     guest token on each side, so the two build separate rows in the shared `carts` /
 *     `cart_items` / `orders` tables instead of trampling one another. The cost is that row ids,
 *     timestamps, the guest token and the order number cannot match; rules D-16…D-20 absorb
 *     exactly those, and each one carries a shape predicate so a null or a wrong type still
 *     fails.
 *  2. **Authenticated cases are READ-ONLY and share one identity.** `me/orders` and
 *     `me/addresses` are fetched with the same minted token on both hosts, against the same
 *     rows, so those comparisons are exact — no absorption at all. The authenticated WRITE
 *     paths (cart/merge's happy path, deleting an address) are proven by Pest against fixtures
 *     instead of by writing to a real customer's rows.
 *  3. **Error branches are the majority.** Validation, ownership, 401, 404, out-of-stock and
 *     total-mismatch responses are pure functions of the request, so they diff exactly and they
 *     are where a reimplementation actually drifts.
 *
 * LOUD: the checkout sequence really does place orders and really does decrement stock, on BOTH
 * hosts. That is the point — it is the only way to prove the ledger — but it means a harness run
 * changes `orders`, `order_items`, `addresses`, `carts`, legacy `products.stock` and clean
 * `catalog_products.stock_express`. The rehearsal protocol therefore compares the FROZEN table
 * set (`core:checksum --set=frozen`), not all 65 legacy tables, after a run that includes the
 * harness. See the wave-3 flag list.
 */
final class CartCases
{
    /**
     * @return list<DiffCase>
     */
    public static function build(): array
    {
        $stocked = self::stockedProduct();
        $empty = self::outOfStockProduct();
        $city = self::shippingCity();
        $reader = self::readOnlyUser();

        $cases = [];
        $cases = array_merge($cases, self::cartSequence($stocked));
        $cases = array_merge($cases, self::cartErrors($stocked));
        $cases = array_merge($cases, self::addressCases($city));
        $cases = array_merge($cases, self::checkoutSequence($stocked, $empty, $city));
        $cases = array_merge($cases, self::accountCases($reader));

        return $cases;
    }

    // ── cart ─────────────────────────────────────────────────────────────────

    /**
     * @param  array{id: int, price: float, stock: int}|null  $product
     * @return list<DiffCase>
     */
    private static function cartSequence(?array $product): array
    {
        if ($product === null) {
            return [];
        }
        $guest = ['X-Guest-Token' => '{guest}'];
        $line = fn (int $qty): array => [
            'product_id' => $product['id'],
            'quantity' => $qty,
            'piece_price' => $product['price'],
            'total_price' => round($product['price'] * $qty, 2),
            'type_stock' => 'Express',
        ];

        return [
            // `$.cart_item` is compared POSITIONALLY on every read below: each host owns its own
            // rows under its own guest token, so pairing the two lists by row id would report
            // one missing plus one extra per line instead of comparing the lines field by field.
            // Position is the honest pairing here — and it still compares every other field.
            //
            // A token with no cart yet: both hosts must answer the safe empty shape, in which the
            // integer zeros of emptyTotals() differ from calculateTotals()' floats.
            new DiffCase('cart:empty', 'api/me/cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'GET', null, 'cart-basic'),
            new DiffCase('cart:add', 'api/add_to_cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', $line(1), 'cart-basic'),
            new DiffCase('cart:show', 'api/me/cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'GET', null, 'cart-basic', ['item' => '$.cart_item[0].id'], ['$.cart_item']),
            new DiffCase('cart:validate', 'api/cart/validate', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', [], 'cart-basic'),
            // The same line again REPLACES the quantity (only the merge path adds).
            new DiffCase('cart:add-again', 'api/add_to_cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', $line(2), 'cart-basic'),
            new DiffCase('cart:show-2', 'api/me/cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'GET', null, 'cart-basic', [], ['$.cart_item']),
            // Delete BY ID — each host deletes the id it captured from its own cart.
            new DiffCase('cart:delete-item', 'api/delete_cart/{item}', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'DELETE', null, 'cart-basic'),
            new DiffCase('cart:show-3', 'api/me/cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'GET', null, 'cart-basic', [], ['$.cart_item']),
            // …and the delete is not idempotent: the row is gone, so the second one is a 404.
            new DiffCase('cart:delete-item-again', 'api/delete_cart/{item}', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'DELETE', null, 'cart-basic'),
            new DiffCase('cart:add-3', 'api/add_to_cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', $line(1), 'cart-basic'),
            // remove_from_cart deletes every line for the product and IS idempotent.
            new DiffCase('cart:remove', 'api/remove_from_cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => $product['id']], 'cart-basic'),
            new DiffCase('cart:remove-again', 'api/remove_from_cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => $product['id']], 'cart-basic'),
            new DiffCase('cart:show-4', 'api/me/cart', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'GET', null, 'cart-basic', [], ['$.cart_item']),
            // An empty cart validates as valid with the empty (integer-zero) totals.
            new DiffCase('cart:validate-empty', 'api/cart/validate', 'json', $guest, true, 'cart', DiffCase::ACCEPT_AXIOS, 'POST', [], 'cart-basic'),
        ];
    }

    /**
     * @param  array{id: int, price: float, stock: int}|null  $product
     * @return list<DiffCase>
     */
    private static function cartErrors(?array $product): array
    {
        $guest = ['X-Guest-Token' => '{guest}'];
        $id = $product['id'] ?? 1;
        $price = $product['price'] ?? 0.0;

        return [
            // AddToCart catches \Exception, so a VALIDATION failure is a 500 with a ref, not a 422.
            new DiffCase('cart:add:invalid', 'api/add_to_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', [], 'cart-err'),
            new DiffCase('cart:add:missing-product', 'api/add_to_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => 999999, 'quantity' => 1, 'piece_price' => 1, 'total_price' => 1, 'type_stock' => 'Express'], 'cart-err'),
            new DiffCase('cart:add:bad-type-stock', 'api/add_to_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => $id, 'quantity' => 1, 'piece_price' => $price, 'total_price' => $price, 'type_stock' => 'Nope'], 'cart-err'),
            new DiffCase('cart:add:zero-quantity', 'api/add_to_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => $id, 'quantity' => 0, 'piece_price' => $price, 'total_price' => 0, 'type_stock' => 'Express'], 'cart-err'),
            // …but an over-stock request is a real 422 with the legacy message.
            new DiffCase('cart:add:overstock', 'api/add_to_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => $id, 'quantity' => 999999, 'piece_price' => $price, 'total_price' => $price, 'type_stock' => 'Express'], 'cart-err'),
            new DiffCase('cart:remove:no-keys', 'api/remove_from_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', [], 'cart-err'),
            new DiffCase('cart:remove:bad-type', 'api/remove_from_cart', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['product_id' => 'abc'], 'cart-err'),
            new DiffCase('cart:delete:404', 'api/delete_cart/999999', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'DELETE', null, 'cart-err'),
            new DiffCase('cart:delete:non-numeric', 'api/delete_cart/abc', 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'DELETE', null, 'cart-err'),
            // Someone else's cart line: the id exists, but not under this token.
            new DiffCase('cart:delete:not-mine', 'api/delete_cart/'.self::foreignCartItemId(), 'json', $guest, true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'DELETE', null, 'cart-err'),
            new DiffCase('cart:merge:401', 'api/cart/merge', 'json', [], true, 'cart-errors', DiffCase::ACCEPT_AXIOS, 'POST', ['guest_token' => 'nope'], 'cart-err'),
        ];
    }

    // ── address ──────────────────────────────────────────────────────────────

    /**
     * @param  array{id: int, cost: float}|null  $city
     * @return list<DiffCase>
     */
    private static function addressCases(?array $city): array
    {
        if ($city === null) {
            return [];
        }

        return [
            new DiffCase('address:invalid', 'api/add_address', 'json', [], true, 'address', DiffCase::ACCEPT_AXIOS, 'POST', [], 'address'),
            new DiffCase('address:bad-city', 'api/add_address', 'json', [], true, 'address', DiffCase::ACCEPT_AXIOS, 'POST', ['shipping_city_id' => 999999, 'address_line' => 'Harness Street 1', 'phone_number_one' => '01000000000'], 'address'),
            new DiffCase('address:short-line', 'api/add_address', 'json', [], true, 'address', DiffCase::ACCEPT_AXIOS, 'POST', ['shipping_city_id' => $city['id'], 'address_line' => 'x', 'phone_number_one' => '01000000000'], 'address'),
            new DiffCase('address:short-phone', 'api/add_address', 'json', [], true, 'address', DiffCase::ACCEPT_AXIOS, 'POST', ['shipping_city_id' => $city['id'], 'address_line' => 'Harness Street 1', 'phone_number_one' => '123'], 'address'),
        ];
    }

    // ── checkout ─────────────────────────────────────────────────────────────

    /**
     * @param  array{id: int, price: float, stock: int}|null  $product
     * @param  array{id: int, price: float}|null  $outOfStock
     * @param  array{id: int, cost: float}|null  $city
     * @return list<DiffCase>
     */
    private static function checkoutSequence(?array $product, ?array $outOfStock, ?array $city): array
    {
        if ($product === null || $city === null) {
            return [];
        }
        $guest = ['X-Guest-Token' => '{guest}'];
        $item = self::line($product['id'], 1, $product['price']);
        $total = round($product['price'] + $city['cost'], 2);
        $cases = [
            // No items at all: the address row is created and then rolled back with the 422.
            new DiffCase('checkout:empty-cart', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [], ['total_price_for_order' => 0]), 'checkout'),
            new DiffCase('checkout:no-address', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [$item], ['shipping_city_id' => null, 'address_line' => null, 'phone' => null]), 'checkout'),
            new DiffCase('checkout:bad-method', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [$item], ['payment_method' => 'bitcoin']), 'checkout'),
            // The client's total is authoritative for nothing: a wrong one is refused with the
            // server's own number, which both hosts must compute identically.
            new DiffCase('checkout:total-mismatch', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [$item], ['total_price_for_order' => $total + 100]), 'checkout'),
            new DiffCase('checkout:missing-item', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [self::line(999999, 1, 1.0)], []), 'checkout'),
        ];

        if ($outOfStock !== null) {
            $oosTotal = round($outOfStock['price'] + $city['cost'], 2);
            $cases[] = new DiffCase('checkout:insufficient-stock', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST',
                self::orderBody($city['id'], $oosTotal, [self::line($outOfStock['id'], 1, $outOfStock['price'])], []), 'checkout');
        }

        // The one that really places an order on both hosts and really moves both stock numbers.
        $cases[] = new DiffCase('checkout:add-to-cart', 'api/add_to_cart', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', $item, 'checkout-cod');
        $cases[] = new DiffCase('checkout:cod', 'api/add_order', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'POST', self::orderBody($city['id'], $total, [$item], []), 'checkout-cod');
        // …and the cart it was placed from is gone afterwards, on both.
        $cases[] = new DiffCase('checkout:cart-after', 'api/me/cart', 'json', $guest, true, 'checkout', DiffCase::ACCEPT_AXIOS, 'GET', null, 'checkout-cod', [], ['$.cart_item']);

        return $cases;
    }

    // ── account (read-only, one shared identity) ──────────────────────────────

    /**
     * @param  array{id: int, token: string}|null  $reader
     * @return list<DiffCase>
     */
    private static function accountCases(?array $reader): array
    {
        $cases = [
            new DiffCase('account:orders:401', 'api/me/orders', 'json', [], true, 'account'),
            new DiffCase('account:orders:bad-token', 'api/me/orders', 'json', ['Authorization' => 'Bearer not.a.token'], true, 'account'),
            new DiffCase('account:addresses:401', 'api/me/addresses', 'json', [], true, 'account'),
            new DiffCase('account:address:delete:401', 'api/me/addresses/1', 'json', [], true, 'account', DiffCase::ACCEPT_AXIOS, 'DELETE'),
        ];
        if ($reader === null) {
            return $cases;
        }
        $auth = ['Authorization' => 'Bearer '.$reader['token']];

        // Read-only and identical on both sides: same token, same user, same rows — so these
        // four compare byte for byte with nothing absorbed.
        $cases[] = new DiffCase('account:orders', 'api/me/orders', 'json', $auth, true, 'account');
        $cases[] = new DiffCase('account:orders:ar', 'api/me/orders', 'json', $auth + ['Accept-Language' => 'ar-EG,ar;q=0.9'], true, 'account');
        $cases[] = new DiffCase('account:addresses', 'api/me/addresses', 'json', $auth, true, 'account');
        $cases[] = new DiffCase('account:addresses:ar', 'api/me/addresses', 'json', $auth + ['Accept-Language' => 'ar-EG,ar;q=0.9'], true, 'account');
        // Ownership: an id that is not this user's is a 404, never someone else's row.
        $cases[] = new DiffCase('account:address:delete:404', 'api/me/addresses/999999', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'DELETE');
        $cases[] = new DiffCase('account:address:delete:not-mine', 'api/me/addresses/'.self::foreignAddressId($reader['id']), 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'DELETE');
        // cart/merge's two no-op branches need auth but write nothing.
        $cases[] = new DiffCase('cart:merge:no-guest-token', 'api/cart/merge', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'POST', []);
        $cases[] = new DiffCase('cart:merge:unknown-guest', 'api/cart/merge', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'POST', ['guest_token' => '00000000-0000-4000-a000-000000000000']);

        return $cases;
    }

    /**
     * One `items[]` line, in the shape the storefront posts.
     *
     * @return array<string, mixed>
     */
    private static function line(int $productId, int $quantity, float $price): array
    {
        return [
            'product_id' => $productId,
            'quantity' => $quantity,
            'piece_price' => $price,
            'total_price' => round($price * $quantity, 2),
            'type_stock' => 'Express',
        ];
    }

    /**
     * A complete `add_order` body, written as one literal so every key is visible in one place —
     * `$overrides` replaces individual fields for the error cases (a wrong total, an unknown
     * payment method, a missing address).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function orderBody(int $shippingCityId, float $total, array $items, array $overrides): array
    {
        return array_replace([
            'shipping_city_id' => $shippingCityId,
            'address_line' => 'Harness Street 1',
            'phone' => '01000000000',
            'total_price_for_order' => $total,
            'payment_method' => 'cash',
            'guest_name' => 'Harness Guest',
            'guest_email' => 'harness@example.com',
            'guest_phone' => '01000000000',
            'items' => $items,
        ], $overrides);
    }

    // ── fixtures picked out of the live data ─────────────────────────────────

    /**
     * A visible product with express stock to spare, and the price both hosts must compute.
     *
     * @return array{id: int, price: float, stock: int}|null
     */
    public static function stockedProduct(): ?array
    {
        $row = DB::table('catalog_products as cp')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', config()->integer('compat.storefront_id'));
            })
            ->whereNull('cp.deleted_at')->where('cp.is_active', 1)->where('sp.is_visible', 1)
            ->where('cp.stock_express', '>=', 5)
            ->orderBy('cp.id')
            ->first(['cp.id', 'cp.stock_express', 'sp.effective_price', 'sp.effective_sale_price']);
        if (! $row instanceof \stdClass) {
            return null;
        }

        return [
            'id' => Row::int($row, 'id'),
            'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
            'stock' => Row::int($row, 'stock_express'),
        ];
    }

    /**
     * A product whose EXPRESS bucket is empty, so the reservation must be refused.
     *
     * @return array{id: int, price: float}|null
     */
    public static function outOfStockProduct(): ?array
    {
        $row = DB::table('catalog_products as cp')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', config()->integer('compat.storefront_id'));
            })
            ->whereNull('cp.deleted_at')->where('cp.stock_express', '=', 0)
            ->orderBy('cp.id')
            ->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']);
        if (! $row instanceof \stdClass) {
            return null;
        }

        return [
            'id' => Row::int($row, 'id'),
            'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
        ];
    }

    /** @return array{id: int, cost: float}|null */
    public static function shippingCity(): ?array
    {
        $row = DB::connection('legacy')->table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']);
        if (! $row instanceof \stdClass) {
            return null;
        }

        return ['id' => Row::int($row, 'id'), 'cost' => round((float) Row::money($row, 'shipping_cost'), 2)];
    }

    /**
     * A real account with orders AND addresses, used ONLY for GETs. No harness case creates,
     * edits or deletes anything belonging to it — the two write cases aimed at it are a 404 on
     * an id it does not own and a 404 on an id that does not exist.
     *
     * @return array{id: int, token: string}|null
     */
    public static function readOnlyUser(): ?array
    {
        $id = DB::table('orders as o')
            ->join('addresses as a', 'a.user_id', '=', 'o.user_id')
            ->whereNotNull('o.user_id')
            ->groupBy('o.user_id')
            ->orderByRaw('COUNT(DISTINCT o.id) DESC, o.user_id')
            ->value('o.user_id');
        $id ??= DB::table('users')->orderBy('id')->value('id');
        if (! is_numeric($id)) {
            return null;
        }
        $token = HarnessJwt::mint((int) $id);

        return $token === null ? null : ['id' => (int) $id, 'token' => $token];
    }

    /** A cart line that belongs to somebody else, for the ownership case. */
    private static function foreignCartItemId(): int
    {
        $id = DB::table('cart_items')->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : 999998;
    }

    /** An address that is NOT the reader's, for the ownership case. */
    private static function foreignAddressId(int $userId): int
    {
        $id = DB::table('addresses')->where(function (Builder $q) use ($userId): void {
            $q->where('user_id', '!=', $userId)->orWhereNull('user_id');
        })->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : 999998;
    }
}
