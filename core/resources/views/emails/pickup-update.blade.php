<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Pickup — {{ $brandName }}</title>
</head>
@php
    $extended = ($pickupEvent ?? 'ready') === 'extended';
    $store = $pickup['store'] ?? null;
@endphp
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    {{-- Hero --}}
    <tr><td style="padding:36px 32px 10px;text-align:center;">
        <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;letter-spacing:0.2em;">Order #{{ $orderNumber }}</div>
        <div style="display:inline-block;margin-top:14px;padding:10px 26px;border-radius:26px;background:#16a34a;color:#fff;font-size:17px;font-weight:700;">
            @if($extended) Held for you longer &middot; <span dir="rtl">تم تمديد الحجز</span> @else Ready for pickup &middot; <span dir="rtl">جاهز للاستلام</span> @endif
        </div>
    </td></tr>

    {{-- Message --}}
    <tr><td style="padding:18px 32px 4px;text-align:center;">
        <p style="margin:0;font-size:15px;color:#111;">Hello {{ $customerName }},</p>
        <p style="margin:10px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;">
            @if($extended)
            As you asked, we are holding your order at {{ $store['name']['en'] ?? '' }} a little longer.<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">كما طلبت، نحتفظ بطلبك في فرع {{ $store['name']['ar'] ?? '' }} لوقت أطول.</span>
            @else
            Your order is waiting for you at {{ $store['name']['en'] ?? '' }}.<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">طلبك في انتظارك في فرع {{ $store['name']['ar'] ?? '' }}.</span>
            @endif
        </p>
    </td></tr>

    {{-- Collect by + code --}}
    <tr><td style="padding:22px 32px 0;text-align:center;">
        @if(!empty($pickupCollectBy))
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 6px;">Collect By &middot; <span dir="rtl">استلمه قبل</span></p>
        <p style="font-size:16px;font-weight:700;color:#111;margin:0;">{{ $pickupCollectBy['en'] }} &middot; <span dir="rtl">{{ $pickupCollectBy['ar'] }}</span></p>
        <p style="font-size:12px;color:rgba(0,0,0,0.55);margin:6px 0 0;line-height:1.6;">After this time the reservation is released and the item goes back on sale.<br><span dir="rtl">بعد هذا الوقت يُلغى الحجز ويعود المنتج للبيع.</span></p>
        @endif
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:20px 0 6px;">Your Pickup Code &middot; <span dir="rtl">كود الاستلام</span></p>
        <p style="font-size:26px;font-weight:800;letter-spacing:0.3em;color:#111;margin:0;" dir="ltr">{{ $pickup['code'] ?? '' }}</p>
        <p style="font-size:12px;color:rgba(0,0,0,0.55);margin:6px 0 0;line-height:1.6;">Show your order number and this code at the store, with the phone number you ordered with.<br><span dir="rtl">اعرض رقم الطلب وهذا الكود في الفرع، مع رقم الهاتف الذي طلبت به.</span></p>
    </td></tr>

    <tr><td style="padding:22px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- The store --}}
    @if($store)
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 8px;">Pick Up At &middot; <span dir="rtl">الاستلام من</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;line-height:1.7;">
            <strong style="color:#111;">{{ $store['name']['en'] }}</strong> &middot; <span dir="rtl">{{ $store['name']['ar'] }}</span><br>
            {{ $store['address']['en'] }}<br>
            <span dir="rtl">{{ $store['address']['ar'] }}</span>
            @if($store['hours']['en'] || $store['hours']['ar'])<br>{{ $store['hours']['en'] }}@if($store['hours']['ar']) &middot; <span dir="rtl">{{ $store['hours']['ar'] }}</span>@endif @endif
            @if($store['phone'])<br>Phone &middot; <span dir="rtl">الهاتف</span>: <span dir="ltr">{{ $store['phone'] }}</span>@endif
            @if($pickup['store']['map_url'])<br><a href="{{ $pickup['store']['map_url'] }}" style="color:#111;">Open in Maps &middot; <span dir="rtl">افتح الخريطة</span></a>@endif
        </p>
    </td></tr>
    @endif

    {{-- Payment + total --}}
    <tr><td style="padding:18px 32px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Payment &middot; الدفع</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ $paymentEn }} &middot; <span dir="rtl">{{ $paymentAr }}</span></td>
            </tr>
            <tr>
                <td style="font-size:15px;font-weight:700;color:#111;padding:8px 0 4px;">@if(!empty($pickup['pay_at_store'])) To pay at the store &middot; المطلوب في الفرع @else Total &middot; الإجمالي @endif</td>
                <td style="font-size:17px;font-weight:800;color:#111;text-align:right;padding:8px 0 4px;">{{ number_format((float) $total, $moneyDecimals) }} EGP</td>
            </tr>
        </table>
    </td></tr>

    {{-- CTA --}}
    <tr><td style="padding:24px 32px 30px;text-align:center;">
        <a href="{{ $trackUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">Your Orders</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
