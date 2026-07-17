@php($logo = $logo ?? config('watchizer.brand.logo'))
{{-- Brand bar: logo on black + gold accent line. --}}
<tr>
    <td style="background:#111111;padding:24px 32px;text-align:center;">
        <img src="{{ $logo }}" alt="Watchizer" width="130" style="display:block;margin:0 auto;max-width:130px;height:auto;border:0;outline:none;text-decoration:none;">
    </td>
</tr>
<tr>
    <td style="background:#C8A45C;background:linear-gradient(to right,#C8A45C,#E8D5A3,#C8A45C);height:3px;font-size:0;line-height:0;">&nbsp;</td>
</tr>
