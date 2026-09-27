@php
    $mode = $mode ?? 'customer';
    $decimals = $decimals ?? 0;
    $egp  = fn ($n) => number_format((float) $n, $decimals) . ' EGP';
    // A gift is a real line at zero price; it reads as a gift, not as a product priced at nothing.
    $isGift = ! empty($item['is_gift']);
    // Sold below list: the list price is struck through beside what was paid, so these rows
    // explain the Subtotal (list) / Discount block underneath them (OrderTotals).
    $onSale = ! $isGift && isset($item['list_total']) && (float) $item['list_total'] > (float) $item['line_total'] + 0.005;
@endphp

@if($mode === 'admin')
    {{-- Admin table row: full fulfilment detail. --}}
    <tr>
        <td style="padding:10px;border-bottom:1px solid #eee;vertical-align:top;">
            @if($item['image'])
                <img src="{{ $item['image'] }}" alt="" width="56" height="56" style="width:56px;height:56px;object-fit:contain;border-radius:6px;background:#f5f5f5;border:0;display:block;">
            @endif
        </td>
        <td style="padding:10px;border-bottom:1px solid #eee;font-size:13px;color:#111;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
            <div style="font-weight:600;">{{ $item['name_en'] }}</div>
            <div dir="rtl" style="color:#666;font-size:12px;">{{ $item['name_ar'] }}</div>
            <div style="color:#999;font-size:11px;margin-top:3px;">
                @if($item['code'])SKU: {{ $item['code'] }}@endif
                @if($item['model']) &middot; Model: {{ $item['model'] }}@endif
                @if($item['type_stock']) &middot; {{ $item['type_stock'] }}@endif
            </div>
            @if($item['color_dial'] || $item['color_band'])
                <div style="margin-top:5px;font-size:11px;color:#999;">
                    @if($item['color_dial'])Dial @foreach($item['color_dial'] as $hex)<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:{{ $hex }};border:1px solid #ccc;vertical-align:middle;"></span>@endforeach&nbsp;@endif
                    @if($item['color_band'])Band @foreach($item['color_band'] as $hex)<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:{{ $hex }};border:1px solid #ccc;vertical-align:middle;"></span>@endforeach @endif
                </div>
            @endif
        </td>
        <td style="padding:10px;border-bottom:1px solid #eee;font-size:13px;color:#111;text-align:center;">{{ $item['qty'] }}</td>
        <td style="padding:10px;border-bottom:1px solid #eee;font-size:13px;color:#111;text-align:right;white-space:nowrap;">{{ $isGift ? 'Gift' : $egp($item['unit_price']) }}</td>
        <td style="padding:10px;border-bottom:1px solid #eee;font-size:13px;color:#111;font-weight:700;text-align:right;white-space:nowrap;">
            @if($isGift)
                Gift
            @else
                @if($onSale)<span style="font-weight:400;color:#999;text-decoration:line-through;">{{ $egp($item['list_total']) }}</span><br>@endif
                {{ $egp($item['line_total']) }}
            @endif
        </td>
    </tr>
@else
    {{-- Customer row: thumbnail + name (AR+EN) + line total. --}}
    <tr>
        <td width="80" style="vertical-align:top;padding:0 0 16px;">
            @if($item['image'])
                <img src="{{ $item['image'] }}" alt="" width="80" height="80" style="width:80px;height:80px;object-fit:contain;border-radius:8px;background:#f5f5f5;border:0;display:block;">
            @else
                <div style="width:80px;height:80px;border-radius:8px;background:#f5f5f5;font-size:0;line-height:0;">&nbsp;</div>
            @endif
        </td>
        <td style="vertical-align:top;padding:0 0 16px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
            <div style="font-size:14px;font-weight:600;color:#111;">{{ $item['name_en'] }}</div>
            <div dir="rtl" style="font-size:13px;color:#666;margin-top:2px;">{{ $item['name_ar'] }}</div>
            <div style="font-size:12px;color:#999;margin-top:6px;">
                @if($isGift)
                    Qty {{ $item['qty'] }} &middot; Gift &middot; <span dir="rtl">هدية</span>
                @else
                    Qty {{ $item['qty'] }} &times; {{ $egp($item['unit_price']) }}
                @endif
            </div>
        </td>
        <td style="vertical-align:top;text-align:right;padding:0 0 16px;white-space:nowrap;">
            @if($isGift)
                <div style="font-size:15px;font-weight:700;color:#16a34a;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">Gift &middot; <span dir="rtl">هدية</span></div>
            @else
                @if($onSale)
                    <div style="font-size:12px;color:#999;text-decoration:line-through;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">{{ $egp($item['list_total']) }}</div>
                @endif
                <div style="font-size:15px;font-weight:700;color:#111;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">{{ $egp($item['line_total']) }}</div>
            @endif
        </td>
    </tr>
@endif
