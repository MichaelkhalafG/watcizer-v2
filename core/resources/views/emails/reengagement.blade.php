<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>New picks for you — Watchizer</title>
</head>
{{-- The weekly re-engagement e-mail (2026-10-01). ENGLISH ONLY (developer, 2026-10-01): the two
     languages run together read as a mistake, and one version is easier to keep right than two.
     Today's prices, products in stock, and a working unsubscribe. It does NOT use the shared
     emails.partials.footer: that footer is bilingual and pinned byte-for-byte to the order e-mails'
     legacy original (OrderMailContractTest), so this e-mail carries its own English footer below. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:32px 32px 8px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;">New picks for you</h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);">A few pieces we think you'll like, in stock today.</p>
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
                        <p style="margin:10px 0 0;font-size:13px;line-height:1.5;color:#111;">{{ $p['name'] }}</p>
                        <p style="margin:4px 0 0;font-size:14px;font-weight:800;color:#111;">EGP {{ number_format($p['price']) }}</p>
                    </a>
                </td>
            @endforeach
            @if (count($pair) === 1)<td width="50%"></td>@endif
            </tr>
        @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:18px 32px 12px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;">Prices and stock are as of sending and may change.</p>
    </td></tr>

    <tr><td style="padding:0 32px 24px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;">
            <a href="{{ $unsubscribeUrl }}" style="color:rgba(0,0,0,0.55);">Unsubscribe from these e-mails</a>
        </p>
    </td></tr>

    {{-- English footer (see the note at the top). --}}
    <tr>
        <td style="background:#111111;padding:28px 32px;text-align:center;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
            <span style="display:block;margin:0 auto 16px;font-size:18px;font-weight:700;letter-spacing:3px;color:#ffffff;text-transform:uppercase;line-height:1;">WATCH<span style="color:#C8A45C;">IZER</span></span>
            <a href="https://wa.me/201551096234" style="display:inline-block;text-decoration:none;color:#C8A45C;font-size:14px;font-weight:600;line-height:1.5;">
                <span style="font-size:16px;">&#128241;</span> Questions? Message us on WhatsApp
            </a>
            <div style="height:1px;background:rgba(255,255,255,0.12);margin:18px auto 14px;max-width:220px;font-size:0;line-height:0;">&nbsp;</div>
            <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.4);">{{ config('notifications.brand.copyright') }}</p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>
