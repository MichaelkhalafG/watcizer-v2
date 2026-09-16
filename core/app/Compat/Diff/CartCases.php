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
        /*
         * ── the subject for `account:orders`, and why it works ───────────────────────────────
         *
         * `orders` and `order_items` are SHARED tables. Both hosts place their authenticated order
         * into the same table, and then both read the same rows back — so `me/orders` answers with
         * identical bytes on both sides even though the two orders have different ids and
         * `order_number`s (D-20). That is what makes an order-line comparison possible at all
         * without the harness seeding fixture data.
         *
         * This runs in `accountCases()` rather than `checkoutSequence()` so it is ordered BEFORE
         * the `account:orders` reads that need it, on the reader's own identity.
         */
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

        /*
         * An AUTHENTICATED COD checkout, on the reader's identity, before the reads below.
         *
         * Its own response differs between the hosts by `order_number` (D-20: MAX+1 over a shared
         * table, so the second host takes the next one) — already sanctioned, and the same rule
         * the guest `checkout:cod` case relies on.
         */
        $subject = self::authenticatedCheckout($reader, $auth);
        foreach ($subject as $case) {
            $cases[] = $case;
        }

        /*
         * Read-only and identical on both sides: same token, same user, and — because `orders` is
         * shared — the same ROWS, including the two orders the case above just placed. So these
         * compare byte for byte with nothing absorbed.
         *
         * `mustNotBeEmpty`: these two are the ONLY cases that compare an order line's shape, and
         * for four waves they compared two empty arrays and called it a match. If the subject ever
         * disappears again the run fails instead of passing quietly.
         */
        $cases[] = new DiffCase('account:orders', 'api/me/orders', 'json', $auth, true, 'account',
            DiffCase::ACCEPT_AXIOS, 'GET', null, '', [], ['$', '$[*].order_item'], mustNotBeEmpty: true);
        $cases[] = new DiffCase('account:orders:ar', 'api/me/orders', 'json', $auth + ['Accept-Language' => 'ar-EG,ar;q=0.9'], true, 'account',
            DiffCase::ACCEPT_AXIOS, 'GET', null, '', [], ['$', '$[*].order_item'], mustNotBeEmpty: true);
        // `me/addresses` was vacuous for the same reason and is now a real comparison: the
        // checkout above gave the reader an address, and both hosts read it from the shared table.
        $cases[] = new DiffCase('account:addresses', 'api/me/addresses', 'json', $auth, true, 'account',
            DiffCase::ACCEPT_AXIOS, 'GET', null, '', [], [], mustNotBeEmpty: true);
        $cases[] = new DiffCase('account:addresses:ar', 'api/me/addresses', 'json', $auth + ['Accept-Language' => 'ar-EG,ar;q=0.9'], true, 'account',
            DiffCase::ACCEPT_AXIOS, 'GET', null, '', [], [], mustNotBeEmpty: true);
        // Ownership: an id that is not this user's is a 404, never someone else's row.
        $cases[] = new DiffCase('account:address:delete:404', 'api/me/addresses/999999', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'DELETE');
        $cases[] = new DiffCase('account:address:delete:not-mine', 'api/me/addresses/'.self::foreignAddressId($reader['id']), 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'DELETE');
        // cart/merge's two no-op branches need auth but write nothing.
        $cases[] = new DiffCase('cart:merge:no-guest-token', 'api/cart/merge', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'POST', []);
        $cases[] = new DiffCase('cart:merge:unknown-guest', 'api/cart/merge', 'json', $auth, true, 'account', DiffCase::ACCEPT_AXIOS, 'POST', ['guest_token' => '00000000-0000-4000-a000-000000000000']);

        return $cases;
    }

    /**
     * The authenticated COD checkout that gives `account:orders` something to compare.
     *
     * Placed on the reader's own identity with the reader's own address, so nothing about it
     * belongs to a guest token and the order really is theirs. It writes — that is the point —
     * which makes it litter of exactly the kind rehearsal rule 11.0 records: two orders per run,
     * one per host, and a rebuild afterwards.
     *
     * @param  array{id: int, token: string}  $reader
     * @param  array<string, string>  $auth
     * @return list<DiffCase>
     */
    private static function authenticatedCheckout(array $reader, array $auth): array
    {
        $product = self::stockedProduct();
        $city = self::shippingCity();
        if ($product === null || $city === null) {
            return [];
        }

        $item = self::line($product['id'], 1, $product['price']);

        /*
         * ── reuse an address if the reader has one, create it if not ─────────────────────────
         *
         * First run on a database where no user owns an address: send the three address FIELDS, so
         * `add_order` creates one and stamps `user_id` on it. That is the only way to bring the
         * subject into existence through the endpoints under test rather than by seeding rows
         * behind their back.
         *
         * Every run after that: send `address_id` instead, so the reader's account does not grow
         * one address per run forever (rehearsal rule 11.0 is about exactly this kind of drip).
         *
         * The shipping cost — and therefore the total the server will demand — comes from
         * whichever city the chosen address carries, which is why it is read rather than assumed.
         */
        $addressId = DB::table('addresses')->where('user_id', $reader['id'])->orderBy('id')->value('id');

        if (is_numeric($addressId)) {
            $cost = DB::table('addresses as a')
                ->join('shipping_cities as c', 'c.id', '=', 'a.shipping_city_id')
                ->where('a.id', (int) $addressId)
                ->value('c.shipping_cost');
            $body = [
                'user_id' => $reader['id'],
                'address_id' => (int) $addressId,
                'total_price_for_order' => round($product['price'] + (is_numeric($cost) ? (float) $cost : $city['cost']), 2),
                'payment_method' => 'cash',
                'items' => [$item],
            ];
        } else {
            $body = [
                'user_id' => $reader['id'],
                'shipping_city_id' => $city['id'],
                'address_line' => 'Harness Account Street 1',
                'phone' => '01000000000',
                'total_price_for_order' => round($product['price'] + $city['cost'], 2),
                'payment_method' => 'cash',
                'items' => [$item],
            ];
        }

        return [
            new DiffCase('checkout:cod:auth', 'api/add_order', 'json', $auth, true, 'account',
                DiffCase::ACCEPT_AXIOS, 'POST', $body),
        ];
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
     * The account a REMOTE run must use, named by the operator (`--subject-user`).
     *
     * A static because a `compat:diff` run is one process doing one thing, and threading an option
     * through `all()` → `accountCases()` → here would put the value in three signatures that exist
     * for nothing else. Null is the local case: pick a subject from the database, which is safe
     * precisely because the database is a copy.
     */
    private static ?int $subjectUserId = null;

    public static function useSubject(int $userId): void
    {
        self::$subjectUserId = $userId;
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
        if (self::$subjectUserId !== null) {
            /*
             * An operator-named account is used AS GIVEN — not searched for, not fallen back from.
             * If it does not exist the run must stop rather than quietly choose somebody else,
             * because "quietly chose somebody else" on a production host means this harness
             * attached its orders and addresses to a real customer.
             */
            $exists = DB::table('users')->where('id', self::$subjectUserId)->exists();
            if (! $exists) {
                throw new \RuntimeException(
                    'The --subject-user account '.self::$subjectUserId.' does not exist on this database. '
                    .'Name an account that does; the harness will not pick one for a remote run.'
                );
            }

            $token = HarnessJwt::mint(self::$subjectUserId);

            return $token === null ? null : ['id' => self::$subjectUserId, 'token' => $token];
        }

        /*
         * ── the silent fallback that made a case pass for four waves ─────────────────────────
         *
         * This used to prefer a user with orders AND addresses and then fall back to
         * `users.orderBy(id)->value('id')` — user 1. In THIS database every one of the 45 orders
         * is a GUEST order (`user_id IS NULL`), so the preferred search found nobody, the fallback
         * answered user 1, and `account:orders` compared `[]` with `[]` and reported IDENTICAL.
         *
         * Nothing was wrong with the comparison. The case simply had no subject, and the fallback
         * hid that — which means the order-line SHAPE has never been byte-compared by this harness:
         * not for M1f's `variant_id`, not for M1l's `promotion_rule_id` / `is_reward`.
         *
         * Measured on 2026-09-13: 4 users, 47 addresses, and **not one address owned by a user**.
         * Every address and every order in this database belongs to a guest token. So the subject
         * cannot be FOUND — it has to be CREATED, and `checkout:cod:auth` below does exactly that:
         * an authenticated `add_order` carrying address FIELDS makes
         * `CompatAccount::createAddress()` stamp `user_id`, which gives `me/addresses` an address
         * and `me/orders` an order with a line, on both hosts, in the same shared tables.
         *
         * An address is therefore an OUTPUT of the run, not a precondition for it. What this
         * method needs is only a user to be; the ordering still PREFERS one who already has
         * orders or addresses, so repeat runs settle on the same subject instead of spreading
         * fixture rows across accounts.
         */
        $id = DB::table('users as u')
            ->leftJoin('orders as o', 'o.user_id', '=', 'u.id')
            ->leftJoin('addresses as a', 'a.user_id', '=', 'u.id')
            ->groupBy('u.id')
            ->orderByRaw('COUNT(DISTINCT o.id) DESC, COUNT(DISTINCT a.id) DESC, u.id')
            ->value('u.id');

        if (! is_numeric($id)) {
            return null;
        }
        $token = HarnessJwt::mint((int) $id);

        return $token === null ? null : ['id' => (int) $id, 'token' => $token];
    }

    /** Why the account cases are missing, for the run's own output. Null when they are not. */
    public static function readerRefusal(): ?string
    {
        if (self::readOnlyUser() !== null) {
            return null;
        }

        return 'no users in this database, so no authenticated case can run and nothing on the '
            .'account surface can be compared';
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
