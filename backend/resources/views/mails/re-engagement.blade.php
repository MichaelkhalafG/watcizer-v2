@php
    use Illuminate\Support\Str;
    $frontend = rtrim(config('services.frontend_url', 'https://watchizereg.com'), '/');
    $imgBase  = 'https://dash.watchizereg.com/Uploads_Images/Product/';
@endphp
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,'Times New Roman',serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

<!-- Header -->
<tr>
<td style="background:#0a0a0a;padding:24px 32px;text-align:center;">
  <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer" width="120" style="display:block;margin:0 auto;">
</td>
</tr>

<!-- Gold line -->
<tr>
<td style="background:linear-gradient(to right,#C8A45C,#E8D5A3,#C8A45C);height:3px;font-size:0;line-height:0;">&nbsp;</td>
</tr>

<!-- Greeting -->
<tr>
<td style="padding:40px 32px 16px;text-align:center;">
  <h1 style="font-size:22px;font-weight:300;color:#111;margin:0;font-family:Georgia,serif;">
    We miss you, {{ $user->first_name }}!
  </h1>
  <p style="font-size:14px;color:rgba(0,0,0,0.5);margin:12px 0 0;line-height:1.6;">
    It's been a while since your last visit.<br>
    Here are some exclusive deals we picked just for you.
  </p>
</td>
</tr>

<!-- Offers grid (2 columns) -->
<tr>
<td style="padding:24px 24px 8px;">
  <table width="100%" cellpadding="0" cellspacing="0">
  @foreach($offers->chunk(2) as $row)
  <tr>
    @foreach($row as $product)
    @php
      $sale     = (float) $product->sale_price_after_discount;
      $price    = (float) $product->selling_price;
      $hasSale  = $sale > 0 && $sale < $price;
      $pct      = $hasSale ? round((1 - $sale / $price) * 100) : 0;
      $brand    = optional($product->brand)->brand_name;
    @endphp
    <td width="50%" style="padding:8px;vertical-align:top;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9f9f9;border-radius:8px;overflow:hidden;">
      <tr>
        <td style="padding:16px;text-align:center;">
          <a href="{{ $frontend }}/products/{{ $product->id }}" style="text-decoration:none;color:inherit;display:block;">
            @if($product->image)
            <img src="{{ $imgBase . $product->image }}"
                 alt="{{ $product->product_title }}"
                 width="120" height="120"
                 style="object-fit:contain;display:block;margin:0 auto 10px;">
            @endif
            @if($brand)
            <p style="font-size:10px;color:#C8A45C;text-transform:uppercase;letter-spacing:0.15em;margin:0 0 4px;">
              {{ $brand }}
            </p>
            @endif
            <p style="font-size:12px;font-weight:500;color:#111;margin:0 0 6px;line-height:1.3;">
              {{ Str::limit($product->product_title, 30) }}
            </p>
            @if($hasSale)
            <p style="margin:0;">
              <span style="font-size:14px;font-weight:700;color:#111;">
                {{ number_format($sale) }}
              </span>
              <span style="font-size:10px;color:rgba(0,0,0,0.35);text-decoration:line-through;">
                {{ number_format($price) }}
              </span>
              <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700;">
                -{{ $pct }}%
              </span>
            </p>
            @else
            <p style="font-size:14px;font-weight:700;color:#111;margin:0;">
              {{ number_format($price) }} EGP
            </p>
            @endif
          </a>
        </td>
      </tr>
      </table>
    </td>
    @endforeach
    @if($row->count() === 1)
    <td width="50%" style="padding:8px;">&nbsp;</td>
    @endif
  </tr>
  @endforeach
  </table>
</td>
</tr>

<!-- CTA -->
<tr>
<td style="padding:24px 32px 40px;text-align:center;">
  <a href="{{ $frontend }}/listing"
     style="display:inline-block;background:#111;color:#fff;text-decoration:none;
            padding:15px 48px;border-radius:6px;font-size:12px;font-weight:700;
            letter-spacing:0.2em;text-transform:uppercase;">
    EXPLORE DEALS
  </a>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background:#0a0a0a;padding:20px 32px;text-align:center;">
  <p style="font-size:10px;color:rgba(255,255,255,0.35);margin:0;">
    Watchizer — Egypt's Premier Watch Destination
  </p>
  <p style="font-size:10px;color:rgba(255,255,255,0.2);margin:6px 0 0;">
    <a href="{{ $frontend }}/account"
       style="color:rgba(255,255,255,0.3);text-decoration:underline;">
      Manage preferences
    </a>
  </p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
