<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use Illuminate\Support\Carbon;

/**
 * What happened to the code a shopper typed (M1u, 2026-10-30), and the sentence the storefront shows for it.
 *
 * Both languages come back, as the payment-method refusal does: the request's Accept-Language is the browser's, not
 * the language the page is shown in, so the page picks. Written for the shopper — what is wrong and what to do — in
 * the storefront's voice; never a developer's word.
 *
 * i18n-exempt-file: shopper-facing STOREFRONT text, both languages returned side by side for the page to pick — not
 * dashboard text, so it never goes through ManageText (whose locale is the operator's, not the shopper's).
 */
final class CouponStatus
{
    public const APPLIED = 'applied';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const NOT_STARTED = 'not_started';

    public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

    public const MINIMUM_NOT_REACHED = 'minimum_not_reached';

    public const USAGE_LIMIT = 'usage_limit';

    public const CUSTOMER_LIMIT = 'customer_limit';

    /** The code is valid, but a better offer on the cart wins and the two do not combine. */
    public const NOT_BEST = 'not_best';

    /** A wheel code (App\Domain\Wheel) typed with an order phone other than the one it was won with. */
    public const PHONE_MISMATCH = 'phone_mismatch';

    /** A wheel code that an order (not cancelled) has already used. */
    public const WHEEL_USED = 'wheel_used';

    /** A wheel INVITE typed by the number that won it (the wheel's invite-a-friend prize, 2026-11-09). */
    public const INVITE_OWN = 'invite_own';

    /** A wheel INVITE typed by a number that has ordered before — it is for a first order. */
    public const INVITE_NOT_FIRST = 'invite_not_first';

    /** A wheel INVITE this friend has already used. */
    public const INVITE_USED = 'invite_used';

    /** An invite whose winner has not signed in to activate it yet (2026-11-09 evening). */
    public const INVITE_INACTIVE = 'invite_inactive';

    /** A wheel GIFT code whose product is out of stock right now. */
    public const GIFT_OUT_OF_STOCK = 'gift_out_of_stock';

    /** The cart holds an item at a live campaign price (Hot Deals / Flash Sale): one offer per order (2026-11-10). */
    public const ON_OFFER = 'on_offer';

    /** @var list<string> */
    public const ALL = [self::APPLIED, self::INVALID, self::EXPIRED, self::NOT_STARTED, self::NO_ELIGIBLE_ITEMS,
        self::MINIMUM_NOT_REACHED, self::USAGE_LIMIT, self::CUSTOMER_LIMIT, self::NOT_BEST, self::PHONE_MISMATCH, self::WHEEL_USED,
        self::INVITE_OWN, self::INVITE_NOT_FIRST, self::INVITE_USED, self::INVITE_INACTIVE, self::GIFT_OUT_OF_STOCK, self::ON_OFFER];

