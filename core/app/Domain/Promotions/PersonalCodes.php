<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * PERSONAL CODES (2026-11-24, the abandoned-cart e-mail and the e-mail campaigns): one code per e-mail, single use, valid
 * only for that customer, expiring after the days set. The wheel's shape, bound to an ADDRESS instead of a phone:
 *
 *  - The TERMS (percent, cap) name ONE shared promotion rule (issuer `personal`, made once per set of terms, never
 *    typed by a shopper: its own code is internal, like the wheel's). Each e-mail gets its own row in
 *    `core_personal_codes`: the code, the address (and the account of that address, if one exists), the expiry, the
 *    order that used it.
 *  - The ENGINE reaches the rule through the row (PromotionEngine: a typed code that is not a wheel code is looked up
 *    here), and asks {@see self::refusal()} first: used, expired, or the order's e-mail is another one.
 *  - ONE OFFER PER ORDER is the engine's own rule and applies unchanged: a cart holding an item at a live campaign price
 *    takes no code, this one included ("not valid on items already on offer", said in the e-mail).
 *  - The order marks the row used, in the order's transaction (PromotionDiscounts::record).
 */
final class PersonalCodes
{
    public const ISSUER = 'personal';

    public const TABLE = 'core_personal_codes';

    public const PURPOSES = ['cart_reminder', 'campaign'];

    /**
     * Issue one code. Returns the row's id, the code and its expiry.
     *
     * @return array{id: int, code: string, expires_at: string, rule_id: int}
     */
    public static function issue(int $storefrontId, string $email, string $purpose, ?int $sourceId, float $percent, ?float $cap, int $days): array
    {
        $email = mb_strtolower(trim($email));
        $ruleId = self::ruleFor($storefrontId, $percent, $cap);
        $userId = Coerce::nint(DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id'));
        $expires = now()->addDays(max(1, $days))->endOfDay();
        for ($try = 0; ; $try++) {
            $code = self::newCode($purpose);
            try {
                $id = (int) DB::table(self::TABLE)->insertGetId([
                    'storefront_id' => $storefrontId, 'code' => $code, 'promotion_rule_id' => $ruleId, 'email' => $email, 'user_id' => $userId,
                    'purpose' => in_array($purpose, self::PURPOSES, true) ? $purpose : 'campaign', 'source_id' => $sourceId,
                    'expires_at' => $expires, 'created_at' => now(),
                ]);

                return ['id' => $id, 'code' => $code, 'expires_at' => $expires->toDateTimeString(), 'rule_id' => $ruleId];
            } catch (QueryException $e) {
                if ($try >= 4 || ! in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                    throw $e;
                }
            }
        }
    }

    /** A code a shopper can read and type: `CART-7F3A-9C2E` / `GIFT-…`, never confusable letters. */
    public static function newCode(string $purpose): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $part = static function () use ($alphabet): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $s;
        };

        return ($purpose === 'cart_reminder' ? 'CART' : 'GIFT').'-'.$part().'-'.$part();
    }

    /** The row a typed code names on this shop, or null. */
    public static function byCode(int $storefrontId, string $code): ?stdClass
    {
        $raw = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('code', strtoupper(trim($code)))->first();

        return $raw === null ? null : Row::cast($raw);
    }

    /**
     * Why this code cannot be used on this cart now, or null. The address binding: the order's e-mail key (`e:`) or its
     * account (`u:`) must be the code's; a cart that names neither yet (a guest before the checkout form) is not refused
     * here - the order is checked again with the e-mail it is placed with.
     */
    public static function refusal(stdClass $row, CartSnapshot $cart, \DateTimeInterface $now): ?string
    {
        if (Row::nint($row, 'used_order_id') !== null) {
            return CouponStatus::WHEEL_USED;                    // "This code has already been used." - worded for any code
        }
        if (strtotime(Row::str($row, 'expires_at')) < $now->getTimestamp()) {
            return CouponStatus::EXPIRED;
        }
        $keys = $cart->customerKeys;
        $emails = array_values(array_filter($keys, fn (string $k): bool => str_starts_with($k, 'e:')));
        $users = array_values(array_filter($keys, fn (string $k): bool => str_starts_with($k, 'u:')));
        $mine = in_array('e:'.Row::str($row, 'email'), $keys, true)
            || (Row::nint($row, 'user_id') !== null && in_array('u:'.Row::int($row, 'user_id'), $keys, true));
        if (! $mine && ($emails !== [] || $users !== [])) {
            return CouponStatus::EMAIL_MISMATCH;
        }

        return null;
    }

    /** The order used this code (the order's transaction). A code that is not personal is left alone. */
    public static function markUsed(?string $code, int $orderId, int $storefrontId): void
    {
        if ($code === null || $code === '') {
            return;
        }
        DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('code', strtoupper($code))->whereNull('used_order_id')
            ->update(['used_order_id' => $orderId, 'used_at' => now()]);
    }

    /** The shared rule of one set of terms on a shop, made once. */
    public static function ruleFor(int $storefrontId, float $percent, ?float $cap): int
    {
        $cap = $cap !== null && $cap > 0 ? $cap : null;
        $storefrontCode = Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('code'));
        $code = strtoupper(self::ISSUER.'-'.preg_replace('/[^a-z0-9]+/i', '', $storefrontCode).'-'.substr(sha1(number_format($percent, 2, '.', '').'|'.($cap === null ? '' : number_format($cap, 2, '.', ''))), 0, 8));
        $existing = Coerce::nint(DB::table('promotion_rules')->where('code', $code)->value('id'));
        if ($existing !== null) {
            return $existing;
        }
        $pct = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $id = app(PromotionWriter::class)->save([
            'name' => "Personal code - {$pct}%".($cap === null ? '' : ' up to EGP '.number_format($cap, 0)),
            'code' => $code,
            'label_en' => "Your code: {$pct}% off",
            'label_ar' => "كودك: خصم {$pct}٪", // i18n-exempt: DATA - the rule's own Arabic label, stored on the row and shown to shoppers
            'priority' => 0,
            'is_active' => true,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            // the rule outlives every code it backs; each code's own expiry is the real window (refusal())
            'ends_at' => '2099-12-31 23:59:59',
            'apply_per' => PromotionMoney::PER_ORDER,
            'max_discount' => $cap,
            'min_order_amount' => null,
            'include_sale_items' => true,
            'stackable' => false,
            'unit_floor' => 0,
            'storefronts' => [$storefrontId],
            'conditions' => [],
            'rewards' => [['type' => 'percent_discount', 'amount' => $percent]],
            'scopes' => [],
        ], null, null);
        DB::table('promotion_rules')->where('id', $id)->update(['issuer' => self::ISSUER]);

        return $id;
    }
}
