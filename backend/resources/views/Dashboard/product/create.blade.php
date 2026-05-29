@extends('Dashboard.layouts.master')
@section('title-head'){{ trans('product.add') }}@endsection

@section('content')
<div class="row">
    <div class="pagetitle col-6">
        <h1>{{ trans('product.add') }}</h1>
        <nav><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
            <li class="breadcrumb-item active">{{ trans('product.add') }}</li>
        </ol></nav>
    </div>
</div>

<section class="section">
<div class="row"><div class="col-lg-12"><div class="card"><div class="card-body">
<h5 class="card-title d-flex align-items-center justify-content-between">
    {{ trans('product.add') }}
</h5>

<form action="{{ route('product.store') }}" method="POST" class="row g-3" enctype="multipart/form-data" id="product-form">
@csrf

{{-- ═══════════════════════════════════════════
     § 1  BASIC INFO
═══════════════════════════════════════════ --}}
<div class="col-12"><div class="sec-title">{{ trans('product.section_basic') }}</div></div>

<div class="col-6">
    <label class="form-label">{{ trans('product.product_title') }} (AR) <span class="req">*</span></label>
    <input type="text" class="form-control" name="product_title[ar]" id="title_ar" value="{{ old('product_title.ar') }}"
           oninput="autoSEO()">
    @error('product_title.ar')<div class="err">{{ $message }}</div>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.product_title') }} (EN) <span class="req">*</span></label>
    <input type="text" class="form-control" name="product_title[en]" id="title_en" value="{{ old('product_title.en') }}"
           oninput="autoSEO()">
    @error('product_title.en')<div class="err">{{ $message }}</div>@enderror
</div>

