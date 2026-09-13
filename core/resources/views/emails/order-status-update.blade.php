<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Order Update — Watchizer</title>
</head>
@php
    $accent = [
        'pending'    => '#1565c0',
        'processing' => '#b8860b',
        'shipped'    => '#1565c0',
        'delivered'  => '#16a34a',
        'completed'  => '#16a34a',
        'cancelled'  => '#c62828',
    ][$status] ?? '#111111';

    $messageEn = [
        'pending'    => 'We have received your order and it is awaiting confirmation.',
        'processing' => 'Good news — your order is now being prepared for delivery.',
        'shipped'    => 'Your order is on its way to you.',
        'delivered'  => 'Your order has been delivered. We hope you love it!',
        'completed'  => 'Your order is complete. Thank you for shopping with Watchizer!',
        'cancelled'  => 'Your order has been cancelled. If this is a mistake, please contact us.',
    ][$status] ?? 'Your order status has been updated.';

    $messageAr = [
        'pending'    => 'تم استلام طلبك وهو في انتظار التأكيد.',
        'processing' => 'خبر سعيد — جاري تجهيز طلبك للتوصيل.',
        'shipped'    => 'طلبك في الطريق إليك.',
        'delivered'  => 'تم توصيل طلبك. نتمنى أن ينال إعجابك!',
        'completed'  => 'تم اكتمال طلبك. شكراً لتسوقك من Watchizer!',
        'cancelled'  => 'تم إلغاء طلبك. إذا كان هذا عن طريق الخطأ، يرجى التواصل معنا.',
    ][$status] ?? 'تم تحديث حالة طلبك.';
@endphp
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    {{-- Status hero --}}
    <tr><td style="padding:36px 32px 10px;text-align:center;">
        <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;letter-spacing:0.2em;">Order #{{ $orderNumber }}</div>
        <div style="display:inline-block;margin-top:14px;padding:10px 26px;border-radius:26px;background:{{ $accent }};color:#fff;font-size:17px;font-weight:700;">
            {{ $statusEn }} &middot; <span dir="rtl">{{ $statusAr }}</span>
        </div>
    </td></tr>

    {{-- Message --}}
    <tr><td style="padding:18px 32px 4px;text-align:center;">
        <p style="margin:0;font-size:15px;color:#111;">Hello {{ $customerName }},</p>
        <p style="margin:10px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;">
            {{ $messageEn }}<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">{{ $messageAr }}</span>
        </p>
    </td></tr>

    <tr><td style="padding:22px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Order summary --}}
    <tr><td style="padding:20px 32px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Order Number &middot; رقم الطلب</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;font-weight:600;">#{{ $orderNumber }}</td>
            </tr>
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Order Date &middot; تاريخ الطلب</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ $createdAt }}</td>
            </tr>
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Payment &middot; الدفع</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ $paymentEn }}</td>
            </tr>
            <tr>
                <td style="font-size:15px;font-weight:700;color:#111;padding:8px 0 4px;">Total &middot; الإجمالي</td>
                <td style="font-size:17px;font-weight:800;color:#111;text-align:right;padding:8px 0 4px;">{{ number_format($total) }} EGP</td>
            </tr>
        </table>
    </td></tr>

    {{-- CTA --}}
    <tr><td style="padding:24px 32px 30px;text-align:center;">
        <a href="{{ $trackUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">Track Your Order</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
