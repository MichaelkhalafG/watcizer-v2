<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Pickup\PickupCheckout;
use App\Domain\Pickup\PickupLifecycle;
use App\Storefront\StorefrontIdentity;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The dashboard's WhatsApp button on a pickup order (click & collect, 2026-11-14): a wa.me link to the CUSTOMER's
 * number with a ready-made message a person reads and sends. Core sends no WhatsApp of its own (no API, no SMS).
 *
 * The message follows the pickup's state — being prepared, ready (store, code, collect-by, map), extended — in
 * Arabic, then English. Customer text, so it lives with the e-mails (exempt from the dashboard's translation scan).
 */
final class PickupWhatsApp
{
    /** The wa.me link, or null when the order has no usable phone or is not a pickup. */
    public static function url(int $orderId, ?string $phone, ?string $name): ?string
    {
        $digits = self::waDigits($phone);
        $facts = PickupCheckout::facts($orderId);
        if ($digits === null || $facts === null) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode(self::message($orderId, $facts, $name));
    }

    /** An Egyptian mobile as wa.me wants it: 20 + ten digits. */
    public static function waDigits(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (preg_match('/^0(1[0-9]{9})$/', $d, $m) === 1) {
            return '20'.$m[1];
        }
        if (preg_match('/^(?:00)?20(1[0-9]{9})$/', $d, $m) === 1) {
            return '20'.$m[1];
        }

        return null;
    }

    /**
     * @param  array{status: string, code: string, pay_at_store: bool, store: array{name: array{en: string, ar: string}, address: array{en: string, ar: string}, hours: array{en: string|null, ar: string|null}, map_url: string|null, phone: string|null, whatsapp: string|null}, ready: array{kind: string, min: int, max: int, sentence: array{en: string, ar: string}}, ready_at: string|null, expires_at: string|null}  $facts
     */
    public static function message(int $orderId, array $facts, ?string $name): string
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['order_number', 'storefront_id']);
        $number = $order === null ? '' : Row::str(Row::cast($order), 'order_number');
        $brand = '';
        try {
            $brand = $order === null ? '' : StorefrontIdentity::for(Row::int(Row::cast($order), 'storefront_id'))->viewData()['brandName'];
        } catch (Throwable) {
            // a shop with no identity still gets a message, just without its name
        }
        $store = $facts['store'];
        $until = PickupLifecycle::cairo($facts['expires_at'], 'Y-m-d H:i');
        $hello = trim('مرحبًا '.($name ?? ''));
        $helloEn = trim('Hello '.($name ?? ''));

        $ar = [];
        $en = [];
        if ($facts['status'] === PickupLifecycle::READY) {
            $ar[] = $hello.'، طلبك رقم '.$number.' من '.$brand.' جاهز للاستلام من فرع '.$store['name']['ar'].'.';
            $ar[] = 'العنوان: '.$store['address']['ar'];
            if ($store['hours']['ar']) {
                $ar[] = 'المواعيد: '.$store['hours']['ar'];
            }
            $ar[] = 'كود الاستلام: '.$facts['code'];
            if ($until !== null) {
                $ar[] = 'نحتفظ به لك حتى '.$until.'.';
            }
            $en[] = $helloEn.', your '.$brand.' order '.$number.' is ready for pickup at '.$store['name']['en'].'.';
            $en[] = 'Pickup code: '.$facts['code'].($until !== null ? ' — held for you until '.$until.'.' : '');
        } else {
            $ar[] = $hello.'، بخصوص طلبك رقم '.$number.' من '.$brand.' للاستلام من فرع '.$store['name']['ar'].': '.$facts['ready']['sentence']['ar'].'، وسنبلغك فور أن يصبح جاهزًا.';
            $en[] = $helloEn.', about your '.$brand.' order '.$number.' for pickup at '.$store['name']['en'].': '.$facts['ready']['sentence']['en'].'. We will tell you the moment it is ready.';
        }
        if ($facts['pay_at_store']) {
            $ar[] = 'الدفع عند الاستلام في الفرع.';
        }
        if ($store['map_url']) {
            $ar[] = $store['map_url'];
        }

        return implode("\n", $ar)."\n\n".implode("\n", $en);
    }
}
