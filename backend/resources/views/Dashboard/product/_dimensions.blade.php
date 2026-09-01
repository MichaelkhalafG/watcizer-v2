{{-- Physical dimensions for fashion items (bags/wallets/accessories). Stored in
     the extra_attributes JSON column (no watch-only size columns are reused).
     $dims = current values (create: []; edit: $product->extra_attributes). --}}
@php $dims = $dims ?? []; @endphp
<div class="col-12"><div class="attr-title">{{ trans('product.dimensions_cm') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.width_cm') }}</label>
    <input type="text" class="form-control num-only" name="width_cm"
           value="{{ old('width_cm', $dims['width_cm'] ?? '') }}" placeholder="cm">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.height_cm') }}</label>
    <input type="text" class="form-control num-only" name="height_cm"
           value="{{ old('height_cm', $dims['height_cm'] ?? '') }}" placeholder="cm">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.depth_cm') }}</label>
    <input type="text" class="form-control num-only" name="depth_cm"
           value="{{ old('depth_cm', $dims['depth_cm'] ?? '') }}" placeholder="cm">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.strap_length_cm') }}</label>
    <input type="text" class="form-control num-only" name="strap_length_cm"
           value="{{ old('strap_length_cm', $dims['strap_length_cm'] ?? '') }}" placeholder="cm">
</div>
