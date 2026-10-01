<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>Re-engagement preview — Watchizer</title>
</head>
{{-- The team's preview of the weekly e-mail. English only (developer, 2026-10-01), like the e-mail. --}}
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#111;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;">
    <h1 style="font-size:19px;margin:0 0 12px;">Preview of this week's e-mail ({{ $week }})</h1>
    <p style="font-size:14px;line-height:1.8;margin:0;">
        Goes out after: <strong>{{ $send_after }}</strong><br>
        To: <strong>{{ $audience === 'customers' ? 'CUSTOMERS — real people' : 'the team only (a test)' }}</strong> — <strong>{{ $recipients }}</strong> recipient(s)<br>
        To stop it before then: <a href="{{ $manageUrl }}">open the re-engagement screen on the dashboard</a> and pause it.
    </p>
    @if ($sample)
        <h2 style="font-size:15px;margin:22px 0 8px;">A sample of what {{ $sample['email'] }} will get</h2>
        <ul style="padding-inline-start:18px;margin:0;font-size:13px;line-height:1.8;">
            @foreach ($sample['products'] as $p)
                <li><a href="{{ $p['url'] }}">{{ $p['name'] }}</a> — EGP {{ number_format($p['price']) }}</li>
            @endforeach
        </ul>
    @else
        <p style="font-size:13px;color:#b42318;margin:16px 0 0;">No eligible products this week (in stock, visible, price unchanged for 14 days) — nobody will be e-mailed.</p>
    @endif
</div>
</body>
</html>
