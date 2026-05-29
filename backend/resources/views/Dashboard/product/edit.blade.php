@extends('Dashboard.layouts.master')
@section('title-head'){{ trans('product.edit_product') }}@endsection

@section('content')
<div class="row">
    <div class="pagetitle col-6">
        <h1>{{ trans('product.edit_product') }}</h1>
        <nav><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
            <li class="breadcrumb-item">{{ trans('sidebar.product') }}</li>
            <li class="breadcrumb-item active">{{ trans('product.edit_product') }}</li>
        </ol></nav>
    </div>
</div>

<section class="section">
<div class="row"><div class="col-lg-12"><div class="card"><div class="card-body">
<h5 class="card-title">{{ trans('product.edit_product') }}</h5>

<form action="{{ route('product.update', $product->id) }}" class="row g-3" method="POST" enctype="multipart/form-data">
@csrf
@method('PUT')

{{-- ── BASIC ──────────────────────────────────────────── --}}
<div class="col-12"><div class="sec-title">Basic Information</div></div>

<div class="col-6">
    <label class="form-label">{{ trans('product.product_title') }} (AR) <span class="req">*</span></label>
    <input type="text" class="form-control" name="product_title[ar]"
           value="{{ old('product_title.ar', $product->translate('ar')->product_title) }}">
    @error('product_title.ar')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.product_title') }} (EN) <span class="req">*</span></label>
    <input type="text" class="form-control" name="product_title[en]"
           value="{{ old('product_title.en', $product->translate('en')->product_title) }}">
    @error('product_title.en')<p class="err">{{ $message }}</p>@enderror
</div>

