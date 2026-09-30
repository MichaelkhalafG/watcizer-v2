<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>إلغاء الاشتراك · Unsubscribe — Watchizer</title>
    <style>
        body { margin: 0; background: #f5f5f5; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: #111; }
        main { max-width: 440px; margin: 64px auto; padding: 32px 24px; background: #fff; border-radius: 10px; text-align: center; }
        h1 { font-size: 20px; margin: 12px 0 8px; }
        p { font-size: 14px; line-height: 1.7; color: rgba(0, 0, 0, 0.6); margin: 0 0 6px; }
        .en { direction: ltr; }
        button { margin-top: 18px; padding: 12px 32px; border: 0; border-radius: 6px; background: #111; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; }
        a { color: #111; }
    </style>
</head>
<body>
<main>
    <div style="font-size:20px;font-weight:700;letter-spacing:4px;">WATCH<span style="color:#C8A45C;">IZER</span></div>
    @if (! $done)
        <h1>إلغاء الاشتراك في رسائلنا</h1>
        <p>لن تصلك رسائل «اخترنا لك» بعد الآن. رسائل طلباتك تصلك كالمعتاد.</p>
        <p class="en">Stop the "new picks for you" e-mails. Your order e-mails still arrive as usual.</p>
        <form method="POST" action="{{ url('/unsubscribe/'.$send.'/'.$signature) }}">
            <button type="submit">إلغاء الاشتراك · Unsubscribe</button>
        </form>
    @else
        <h1>تم إلغاء الاشتراك</h1>
        <p>لن تصلك رسائل «اخترنا لك» بعد الآن.</p>
        <p class="en">Done — you will not get these e-mails any more.</p>
    @endif
    <p style="margin-top:22px;"><a href="https://watchizereg.com">watchizereg.com</a></p>
</main>
</body>
</html>
