<!DOCTYPE html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $ar ? 'عاد متوفراً' : 'Back in stock' }} — Watchizer</title>
</head>
{{-- "It's back in stock" (2026-10-01): the shopper's own language first, the other beneath it the
     way the order e-mails pair them; the product, its price, one button, and a way to stop. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:34px 32px 6px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
            {{ $ar ? 'المنتج الذي انتظرته عاد متوفراً' : 'The piece you were waiting for is back' }}
        </h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);" dir="{{ $ar ? 'ltr' : 'rtl' }}">
            {{ $ar ? 'The piece you were waiting for is back in stock' : 'المنتج الذي انتظرته عاد متوفراً' }}
        </p>
    </td></tr>

    <tr><td style="padding:22px 32px 0;text-align:center;">
        @if ($product['image'])
            <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" width="240" style="width:240px;max-width:100%;height:auto;border-radius:8px;background:#fafafa;">
        @endif
        <p style="margin:16px 0 0;font-size:15px;font-weight:600;color:#111;line-height:1.6;" dir="{{ $ar ? 'rtl' : 'ltr' }}">{{ $product['name'] }}</p>
        <p style="margin:6px 0 0;font-size:17px;font-weight:800;color:#111;">{{ number_format($product['price']) }} EGP</p>
        <p style="margin:12px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
            {{ $ar ? 'الكمية محدودة، والإشعار أُرسل لعدد من المنتظرين — اطلبه الآن قبل نفاده.' : 'Stock is limited and a few others were told too — order now before it sells out again.' }}
        </p>
    </td></tr>

    <tr><td style="padding:24px 32px 26px;text-align:center;">
        <a href="{{ $product['url'] }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">{{ $ar ? 'اطلبه الآن' : 'Shop now' }}</a>
    </td></tr>

    <tr><td style="padding:0 32px 26px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;">
            {{ $ar ? 'وصلتك هذه الرسالة لأنك طلبت إشعاراً عند توفر هذا المنتج.' : 'You are receiving this because you asked to be told when this product was back.' }}
            <a href="{{ $stopUrl }}" style="color:rgba(0,0,0,0.55);">{{ $ar ? 'إلغاء هذا الإشعار' : 'Stop this alert' }}</a>
        </p>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
