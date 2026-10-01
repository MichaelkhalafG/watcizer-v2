<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Unsubscribe — Watchizer</title>
    {{-- Reached from the weekly e-mail, which is English only (developer, 2026-10-01) — so is this. --}}
    <style>
        body { margin: 0; background: #f5f5f5; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: #111; }
        main { max-width: 440px; margin: 64px auto; padding: 32px 24px; background: #fff; border-radius: 10px; text-align: center; }
        h1 { font-size: 20px; margin: 12px 0 8px; }
        p { font-size: 14px; line-height: 1.7; color: rgba(0, 0, 0, 0.6); margin: 0 0 6px; }
        button { margin-top: 18px; padding: 12px 32px; border: 0; border-radius: 6px; background: #111; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; }
        a { color: #111; }
    </style>
</head>
<body>
<main>
    <div style="font-size:20px;font-weight:700;letter-spacing:4px;">WATCH<span style="color:#C8A45C;">IZER</span></div>
    @if (! $done)
        <h1>Unsubscribe from our e-mails</h1>
        <p>You will stop getting the "new picks for you" e-mails. Your order e-mails will still arrive as usual.</p>
        <form method="POST" action="{{ url('/unsubscribe/'.$send.'/'.$signature) }}">
            <button type="submit">Unsubscribe</button>
        </form>
    @else
        <h1>You're unsubscribed</h1>
        <p>You will not get these e-mails any more.</p>
    @endif
    <p style="margin-top:22px;"><a href="https://watchizereg.com">watchizereg.com</a></p>
</main>
</body>
</html>
