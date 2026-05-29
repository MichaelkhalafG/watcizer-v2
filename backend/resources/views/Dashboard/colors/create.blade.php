@extends('Dashboard.layouts.master')
@section('title-head')Add Color@endsection

@section('content')
<div class="pagetitle">
    <h1>Add Color</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('new-colors.index') }}">Colors</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol></nav>
</div>

<section class="section">
<div class="card" style="max-width:500px">
<div class="card-body pt-3">
    <h5 class="card-title">New Color</h5>

    <form action="{{ route('new-colors.store') }}" method="POST">
    @csrf
    <div class="mb-3">
        <label class="form-label">Name (English) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
               name="name_en" value="{{ old('name_en') }}" placeholder="e.g. Black">
        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Name (Arabic) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_ar') is-invalid @enderror"
               name="name_ar" value="{{ old('name_ar') }}" placeholder="e.g. أسود" dir="rtl">
        @error('name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Color (HEX) <span class="text-danger">*</span></label>
        <div class="d-flex align-items-center gap-3">
            <input type="color" class="form-control form-control-color"
                   id="hex_picker" value="#000000"
                   oninput="document.getElementById('hex_text').value=this.value"
                   style="width:60px;height:40px;padding:2px;cursor:pointer">
            <input type="text" class="form-control @error('hex') is-invalid @enderror"
                   name="hex" id="hex_text" value="{{ old('hex', '#000000') }}"
                   placeholder="#000000" maxlength="7"
                   oninput="if(this.value.length==7) document.getElementById('hex_picker').value=this.value">
        </div>
        @error('hex')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="is_active" {{ old('is_active', 1) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>

    {{-- Preview --}}
    <div class="mb-3 p-3 border rounded-3 bg-light">
        <small class="text-muted d-block mb-2">Preview:</small>
        <div class="d-flex align-items-center gap-2">
            <div id="preview_swatch"
                 style="width:40px;height:40px;border-radius:50%;background:#000;border:2px solid #ddd"></div>
            <span id="preview_name" class="fw-500">Black</span>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Save Color</button>
        <a href="{{ route('new-colors.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
    </form>
</div>
</div>
</section>

<script>
document.getElementById('hex_picker').addEventListener('input', function(){
    document.getElementById('preview_swatch').style.background = this.value;
});
document.getElementById('hex_text').addEventListener('input', function(){
    if(this.value.length === 7) document.getElementById('preview_swatch').style.background = this.value;
});
document.querySelector('[name="name_en"]').addEventListener('input', function(){
    document.getElementById('preview_name').textContent = this.value || 'Color Name';
});
</script>
@endsection