<!DOCTYPE html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $ar ? 'مكافأة دعوتك' : 'Your invite reward' }} — {{ $brandName }}</title>
</head>
{{-- The wheel's invite reward (2026-11-09): the winner's friend's first order was delivered — here is the winner's own
     code. The winner's language first, the other beneath, the way the order e-mails pair them; the code large and
     plain (a mail client must not break it); the terms in one line each. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:34px 32px 6px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
            {{ $ar ? 'وصل أول طلب لصديقك — شكرًا لدعوته' : "Your friend's first order has been delivered — thank you for inviting them" }}
        </h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);" dir="{{ $ar ? 'ltr' : 'rtl' }}">
            {{ $ar ? "Your friend's first order has been delivered — thank you for inviting them" : 'وصل أول طلب لصديقك — شكرًا لدعوته' }}
        </p>
    </td></tr>

    <tr><td style="padding:22px 32px 0;text-align:center;">
        <p style="margin:0;font-size:15px;color:#111;line-height:1.6;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
            {{ $ar ? "هذه مكافأتك: خصم {$percent}٪ على طلبك القادم." : "Here is your reward: {$percent}% off your next order." }}
        </p>
        <p style="margin:16px 0 0;padding:14px 18px;display:inline-block;border:1px dashed #C8A45C;border-radius:8px;font-size:24px;letter-spacing:0.08em;font-family:Consolas,Menlo,monospace;font-weight:700;color:#111;" dir="ltr">{{ $code }}</p>
        <p style="margin:14px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
            {{ $ar ? "استخدام واحد، مع رقم الهاتف الذي أدرت به العجلة، حتى {$untilAr}. لا يُجمع مع العروض الأخرى." : "Single use, with the phone number you spun with, until {$untilEn}. It does not combine with other offers." }}
        </p>
    </td></tr>

    <tr><td style="padding:22px 32px 26px;text-align:center;">
        <p style="margin:0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;" dir="{{ $ar ? 'ltr' : 'rtl' }}">
            {{ $ar ? "Here is your reward: {$percent}% off your next order. Single use, with the phone number you spun with, until {$untilEn}." : "هذه مكافأتك: خصم {$percent}٪ على طلبك القادم. استخدام واحد، مع رقم الهاتف الذي أدرت به العجلة، حتى {$untilAr}." }}
        </p>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
