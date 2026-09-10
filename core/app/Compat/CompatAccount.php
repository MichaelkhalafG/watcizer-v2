<?php

namespace App\Compat;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The four authenticated account paths (`me/orders`, `me/addresses`, `POST add_address`,
 * `DELETE me/addresses/{id}`).
 *
 * §3.3 calls these "move, trivial" and wave 2 left them proxied because they need the JWT
 * validation that arrives with the cart. They also cannot stay behind once the checkout moves:
 * `add_order` writes the `orders` and `addresses` rows that `me/orders` reads, and an order
 * placed on core must be visible on the account page in the same request cycle.
 *
 * The nesting reproduces the legacy eager loads exactly — `with(['order_item',
 * 'address.shippingCity.translations'])` serialises as `order_item`, then `address`, then
 * `shipping_city` inside it, then `translations` inside that, with Astrotomic appending the
 * translated `city_name` for the request locale (`to_array_always_loads_translations` is on in
 * both apps, and fallback is off in both).
 */
final class CompatAccount
{
    /**
     * `Order::where('user_id', $authId)->with([...])->orderByDesc('id')->get()`.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(int $userId, string $locale): array
    {
        $orders = DB::table('orders')->where('user_id', $userId)->orderByDesc('id')->get();
        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = [];
        $addressIds = [];
        foreach ($orders as $order) {
            $orderIds[] = Row::int($order, 'id');
            $addressIds[Row::int($order, 'address_id')] = true;
        }

        /** @var array<int, list<array<string, mixed>>> $itemsByOrder */
        $itemsByOrder = [];
        foreach (DB::table('order_items')->whereIn('order_id', $orderIds)->orderBy('id')->get() as $item) {
            $itemsByOrder[Row::int($item, 'order_id')][] = $this->orderItemRow($item);
        }

        $addresses = $this->addressRowsById(array_keys($addressIds), $locale);

        $out = [];
        foreach ($orders as $order) {
            $id = Row::int($order, 'id');
            $out[] = [
                'id' => $id,
                'user_id' => Row::nint($order, 'user_id'),
                'guest_name' => Row::nstr($order, 'guest_name'),
                'guest_email' => Row::nstr($order, 'guest_email'),
                'guest_token' => Row::nstr($order, 'guest_token'),
                'guest_phone' => Row::nstr($order, 'guest_phone'),
                'address_id' => Row::int($order, 'address_id'),
                'total_price_for_order' => Row::money($order, 'total_price_for_order'),
                'status' => Row::str($order, 'status'),
                'payment_method' => Row::str($order, 'payment_method'),
                'order_number' => Row::str($order, 'order_number'),
                'note' => Row::nstr($order, 'note'),
                'created_at' => LegacyJson::ts(Row::nstr($order, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($order, 'updated_at')),
                'order_item' => $itemsByOrder[$id] ?? [],
                'address' => $addresses[Row::int($order, 'address_id')] ?? null,
            ];
        }

        return $out;
    }

    /**
     * `Address::where('user_id', $authId)->with('shippingCity.translations')->get()`.
     *
     * @return list<array<string, mixed>>
     */
    public function addresses(int $userId, string $locale): array
    {
        $rows = DB::table('addresses')->where('user_id', $userId)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $cityIds = [];
        foreach ($rows as $row) {
            $cityIds[Row::int($row, 'shipping_city_id')] = true;
        }
        $cities = $this->shippingCitiesById(array_keys($cityIds), $locale);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->addressRow($row, $cities);
        }

        return $out;
    }

    /** `Address::create([...])` — returns the new id. */
    public function createAddress(?int $userId, ?string $guestToken, int $shippingCityId, string $addressLine, string $phoneOne, ?string $phoneTwo): int
    {
        $now = now();

        return (int) DB::table('addresses')->insertGetId([
            'user_id' => $userId,
            'guest_token' => $userId !== null ? null : $guestToken,
            'shipping_city_id' => $shippingCityId,
            'address_line' => $addressLine,
            'phone_number_one' => $phoneOne,
            'phone_number_two' => $phoneTwo,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** `Address::where('user_id', $authId)->findOrFail($id)->delete()` — false when it is not theirs. */
    public function deleteAddress(int $userId, int $addressId): bool
    {
        return DB::table('addresses')->where('user_id', $userId)->where('id', $addressId)->delete() > 0;
    }

    /**
     * @param  list<int>  $addressIds
     * @return array<int, array<string, mixed>>
     */
    private function addressRowsById(array $addressIds, string $locale): array
    {
        if ($addressIds === []) {
            return [];
        }
        $rows = DB::table('addresses')->whereIn('id', $addressIds)->orderBy('id')->get();
        $cityIds = [];
        foreach ($rows as $row) {
            $cityIds[Row::int($row, 'shipping_city_id')] = true;
        }
        $cities = $this->shippingCitiesById(array_keys($cityIds), $locale);

        $out = [];
        foreach ($rows as $row) {
            $out[Row::int($row, 'id')] = $this->addressRow($row, $cities);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cities
     * @return array<string, mixed>
     */
    private function addressRow(stdClass $row, array $cities): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'user_id' => Row::nint($row, 'user_id'),
            'guest_token' => Row::nstr($row, 'guest_token'),
            'shipping_city_id' => Row::int($row, 'shipping_city_id'),
            'address_line' => Row::str($row, 'address_line'),
            'phone_number_one' => Row::str($row, 'phone_number_one'),
            'phone_number_two' => Row::nstr($row, 'phone_number_two'),
            'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
            'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
            'shipping_city' => $cities[Row::int($row, 'shipping_city_id')] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function orderItemRow(stdClass $item): array
    {
        return [
            'id' => Row::int($item, 'id'),
            'order_id' => Row::int($item, 'order_id'),
            'product_id' => Row::nint($item, 'product_id'),
            // M1f added this column to the SHARED table, so the legacy `order_item` relation
            // serialises it here too, in this position.
            'variant_id' => Row::nint($item, 'variant_id'),
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

    /**
     * The `shipping_city` object exactly as `show_shipping_city` emits it: the row, then the
     * Astrotomic-appended `city_name` for the locale, then every translation row.
     *
     * @param  list<int>  $cityIds
     * @return array<int, array<string, mixed>>
     */
    private function shippingCitiesById(array $cityIds, string $locale): array
    {
        if ($cityIds === []) {
            return [];
        }
        /** @var array<int, list<array<string, mixed>>> $translations */
        $translations = [];
        $rows = DB::connection('legacy')->table('shipping_city_translations')
            ->whereIn('shipping_city_id', $cityIds)
            ->select(['id', 'locale', 'shipping_city_id', 'city_name'])
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            $translations[Row::int($row, 'shipping_city_id')][] = [
                'id' => Row::int($row, 'id'),
                'locale' => Row::str($row, 'locale'),
                'shipping_city_id' => Row::int($row, 'shipping_city_id'),
                'city_name' => Row::nstr($row, 'city_name'),
            ];
        }

        $out = [];
        foreach (DB::connection('legacy')->table('shipping_cities')->whereIn('id', $cityIds)->select(['id', 'shipping_cost', 'created_at', 'updated_at'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $name = null;
            foreach ($translations[$id] ?? [] as $t) {
                if ($t['locale'] === $locale) {
                    $name = $t['city_name'];
                }
            }
            $out[$id] = [
                'id' => $id,
                'shipping_cost' => Row::nstr($row, 'shipping_cost'),
                'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
                'city_name' => $name,
                'translations' => $translations[$id] ?? [],
            ];
        }

        return $out;
    }
}
