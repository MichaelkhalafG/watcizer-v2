<?php

declare(strict_types=1);

namespace App\Domain\Wheel;

use RuntimeException;

/**
 * A spin the wheel would not give, with the code the storefront words and the sentence in both languages.
 *
 * i18n-exempt-file: shopper-facing STOREFRONT text, both languages returned side by side for the page to pick —
 * never dashboard text (the operator's locale is not the shopper's).
 */
final class WheelRefused extends RuntimeException
{
    public const WHEEL_OFF = 'wheel_off';

    public const INVALID_PHONE = 'invalid_phone';

    public const DEVICE_LIMIT = 'device_limit';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('wheel refused: '.$reason);
    }

    /** @return array{en: string, ar: string} */
    public function messages(): array
    {
        return match ($this->reason) {
            self::WHEEL_OFF => ['en' => 'This offer has ended.', 'ar' => 'انتهى هذا العرض.'],
            self::INVALID_PHONE => ['en' => 'Enter an Egyptian mobile number (01xxxxxxxxx).', 'ar' => 'أدخل رقم موبايل مصري (01xxxxxxxxx).'],
            self::DEVICE_LIMIT => ['en' => 'This device has already spun the wheel this round. Enter the phone number you spun with to see your result.', 'ar' => 'هذا الجهاز أدار العجلة بالفعل في هذه الجولة. أدخل رقم الهاتف الذي أدرت به العجلة لترى نتيجتك.'],
            default => ['en' => 'The wheel could not be spun right now.', 'ar' => 'تعذّر إدارة العجلة الآن.'],
        };
    }

    public function status(): int
    {
        return $this->reason === self::WHEEL_OFF ? 410 : 422;
    }
}