{{-- ═══════════════════════════════════════════
     § 2  CATEGORY — 3 LEVELS
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_classification') }}</div></div>

<div class="col-12">
    <div id="cat-bc" class="cat-bc d-none">
        <i class="bi bi-diagram-3"></i>&nbsp;
        <span id="bc-1"></span><span id="bc-sep1" class="bc-arr d-none"> › </span>
        <span id="bc-2" class="d-none"></span><span id="bc-sep2" class="bc-arr d-none"> › </span>
        <span id="bc-3" class="d-none"></span>
    </div>
</div>

<div class="col-4">
    <label class="form-label">{{ trans('product.main_category') }} <span class="req">*</span> <span class="lvl-badge bg-primary">L1</span></label>
    <select class="form-select select2" name="main_category_id" id="cat1">
        <option value="">{{ trans('product.select') }}…</option>
        @foreach($main_categories as $c)
            <option value="{{ $c->id }}" data-slug="{{ $c->slug }}"
                    data-name="{{ $c->translate('en')->name ?? $c->name }}"
                    @selected(old('main_category_id') == $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    @error('main_category_id')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-4">
    <label class="form-label">{{ trans('product.sub_category') }} <span class="lvl-badge bg-secondary">L2</span> <span class="multi-hint">multi</span></label>
    <select class="form-select select2" name="sub_category_id[]" id="cat2" multiple disabled>
        <option value="">— {{ trans('product.select_main_first') }} —</option>
    </select>
    <div id="load2" class="ldr d-none"><div class="spinner-border spinner-border-sm"></div></div>
    @error('sub_category_id')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-4">
    <label class="form-label">{{ trans('product.product_type') }} <span class="lvl-badge bg-info">L3</span> <span class="multi-hint">multi</span></label>
    <select class="form-select select2" name="product_type_id[]" id="cat3" multiple disabled>
        <option value="">— {{ trans('product.select_sub_first') }} —</option>
    </select>
    <div id="load3" class="ldr d-none"><div class="spinner-border spinner-border-sm"></div></div>
    @error('product_type_id')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-6">
    <label class="form-label">{{ trans('product.gender_id') }} <span class="req">*</span></label>
    <select class="form-select select2" name="gender_id[]" multiple>
        @foreach($gender as $item)
            <option value="{{ $item->id }}" @selected(in_array($item->id, old('gender_id',[])))>{{ $item->gender_name }}</option>
        @endforeach
    </select>
    @error('gender_id')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-6">
    <label class="form-label">{{ trans('product.type_id') }}</label>
    <select class="form-select select2" name="sub_type_id">
        <option value="">{{ trans('product.select') }}…</option>
        @foreach($sub_type as $item)
            <option value="{{ $item->id }}" @selected(old('sub_type_id') == $item->id)>{{ $item->sub_type_name }}</option>
        @endforeach
    </select>
</div>

{{-- ═══════════════════════════════════════════
     § 3  BRAND + PRICING
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_pricing') }}</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.brand_id') }} <span class="req">*</span></label>
    <select class="form-select select2" name="brand_id" id="sel_brand" onchange="autoSEO()">
        <option value="">{{ trans('product.select') }}…</option>
        @foreach($brand as $item)
            <option value="{{ $item->id }}" data-name="{{ $item->brand_name }}" @selected(old('brand_id') == $item->id)>{{ $item->brand_name }}</option>
        @endforeach
    </select>
    @error('brand_id')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.purchase_price') }} <span class="req">*</span></label>
    <input class="form-control num-only" name="purchase_price" value="{{ old('purchase_price') }}" placeholder="0.00">
    @error('purchase_price')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.selling_price') }} <span class="req">*</span></label>
    <input class="form-control num-only" name="selling_price" id="selling_price" value="{{ old('selling_price') }}" placeholder="0.00">
    @error('selling_price')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.sale_price_after_discount') }}</label>
    <input class="form-control num-only" name="sale_price_after_discount" id="sale_price" value="{{ old('sale_price_after_discount') }}" placeholder="0.00">
</div>

<div class="col-3">
    <label class="form-label">
        {{ trans('product.percentage_discount') }} (%)
        <span class="badge bg-success-subtle text-success ms-1" style="font-size:10px">Auto</span>
    </label>
    <div class="input-group">
        <input class="form-control" name="percentage_discount" id="pct_discount" value="{{ old('percentage_discount') }}" placeholder="0" readonly style="background:#f8f9fa">
        <span class="input-group-text">%</span>
    </div>
    <small class="text-muted" style="font-size:10px">((Selling − Sale) ÷ Selling) × 100</small>
</div>

{{-- ═══════════════════════════════════════════
     § 4  STOCK
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_stock') }}</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.stock') }} <span class="req">*</span></label>
    <input class="form-control num-only" name="stock" id="stock_field" value="{{ old('stock') }}" placeholder="0" oninput="checkLowStock()">
    @error('stock')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.market_stock') }}</label>
    <input class="form-control num-only" name="market_stock" value="{{ old('market_stock') }}" placeholder="0">
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.low_stock_threshold') }}</label>
    <input class="form-control num-only" name="low_stock_threshold" id="low_stock_threshold"
           value="{{ old('low_stock_threshold', 5) }}" placeholder="5" oninput="checkLowStock()">
    <small class="text-muted" style="font-size:10px">{{ trans('product.low_stock_hint') }}</small>
</div>

<div class="col-3 d-flex align-items-end">
    <div id="low-stock-alert" class="alert alert-warning py-2 px-3 mb-0 w-100 d-none" style="font-size:12px">
        <i class="bi bi-exclamation-triangle me-1"></i> {{ trans('product.low_stock_warning') }}
    </div>
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.wa_code') }} <span class="req">*</span></label>
    <input type="text" class="form-control" name="wa_code" value="{{ old('wa_code') }}">
    @error('wa_code')<div class="err">{{ $message }}</div>@enderror
</div>

<div class="col-3">
    <label class="form-label">{{ trans('product.sku_unique') }}</label>
    <div class="input-group">
        <input type="text" class="form-control" name="sku_unique" id="sku_field" value="{{ old('sku_unique') }}">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="genSKU()" title="Auto-generate SKU">
            <i class="bi bi-magic"></i>
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════
     § 5  DYNAMIC ATTRIBUTES
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1">
    <div class="sec-title">{{ trans('product.section_attributes') }} <small class="fw-normal text-muted ms-2">{{ trans('product.attributes_hint') }}</small></div>
</div>

{{-- ── WATCHES ── --}}
<div class="cat-fields d-none" data-cat="watches,smart-watches,wall-clocks">
<div class="row g-3">
<div class="col-12"><div class="attr-title"><i class="bi bi-watch me-1"></i>{{ trans('product.watch_attributes') }}</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.dial_color_id') }}</label>
    <select class="form-select colorSel" name="dial_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}" @selected(in_array($c->id,old('dial_color_id',[])))>{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_color_id') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}" @selected(in_array($c->id,old('band_color_id',[])))>{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.watch_movement_id') }}</label>
    <select class="form-select select2" name="watch_movement_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($movement_type as $item)<option value="{{ $item->id }}" @selected(old('watch_movement_id') == $item->id)>{{ $item->movement_type_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_material_id') }}</label>
    <select class="form-select select2" name="band_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('band_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_case_material_id') }}</label>
    <select class="form-select select2" name="dial_case_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('dial_case_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_glass_material_id') }}
        <small class="text-muted">(Sapphire/Mineral)</small>
    </label>
    <select class="form-select select2" name="dial_glass_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('dial_glass_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.case_shape_id') }}</label>
    <select class="form-select select2" name="case_shape_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($shape as $item)<option value="{{ $item->id }}" @selected(old('case_shape_id') == $item->id)>{{ $item->shape_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_closure_id') }}</label>
    <select class="form-select select2" name="band_closure_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($closure_type as $item)<option value="{{ $item->id }}" @selected(old('band_closure_id') == $item->id)>{{ $item->closure_type_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.dial_display_type_id') }}</label>
    <select class="form-select select2" name="dial_display_type_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($display_type as $item)<option value="{{ $item->id }}" @selected(old('dial_display_type_id') == $item->id)>{{ $item->display_type_name }}</option>@endforeach
    </select>
</div>

{{-- Sizes --}}
@foreach([['case_size','case_size_type_id','product.case_size'],['water_resistance','water_resistance_size_type_id','product.water_resistance'],['band_width','band_width_size_type_id','product.band_width'],['case_thickness','case_thickness_size_type_id','product.case_thickness']] as [$v,$u,$l])
<div class="col-3">
    <label class="form-label">{{ trans($l) }}</label>
    <div class="input-group">
        <input class="form-control num-only" name="{{ $v }}" value="{{ old($v) }}" placeholder="0">
        <select class="form-select" name="{{ $u }}" style="max-width:80px">
            <option value="">{{ trans('product.unit') }}</option>
            @foreach($size_type as $st)<option value="{{ $st->id }}" @selected(old($u) == $st->id)>{{ $st->size_type_name }}</option>@endforeach
        </select>
    </div>
</div>
@endforeach

<div class="col-3">
    <label class="form-label">{{ trans('product.interchangeable_dial') }}</label>
    <select class="form-select" name="interchangeable_dial">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('interchangeable_dial')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('interchangeable_dial')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.interchangeable_strap') }}</label>
    <select class="form-select" name="interchangeable_strap">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('interchangeable_strap')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('interchangeable_strap')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.watch_box') }}</label>
    <select class="form-select" name="watch_box">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('watch_box')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('watch_box')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.warranty_years') }}</label>
    <input type="text" class="form-control" name="warranty_years" value="{{ old('warranty_years') }}" placeholder="e.g. 2">
