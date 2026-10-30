<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

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

    /** @var list<string> */
    public const ALL = [self::APPLIED, self::INVALID, self::EXPIRED, self::NOT_STARTED, self::NO_ELIGIBLE_ITEMS,
        self::MINIMUM_NOT_REACHED, self::USAGE_LIMIT, self::CUSTOMER_LIMIT, self::NOT_BEST];

    /**
     * @param  float|null  $minimum  the minimum order, for `minimum_not_reached`
     * @return array{en: string, ar: string}
     */
    public static function messages(string $status, ?float $minimum = null): array
    {
        $egp = $minimum === null ? '' : number_format($minimum, 0, '.', ',');
        $egpAr = $minimum === null ? '' : self::arabicDigits(number_format($minimum, 0, '.', '٬'));

        return match ($status) {
            self::APPLIED => ['en' => 'Code applied.', 'ar' => 'تم تطبيق الكود.'],
            self::EXPIRED => ['en' => 'This code has expired.', 'ar' => 'انتهت صلاحية هذا الكود.'],
            self::NOT_STARTED => ['en' => 'This code is not active yet. Try again when the offer starts.', 'ar' => 'هذا الكود لم يبدأ بعد. جرّبه عند بداية العرض.'],
            self::NO_ELIGIBLE_ITEMS => ['en' => 'None of the items in your cart are included in this offer.', 'ar' => 'لا يوجد في سلتك منتج يشمله هذا العرض.'],
            self::MINIMUM_NOT_REACHED => [
                'en' => $egp === '' ? 'Your order has not reached the minimum for this code.' : "This code needs an order of at least EGP {$egp}.",
                'ar' => $egpAr === '' ? 'طلبك لم يصل إلى الحد الأدنى لهذا الكود.' : "هذا الكود يحتاج طلبًا لا يقل عن {$egpAr} ج.م.",
            ],
            self::USAGE_LIMIT => ['en' => 'This code has reached its usage limit.', 'ar' => 'وصل هذا الكود إلى الحد الأقصى لعدد مرات الاستخدام.'],
            self::CUSTOMER_LIMIT => ['en' => 'You have already used this code the maximum number of times.', 'ar' => 'استخدمت هذا الكود بالفعل أقصى عدد من المرات.'],
            self::NOT_BEST => ['en' => 'A better offer already applies to your cart, and offers do not combine.', 'ar' => 'يوجد عرض أفضل مطبّق على سلتك بالفعل، والعروض لا تتجمع.'],
            default => ['en' => 'This code is not valid. Check it and try again.', 'ar' => 'هذا الكود غير صحيح. راجعه وحاول مرة أخرى.'],
        };
    }

    private static function arabicDigits(string $value): string
    {
        return strtr($value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
