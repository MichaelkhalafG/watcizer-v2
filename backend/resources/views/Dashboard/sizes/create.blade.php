@extends('Dashboard.layouts.master')
@section('title-head')Add Size@endsection

@section('content')
<div class="pagetitle">
    <h1>Add Size</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('new-sizes.index') }}">Sizes</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol></nav>
</div>

<section class="section">
<div class="card" style="max-width:500px">
<div class="card-body pt-3">
    <h5 class="card-title">New Size</h5>

    <form action="{{ route('new-sizes.store') }}" method="POST">
    @csrf

    <div class="mb-3">
        <label class="form-label">Type <span class="text-danger">*</span></label>
        <select class="form-select" name="type" id="size_type">
            @foreach($types as $key => $label)
            <option value="{{ $key }}" {{ old('type') === $key ? 'selected' : '' }}>
                {{ $label }}
            </option>
            @endforeach
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Name (English) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
               name="name_en" value="{{ old('name_en') }}" placeholder="e.g. XL, 42, Free Size">
        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label">Name (Arabic) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name_ar') is-invalid @enderror"
               name="name_ar" value="{{ old('name_ar') }}" placeholder="e.g. XL، 42" dir="rtl">
        @error('name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="is_active" {{ old('is_active', 1) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Save Size</button>
        <a href="{{ route('new-sizes.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
    </form>
</div>
</div>
</section>
@endsection