@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('category.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('category.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.category') }}</li>
                    <li class="breadcrumb-item active">{{ trans('category.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('category.add') }}</h5>

                        <form action="{{ route('category.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <div class="col-12 mb-2">
                                <label for="name_ar" class="form-label">{{ trans('category.name') }} (ar)</label>
                                <input type="text" class="form-control" name="name[ar]" id="name_ar" value="{{ old('name.ar') }}">
                                @error('name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="name_en" class="form-label">{{ trans('category.name') }} (en)</label>
                                <input type="text" class="form-control" name="name[en]" id="name_en" value="{{ old('name.en') }}">
                                @error('name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="description_ar" class="form-label">{{ trans('category.description') }} (ar)</label>
                                <textarea class="form-control" name="description[ar]" id="description_ar" rows="2">{{ old('description.ar') }}</textarea>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="description_en" class="form-label">{{ trans('category.description') }} (en)</label>
                                <textarea class="form-control" name="description[en]" id="description_en" rows="2">{{ old('description.en') }}</textarea>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="parent_id" class="form-label">{{ trans('category.parent') }}</label>
                                <select class="form-select" name="parent_id" id="parent_id">
                                    <option value="">{{ trans('category.none_parent') }}</option>
                                    @foreach ($parents as $parent)
                                        <option value="{{ $parent->id }}" {{ old('parent_id') == $parent->id ? 'selected' : '' }}>
                                            {{ str_repeat('— ', max(0, $parent->level - 1)) }}{{ $parent->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('parent_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="slug" class="form-label">{{ trans('category.slug') }}</label>
                                <input type="text" class="form-control" name="slug" id="slug" value="{{ old('slug') }}">
                                @error('slug')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="sort_order" class="form-label">{{ trans('category.sort_order') }}</label>
                                <input type="number" min="0" class="form-control" name="sort_order" id="sort_order" value="{{ old('sort_order', 0) }}">
                                @error('sort_order')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2 form-check">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">{{ trans('category.is_active') }}</label>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="image" class="form-label">{{ trans('category.category_image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('category.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