    /**
     * @param  float|null  $minimum  the minimum order, for `minimum_not_reached`
     * @param  array{kind?: string|null, phone_hint?: string|null, order_phone_hint?: string|null, order_number?: string|null, other_discount?: float|null, expires_at?: string|null, needs_en?: string|null, needs_ar?: string|null}  $wheel  the wheel code's facts, for the wheel statuses (`other_discount` and `expires_at` for `not_best`; `needs_*` for a scoped code with nothing eligible)
     * @return array{en: string, ar: string}
     */
    public static function messages(string $status, ?float $minimum = null, array $wheel = []): array
    {
        $egp = $minimum === null ? '' : number_format($minimum, 0, '.', ',');
        $egpAr = $minimum === null ? '' : self::arabicDigits(number_format($minimum, 0, '.', '٬'));
        $won = $wheel['phone_hint'] ?? null;
        $typed = $wheel['order_phone_hint'] ?? null;
        $order = $wheel['order_number'] ?? null;
        // a wheel code that lost to a bigger automatic offer: the amount that won and the day the code is good until
        $otherAmount = $wheel['other_discount'] ?? null;
        $other = $otherAmount === null ? null : number_format($otherAmount, 0, '.', ',');
        $otherAr = $otherAmount === null ? null : self::arabicDigits(number_format($otherAmount, 0, '.', '٬'));
        $expires = $wheel['expires_at'] ?? null;
        $until = $expires === null ? null : Carbon::parse($expires)->timezone('Africa/Cairo');
        $untilAr = $until === null ? null : $until->copy();
        $untilAr?->locale('ar');
        $needsEn = $wheel['needs_en'] ?? null;
        $needsAr = $wheel['needs_ar'] ?? null;

        return match ($status) {
            self::PHONE_MISMATCH => $typed !== null && $won !== null ? [
                'en' => "The phone number in your order ({$typed}) is not the one this code was won with ({$won}). Use the same number in your order details, or remove the code.",
                'ar' => "رقم الهاتف في طلبك ({$typed}) ليس الرقم الذي رُبح به هذا الكود ({$won}). استخدم الرقم نفسه في بيانات طلبك، أو أزل الكود.",
            ] : [
                'en' => $won === null ? 'This code works only with the phone number it was won with. Use the same number in your order details.' : "This code works only with the phone number it was won with ({$won}). Use the same number in your order details.",
                'ar' => $won === null ? 'هذا الكود يعمل فقط مع رقم الهاتف الذي رُبح به. استخدم الرقم نفسه في بيانات طلبك.' : "هذا الكود يعمل فقط مع رقم الهاتف الذي رُبح به ({$won}). استخدم الرقم نفسه في بيانات طلبك.",
            ],
            self::WHEEL_USED => [
                'en' => $order === null ? 'This code has already been used.' : "This code has already been used on order {$order}.",
                'ar' => $order === null ? 'استُخدم هذا الكود بالفعل.' : "استُخدم هذا الكود بالفعل في الطلب {$order}.",
            ],
            self::APPLIED => ['en' => 'Code applied.', 'ar' => 'تم تطبيق الكود.'],
            // Michael's words (2026-11-10)
            // the Latin names isolated (LRI-PDI) and unbroken (no-break spaces): the brackets sit right on an Arabic page
            self::ON_OFFER => ['en' => 'Discount codes can\'t be combined with items already on offer (Hot Deals / Flash Sale).', 'ar' => "لا يمكن استخدام كود الخصم مع منتجات عليها عرض (\u{2066}Hot\u{A0}Deals / Flash\u{A0}Sale\u{2069})."],
            self::EXPIRED => ['en' => 'This code has expired.', 'ar' => 'انتهت صلاحية هذا الكود.'],
            self::NOT_STARTED => ['en' => 'This code is not active yet. Try again when the offer starts.', 'ar' => 'هذا الكود لم يبدأ بعد. جرّبه عند بداية العرض.'],
            self::NO_ELIGIBLE_ITEMS => $needsEn !== null && $needsAr !== null
                ? ['en' => $needsEn, 'ar' => $needsAr]
                : ['en' => 'None of the items in your cart are included in this offer.', 'ar' => 'لا يوجد في سلتك منتج يشمله هذا العرض.'],
            self::INVITE_OWN => ['en' => 'This invite is yours to share — it works for a friend\'s first order, not your own.', 'ar' => 'هذه الدعوة لك لتشاركها — تعمل على أول طلب لصديقك، لا على طلبك أنت.'],
            self::INVITE_NOT_FIRST => ['en' => 'This invite is for a first order, and this phone number has ordered before.', 'ar' => 'هذه الدعوة لأول طلب فقط، وهذا الرقم طلب من قبل.'],
            self::INVITE_USED => ['en' => 'This invite has already been used with this phone number.', 'ar' => 'هذه الدعوة استُخدمت بالفعل مع هذا الرقم.'],
            self::INVITE_INACTIVE => ['en' => 'This invite is not active yet — its owner has to sign in on the wheel\'s page to activate it.', 'ar' => 'هذه الدعوة غير مفعّلة بعد — على صاحبها تسجيل الدخول في صفحة العجلة لتفعيلها.'],
            self::GIFT_OUT_OF_STOCK => $until !== null && $untilAr !== null ? [
                'en' => "The gift is out of stock right now, so the code could not be applied. It stays valid until {$until->format('j M, H:i')} — try again later or write to us.",
                'ar' => "الهدية غير متوفرة حاليًا، لذلك لم يُطبَّق الكود. يبقى صالحًا حتى {$untilAr->translatedFormat('j F، H:i')} — حاول لاحقًا أو راسلنا.",
            ] : ['en' => 'The gift is out of stock right now, so the code could not be applied.', 'ar' => 'الهدية غير متوفرة حاليًا، لذلك لم يُطبَّق الكود.'],
            self::MINIMUM_NOT_REACHED => [
                'en' => $egp === '' ? 'Your order has not reached the minimum for this code.' : "This code needs an order of at least EGP {$egp}.",
                'ar' => $egpAr === '' ? 'طلبك لم يصل إلى الحد الأدنى لهذا الكود.' : "هذا الكود يحتاج طلبًا لا يقل عن {$egpAr} ج.م.",
            ],
            self::USAGE_LIMIT => ['en' => 'This code has reached its usage limit.', 'ar' => 'وصل هذا الكود إلى الحد الأقصى لعدد مرات الاستخدام.'],
            self::CUSTOMER_LIMIT => ['en' => 'You have already used this code the maximum number of times.', 'ar' => 'استخدمت هذا الكود بالفعل أقصى عدد من المرات.'],
            self::NOT_BEST => $other !== null && $until !== null && $untilAr !== null ? [
                'en' => "Your order already has a bigger discount (EGP {$other}), so the wheel code is not applied. Keep it for another order — it is valid until {$until->format('j M, H:i')}.",
                'ar' => "طلبك عليه خصم أكبر بالفعل ({$otherAr} ج.م)، لذلك لم يُطبَّق كود العجلة. احتفظ به لطلب آخر — صالح حتى {$untilAr->translatedFormat('j F، H:i')}.",
            ] : ['en' => 'A better offer already applies to your cart, and offers do not combine.', 'ar' => 'يوجد عرض أفضل مطبّق على سلتك بالفعل، والعروض لا تتجمع.'],
            default => ['en' => 'This code is not valid. Check it and try again.', 'ar' => 'هذا الكود غير صحيح. راجعه وحاول مرة أخرى.'],
        };
    }

    private static function arabicDigits(string $value): string
    {
        return strtr($value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
