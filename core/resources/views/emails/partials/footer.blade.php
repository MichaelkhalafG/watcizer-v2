{{-- Shared dark footer: the storefront's wordmark (the same PNG as the header at 140 px, spec §22.3),
     its customer-service WhatsApp and its copyright line — all three from StorefrontIdentity, i.e.
     the storefront row (W2, 2026-10-13). No fallback: a storefront without them is parked by the
     mailer, never rendered with another shop's. Developer credit is intentionally NOT here —
     frontend only. --}}
<tr>
    <td style="background:#111111;padding:28px 32px;text-align:center;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
        <img src="{{ $wordmarkUrl }}" width="140" height="43" alt="{{ $brandName }}" style="display:block;margin:0 auto 16px;width:140px;height:43px;border:0;outline:none;text-decoration:none;">

        {{-- WhatsApp support (Unicode icon — inline SVG is stripped by Gmail) --}}
        <a href="{{ $whatsappUrl }}" style="display:inline-block;text-decoration:none;color:#C8A45C;font-size:14px;font-weight:600;line-height:1.5;">
            <span style="font-size:16px;">&#128241;</span>
            <span dir="rtl" style="font-family:'Segoe UI',Tahoma,Arial,sans-serif;">للاستعلام عن طلبك تواصل معنا على واتساب</span>
        </a>
        <p style="margin:6px 0 0;font-size:11px;color:rgba(255,255,255,0.5);">Contact us on WhatsApp for order inquiries</p>

        <div style="height:1px;background:rgba(255,255,255,0.12);margin:18px auto 14px;max-width:220px;font-size:0;line-height:0;">&nbsp;</div>

        <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.4);">{{ $copyright }}</p>
    </td>
</tr>
