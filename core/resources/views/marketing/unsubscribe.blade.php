<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $brandName }}</title>
    {{-- The abandoned-cart e-mail's and the campaigns' unsubscribe (2026-11-24): the shop's own name, Arabic first with
         English beneath like the e-mails. ONE CLICK: the form submits itself when the page opens in a browser; a link
         scanner that runs no script changes nothing, and the button stays for a browser without scripts. --}}
    <style>
        body { margin: 0; background: #f5f5f5; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: #111; }
        main { max-width: 460px; margin: 64px auto; padding: 32px 24px; background: #fff; border-radius: 10px; text-align: center; }
        h1 { font-size: 20px; margin: 12px 0 8px; }
        p { font-size: 14px; line-height: 1.7; color: rgba(0, 0, 0, 0.6); margin: 0 0 6px; }
        .en { direction: ltr; }
        button { margin-top: 18px; padding: 12px 32px; border: 0; border-radius: 6px; background: #111; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; }
        a { color: #111; }
    </style>
</head>
<body>
<main>
    <div style="font-size:20px;font-weight:700;letter-spacing:2px;">{{ $brandName }}</div>
    @if (! $done)
        <h1>جارٍ إلغاء اشتراكك…</h1>
        <p>لن تصلك رسائل العروض والتذكير بعد الآن. رسائل طلباتك تصل كالمعتاد.</p>
        <p class="en">Unsubscribing you… You will stop getting our offers and reminders. Your order e-mails will still arrive.</p>
        <form id="unsubscribe" method="POST" action="{{ $action }}">
            <button type="submit">إلغاء الاشتراك · Unsubscribe</button>
        </form>
        <script>document.getElementById('unsubscribe').submit();</script>
    @else
        <h1>تم إلغاء اشتراكك</h1>
        <p>لن تصلك هذه الرسائل بعد الآن.</p>
        <p class="en">You're unsubscribed. You will not get these e-mails any more.</p>
    @endif
    <p style="margin-top:22px;"><a href="{{ $site }}">{{ preg_replace('#^https?://#', '', $site) }}</a></p>
</main>
</body>
</html>
