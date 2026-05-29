@extends('Dashboard.layouts.master')
@section('title-head')Edit Size@endsection

@section('content')
<div class="pagetitle">
    <h1>Edit Size</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('new-sizes.index') }}">Sizes</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol></nav>
</div>

<section class="section">
<div class="card" style="max-width:500px">
<div class="card-body pt-3">
    <h5 class="card-title">Edit: {{ $size->name_en }}</h5>

    <form action="{{ route('new-sizes.update', $size) }}" method="POST">
    @csrf @method('PUT')

    <div class="mb-3">
        <label class="form-label">Type <span class="text-danger">*</span></label>
        <select class="form-select" name="type">
            @foreach($types as $key => $label)
            <option value="{{ $key }}" {{ old('type', $size->type) === $key ? 'selected' : '' }}>
                {{ $label }}
            </option>
            @endforeach
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Name (English) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
               name="name_en" value="{{ old('name_en', $size->name_en) }}">
        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Name (Arabic) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_ar') is-invalid @enderror"
               name="name_ar" value="{{ old('name_ar', $size->name_ar) }}" dir="rtl">
        @error('name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="is_active" {{ old('is_active', $size->is_active) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Update Size</button>
        <a href="{{ route('new-sizes.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
    </form>
</div>
</div>
</section>
@endsection