<?php

declare(strict_types=1);

namespace App\Domain\Wheel;

use App\Domain\Notifications\StorefrontMailer;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Mail\WheelReferralReward;
use App\Storefront\StorefrontIdentity;
use App\Support\Coerce;
use App\Transform\Row;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * "INVITE A FRIEND" (the client's prize, 2026-11-09): the referral side of the wheel.
 *
 * The winner of a referral prize holds an INVITE code (`FRIEND-…`, {@see WheelPrizes::INVITE_DAYS}). A friend types
 * it at checkout: the engine reaches the prize's `referral_friend` rule through the spin ({@see Wheel::spinByCode()})
 * and THIS class adds the invite's gates — the invite's own expiry; not the winner's own number; a number that has
 * never ordered before (no order of this storefront that is not cancelled, by the order's phone, its address's or its
 * account's); not a friend who already used this invite. The friend's order writes a referral row (`placed`). When
 * the team marks that order DELIVERED ({@see self::onOrderStatus()}), the row becomes `delivered` and the winner gets
 * a reward: a spin row of their own (campaign `referral-<referral id>`, the winner's phone, the prize's percentage,
 * {@see Wheel::REWARD_TTL_HOURS}) and an e-mail if the shop knows one. A cancelled order earns nothing, ever: the row
 * is `cancelled` and no reward is issued.
 *
 * One reward per friend: the referral row is unique per spin and friend phone.
 */
final class WheelReferrals
{
    public const TABLE = 'storefront_wheel_referrals';

    public const PLACED = 'placed';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /** Why an INVITE must not apply to this cart — null when it may (the cart page without a phone passes). */
    public static function refusal(stdClass $spin, CartSnapshot $cart, DateTimeInterface $now): ?string
    {
        // dormant until the winner signed in to activate it (Michael, 2026-11-09 evening)
        if (Row::nstr($spin, 'invite_activated_at') === null) {
            return CouponStatus::INVITE_INACTIVE;
        }
        $stamp = Carbon::instance($now)->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s');
        if ((Row::nstr($spin, 'invite_expires_at') ?? '') <= $stamp) {
            return CouponStatus::EXPIRED;
        }
        $phones = Wheel::cartPhones($cart);
        if ($phones === []) {
            return null;
        }
        $key = substr($phones[0], 2);
        if ($key === Row::str($spin, 'phone_key')) {
            return CouponStatus::INVITE_OWN;
        }
        $existing = DB::table(self::TABLE)->where('spin_id', Row::int($spin, 'id'))->where('friend_phone_key', $key)
            ->where('status', '!=', self::CANCELLED)->exists();
        if ($existing) {
            return CouponStatus::INVITE_USED;
        }
        if (self::hasOrdered(Row::int($spin, 'storefront_id'), $key)) {
            return CouponStatus::INVITE_NOT_FIRST;
        }

        return null;
    }

    /**
     * Has this phone ever placed an order on the storefront that stands (not cancelled)? Read by the order's own phone,
     * its address's and its account's — a shopper is the same person under all three.
     */
    public static function hasOrdered(int $storefrontId, string $phoneKey): bool
    {
        $like = '%'.$phoneKey;

        return DB::table('orders as o')
            ->leftJoin('addresses as a', 'a.id', '=', 'o.address_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.storefront_id', $storefrontId)
            ->where('o.status', '!=', 'cancelled')
            ->where(function (Builder $q) use ($like): void {
                $q->where('o.guest_phone', 'like', $like)
                    ->orWhere('a.phone_number_one', 'like', $like)
                    ->orWhere('u.phone_number', 'like', $like);
            })
            ->exists();
    }

    /** The friend's order was placed with the invite: the referral row (`placed`). Called where the redemption is written. */
    public static function recordPlaced(string $inviteCode, int $orderId): void
    {
        $spin = DB::table(Wheel::TABLE)->where('invite_code', $inviteCode)->first();
        $order = DB::table('orders as o')->leftJoin('addresses as a', 'a.id', '=', 'o.address_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.id', $orderId)->first(['o.storefront_id', 'o.guest_phone', 'a.phone_number_one', 'u.phone_number']);
        if (! $spin instanceof stdClass || ! $order instanceof stdClass) {
            return;
        }
        $spin = Row::cast($spin);
        $order = Row::cast($order);
        $phone = Wheel::normalizePhone(Row::nstr($order, 'guest_phone') ?? Row::nstr($order, 'phone_number_one') ?? Row::nstr($order, 'phone_number'));
        if ($phone === null) {
            return;
        }
        try {
            DB::table(self::TABLE)->insert([
                'storefront_id' => Row::int($spin, 'storefront_id'),
                'spin_id' => Row::int($spin, 'id'),
                'friend_phone' => $phone,
                'friend_phone_key' => Wheel::phoneKey($phone),
                'order_id' => $orderId,
                'status' => self::PLACED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // the same friend on the same invite: one row, the first order's (a cancelled one is re-used below)
            DB::table(self::TABLE)->where('spin_id', Row::int($spin, 'id'))->where('friend_phone_key', Wheel::phoneKey($phone))
                ->where('status', self::CANCELLED)->update(['order_id' => $orderId, 'status' => self::PLACED, 'updated_at' => now()]);
        }
    }

    /**
     * An order's status changed (OrderFulfilment): DELIVERED earns the winner their reward; CANCELLED earns nothing.
     * Never throws — a reward that cannot be issued is logged, the status change stands.
     */
    public static function onOrderStatus(int $orderId, string $status): void
    {
        if ($status !== self::DELIVERED && $status !== self::CANCELLED) {
            return;
        }
        foreach (DB::table(self::TABLE)->where('order_id', $orderId)->where('status', self::PLACED)->get() as $raw) {
            $referral = Row::cast($raw);
            if ($status === self::CANCELLED) {
                DB::table(self::TABLE)->where('id', Row::int($referral, 'id'))->update(['status' => self::CANCELLED, 'updated_at' => now()]);

                continue;
            }
            try {
                $rewardId = self::issueReward($referral);
                DB::table(self::TABLE)->where('id', Row::int($referral, 'id'))->update([
                    'status' => self::DELIVERED, 'reward_spin_id' => $rewardId, 'delivered_at' => now(), 'updated_at' => now(),
                ]);
                self::notify($referral, $rewardId);
            } catch (\Throwable $e) {
                Log::error('wheel: a referral reward could not be issued', ['referral' => Row::int($referral, 'id'), 'error' => $e->getMessage()]);
            }
        }
    }

    /** The winner's reward: a spin row of their own with the prize's percentage, 48 hours, their phone. */
    private static function issueReward(stdClass $referral): int
    {
        $spin = Row::cast(DB::table(Wheel::TABLE)->where('id', Row::int($referral, 'spin_id'))->first() ?? new stdClass);
        $storefrontId = Row::int($spin, 'storefront_id');
        $value = Row::nfloat($spin, 'prize_value') ?? 15.0;
        $cap = Row::nfloat($spin, 'prize_cap');
        $labelEn = 'Invite reward: '.rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'% off';
        $labelAr = 'مكافأة الدعوة: خصم '.rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'٪'; // i18n-exempt: DATA — the reward's own Arabic label, stored on the row
        $terms = [
            'type' => 'referral_reward', 'value' => $value, 'cap' => $cap, 'min_order' => null, 'label_en' => $labelEn, 'label_ar' => $labelAr,
            'scope_category_id' => null, 'min_quantity' => null, 'apply_mode' => WheelPrizes::MODE_ORDER, 'gift_product_id' => null,
        ];
        $ruleId = Wheel::ruleFor($storefrontId, $terms, 'referral_reward');
        $now = now();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return (int) DB::table(Wheel::TABLE)->insertGetId([
                    'storefront_id' => $storefrontId,
                    'campaign' => 'referral-'.Row::int($referral, 'id'),
                    'phone' => Row::str($spin, 'phone'),
                    'phone_key' => Row::str($spin, 'phone_key'),
                    'code' => Wheel::newCode(),
                    'prize_id' => Row::nint($spin, 'prize_id'),
                    'prize_label_en' => $labelEn,
                    'prize_label_ar' => $labelAr,
                    'prize_type' => 'percent_discount',
                    'prize_value' => $value,
                    'prize_cap' => $cap,
                    'min_order' => null,
                    'prize_apply_mode' => WheelPrizes::MODE_ORDER,
                    'color' => Row::str($spin, 'color'),
                    'promotion_rule_id' => $ruleId,
                    'expires_at' => $now->copy()->addHours(Wheel::REWARD_TTL_HOURS)->format('Y-m-d H:i:s'),
                    'source_referral_id' => Row::int($referral, 'id'),
                    'user_id' => Row::nint($spin, 'user_id'),                   // the winner's account, so the account page lists the reward
                    'locale' => Row::str($spin, 'locale'),
                    'created_at' => $now,
                ]);
            } catch (QueryException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('no code');
    }

    /** The winner is told by e-mail when the shop knows one for their number (an account, else a past order's). */
    private static function notify(stdClass $referral, int $rewardId): void
    {
        $reward = Row::cast(DB::table(Wheel::TABLE)->where('id', $rewardId)->first() ?? new stdClass);
        $key = Row::str($reward, 'phone_key');
        $storefrontId = Row::int($reward, 'storefront_id');
        // the ACCOUNT that activated the invite first (Michael, 2026-11-09 evening); then the older lookups by number
        $email = self::accountEmail(Row::int($referral, 'spin_id'))
            ?? Coerce::nstr(DB::table('users')->where('phone_number', 'like', '%'.$key)->whereNotNull('email')->orderByDesc('id')->value('email'))
            ?? Coerce::nstr(DB::table('orders')->where('storefront_id', $storefrontId)->where('guest_phone', 'like', '%'.$key)->whereNotNull('guest_email')->orderByDesc('id')->value('guest_email'));
        if ($email === null || trim($email) === '') {
            return;
        }
        try {
            StorefrontMailer::send($storefrontId, trim($email), new WheelReferralReward(
                code: Row::str($reward, 'code'),
                percent: Row::nfloat($reward, 'prize_value') ?? 15.0,
                expiresAt: Carbon::parse(Row::str($reward, 'expires_at')),
                lang: Row::str($reward, 'locale'),
                identity: StorefrontIdentity::for($storefrontId),
            ));
        } catch (\Throwable $e) {
            Log::warning('wheel: the referral reward mail was not sent', ['referral' => Row::int($referral, 'id'), 'error' => $e->getMessage()]);
        }
    }

    /** The e-mail of the account that activated an invite spin, or null. */
    public static function accountEmail(int $spinId): ?string
    {
        $userId = Coerce::nint(DB::table(Wheel::TABLE)->where('id', $spinId)->value('user_id'));
        if ($userId === null) {
            return null;
        }
        $email = Coerce::nstr(DB::table('users')->where('id', $userId)->value('email'));

        return $email === null || trim($email) === '' ? null : trim($email);
    }

    /** `m•••••@example.com` — the account's e-mail as the card names it. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '•••••';
        }

        return mb_substr($email, 0, 1).'•••••'.substr($email, $at);
    }

    /**
     * What the winner's card shows of their invite: whether it is ACTIVE (the winner signed in), the code — only once
     * active — its expiry, the account's masked e-mail, how many friends used it (placed, delivered) and the reward codes
     * earned.
     *
     * @return array{active: bool, code: string|null, expires_at: string|null, email_hint: string|null, friends: array{placed: int, delivered: int}, rewards: list<array{code: string, expires_at: string, state: string}>}
     */
    public static function summary(stdClass $spin, ?DateTimeInterface $at = null): array
    {
        $spinId = Row::int($spin, 'id');
        $active = Row::nstr($spin, 'invite_activated_at') !== null;
        $email = $active ? self::accountEmail($spinId) : null;
        $placed = 0;
        $delivered = 0;
        $rewardIds = [];
        foreach (DB::table(self::TABLE)->where('spin_id', $spinId)->get(['status', 'reward_spin_id']) as $raw) {
            $row = Row::cast($raw);
            $status = Row::str($row, 'status');
            if ($status === self::PLACED) {
                $placed++;
            } elseif ($status === self::DELIVERED) {
                $delivered++;
                $rewardId = Row::nint($row, 'reward_spin_id');
                if ($rewardId !== null) {
                    $rewardIds[] = $rewardId;
                }
            }
        }
        $rewards = [];
        foreach ($rewardIds === [] ? [] : DB::table(Wheel::TABLE)->whereIn('id', $rewardIds)->orderBy('id')->get() as $raw) {
            $reward = Row::cast($raw);
            $used = Wheel::usedBy($reward);
            $rewards[] = [
                'code' => Row::str($reward, 'code'),
                'expires_at' => Carbon::parse(Row::str($reward, 'expires_at'))->toIso8601String(),
                'state' => $used !== null ? Wheel::STATE_USED : (Wheel::isExpired($reward, $at) ? Wheel::STATE_EXPIRED : Wheel::STATE_ACTIVE),
            ];
        }
        $inviteExpires = Row::nstr($spin, 'invite_expires_at');

        return [
            'active' => $active,
            'code' => $active ? Row::nstr($spin, 'invite_code') : null,
            'expires_at' => $inviteExpires === null ? null : Carbon::parse($inviteExpires)->toIso8601String(),
            'email_hint' => $email === null ? null : self::maskEmail($email),
            'friends' => ['placed' => $placed, 'delivered' => $delivered],
            'rewards' => $rewards,
        ];
    }
}