</div>

<div class="col-4">
    <label class="form-label">{{ trans('product.model_name') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="model_name[ar]" placeholder="AR" value="{{ old('model_name.ar') }}">
        <input type="text" class="form-control" name="model_name[en]" placeholder="EN" value="{{ old('model_name.en') }}">
    </div>
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.model_number') }}</label>
    <input type="text" class="form-control" name="model_number" value="{{ old('model_number') }}">
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.country') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="country[ar]" placeholder="AR" value="{{ old('country.ar') }}">
        <input type="text" class="form-control" name="country[en]" placeholder="EN" value="{{ old('country.en') }}">
    </div>
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.feature_id') }}</label>
    <select class="form-select select2" name="feature_id[]" multiple>
        @foreach($feature as $item)<option value="{{ $item->id }}" @selected(in_array($item->id,old('feature_id',[])))>{{ $item->feature_name }}</option>@endforeach
    </select>
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.grade_id') }}</label>
    <select class="form-select select2" name="grade_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($grade as $item)<option value="{{ $item->id }}" @selected(old('grade_id') == $item->id)>{{ $item->grade_name }}</option>@endforeach
    </select>
</div>
<div class="col-4">
    <label class="form-label">{{ trans('product.stone') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="stone[ar]" placeholder="AR" value="{{ old('stone.ar') }}">
        <input type="text" class="form-control" name="stone[en]" placeholder="EN" value="{{ old('stone.en') }}">
    </div>
</div>
</div>
</div>

{{-- ── BAGS ── --}}
<div class="cat-fields d-none" data-cat="bags">
<div class="row g-3">
<div class="col-12"><div class="attr-title">{{ trans('product.bag_attributes') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.color') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}">{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_material_id') }}</label>
    <select class="form-select select2" name="band_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('band_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.size') }}</label>
    <select class="form-select select2" name="case_size_type_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($size_type as $item)<option value="{{ $item->id }}" @selected(old('case_size_type_id') == $item->id)>{{ $item->size_type_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.case_shape_id') }} <small class="text-muted">(Shape)</small></label>
    <select class="form-select select2" name="case_shape_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($shape as $item)<option value="{{ $item->id }}" @selected(old('case_shape_id') == $item->id)>{{ $item->shape_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.bag_strap_type') }}</label>
    <input type="text" class="form-control" name="bag_strap_type" value="{{ old('bag_strap_type') }}" placeholder="e.g. Removable, Fixed">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.bag_compartments') }}</label>
    <input type="text" class="form-control num-only" name="bag_compartments" value="{{ old('bag_compartments') }}" placeholder="e.g. 3">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.laptop_compartment') }}</label>
    <select class="form-select" name="laptop_compartment">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('laptop_compartment')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('laptop_compartment')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.waterproof') }}</label>
    <select class="form-select" name="waterproof">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('waterproof')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('waterproof')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
</div>
</div>

{{-- ── WALLETS ── --}}
<div class="cat-fields d-none" data-cat="wallets">
<div class="row g-3">
<div class="col-12"><div class="attr-title">{{ trans('product.wallet_attributes') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.color') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}">{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_material_id') }}</label>
    <select class="form-select select2" name="band_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('band_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.wallet_card_slots') }}</label>
    <input type="text" class="form-control num-only" name="wallet_card_slots" value="{{ old('wallet_card_slots') }}" placeholder="e.g. 8">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.rfid_protection') }}</label>
    <select class="form-select" name="rfid_protection">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('rfid_protection')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('rfid_protection')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.coin_pocket') }}</label>
    <select class="form-select" name="coin_pocket">
        <option value="">{{ trans('product.select') }}</option>
        <option value="1" @selected(old('coin_pocket')==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('coin_pocket')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_closure_id') }}</label>
    <select class="form-select select2" name="band_closure_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($closure_type as $item)<option value="{{ $item->id }}" @selected(old('band_closure_id') == $item->id)>{{ $item->closure_type_name }}</option>@endforeach
    </select>
</div>
</div>
</div>

{{-- ── BELTS / CAPS / BRACELETS / STRAPS ── --}}
<div class="cat-fields d-none" data-cat="belts,caps,bracelets,accessories-spare-parts">
<div class="row g-3">
<div class="col-12"><div class="attr-title">{{ trans('product.accessory_attributes') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.color') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}">{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_material_id') }}</label>
    <select class="form-select select2" name="band_material_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($material as $item)<option value="{{ $item->id }}" @selected(old('band_material_id') == $item->id)>{{ $item->material_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.size') }}</label>
    <select class="form-select select2" name="case_size_type_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($size_type as $item)<option value="{{ $item->id }}" @selected(old('case_size_type_id') == $item->id)>{{ $item->size_type_name }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.band_closure_id') }}</label>
    <select class="form-select select2" name="band_closure_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($closure_type as $item)<option value="{{ $item->id }}" @selected(old('band_closure_id') == $item->id)>{{ $item->closure_type_name }}</option>@endforeach
    </select>
</div>
</div>
</div>

{{-- ── PERFUMES ── --}}
<div class="cat-fields d-none" data-cat="perfumes">
<div class="row g-3">
<div class="col-12"><div class="attr-title">{{ trans('product.perfume_attributes') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.perfume_volume') }} (ml)</label>
    <input type="text" class="form-control num-only" name="perfume_volume" value="{{ old('perfume_volume') }}" placeholder="e.g. 100">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.perfume_type') }}</label>
    <select class="form-select select2" name="perfume_type">
        <option value="">{{ trans('product.select') }}</option>
        <option value="edp" @selected(old('perfume_type')=='edp')>Eau de Parfum (EDP)</option>
        <option value="edt" @selected(old('perfume_type')=='edt')>Eau de Toilette (EDT)</option>
        <option value="body_spray" @selected(old('perfume_type')=='body_spray')>Body Spray</option>
        <option value="cologne" @selected(old('perfume_type')=='cologne')>Cologne</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.perfume_scent') }}</label>
    <input type="text" class="form-control" name="perfume_scent" value="{{ old('perfume_scent') }}" placeholder="e.g. Woody, Floral, Oud">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.country') }} (AR/EN)</label>
    <div class="input-group">
        <input type="text" class="form-control" name="country[ar]" placeholder="AR" value="{{ old('country.ar') }}">
        <input type="text" class="form-control" name="country[en]" placeholder="EN" value="{{ old('country.en') }}">
    </div>
</div>
</div>
</div>

{{-- ── ELECTRONICS ── --}}
<div class="cat-fields d-none" data-cat="electronics">
<div class="row g-3">
<div class="col-12"><div class="attr-title">{{ trans('product.electronics_attributes') }}</div></div>
<div class="col-3">
    <label class="form-label">{{ trans('product.color') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}">{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.warranty_years') }}</label>
    <input type="text" class="form-control" name="warranty_years" value="{{ old('warranty_years') }}" placeholder="e.g. 1">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.elec_battery_capacity') }} (mAh)</label>
    <input type="text" class="form-control num-only" name="elec_battery_capacity" value="{{ old('elec_battery_capacity') }}" placeholder="e.g. 10000">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.elec_connectivity') }}</label>
    <input type="text" class="form-control" name="elec_connectivity" value="{{ old('elec_connectivity') }}" placeholder="e.g. USB-C, Bluetooth">
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.feature_id') }}</label>
    <select class="form-select select2" name="feature_id[]" multiple>
        @foreach($feature as $item)<option value="{{ $item->id }}">{{ $item->feature_name }}</option>@endforeach
    </select>
</div>
</div>
</div>

{{-- ── GENERIC FALLBACK ── --}}
<div class="cat-fields d-none" data-cat="bundles,outlet,toys,uncategorized">
<div class="row g-3">
<div class="col-3">
    <label class="form-label">{{ trans('product.color') }}</label>
    <select class="form-select colorSel" name="band_color_id[]" multiple>
        @foreach($color as $c)<option value="{{ $c->id }}" data-color="{{ $c->color_value }}">{{ $c->color_name ?? trans('product.none_name') }}</option>@endforeach
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.feature_id') }}</label>
    <select class="form-select select2" name="feature_id[]" multiple>
        @foreach($feature as $item)<option value="{{ $item->id }}">{{ $item->feature_name }}</option>@endforeach
    </select>
</div>
</div>
</div>

{{-- ═══════════════════════════════════════════
     § 6  PRODUCT VARIANTS
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1">
    <div class="sec-title d-flex align-items-center justify-content-between">
        <span>{{ trans('product.section_variants') }}</span>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariant()">
            <i class="bi bi-plus me-1"></i>{{ trans('product.add_variant') }}
        </button>
    </div>
    <small class="text-muted d-block mb-2" style="font-size:11px">{{ trans('product.variants_hint') }}</small>
</div>

<div class="col-12" id="variants-container">
    {{-- Variants injected by JS --}}
</div>

{{-- ═══════════════════════════════════════════
     § 7  DESCRIPTIONS
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_descriptions') }}</div></div>

<div class="col-6">
    <label class="form-label">{{ trans('product.short_description') }} (AR) <span class="req">*</span></label>
    <textarea class="form-control" name="short_description[ar]" rows="2">{{ old('short_description.ar') }}</textarea>
    @error('short_description.ar')<div class="err">{{ $message }}</div>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.short_description') }} (EN) <span class="req">*</span></label>
    <textarea class="form-control" name="short_description[en]" rows="2">{{ old('short_description.en') }}</textarea>
    @error('short_description.en')<div class="err">{{ $message }}</div>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.long_description') }} (AR) <span class="req">*</span></label>
    <textarea class="form-control" name="long_description[ar]" rows="3">{{ old('long_description.ar') }}</textarea>
    @error('long_description.ar')<div class="err">{{ $message }}</div>@enderror
</div>
<div class="col-6">
    <label class="form-label">{{ trans('product.long_description') }} (EN) <span class="req">*</span></label>
    <textarea class="form-control" name="long_description[en]" rows="3">{{ old('long_description.en') }}</textarea>
    @error('long_description.en')<div class="err">{{ $message }}</div>@enderror
</div>

{{-- ═══════════════════════════════════════════
     § 8  SEO
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1">
    <div class="sec-title d-flex align-items-center justify-content-between">
        <span>{{ trans('product.section_seo') }}</span>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="autoSEO(true)">
            <i class="bi bi-magic me-1"></i>{{ trans('product.regenerate_seo') }}
        </button>
    </div>
</div>

<div class="col-6">
    <label class="form-label">{{ trans('product.seo_title') }}</label>
    <input type="text" class="form-control" name="seo_title" id="seo_title" value="{{ old('seo_title') }}"
           placeholder="{{ trans('product.seo_title_placeholder') }}" maxlength="60">
    <div class="d-flex justify-content-between mt-1">
        <small class="text-muted" style="font-size:10px">{{ trans('product.seo_title_hint') }}</small>
        <small id="seo-title-count" class="text-muted" style="font-size:10px">0/60</small>
    </div>
</div>

<div class="col-6">
    <label class="form-label">{{ trans('product.seo_slug') }}</label>
    <div class="input-group">
        <span class="input-group-text text-muted" style="font-size:11px">/product/</span>
        <input type="text" class="form-control" name="seo_slug" id="seo_slug" value="{{ old('seo_slug') }}"
               placeholder="product-name-brand">
    </div>
</div>

<div class="col-12">
    <label class="form-label">{{ trans('product.seo_meta_description') }}</label>
    <textarea class="form-control" name="seo_meta_description" id="seo_meta" rows="2"
              placeholder="{{ trans('product.seo_meta_placeholder') }}" maxlength="160">{{ old('seo_meta_description') }}</textarea>
    <div class="d-flex justify-content-between mt-1">
        <small class="text-muted" style="font-size:10px">{{ trans('product.seo_meta_hint') }}</small>
        <small id="seo-meta-count" class="text-muted" style="font-size:10px">0/160</small>
    </div>
</div>

<div class="col-12">
    <label class="form-label">{{ trans('product.search_keywords') }}</label>
    <input type="text" class="form-control" name="search_keywords" id="seo_keywords" value="{{ old('search_keywords') }}"
           placeholder="{{ trans('product.search_keywords_placeholder') }}">
    <small class="text-muted" style="font-size:10px">{{ trans('product.seo_keywords_hint') }}</small>
</div>

{{-- Google Preview --}}
<div class="col-12">
    <div class="seo-preview">
        <div class="seo-preview-label">{{ trans('product.seo_preview') }}</div>
        <div class="seo-preview-title" id="preview-title">{{ trans('product.seo_preview_title_placeholder') }}</div>
        <div class="seo-preview-url">/product/<span id="preview-slug">your-product-slug</span></div>
        <div class="seo-preview-desc" id="preview-desc">{{ trans('product.seo_preview_desc_placeholder') }}</div>
    </div>
</div>

{{-- ═══════════════════════════════════════════
     § 9  MEDIA / IMAGES
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_media') }}</div></div>

{{-- Main image --}}
<div class="col-12">
    <div class="img-section-label">
        <i class="bi bi-image me-1"></i>{{ trans('product.main_image_label') }}
        <span class="badge bg-danger ms-1">{{ trans('product.required') }}</span>
        <small class="text-muted ms-2">{{ trans('product.main_image_hint') }}</small>
    </div>
    <div class="main-upload-box" id="main-drop" onclick="document.getElementById('img_input').click()">
        <div id="main-placeholder">
            <i class="bi bi-cloud-upload" style="font-size:2rem;opacity:.4"></i>
            <p class="mb-1 mt-2 fw-500">{{ trans('product.click_or_drop') }}</p>
            <small class="text-muted">JPG · PNG · WEBP — max 5MB</small>
        </div>
        <img id="img_thumb" src="#" class="d-none main-thumb-preview">
        <button type="button" id="main-remove" class="btn-remove-img d-none" onclick="removeMainImg(event)"><i class="bi bi-x"></i></button>
    </div>
    <input type="file" class="d-none" name="image" id="img_input" accept="image/*">
    @error('image')<div class="err">{{ $message }}</div>@enderror
</div>

{{-- Gallery --}}
<div class="col-12 mt-2">
    <div class="img-section-label">
        <i class="bi bi-images me-1"></i>{{ trans('product.gallery_images_label') }}
        <span class="badge bg-secondary ms-1">{{ trans('product.optional') }}</span>
        <small class="text-muted ms-2">{{ trans('product.gallery_hint') }}</small>
        <span id="gallery-count" class="badge bg-light text-dark ms-2">0 / 10</span>
    </div>
    <div class="gallery-drop-zone" id="gallery-drop" onclick="document.getElementById('gallery_input').click()">
        <i class="bi bi-plus-circle" style="font-size:1.4rem;opacity:.5"></i>
        <p class="mb-0 mt-1">{{ trans('product.add_gallery_images') }}</p>
        <small class="text-muted">{{ trans('product.upload_formats') }}</small>
    </div>
    <!-- ============= التعديل هنا ============= -->
    <input type="file" class="d-none" id="gallery_input" accept="image/*" multiple>
    <div id="gallery-base64-inputs"></div>
    <div class="gallery-grid mt-3" id="gallery-grid"></div>
    <!-- ===================================== -->
</div>

{{-- ═══════════════════════════════════════════
     § 10  SETTINGS
═══════════════════════════════════════════ --}}
<div class="col-12 mt-1"><div class="sec-title">{{ trans('product.section_settings') }}</div></div>

<div class="col-3">
    <label class="form-label">{{ trans('product.active') }} <span class="req">*</span></label>
    <select class="form-select" name="active">
        <option value="1" @selected(old('active',1)==1)>{{ trans('product.yes') }}</option>
        <option value="0" @selected(old('active')=='0')>{{ trans('product.no') }}</option>
    </select>
</div>
<div class="col-3">
    <label class="form-label">{{ trans('product.grade_id') }}</label>
    <select class="form-select select2" name="grade_id">
        <option value="">{{ trans('product.select') }}</option>
        @foreach($grade as $item)<option value="{{ $item->id }}" @selected(old('grade_id') == $item->id)>{{ $item->grade_name }}</option>@endforeach
    </select>
</div>

{{-- Submit + Duplicate --}}
<div class="col-12 text-center mt-3 pb-2">
    <a href="{{ route('product.index') }}" class="btn btn-secondary px-4">{{ trans('mainBtn.close_btn') }}</a>
    <button type="submit" name="action" value="save" class="btn btn-primary px-5 ms-2">{{ trans('mainBtn.add_btn') }}</button>
    <button type="submit" name="action" value="save_duplicate" class="btn btn-outline-primary px-4 ms-2">
        <i class="bi bi-copy me-1"></i>{{ trans('product.save_and_duplicate') }}
    </button>
</div>

</form>
</div></div></div></div>
</section>

<style>
.sec-title{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#8a9ab0;border-bottom:1px solid #eef0f3;padding-bottom:5px;margin-bottom:4px}
.attr-title{font-size:13px;font-weight:600;color:#3d6fd1;margin-bottom:0}
.req{color:#dc3545}.err{color:#dc3545;font-size:12px;margin-top:2px}
.lvl-badge{font-size:9px;font-weight:700;padding:2px 5px;border-radius:4px;color:#fff;margin-left:4px}
.multi-hint{font-size:10px;color:#6c757d;font-style:italic}
.cat-bc{background:#f0f5ff;border:1px solid #c8d8ff;border-radius:8px;padding:7px 14px;font-size:13px;color:#2a5bd7}
.bc-arr{color:#aaa;margin:0 3px}
.cat-fields{width:100%}.cat-fields.d-none{display:none!important}
.ldr{margin-top:4px}
/* Images */
.img-section-label{font-size:12px;font-weight:600;color:#555;margin-bottom:8px;display:flex;align-items:center;flex-wrap:wrap;gap:4px}
.main-upload-box{position:relative;border:2px dashed #c8d4e0;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s;min-height:120px;display:flex;align-items:center;justify-content:center}
.main-upload-box:hover,.main-upload-box.drag{border-color:#378ADD}
.main-upload-box.has-image{border-style:solid;border-color:#378ADD;padding:8px}
.main-thumb-preview{max-height:160px;max-width:100%;border-radius:8px;object-fit:contain}
.btn-remove-img{position:absolute;top:6px;right:6px;width:26px;height:26px;border-radius:50%;border:none;background:rgba(220,53,69,.85);color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.gallery-drop-zone{border:2px dashed #c8d4e0;border-radius:10px;padding:14px;text-align:center;cursor:pointer;transition:border-color .2s;color:#888}
.gallery-drop-zone:hover,.gallery-drop-zone.drag{border-color:#378ADD;color:#378ADD}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}
.gal-item{position:relative;border-radius:8px;overflow:hidden;border:1.5px solid #e0e5ec;aspect-ratio:1;background:#f8f9fa}
.gal-item img{width:100%;height:100%;object-fit:cover;display:block}
.gal-item .gal-remove{position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,.85);color:#fff;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s}
.gal-item:hover .gal-remove{opacity:1}
.gal-item .gal-n{position:absolute;bottom:2px;left:3px;font-size:9px;color:#fff;background:rgba(0,0,0,.5);padding:1px 4px;border-radius:3px}
/* Variants */
.variant-row{background:var(--bs-light,#f8f9fa);border:1px solid #e0e5ec;border-radius:10px;padding:14px;position:relative;margin-bottom:8px}
.variant-remove{position:absolute;top:8px;right:10px;background:none;border:none;color:#dc3545;font-size:18px;cursor:pointer;line-height:1}
/* SEO */
.seo-preview{border:1px solid #e0e5ec;border-radius:8px;padding:14px;background:#fff}
.seo-preview-label{font-size:10px;color:#888;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.seo-preview-title{font-size:17px;color:#1a0dab;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.seo-preview-url{font-size:13px;color:#006621;margin:2px 0}
.seo-preview-desc{font-size:13px;color:#545454;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
</style>
@endsection

@section('script')
<script>
$('.select2').select2({theme:'bootstrap-5',width:'100%'});

$(document).ready(function(){

    /* ── Color select2 ── */
    function initColor($ctx){
        $ctx.find('.colorSel').select2({theme:'bootstrap-5',templateResult:fmtColor,templateSelection:fmtColor});
    }
    function fmtColor(s){
        if(!s.id)return s.text;
        var c=$(s.element).data('color');
        return $('<span style="background:'+c+';color:#fff;display:block;padding:3px 10px;border-radius:4px">'+s.text+'</span>');
    }
    initColor($(document));

    /* ── Auto-discount ── */
    function calcDiscount(){
        var sell=parseFloat($('#selling_price').val())||0;
        var sale=parseFloat($('#sale_price').val())||0;
        $('#pct_discount').val(sell>0&&sale>0&&sale<sell?((sell-sale)/sell*100).toFixed(1):'');
    }
    $('#selling_price,#sale_price').on('input',calcDiscount);
    $(document).on('input','.num-only',function(){this.value=this.value.replace(/[^0-9.]/g,'');});

    /* ── Low stock warning ── */
    window.checkLowStock=function(){
        var s=parseInt($('#stock_field').val())||0;
        var t=parseInt($('#low_stock_threshold').val())||5;
        s>0&&s<=t?$('#low-stock-alert').removeClass('d-none'):$('#low-stock-alert').addClass('d-none');
    };

    /* ── SKU generator ── */
    window.genSKU=function(){
        var rand=Math.random().toString(36).substring(2,8).toUpperCase();
        var prefix=$('#cat1').find(':selected').text().substring(0,3).toUpperCase()||'PRD';
        $('#sku_field').val(prefix+'-'+rand);
    };

    /* ══════════════════════════════════════════
       3-LEVEL DYNAMIC CATEGORIES
    ══════════════════════════════════════════ */
    const API='/api/categories';
    const slugMap={
        'watches':'watches,smart-watches,wall-clocks',
        'smart-watches':'watches,smart-watches,wall-clocks',
        'wall-clocks':'watches,smart-watches,wall-clocks',
        'bags':'bags','wallets':'wallets','belts':'belts,caps,bracelets,accessories-spare-parts',
        'caps':'belts,caps,bracelets,accessories-spare-parts',
        'bracelets':'belts,caps,bracelets,accessories-spare-parts',
        'perfumes':'perfumes','electronics':'electronics',
        'accessories-spare-parts':'belts,caps,bracelets,accessories-spare-parts',
    };

    function showAttr(slug){
        $('.cat-fields').addClass('d-none');
        if(!slug)return;
        var t=slugMap[slug]||'bundles,outlet,toys,uncategorized';
        var list=t.split(',');
        $('.cat-fields').each(function(){
            var cats=$(this).data('cat').split(',');
            if(cats.some(function(c){return list.includes(c.trim());})){
                $(this).removeClass('d-none');
                $(this).find('.select2:not(.colorSel)').select2({theme:'bootstrap-5',width:'100%'});
                initColor($(this));
            }
        });
    }

    function updateBC(n1,n2,n3){
        if(!n1){$('#cat-bc').addClass('d-none');return;}
        $('#cat-bc').removeClass('d-none');
        $('#bc-1').text(n1);
        n2?($('#bc-2').text(n2).removeClass('d-none'),$('#bc-sep1').removeClass('d-none')):($('#bc-2').addClass('d-none'),$('#bc-sep1').addClass('d-none'));
        n3?($('#bc-3').text(n3).removeClass('d-none'),$('#bc-sep2').removeClass('d-none')):($('#bc-3').addClass('d-none'),$('#bc-sep2').addClass('d-none'));
    }

    function resetMs($s,ph){
        if($s.hasClass('select2-hidden-accessible')){$s.val(null).trigger('change');}
        $s.empty().prop('disabled',true);
    }

    function loadCh(pid,$s,$l,ph){
        $l.removeClass('d-none');resetMs($s,ph);
        $.getJSON(API+'/'+pid+'/children').done(function(data){
            $l.addClass('d-none');$s.empty();
            var loc=$('html').attr('lang')||'en';
            $.each(data,function(_,it){
                var nm=loc==='ar'?(it.name_ar||it.name_en):(it.name_en||it.name);
                $s.append('<option value="'+it.id+'" data-slug="'+it.slug+'" data-name="'+nm+'">'+nm+'</option>');
            });
            if(data.length){$s.prop('disabled',false).select2({theme:'bootstrap-5',width:'100%',placeholder:ph,allowClear:true});}
        }).fail(function(){$l.addClass('d-none');});
    }

    $('#cat1').on('change',function(){
        var id=$(this).val(),slug=$(this).find(':selected').data('slug')||'';
        var name=$(this).find(':selected').data('name')||$(this).find(':selected').text();
        resetMs($('#cat2'));resetMs($('#cat3'));
        updateBC(name,null,null);showAttr(slug);autoSEO();
        if(!id)return;
        loadCh(id,$('#cat2'),$('#load2'),'{{ trans("product.sub_category") }}');
    });
    $('#cat2').on('change',function(){
        var vals=$(this).val()||[];var last=vals[vals.length-1];
        var names=[];$(this).find(':selected').each(function(){names.push($(this).data('name')||$(this).text());});
        var bc1=$('#cat1').find(':selected').data('name')||'';
        resetMs($('#cat3'));updateBC(bc1,names.join(', '),null);
        if(!last)return;
        loadCh(last,$('#cat3'),$('#load3'),'{{ trans("product.product_type") }}');
    });
    $('#cat3').on('change',function(){
        var names=[];$(this).find(':selected').each(function(){names.push($(this).data('name')||$(this).text());});
        var bc1=$('#cat1').find(':selected').data('name')||'';
        var bc2=[];$('#cat2').find(':selected').each(function(){bc2.push($(this).data('name')||$(this).text());});
        updateBC(bc1,bc2.join(', '),names.join(', '));
    });

    /* ══════════════════════════════════════════
       SEO AUTO-GENERATION
    ══════════════════════════════════════════ */
    window.autoSEO=function(force){
        var titleEn=$('#title_en').val().trim();
        var brand=$('#sel_brand').find(':selected').data('name')||'';
        var cat=$('#cat1').find(':selected').text().trim();

        if(!titleEn)return;

        /* Slug */
        var slug=(titleEn+' '+brand).toLowerCase()
            .replace(/[^a-z0-9\s-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
        if(!$('#seo_slug').val()||force)$('#seo_slug').val(slug);

        /* SEO Title */
        var seoTitle=titleEn+(brand?' — '+brand:'')+(cat?' | '+cat:'');
        if(seoTitle.length>60)seoTitle=seoTitle.substring(0,57)+'...';
        if(!$('#seo_title').val()||force)$('#seo_title').val(seoTitle);

        /* Keywords */
        var kw=[titleEn.toLowerCase()];
        if(brand)kw.push(brand.toLowerCase());
        if(cat)kw.push(cat.toLowerCase());
        if(!$('#seo_keywords').val()||force)$('#seo_keywords').val(kw.join(', '));

        updateSEOPreview();
    };

    function updateSEOPreview(){
        var t=$('#seo_title').val()||'{{ trans("product.seo_preview_title_placeholder") }}';
        var s=$('#seo_slug').val()||'your-product-slug';
        var d=$('#seo_meta').val()||'{{ trans("product.seo_preview_desc_placeholder") }}';
        $('#preview-title').text(t);
        $('#preview-slug').text(s);
        $('#preview-desc').text(d);
    }

    $('#seo_title').on('input',function(){
        $('#seo-title-count').text($(this).val().length+'/60');
        updateSEOPreview();
    });
    $('#seo_meta').on('input',function(){
        $('#seo-meta-count').text($(this).val().length+'/160');
        updateSEOPreview();
    });
    $('#seo_slug').on('input',function(){
        $(this).val($(this).val().toLowerCase().replace(/[^a-z0-9-]/g,'-').replace(/-+/g,'-'));
        updateSEOPreview();
    });

    /* ══════════════════════════════════════════
       PRODUCT VARIANTS
    ══════════════════════════════════════════ */
    var variantCount=0;
    window.addVariant=function(){
        var i=variantCount++;
        var html='<div class="variant-row" id="variant-'+i+'">'
            +'<button type="button" class="variant-remove" onclick="removeVariant('+i+')" title="Remove"><i class="bi bi-x-circle"></i></button>'
            +'<div class="row g-2 align-items-end">'
            +'<div class="col-3"><label class="form-label small">{{ trans("product.variant_name") }}</label>'
            +'<input type="text" class="form-control form-control-sm" name="variants['+i+'][name]" placeholder="e.g. Black / Silver / Gold"></div>'
            +'<div class="col-2"><label class="form-label small">{{ trans("product.variant_price") }}</label>'
            +'<input type="text" class="form-control form-control-sm num-only" name="variants['+i+'][price]" placeholder="0.00"></div>'
            +'<div class="col-2"><label class="form-label small">{{ trans("product.variant_stock") }}</label>'
            +'<input type="text" class="form-control form-control-sm num-only" name="variants['+i+'][stock]" placeholder="0"></div>'
            +'<div class="col-3"><label class="form-label small">{{ trans("product.variant_sku") }}</label>'
            +'<input type="text" class="form-control form-control-sm" name="variants['+i+'][sku]" placeholder="Optional"></div>'
            +'<div class="col-2"><label class="form-label small">{{ trans("product.variant_image") }}</label>'
            +'<input type="file" class="form-control form-control-sm" name="variants['+i+'][image]" accept="image/*"></div>'
            +'</div></div>';
        $('#variants-container').append(html);
    };
    window.removeVariant=function(i){$('#variant-'+i).remove();};

    /* ══════════════════════════════════════════
       MAIN IMAGE — drag/drop
    ══════════════════════════════════════════ */
    var mdz=document.getElementById('main-drop');
    mdz.addEventListener('dragover',function(e){e.preventDefault();this.classList.add('drag');});
    mdz.addEventListener('dragleave',function(){this.classList.remove('drag');});
    mdz.addEventListener('drop',function(e){
        e.preventDefault();this.classList.remove('drag');
        var f=e.dataTransfer.files[0];
        if(f&&f.type.startsWith('image/')){setMainImg(f);var dt=new DataTransfer();dt.items.add(f);document.getElementById('img_input').files=dt.files;}
    });
    document.getElementById('img_input').addEventListener('change',function(){if(this.files[0])setMainImg(this.files[0]);});

    function setMainImg(f){
        var r=new FileReader();
        r.onload=function(e){
            $('#img_thumb').attr('src',e.target.result).removeClass('d-none');
            $('#main-placeholder').addClass('d-none');
            $('#main-remove').removeClass('d-none');
            $('#main-drop').addClass('has-image');
        };
        r.readAsDataURL(f);
    }
    window.removeMainImg=function(e){
        e.stopPropagation();
        document.getElementById('img_input').value='';
        $('#img_thumb').addClass('d-none');$('#main-placeholder').removeClass('d-none');
        $('#main-remove').addClass('d-none');$('#main-drop').removeClass('has-image');
    };

    /* ══════════════════════════════════════════
       GALLERY IMAGES
    ══════════════════════════════════════════ */
    var MAX=10,gFiles=[];
    var gdz=document.getElementById('gallery-drop');
    gdz.addEventListener('dragover',function(e){e.preventDefault();this.classList.add('drag');});
    gdz.addEventListener('dragleave',function(){this.classList.remove('drag');});
    gdz.addEventListener('drop',function(e){e.preventDefault();this.classList.remove('drag');addGal(e.dataTransfer.files);});
    document.getElementById('gallery_input').addEventListener('change',function(){var files=Array.from(this.files);this.value='';addGal(files);});
    
    function addGal(files){
        for(var i=0;i<files.length;i++){
            if(gFiles.length>=MAX)break;
            if(!files[i].type.startsWith('image/'))continue;
            gFiles.push(files[i]);
            renderGal(files[i],gFiles.length-1);
        }
        syncGal();updateGalCount();
    }
    
    function renderGal(f,idx){
        var g=document.getElementById('gallery-grid');
        var d=document.createElement('div');d.className='gal-item';d.dataset.idx=idx;
        var r=new FileReader();
        r.onload=function(e){
            d.innerHTML='<img src="'+e.target.result+'">'
                +'<button type="button" class="gal-remove" onclick="removeGal(this)"><i class="bi bi-x"></i></button>'
                +'<span class="gal-n">'+(idx+1)+'</span>';
        };r.readAsDataURL(f);g.appendChild(d);
    }
    
    window.removeGal=function(btn){
        var item=btn.closest('.gal-item');
        gFiles.splice(parseInt(item.dataset.idx),1);
        document.getElementById('gallery-grid').innerHTML='';
        gFiles.forEach(function(f,i){renderGal(f,i);});
        syncGal();updateGalCount();
    };
    
    /* ============= التعديل هنا ============= */
    window.syncGal = function(){
        var container = document.getElementById('gallery-base64-inputs');
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
    /* ===================================== */
    
    function updateGalCount(){
        document.getElementById('gallery-count').textContent=gFiles.length+' / '+MAX;
        var z=document.getElementById('gallery-drop');
        if(gFiles.length>=MAX){z.style.opacity='.4';z.style.pointerEvents='none';}
        else{z.style.opacity='';z.style.pointerEvents='';}
    }

});
</script>
@endsection