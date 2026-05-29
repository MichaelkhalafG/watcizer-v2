@extends('Dashboard.layouts.master')
@section('title-head')Add Variants@endsection

@section('content')
<div class="pagetitle">
    <h1>Add Variants</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('product.index') }}">Products</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.variants.index', $product) }}">Variants</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol></nav>
</div>

<section class="section">
<div class="card">
<div class="card-body pt-3">
    <h5 class="card-title">Add Variants for: {{ $product->translate('en')->product_title }}</h5>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Auto-generate section --}}
    <div class="p-3 border rounded-3 mb-4" style="background:#f0f5ff">
        <h6 class="fw-700 mb-3"><i class="bi bi-magic me-2"></i>Auto-Generate Variants</h6>
        <p class="text-muted small mb-3">Select colors and sizes to auto-generate all combinations</p>

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label fw-600">Select Colors</label>
                <div class="border rounded-3 p-2" style="max-height:200px;overflow-y:auto;background:#fff">
                    @foreach(\App\Models\NewColor::where('is_active',true)->get() as $color)
                    <label class="d-flex align-items-center gap-2 p-1 rounded hover-bg" style="cursor:pointer">
                        <input type="checkbox" class="gen-color" value="{{ $color->id }}">
                        <div style="width:18px;height:18px;border-radius:50%;background:{{ $color->hex }};border:1px solid #ddd;flex-shrink:0"></div>
                        <span class="small">{{ $color->name_en }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-600">Select Sizes</label>
                <div class="border rounded-3 p-2" style="max-height:200px;overflow-y:auto;background:#fff">
                    @foreach($sizes as $type => $group)
                    <div class="text-muted" style="font-size:10px;font-weight:700;letter-spacing:.5px;padding:4px 4px 2px;text-transform:uppercase">
                        {{ \App\Models\NewSize::TYPES[$type] ?? $type }}
                    </div>
                    @foreach($group as $size)
                    <label class="d-flex align-items-center gap-2 p-1 rounded hover-bg" style="cursor:pointer">
                        <input type="checkbox" class="gen-size" value="{{ $size->id }}">
                        <span class="small">{{ $size->name_en }}</span>
                        <span class="text-muted ms-1" style="font-size:10px">{{ $size->name_ar }}</span>
                    </label>
                    @endforeach
                    @endforeach
                </div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-primary w-100" id="generateBtn">
                    <i class="bi bi-magic me-1"></i> Generate
                </button>
            </div>
        </div>
        <div id="generate_result" class="mt-2"></div>
    </div>

    {{-- Manual Add section --}}
    <h6 class="fw-700 mb-3"><i class="bi bi-plus-circle me-2"></i>Add Manually</h6>

    <form action="{{ route('products.variants.store', $product) }}" method="POST">
    @csrf

    <div id="variants_container">
        <div class="variant-row border rounded-3 p-3 mb-2 position-relative" data-index="0">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-2"
                    onclick="removeVariant(this)" style="font-size:10px"></button>
            <div class="row g-2 align-items-end">
                <div class="col-3">
                    <label class="form-label small">Color</label>
                    <select class="form-select form-select-sm" name="variants[0][color_id]">
                        <option value="">— No Color —</option>
                        @foreach(\App\Models\NewColor::where('is_active',true)->get() as $c)
                        <option value="{{ $c->id }}">{{ $c->name_en }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-3">
                    <label class="form-label small">Size</label>
                    <select class="form-select form-select-sm" name="variants[0][size_id]">
                        <option value="">— No Size —</option>
                        @foreach(\App\Models\NewSize::where('is_active',true)->get() as $s)
                        <option value="{{ $s->id }}">{{ $s->name_en }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-2">
                    <label class="form-label small">Stock <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="variants[0][stock]" value="0" min="0">
                </div>
                <div class="col-2">
                    <label class="form-label small">Price +/-</label>
                    <input type="number" class="form-control form-control-sm" name="variants[0][price_modifier]" value="0" step="0.01">
                </div>
                <div class="col-2">
                    <label class="form-label small">SKU</label>
                    <input type="text" class="form-control form-control-sm" name="variants[0][sku]" placeholder="Optional">
                </div>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addVariantRow()">
        <i class="bi bi-plus me-1"></i> Add Row
    </button>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Save Variants</button>
        <a href="{{ route('products.variants.index', $product) }}" class="btn btn-secondary">Cancel</a>
    </div>
    </form>

</div>
</div>
</section>

<script>
var variantIndex = 1;
const colorOptions = `{!! \App\Models\NewColor::where('is_active',true)->get()->map(fn($c) => "<option value='{$c->id}'>{$c->name_en}</option>")->implode('') !!}`;
const sizeOptions  = `{!! \App\Models\NewSize::where('is_active',true)->get()->map(fn($s) => "<option value='{$s->id}'>{$s->name_en}</option>")->implode('') !!}`;

function addVariantRow(colorId='', sizeId='') {
    var i = variantIndex++;
    var html = `<div class="variant-row border rounded-3 p-3 mb-2 position-relative" data-index="${i}">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-2"
                onclick="removeVariant(this)" style="font-size:10px"></button>
        <div class="row g-2 align-items-end">
            <div class="col-3">
                <label class="form-label small">Color</label>
                <select class="form-select form-select-sm" name="variants[${i}][color_id]">
                    <option value="">— No Color —</option>
                    ${colorOptions}
                </select>
            </div>
            <div class="col-3">
                <label class="form-label small">Size</label>
                <select class="form-select form-select-sm" name="variants[${i}][size_id]">
                    <option value="">— No Size —</option>
                    ${sizeOptions}
                </select>
            </div>
            <div class="col-2">
                <label class="form-label small">Stock</label>
                <input type="number" class="form-control form-control-sm" name="variants[${i}][stock]" value="0" min="0">
            </div>
            <div class="col-2">
                <label class="form-label small">Price +/-</label>
                <input type="number" class="form-control form-control-sm" name="variants[${i}][price_modifier]" value="0" step="0.01">
            </div>
            <div class="col-2">
                <label class="form-label small">SKU</label>
                <input type="text" class="form-control form-control-sm" name="variants[${i}][sku]" placeholder="Optional">
            </div>
        </div>
    </div>`;
    document.getElementById('variants_container').insertAdjacentHTML('beforeend', html);

    // Set selected values if passed
    if(colorId || sizeId) {
        var rows = document.querySelectorAll('.variant-row');
        var last = rows[rows.length - 1];
        if(colorId) last.querySelector(`select[name$="[color_id]"]`).value = colorId;
        if(sizeId)  last.querySelector(`select[name$="[size_id]"]`).value  = sizeId;
    }
}

function removeVariant(btn) {
    var rows = document.querySelectorAll('.variant-row');
    if(rows.length <= 1) return;
    btn.closest('.variant-row').remove();
}

// Auto-generate
document.getElementById('generateBtn').addEventListener('click', function(){
    var colorIds = [...document.querySelectorAll('.gen-color:checked')].map(c => c.value);
    var sizeIds  = [...document.querySelectorAll('.gen-size:checked')].map(s => s.value);

    if(!colorIds.length && !sizeIds.length) {
        document.getElementById('generate_result').innerHTML =
            '<div class="alert alert-warning py-2 small mt-2">Please select at least one color or size</div>';
        return;
    }

    // Clear container except first row
    var container = document.getElementById('variants_container');
    container.innerHTML = '';
    variantIndex = 0;

    if(colorIds.length && sizeIds.length) {
        // Color × Size combinations
        colorIds.forEach(cId => {
            sizeIds.forEach(sId => { addVariantRow(cId, sId); });
        });
    } else if(colorIds.length) {
        colorIds.forEach(cId => addVariantRow(cId, ''));
    } else {
        sizeIds.forEach(sId => addVariantRow('', sId));
    }

    var count = document.querySelectorAll('.variant-row').length;
    document.getElementById('generate_result').innerHTML =
        `<div class="alert alert-success py-2 small mt-2">✅ Generated ${count} variants. Review and save.</div>`;
});
</script>

<style>
.hover-bg:hover { background: #f0f5ff; }
</style>
@endsection