@extends('Dashboard.layouts.master')
@section('title-head')Edit Color@endsection

@section('content')
<div class="pagetitle">
    <h1>Edit Color</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('new-colors.index') }}">Colors</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol></nav>
</div>

<section class="section">
<div class="card" style="max-width:500px">
<div class="card-body pt-3">
    <h5 class="card-title">Edit: {{ $color->name_en }}</h5>

    <form action="{{ route('new-colors.update', $color) }}" method="POST">
    @csrf @method('PUT')

    <div class="mb-3">
        <label class="form-label">Name (English) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
               name="name_en" value="{{ old('name_en', $color->name_en) }}">
        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Name (Arabic) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_ar') is-invalid @enderror"
               name="name_ar" value="{{ old('name_ar', $color->name_ar) }}" dir="rtl">
        @error('name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Color (HEX) <span class="text-danger">*</span></label>
        <div class="d-flex align-items-center gap-3">
            <input type="color" class="form-control form-control-color"
                   id="hex_picker" value="{{ $color->hex }}"
                   oninput="document.getElementById('hex_text').value=this.value;document.getElementById('preview_swatch').style.background=this.value"
                   style="width:60px;height:40px;padding:2px;cursor:pointer">
            <input type="text" class="form-control @error('hex') is-invalid @enderror"
                   name="hex" id="hex_text" value="{{ old('hex', $color->hex) }}" maxlength="7">
        </div>
        @error('hex')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="is_active" {{ old('is_active', $color->is_active) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>

    <div class="mb-3 p-3 border rounded-3 bg-light">
        <small class="text-muted d-block mb-2">Preview:</small>
        <div class="d-flex align-items-center gap-2">
            <div id="preview_swatch"
                 style="width:40px;height:40px;border-radius:50%;background:{{ $color->hex }};border:2px solid #ddd"></div>
            <span class="fw-500">{{ $color->name_en }}</span>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Update Color</button>
        <a href="{{ route('new-colors.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
    </form>
</div>
</div>
</section>
@endsection