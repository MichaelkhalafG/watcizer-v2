<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Your order wasn't completed — Watchizer</title>
</head>
{{-- A card order nobody paid for, cancelled by orders:expire-unpaid (2026-10-05). The text is the
     developer-approved draft: what happened, the items released, a link that brings it back. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    {{-- English --}}
    <tr><td style="padding:34px 32px 6px;" dir="ltr">
        <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;letter-spacing:0.2em;">Order #{{ $orderNumber }}</div>
        <h1 style="margin:12px 0 0;font-size:22px;font-weight:600;color:#111;">Your order wasn't completed</h1>
        <p style="margin:16px 0 0;font-size:15px;color:#111;">Hello {{ $customerName }},</p>
        <p style="margin:10px 0 0;font-size:14px;color:rgba(0,0,0,0.7);line-height:1.75;">
            Your order #{{ $orderNumber }} was cancelled because the payment wasn't finished. We've released the items, so they're available in the shop again.
        </p>
        <p style="margin:10px 0 0;font-size:14px;color:rgba(0,0,0,0.7);line-height:1.75;">
            If you'd still like them, the button below brings your order back: your items go back in your cart at today's prices, and your details are already filled in at checkout.
        </p>
    </td></tr>
    <tr><td style="padding:22px 32px 6px;text-align:center;">
        <a href="{{ $recoveryUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">Bring my order back</a>
    </td></tr>
    <tr><td style="padding:8px 32px 0;" dir="ltr">
        <p style="margin:0;font-size:12px;color:rgba(0,0,0,0.5);line-height:1.7;text-align:center;">
            This link works for {{ $recoveryDays }} days. If anything in your order has sold out or changed price, you'll see it before you pay.
        </p>
    </td></tr>

    <tr><td style="padding:24px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- Arabic --}}
    <tr><td style="padding:22px 32px 6px;text-align:right;" dir="rtl">
        <h2 style="margin:0;font-size:20px;font-weight:600;color:#111;">طلبك لم يكتمل</h2>
        <p style="margin:14px 0 0;font-size:15px;color:#111;">مرحباً {{ $customerName }}،</p>
        <p style="margin:10px 0 0;font-size:14px;color:rgba(0,0,0,0.7);line-height:1.9;">
            تم إلغاء طلبك رقم #{{ $orderNumber }} لأن الدفع لم يكتمل. أرجعنا المنتجات إلى المتجر، فأصبحت متاحة من جديد.
        </p>
        <p style="margin:10px 0 0;font-size:14px;color:rgba(0,0,0,0.7);line-height:1.9;">
            إن كنت لا تزال ترغب فيها، يعيد الزر أدناه طلبك: تعود المنتجات إلى سلتك بأسعار اليوم، وتجد بياناتك مكتوبة في صفحة إتمام الطلب.
        </p>
    </td></tr>
    <tr><td style="padding:22px 32px 6px;text-align:center;">
        <a href="{{ $recoveryUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:14px;font-weight:700;">استرجع طلبي</a>
    </td></tr>
    <tr><td style="padding:8px 32px 0;" dir="rtl">
        <p style="margin:0;font-size:12px;color:rgba(0,0,0,0.5);line-height:1.8;text-align:center;">
            الرابط صالح لمدة {{ strtr((string) $recoveryDays, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) }} أيام. إن نفد منتج من طلبك أو تغيّر سعره، ستعرف ذلك قبل الدفع.
        </p>
    </td></tr>

    <tr><td style="padding:24px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.07);font-size:0;line-height:0;">&nbsp;</div></td></tr>

    {{-- What was in the order --}}
    <tr><td style="padding:20px 32px 4px;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 16px;">Your Order &middot; <span dir="rtl">طلبك</span></p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            @foreach($items as $item)
                @include('emails.partials.product-row', ['item' => $item, 'mode' => 'customer', 'decimals' => $moneyDecimals])
            @endforeach
        </table>
    </td></tr>

    {{-- Help --}}
    <tr><td style="padding:16px 32px 28px;text-align:center;">
        <p style="margin:0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.8;">
            Questions? We're on <a href="{{ $whatsappUrl }}" style="color:#111;">WhatsApp</a> every day.<br>
            <span dir="rtl">لأي سؤال، نحن متاحون على <a href="{{ $whatsappUrl }}" style="color:#111;">واتساب</a> يومياً.</span>
        </p>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
