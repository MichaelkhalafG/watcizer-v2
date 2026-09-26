<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * WHO an order's customer is — name, e-mail, phone — answered in ONE place (2026-09-26).
 *
 * The dashboard's order list and detail read only the `guest_*` columns, so a REGISTERED buyer's
 * order showed "Name — · Phone — · Email —": the storefront sends `guest_*` only for guests, and a
 * signed-in shopper's details live on their account and the order's address. The order e-mails had
 * the right answer in a private method, the mailer and the Paymob billing each had their own. One
 * definition now, the one the e-mails have always used (it is the legacy app's):
 *
 *   • name   — the account's first + last name; else `guest_name`;
 *   • email  — the account's e-mail; else `guest_email`;
 *   • phone  — the order ADDRESS's first phone, then `guest_phone`, then the account's own phone,
 *              then the address's second phone. The address comes first because it is the number
 *              the shopper typed for THIS delivery. `phone_alt` is the next distinct one, so the
 *              team has a second number to try when there is one.
 *
 * A value that is blank everywhere is null, never a placeholder: the screen shows "—" and each
 * caller that needs a placeholder (the e-mail's "Guest", Paymob's required billing fields) adds its
 * own.
 */
final class OrderCustomer
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $phoneAlt,
    ) {}

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public static function of(int $orderId): ?self
    {
        return self::forOrders([$orderId])[$orderId] ?? null;
    }

    /**
     * One query for a whole page of orders.
     *
     * @param  list<int>  $orderIds
     * @return array<int, self> keyed by order id
     */
    public static function forOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('addresses as a', 'a.id', '=', 'o.address_id')
            ->whereIn('o.id', array_values(array_unique($orderIds)))
            ->get([
                'o.id', 'o.user_id', 'o.guest_name', 'o.guest_email', 'o.guest_phone',
                'u.first_name', 'u.last_name', 'u.email as user_email', 'u.phone_number as user_phone',
                'a.phone_number_one', 'a.phone_number_two',
            ]);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $userId = Row::nint($row, 'user_id');
            $account = $userId === null ? null
                : self::clean(trim((Row::nstr($row, 'first_name') ?? '').' '.(Row::nstr($row, 'last_name') ?? '')));

            $phones = [];
            foreach (['phone_number_one', 'guest_phone', 'user_phone', 'phone_number_two'] as $column) {
                $phone = self::clean(Row::nstr($row, $column));
                if ($phone !== null && ! in_array($phone, $phones, true)) {
                    $phones[] = $phone;
                }
            }

            $out[Row::int($row, 'id')] = new self(
                $userId,
                $account ?? self::clean(Row::nstr($row, 'guest_name')),
                self::clean(Row::nstr($row, 'user_email')) ?? self::clean(Row::nstr($row, 'guest_email')),
                $phones[0] ?? null,
                $phones[1] ?? null,
            );
        }

        return $out;
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
