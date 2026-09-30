<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>اخترنا لك — Watchizer</title>
</head>
{{-- The weekly re-engagement e-mail (2026-10-01): today's prices, products in stock, Arabic first
     with English beneath like the order e-mails, and a working unsubscribe. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:32px 32px 8px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;" dir="rtl">اخترنا لك من جديد</h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);" dir="ltr">A few new picks we think you'll like</p>
    </td></tr>

    <tr><td style="padding:16px 22px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        @foreach (array_chunk($products, 2) as $pair)
            <tr>
            @foreach ($pair as $p)
                <td width="50%" valign="top" style="padding:10px;text-align:center;">
                    <a href="{{ $p['url'] }}" style="text-decoration:none;color:#111;">
                        @if ($p['image'])
                            <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" width="220" style="width:100%;max-width:220px;height:auto;border-radius:8px;background:#fafafa;">
                        @endif
                        <p style="margin:10px 0 0;font-size:13px;line-height:1.5;color:#111;" dir="rtl">{{ $p['name'] }}</p>
                        <p style="margin:4px 0 0;font-size:14px;font-weight:800;color:#111;">{{ number_format($p['price']) }} EGP</p>
                    </a>
                </td>
            @endforeach
            @if (count($pair) === 1)<td width="50%"></td>@endif
            </tr>
        @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:18px 32px 12px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;" dir="rtl">الأسعار والتوفر وقت إرسال الرسالة، وقد تتغير.</p>
        <p style="margin:2px 0 0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;" dir="ltr">Prices and stock as of sending; they may change.</p>
    </td></tr>

    <tr><td style="padding:0 32px 24px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;">
            <a href="{{ $unsubscribeUrl }}" style="color:rgba(0,0,0,0.55);">إلغاء الاشتراك في هذه الرسائل · Unsubscribe</a>
        </p>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
