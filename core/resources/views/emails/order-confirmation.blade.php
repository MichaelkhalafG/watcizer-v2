<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Order Confirmation — {{ $brandName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    {{-- Success hero --}}
    <tr><td style="padding:36px 32px 18px;text-align:center;">
        <div style="width:56px;height:56px;border-radius:50%;background:#f0fdf4;margin:0 auto 16px;line-height:56px;font-size:28px;color:#16a34a;">&#10003;</div>
        <h1 style="margin:0;font-size:23px;font-weight:600;color:#111;">Order Confirmed &middot; <span dir="rtl">تم استلام طلبك</span></h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);">Thank you for your purchase &middot; <span dir="rtl">شكراً لطلبك</span></p>
    </td></tr>

    {{-- Order number + date --}}
    <tr><td style="padding:0 32px 22px;text-align:center;">
        <table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 auto;background:#f9f9f9;border-radius:8px;">
        <tr><td style="padding:14px 30px;text-align:center;">
            <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;letter-spacing:0.15em;">Order Number &middot; رقم الطلب</div>
            <div style="font-size:22px;font-weight:700;color:#111;padding-top:4px;">#{{ $orderNumber }}</div>
            <div style="font-size:11px;color:rgba(0,0,0,0.4);padding-top:6px;">{{ $createdAt }}</div>
        </td></tr>
        </table>
    </td></tr>

    {{-- Greeting --}}
    <tr><td style="padding:0 32px 4px;">
        <p style="margin:0;font-size:15px;color:#111;">Hello {{ $customerName }},</p>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;">
            @if(!empty($pickup))
            Your order is reserved for you at {{ $pickup['store']['name']['en'] }}. We are preparing it, and we will e-mail you the moment it is ready to collect.<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">طلبك محجوز لك في فرع {{ $pickup['store']['name']['ar'] }}. نجهّزه الآن، وسنراسلك بالبريد فور أن يصبح جاهزًا للاستلام.</span>
            @else
            Your order has been received and is being processed. Our team will contact you shortly to confirm delivery.<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">تم استلام طلبك وجاري تجهيزه. سيتواصل معك فريقنا قريباً لتأكيد التوصيل.</span>
            @endif
        </p>
    </td></tr>

    <tr><td style="padding:20px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Items --}}
    <tr><td style="padding:20px 32px 4px;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 16px;">Your Items &middot; <span dir="rtl">مشترياتك</span></p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            @foreach($items as $item)
                @include('emails.partials.product-row', ['item' => $item, 'mode' => 'customer', 'decimals' => $moneyDecimals])
            @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:0 32px;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Totals --}}
    <tr><td style="padding:18px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            {{-- The column ADDS UP: Subtotal (list) − Discount − Promotion + Shipping = Total.
                 Every figure comes from OrderTotals, the same answer the dashboard shows. --}}
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Subtotal &middot; المجموع قبل الخصم</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ number_format($subtotal, $moneyDecimals) }} EGP</td>
            </tr>
            @if($discount > 0)
            <tr>
                <td style="font-size:13px;color:#16a34a;padding:4px 0;">Discount &middot; الخصم</td>
                <td style="font-size:13px;color:#16a34a;text-align:right;padding:4px 0;">&minus;{{ number_format($discount, $moneyDecimals) }} EGP</td>
            </tr>
            @endif
            @if($promotion > 0)
            <tr>
                <td style="font-size:13px;color:#16a34a;padding:4px 0;">Promotion{{ $promotionName ? ' · ' . $promotionName : '' }} &middot; العرض</td>
                <td style="font-size:13px;color:#16a34a;text-align:right;padding:4px 0;">&minus;{{ number_format($promotion, $moneyDecimals) }} EGP</td>
            </tr>
            @endif
            <tr>
                <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">@if(!empty($pickup)) Pickup at store &middot; استلام من الفرع @else Shipping{{ $cityEn ? ' · ' . $cityEn : '' }} &middot; الشحن @endif</td>
                <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">@if($freeShipping || $shippingCost <= 0) Free &middot; مجاني @else {{ number_format($shippingCost, $moneyDecimals) }} EGP @endif</td>
            </tr>
            <tr><td colspan="2" style="border-top:1px solid rgba(0,0,0,0.08);padding-top:10px;font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr>
                <td style="font-size:16px;font-weight:700;color:#111;padding:4px 0;">Total &middot; الإجمالي</td>
                <td style="font-size:20px;font-weight:800;color:#111;text-align:right;padding:4px 0;">{{ number_format($total, $moneyDecimals) }} EGP</td>
            </tr>
        </table>
    </td></tr>

    <tr><td style="padding:0 32px;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Shipping + payment --}}
    <tr><td style="padding:18px 32px;">
        @if(!empty($pickup))
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 8px;">Pick Up At &middot; <span dir="rtl">الاستلام من</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;line-height:1.7;">
            <strong style="color:#111;">{{ $pickup['store']['name']['en'] }}</strong> &middot; <span dir="rtl">{{ $pickup['store']['name']['ar'] }}</span><br>
            {{ $pickup['store']['address']['en'] }}<br>
            <span dir="rtl">{{ $pickup['store']['address']['ar'] }}</span>
            @if($pickup['store']['hours']['en'] || $pickup['store']['hours']['ar'])<br>{{ $pickup['store']['hours']['en'] }}@if($pickup['store']['hours']['ar']) &middot; <span dir="rtl">{{ $pickup['store']['hours']['ar'] }}</span>@endif @endif
            @if($pickup['store']['map_url'])<br><a href="{{ $pickup['store']['map_url'] }}" style="color:#111;">Open in Maps &middot; <span dir="rtl">افتح الخريطة</span></a>@endif
        </p>
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Your Pickup Code &middot; <span dir="rtl">كود الاستلام</span></p>
        <p style="font-size:22px;font-weight:800;letter-spacing:0.3em;color:#111;margin:0;" dir="ltr">{{ $pickup['code'] }}</p>
        <p style="font-size:12px;color:rgba(0,0,0,0.55);margin:6px 0 0;line-height:1.6;">Show your order number and this code at the store, with the phone number you ordered with.<br><span dir="rtl">اعرض رقم الطلب وهذا الكود في الفرع، مع رقم الهاتف الذي طلبت به.</span></p>
        @else
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 8px;">Shipping To &middot; <span dir="rtl">عنوان الشحن</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;line-height:1.7;">
            {{ $addressLine }}<br>
            {{ $cityEn }}{{ $cityAr ? ' / ' . $cityAr : '' }}
            @if($customerPhone)<br>Phone: {{ $customerPhone }}@endif
        </p>
        @endif

        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Payment &middot; <span dir="rtl">طريقة الدفع</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;">{{ $paymentEn }} &middot; <span dir="rtl">{{ $paymentAr }}</span></p>

        @if(!empty($pickup))
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Ready For Pickup &middot; <span dir="rtl">موعد الاستلام</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;line-height:1.7;">{{ $pickup['ready']['sentence']['en'] }} &middot; <span dir="rtl">{{ $pickup['ready']['sentence']['ar'] }}</span><br>We hold it for you for {{ $pickupHoldHours }} hours after it is ready. &middot; <span dir="rtl">نحتفظ به لك {{ $pickupHoldHours }} ساعة بعد أن يصبح جاهزًا.</span></p>
        @else
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Estimated Delivery &middot; <span dir="rtl">موعد التوصيل</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;">2–5 business days &middot; <span dir="rtl">من ٢ إلى ٥ أيام عمل</span></p>
        @endif

        @if($note)
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Note &middot; <span dir="rtl">ملاحظة</span></p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;">{{ $note }}</p>
        @endif
    </td></tr>

    {{-- CTA --}}
    <tr><td style="padding:6px 32px 30px;text-align:center;">
        <a href="{{ $trackUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">Track Your Order</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