{{-- ── CLASSIFICATION ──────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Classification</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.sub_type_id') }}</label>
    <select class="form-select select2" name="sub_type_id">
        <option value="">Select…</option>
        @foreach($sub_type as $item)
            <option value="{{ $item->id }}" @selected(old('sub_type_id', $product->sub_type_id) == $item->id)>{{ $item->sub_type_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.category_type_id') }}</label>
    <select class="form-select select2" name="category_type_id">
        <option value="">Select…</option>
        @foreach($category_type as $item)
            <option value="{{ $item->id }}" @selected(old('category_type_id', $product->category_type_id) == $item->id)>{{ $item->category_type_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.brand_id') }} <span class="req">*</span></label>
    <select class="form-select select2" name="brand_id">
        <option value="">Select…</option>
        @foreach($brand as $item)
            <option value="{{ $item->id }}" @selected(old('brand_id', $product->brand_id) == $item->id)>{{ $item->brand_name }}</option>
        @endforeach
    </select>
    @error('brand_id')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.grade_id') }}</label>
    <select class="form-select select2" name="grade_id">
        <option value="">Select…</option>
        @foreach($grade as $item)
            <option value="{{ $item->id }}" @selected(old('grade_id', $product->grade_id) == $item->id)>{{ $item->grade_name }}</option>
        @endforeach
    </select>
</div>

<div class="col-6">
    <label class="form-label">{{ trans('product.gender_id') }}</label>
    <select class="form-select select2" name="gender_id[]" multiple>
        @foreach($gender as $item)
            <option value="{{ $item->id }}" @selected(in_array($item->id, old('gender_id', $product->gender->pluck('id')->toArray())))>{{ $item->gender_name }}</option>
        @endforeach
    </select>
</div>

{{-- ── FEATURES — select + manual text ───────────────── --}}
<div class="col-6">
    <label class="form-label">{{ trans('product.feature_id') }}
        <small class="text-muted ms-1">(اختر من القائمة أو اكتب يدوياً)</small>
    </label>
    <select class="form-select select2" name="feature_id[]" multiple id="feature_select">
        @foreach($feature as $item)
            <option value="{{ $item->id }}" @selected(in_array($item->id, old('feature_id', $product->feature->pluck('id')->toArray())))>{{ $item->feature_name }}</option>
        @endforeach
    </select>
    {{-- Manual feature input --}}
    <div class="input-group mt-2">
        <input type="text" class="form-control form-control-sm" id="new_feature_text"
               placeholder="اكتب feature جديد وادوس Add">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addManualFeature()">
            <i class="bi bi-plus"></i> Add
        </button>
    </div>
    <small class="text-muted" style="font-size:11px">الـ features اليدوية هتتحفظ كـ search keywords</small>
    <input type="hidden" name="manual_features" id="manual_features_input"
           value="{{ old('manual_features', '') }}">
</div>

{{-- ── PRICING ──────────────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Pricing & Stock</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.purchase_price') }} <span class="req">*</span></label>
    <input class="form-control" name="purchase_price" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"
           value="{{ old('purchase_price', $product->purchase_price) }}">
    @error('purchase_price')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.selling_price') }} <span class="req">*</span></label>
    <input class="form-control" name="selling_price" id="edit_selling_price"
           oninput="this.value=this.value.replace(/[^0-9.]/g,'');calcDiscount()"
           value="{{ old('selling_price', $product->selling_price) }}">
    @error('selling_price')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.sale_price_after_discount') }}</label>
    <input class="form-control" name="sale_price_after_discount" id="edit_sale_price"
           oninput="this.value=this.value.replace(/[^0-9.]/g,'');calcDiscount()"
           value="{{ old('sale_price_after_discount', $product->sale_price_after_discount) }}">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.percentage_discount') }} (%)
        <span class="badge bg-success-subtle text-success" style="font-size:10px">Auto</span>
    </label>
    <div class="input-group">
        <input class="form-control" name="percentage_discount" id="edit_pct_discount"
               value="{{ old('percentage_discount', $product->percentage_discount) }}"
               readonly style="background:#f8f9fa">
        <span class="input-group-text">%</span>
    </div>
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.stock') }} <span class="req">*</span></label>
    <input class="form-control" name="stock" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"
           value="{{ old('stock', $product->stock) }}">
    @error('stock')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.market_stock') }}</label>
    <input class="form-control" name="market_stock" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"
           value="{{ old('market_stock', $product->market_stock) }}">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.wa_code') }} <span class="req">*</span></label>
    <input type="text" class="form-control" name="wa_code" value="{{ old('wa_code', $product->wa_code) }}">
    @error('wa_code')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.sku_unique') }}</label>
    <input type="text" class="form-control" name="sku_unique" value="{{ old('sku_unique', $product->sku_unique) }}">
</div>

{{-- ── WATCH ATTRIBUTES ─────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Watch Attributes</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.dial_color_id') }}</label>
    <select class="form-select colorSelect" name="dial_color_id[]" multiple>
        @foreach($color as $item)
            <option value="{{ $item->id }}" data-color="{{ $item->color_value }}"
                @selected(in_array($item->id, old('dial_color_id', $product->dialColor->pluck('id')->toArray())))>
                {{ $item->color_name ?? trans('product.none_name') }}
            </option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_color_id') }}</label>
    <select class="form-select colorSelect" name="band_color_id[]" multiple>
        @foreach($color as $item)
            <option value="{{ $item->id }}" data-color="{{ $item->color_value }}"
                @selected(in_array($item->id, old('band_color_id', $product->bandColor->pluck('id')->toArray())))>
                {{ $item->color_name ?? trans('product.none_name') }}
            </option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.watch_movement_id') }}</label>
    <select class="form-select select2" name="watch_movement_id">
        <option value="">Select…</option>
        @foreach($movement_type as $item)
            <option value="{{ $item->id }}" @selected(old('watch_movement_id', $product->watch_movement_id) == $item->id)>{{ $item->movement_type_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_material_id') }}</label>
    <select class="form-select select2" name="band_material_id">
        <option value="">Select…</option>
        @foreach($material as $item)
            <option value="{{ $item->id }}" @selected(old('band_material_id', $product->band_material_id) == $item->id)>{{ $item->material_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_case_material_id') }}</label>
    <select class="form-select select2" name="dial_case_material_id">
        <option value="">Select…</option>
        @foreach($material as $item)
            <option value="{{ $item->id }}" @selected(old('dial_case_material_id', $product->dial_case_material_id) == $item->id)>{{ $item->material_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_glass_material_id') }}</label>
    <select class="form-select select2" name="dial_glass_material_id">
        <option value="">Select…</option>
        @foreach($material as $item)
            <option value="{{ $item->id }}" @selected(old('dial_glass_material_id', $product->dial_glass_material_id) == $item->id)>{{ $item->material_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.case_shape_id') }}</label>
    <select class="form-select select2" name="case_shape_id">
        <option value="">Select…</option>
        @foreach($shape as $item)
            <option value="{{ $item->id }}" @selected(old('case_shape_id', $product->case_shape_id) == $item->id)>{{ $item->shape_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_closure_id') }}</label>
    <select class="form-select select2" name="band_closure_id">
        <option value="">Select…</option>
        @foreach($closure_type as $item)
            <option value="{{ $item->id }}" @selected(old('band_closure_id', $product->band_closure_id) == $item->id)>{{ $item->closure_type_name }}</option>
        @endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_display_type_id') }}</label>
    <select class="form-select select2" name="dial_display_type_id">
        <option value="">Select…</option>
        @foreach($display_type as $item)
            <option value="{{ $item->id }}" @selected(old('dial_display_type_id', $product->dial_display_type_id) == $item->id)>{{ $item->display_type_name }}</option>
        @endforeach
    </select>
</div>

{{-- Sizes --}}
@foreach([
    ['case_size','case_size_type_id','product.case_size'],
    ['water_resistance','water_resistance_size_type_id','product.water_resistance'],
    ['band_width','band_width_size_type_id','product.band_width'],
    ['case_thickness','case_thickness_size_type_id','product.case_thickness'],
    ['band_length','band_size_type_id','product.band_length'],
    ['watch_height','watch_height_size_type_id','product.watch_height'],
    ['watch_width','watch_width_size_type_id','product.watch_width'],
    ['watch_length','watch_length_size_type_id','product.watch_length'],
] as [$val, $unit, $lbl])
<div class="col-3">
    <label class="form-label">{{ trans($lbl) }}</label>
    <div class="input-group">
        <input class="form-control" name="{{ $val }}" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"
               value="{{ old($val, $product->$val) }}" placeholder="0">
        <select class="form-select" name="{{ $unit }}" style="max-width:90px">
            <option value="">Unit</option>
            @foreach($size_type as $st)
                <option value="{{ $st->id }}" @selected(old($unit, $product->$unit) == $st->id)>{{ $st->size_type_name }}</option>
            @endforeach
        </select>
    </div>
</div>
@endforeach

<div class="col-3">
    <label class="form-label">{{ trans('product.interchangeable_dial') }}</label>
    <select class="form-select" name="interchangeable_dial">
        <option value="">Select…</option>
        <option value="1" @selected(old('interchangeable_dial', $product->interchangeable_dial) == 1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('interchangeable_dial', $product->interchangeable_dial) == '0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.interchangeable_strap') }}</label>
    <select class="form-select" name="interchangeable_strap">
        <option value="">Select…</option>
        <option value="1" @selected(old('interchangeable_strap', $product->interchangeable_strap) == 1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('interchangeable_strap', $product->interchangeable_strap) == '0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.watch_box') }}</label>
    <select class="form-select" name="watch_box">
        <option value="">Select…</option>
        <option value="1" @selected(old('watch_box', $product->watch_box) == 1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('watch_box', $product->watch_box) == '0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.warranty_years') }}</label>
    <input type="text" class="form-control" name="warranty_years" value="{{ old('warranty_years', $product->warranty_years) }}">
</div>

<div class="col-4">
    <label class="form-label">{{ trans('product.model_name') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="model_name[ar]" placeholder="AR"
               value="{{ old('model_name.ar', $product->translate('ar')->model_name) }}">
        <input type="text" class="form-control" name="model_name[en]" placeholder="EN"
               value="{{ old('model_name.en', $product->translate('en')->model_name) }}">
    </div>
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.model_number') }}</label>
    <input type="text" class="form-control" name="model_number" value="{{ old('model_number', $product->model_number) }}">
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.country') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="country[ar]" placeholder="AR"
               value="{{ old('country.ar', $product->translate('ar')->country) }}">
        <input type="text" class="form-control" name="country[en]" placeholder="EN"
               value="{{ old('country.en', $product->translate('en')->country) }}">
    </div>
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.stone') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="stone[ar]" placeholder="AR"
               value="{{ old('stone.ar', $product->translate('ar')->stone) }}">
        <input type="text" class="form-control" name="stone[en]" placeholder="EN"
               value="{{ old('stone.en', $product->translate('en')->stone) }}">
    </div>
</div>

{{-- ── DESCRIPTIONS ─────────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Descriptions</div></div>
<div class="col-6">
    <label class="form-label">{{ trans('product.short_description') }} (AR) <span class="req">*</span></label>
    <textarea class="form-control" name="short_description[ar]" rows="2">{{ old('short_description.ar', $product->translate('ar')->short_description) }}</textarea>
    @error('short_description.ar')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.short_description') }} (EN) <span class="req">*</span></label>
    <textarea class="form-control" name="short_description[en]" rows="2">{{ old('short_description.en', $product->translate('en')->short_description) }}</textarea>
    @error('short_description.en')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.long_description') }} (AR) <span class="req">*</span></label>
    <textarea class="form-control" name="long_description[ar]" rows="3">{{ old('long_description.ar', $product->translate('ar')->long_description) }}</textarea>
    @error('long_description.ar')<p class="err">{{ $message }}</p>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.long_description') }} (EN) <span class="req">*</span></label>
    <textarea class="form-control" name="long_description[en]" rows="3">{{ old('long_description.en', $product->translate('en')->long_description) }}</textarea>
    @error('long_description.en')<p class="err">{{ $message }}</p>@enderror
</div>

<div class="col-12">
    <label class="form-label">{{ trans('product.search_keywords') }}</label>
    <input type="text" class="form-control" name="search_keywords" value="{{ old('search_keywords', $product->search_keywords) }}">
</div>

{{-- ── MEDIA ────────────────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Media & Images</div></div>

{{-- Current Main Image ✅ --}}
<div class="col-12">
    <div class="img-section-label">
        <i class="bi bi-image me-1"></i> Main Image
        @if($product->image)
            <span class="badge bg-success ms-2">Current image</span>
        @endif
    </div>

    @if($product->image)
    <div class="d-flex align-items-start gap-3 mb-3">
        {{-- Show current image ✅ --}}
        <div class="current-img-wrap">
            <img src="{{ asset('Uploads_Images/Product/' . $product->image) }}"
                 alt="{{ $product->translate('en')->product_title }}"
                 class="current-img-thumb"
                 id="current_main_img">
            <div class="mt-1 text-center">
                <small class="text-muted" style="font-size:10px">Current</small>
            </div>
        </div>
        {{-- Arrow --}}
        <div class="d-flex align-items-center" style="margin-top:30px;color:#aaa;font-size:20px">→</div>
        {{-- New image upload --}}
        <div class="flex-grow-1">
            <div class="main-upload-box-sm" id="main-drop-edit" onclick="document.getElementById('image_input').click()">
                <div id="new-img-placeholder">
                    <i class="bi bi-cloud-upload" style="font-size:1.5rem;opacity:.4"></i>
                    <p class="mb-0 mt-1" style="font-size:12px">Click to replace image</p>
                    <small class="text-muted" style="font-size:10px">JPG · PNG · WEBP — max 5MB</small>
                </div>
                <img id="new_img_preview" src="#" class="d-none current-img-thumb">
                <button type="button" id="new-img-remove" class="btn-remove-img-sm d-none"
                        onclick="removeNewImg(event)"><i class="bi bi-x"></i></button>
            </div>
            <input type="file" class="d-none" name="image" id="image_input" accept="image/*">
        </div>
    </div>
    @else
    <div class="main-upload-box-sm" id="main-drop-edit" onclick="document.getElementById('image_input').click()">
        <div>
            <i class="bi bi-cloud-upload" style="font-size:1.5rem;opacity:.4"></i>
            <p class="mb-0 mt-1" style="font-size:12px">Click or drop image</p>
        </div>
        <img id="new_img_preview" src="#" class="d-none current-img-thumb">
        <button type="button" id="new-img-remove" class="btn-remove-img-sm d-none"
                onclick="removeNewImg(event)"><i class="bi bi-x"></i></button>
    </div>
    <input type="file" class="d-none" name="image" id="image_input" accept="image/*">
    @endif
    @error('image')<p class="err">{{ $message }}</p>@enderror
</div>

{{-- Gallery Images ✅ --}}
<div class="col-12 mt-2">
    <div class="img-section-label">
        <i class="bi bi-images me-1"></i> Gallery Images
        <span class="badge bg-secondary ms-1">Optional</span>
        <small class="text-muted ms-2">Up to 10 images shown inside product page</small>
        <span id="gallery-count-edit" class="badge bg-light text-dark ms-2">
            {{ ($product->productImages ?? collect())->count() }} / 10
        </span>
    </div>

    {{-- Existing gallery images --}}
    @php $galleryImages = $product->productImages ?? collect(); @endphp
    @if($galleryImages->count() > 0)
    <div class="gallery-grid mb-3" id="existing-gallery">
        @foreach($galleryImages->sortBy('sort') as $img)
        <div class="gal-item" id="existing-img-{{ $img->id }}">
            <img src="{{ asset('Uploads_Images/Product_image/' . $img->image) }}"
                 alt="gallery image">
            {{-- Delete button --}}
            <button type="button" class="gal-remove"
                    onclick="deleteGalleryImage({{ $img->id }}, this)"
                    title="Delete">
                <i class="bi bi-x"></i>
            </button>
            @if($img->is_cover)
            <span class="gal-cover-badge">★</span>
            @endif
            <span class="gal-n">{{ $loop->iteration }}</span>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Add new gallery images --}}
    @if($galleryImages->count() < 10)
    <div class="gallery-drop-zone" id="gallery-drop-edit"
         onclick="document.getElementById('gallery_input_edit').click()">
        <i class="bi bi-plus-circle" style="font-size:1.4rem;opacity:.5"></i>
        <p class="mb-0 mt-1">Add more gallery images</p>
        <small class="text-muted">JPG · PNG · WEBP — max 5MB each</small>
    </div>
    <input type="file" class="d-none" id="gallery_input_edit" accept="image/*" multiple>
    <div id="gallery-base64-inputs-edit"></div>
    <div class="gallery-grid mt-3" id="new-gallery-grid"></div>
    @endif
</div>

{{-- ── SETTINGS ─────────────────────────────────────────── --}}
<div class="col-12 mt-1"><div class="sec-title">Settings</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.active') }} <span class="req">*</span></label>
    <select class="form-select" name="active">
        <option value="1" @selected(old('active', $product->active) == 1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('active', $product->active) == '0')>{{ trans('product.no') }}</option>
    </select>
</div>

{{-- Submit --}}
<div class="col-12 text-center mt-4">
    <a href="{{ route('product.index') }}" class="btn btn-secondary px-4">{{ trans('mainBtn.close_btn') }}</a>
    <button type="submit" class="btn btn-primary px-5 ms-2">{{ trans('mainBtn.edit') }}</button>
</div>

</form>
</div></div></div></div>
</section>

<style>
.sec-title{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#8a9ab0;border-bottom:1px solid #eef0f3;padding-bottom:5px;margin-bottom:4px}
.req{color:#dc3545}.err{color:#dc3545;font-size:12px;margin-top:2px}
.img-section-label{font-size:12px;font-weight:600;color:#555;margin-bottom:8px;display:flex;align-items:center;flex-wrap:wrap;gap:4px}
/* Current image */
.current-img-wrap{flex-shrink:0}
.current-img-thumb{width:100px;height:100px;object-fit:cover;border-radius:8px;border:2px solid #e0e5ec;display:block}
/* Upload box small */
.main-upload-box-sm{position:relative;border:2px dashed #c8d4e0;border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:border-color .2s;min-height:100px;display:flex;align-items:center;justify-content:center}
.main-upload-box-sm:hover{border-color:#378ADD}
.btn-remove-img-sm{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;border:none;background:rgba(220,53,69,.85);color:#fff;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center}
/* Gallery */
.gallery-drop-zone{border:2px dashed #c8d4e0;border-radius:10px;padding:12px;text-align:center;cursor:pointer;transition:border-color .2s;color:#888}
.gallery-drop-zone:hover{border-color:#378ADD;color:#378ADD}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px}
.gal-item{position:relative;border-radius:8px;overflow:hidden;border:1.5px solid #e0e5ec;aspect-ratio:1;background:#f8f9fa}
.gal-item img{width:100%;height:100%;object-fit:cover;display:block}
.gal-item .gal-remove{position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,.85);color:#fff;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.gal-item .gal-n{position:absolute;bottom:2px;left:3px;font-size:9px;color:#fff;background:rgba(0,0,0,.5);padding:1px 4px;border-radius:3px}
.gal-cover-badge{position:absolute;top:3px;left:3px;font-size:11px;color:#ffd700;text-shadow:0 0 3px rgba(0,0,0,.5)}
</style>
@endsection

@section('script')
<script>
$(document).ready(function(){

    // ── Select2 ──────────────────────────────────────────
    $('.select2').select2({ theme:'bootstrap-5', width:'100%' });

    $('.colorSelect').select2({
        theme: 'bootstrap-5',
        templateResult: fmtColor,
        templateSelection: fmtColor,
    });

    function fmtColor(s){
        if(!s.id) return s.text;
        var c = $(s.element).data('color');
        return $('<span style="background:'+c+';color:#fff;display:block;padding:3px 10px;border-radius:4px">'+s.text+'</span>');
    }

    // ── Auto discount ─────────────────────────────────────
    window.calcDiscount = function(){
        var sell = parseFloat($('#edit_selling_price').val()) || 0;
        var sale = parseFloat($('#edit_sale_price').val()) || 0;
        if(sell > 0 && sale > 0 && sale < sell){
            $('#edit_pct_discount').val(((sell - sale) / sell * 100).toFixed(1));
        } else {
            $('#edit_pct_discount').val('');
        }
    };

    // ── Manual features ───────────────────────────────────
    var manualFeatures = [];

    window.addManualFeature = function(){
        var text = $('#new_feature_text').val().trim();
        if(!text) return;
        manualFeatures.push(text);
        $('#new_feature_text').val('');
        updateManualFeaturesInput();
        // Show badge
        var badge = $('<span class="badge bg-primary me-1 mb-1 manual-feat">'
            + text
            + ' <button type="button" onclick="removeManualFeature(this,\''+text+'\')" style="background:none;border:none;color:#fff;font-size:11px;cursor:pointer">×</button>'
            + '</span>');
        $('#manual_features_display').append(badge);
    };

    window.removeManualFeature = function(btn, text){
        manualFeatures = manualFeatures.filter(f => f !== text);
        updateManualFeaturesInput();
        $(btn).closest('.manual-feat').remove();
    };

    function updateManualFeaturesInput(){
        $('#manual_features_input').val(manualFeatures.join(','));
    }

    // Display area for manual features
    $('#feature_select').after('<div id="manual_features_display" class="mt-1 d-flex flex-wrap gap-1"></div>');

    // ── Main image upload ─────────────────────────────────
    var dropEdit = document.getElementById('main-drop-edit');
    if(dropEdit){
        dropEdit.addEventListener('dragover', function(e){e.preventDefault();this.style.borderColor='#378ADD';});
        dropEdit.addEventListener('dragleave', function(){this.style.borderColor='';});
        dropEdit.addEventListener('drop', function(e){
            e.preventDefault();this.style.borderColor='';
            var f = e.dataTransfer.files[0];
            if(f && f.type.startsWith('image/')){
                setNewMainImg(f);
                var dt = new DataTransfer(); dt.items.add(f);
                document.getElementById('image_input').files = dt.files;
            }
        });
    }

    document.getElementById('image_input')?.addEventListener('change', function(){
        if(this.files[0]) setNewMainImg(this.files[0]);
    });

    function setNewMainImg(file){
        var reader = new FileReader();
        reader.onload = function(e){
            $('#new_img_preview').attr('src', e.target.result).removeClass('d-none');
            $('#new-img-placeholder').addClass('d-none');
            $('#new-img-remove').removeClass('d-none');
        };
        reader.readAsDataURL(file);
    }

    window.removeNewImg = function(e){
        e.stopPropagation();
        document.getElementById('image_input').value = '';
        $('#new_img_preview').addClass('d-none');
        $('#new-img-placeholder').removeClass('d-none');
        $('#new-img-remove').addClass('d-none');
    };

    // ── Gallery images — new upload ───────────────────────
    var MAX_GAL = 10;
    var existingCount = {{ ($product->productImages ?? collect())->count() }};
    var gFiles = [];

    var galDrop = document.getElementById('gallery-drop-edit');
    if(galDrop){
        galDrop.addEventListener('dragover', function(e){e.preventDefault();this.classList.add('drag');});
        galDrop.addEventListener('dragleave', function(){this.classList.remove('drag');});
        galDrop.addEventListener('drop', function(e){
            e.preventDefault();this.classList.remove('drag');
            addGalFiles(e.dataTransfer.files);
        });
    }

    document.getElementById('gallery_input_edit')?.addEventListener('change', function(){
        addGalFiles(this.files);
        this.value = '';
    });

    function addGalFiles(files){
        for(var i = 0; i < files.length; i++){
            if((existingCount + gFiles.length) >= MAX_GAL) break;
            if(!files[i].type.startsWith('image/')) continue;
            gFiles.push(files[i]);
            renderNewGal(files[i], gFiles.length - 1);
        }
        syncGal(); updateGalCount();
    }

    function renderNewGal(file, idx){
        var g = document.getElementById('new-gallery-grid');
        if(!g) return;
        var d = document.createElement('div');
        d.className = 'gal-item'; d.dataset.idx = idx;
        var r = new FileReader();
        r.onload = function(e){
            d.innerHTML = '<img src="'+e.target.result+'">'
                +'<button type="button" class="gal-remove" onclick="removeNewGal(this)"><i class="bi bi-x"></i></button>'
                +'<span class="gal-n">'+(existingCount + idx + 1)+'</span>';
        };
        r.readAsDataURL(file);
        g.appendChild(d);
    }

    window.removeNewGal = function(btn){
        var item = btn.closest('.gal-item');
        gFiles.splice(parseInt(item.dataset.idx), 1);
        document.getElementById('new-gallery-grid').innerHTML = '';
        gFiles.forEach(function(f, i){ renderNewGal(f, i); });
        syncGal(); updateGalCount();
    };

    window.syncGal = function(){
        var container = document.getElementById('gallery-base64-inputs-edit');
        container.innerHTML = '';
        gFiles.forEach(function(f, i){
            var r = new FileReader();
            r.onload = function(e){
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'gallery_base64[]';
                inp.value = e.target.result;
                container.appendChild(inp);
            };
            r.readAsDataURL(f);
        });
    };

    function updateGalCount(){
        var total = existingCount + gFiles.length;
        $('#gallery-count-edit').text(total + ' / ' + MAX_GAL);
    }

    // ── Delete existing gallery image via AJAX ────────────
    // ✅ تم تعديل الرابط هنا لاستخدام route name بدلاً من الـ locale prefix
    window.deleteGalleryImage = function(imgId, btn){
    if(!confirm('Delete this image?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route("product.images.destroy", ":id") }}'.replace(':id', imgId);
    form.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}">'
                   + '<input type="hidden" name="_method" value="DELETE">';
    document.body.appendChild(form);
    form.submit();
};

});
</script>
@endsection