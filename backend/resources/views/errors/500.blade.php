<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong — Watchizer Admin</title>
    <style>
        :root { --wz-black:#111; --wz-gold:#C8A45C; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: var(--wz-black); color: #fff; padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .card {
            max-width: 560px; width: 100%; background: #1a1a1a; border: 1px solid rgba(200,164,92,.25);
            border-radius: 14px; padding: 40px 34px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.5);
        }
        .logo { font-size: 22px; font-weight: 800; letter-spacing: .12em; margin-bottom: 22px; }
        .logo span { color: var(--wz-gold); }
        h1 { font-size: 24px; margin: 0 0 12px; }
        p { color: #c9c9c9; line-height: 1.6; margin: 0 0 14px; font-size: 15px; }
        .safe {
            background: rgba(200,164,92,.10); border: 1px solid rgba(200,164,92,.35);
            border-radius: 10px; padding: 14px 16px; margin: 20px 0; color: #f0e6d2; font-size: 14px;
        }
        .safe strong { color: var(--wz-gold); }
        .btn {
            display: inline-block; margin: 8px 6px 0; padding: 12px 28px; border-radius: 8px;
            background: var(--wz-gold); color: #111; font-weight: 700; text-decoration: none; cursor: pointer; font-size: 15px;
        }
        .btn.secondary { background: transparent; color: var(--wz-gold); border: 1px solid rgba(200,164,92,.5); }
        .btn:hover { filter: brightness(1.06); }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">WATCH<span>IZER</span></div>
        <h1>Something went wrong</h1>
        <p>The server hit an unexpected error handling that request. Our team has been notified. This is often a one-off — please try again.</p>

        <div class="safe">
            ✅ <strong>Your work is safe.</strong> If you were filling in a product, everything you typed was auto-saved in this browser.
            Go back to the product form and click <strong>“Restore draft”</strong> if anything is missing.
        </div>

        <a href="javascript:history.back()" class="btn secondary">Go back</a>
        <a href="{{ url('/en/admin/product') }}" class="btn">Products list</a>
    </div>
</body>
</html>
