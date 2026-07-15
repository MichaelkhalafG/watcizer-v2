@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('category.edit_category') }}
@endsection

@section('content')

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('category.edit_category') }}</h1>
            <nav>
                <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                <li class="breadcrumb-item">{{ trans('sidebar.category') }}</li>
                    <li class="breadcrumb-item active">{{ trans('category.edit_category') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('category.edit_category') }}</h5>

                        <form action="{{ route('category.update' , $category->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-12 mb-2">
                                <label for="name_ar" class="form-label">{{ trans('category.name') }} (ar)</label>
                                <input type="text" class="form-control" name="name[ar]" id="name_ar" value="{{ old('name.ar', optional($category->translate('ar'))->name) }}">
                                @error('name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="name_en" class="form-label">{{ trans('category.name') }} (en)</label>
                                <input type="text" class="form-control" name="name[en]" id="name_en" value="{{ old('name.en', optional($category->translate('en'))->name) }}">
                                @error('name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="description_ar" class="form-label">{{ trans('category.description') }} (ar)</label>
                                <textarea class="form-control" name="description[ar]" id="description_ar" rows="2">{{ old('description.ar', optional($category->translate('ar'))->description) }}</textarea>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="description_en" class="form-label">{{ trans('category.description') }} (en)</label>
                                <textarea class="form-control" name="description[en]" id="description_en" rows="2">{{ old('description.en', optional($category->translate('en'))->description) }}</textarea>
                            </div>

                            <div class="col-12 mb-2">
                                <label for="parent_id" class="form-label">{{ trans('category.parent') }}</label>
                                <select class="form-select" name="parent_id" id="parent_id">
                                    <option value="">{{ trans('category.none_parent') }}</option>
                                    @foreach ($parents as $parent)
                                        <option value="{{ $parent->id }}" {{ old('parent_id', $category->parent_id) == $parent->id ? 'selected' : '' }}>
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
                                <input type="text" class="form-control" name="slug" id="slug" value="{{ old('slug', $category->slug) }}">
                                @error('slug')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2">
                                <label for="sort_order" class="form-label">{{ trans('category.sort_order') }}</label>
                                <input type="number" min="0" class="form-control" name="sort_order" id="sort_order" value="{{ old('sort_order', $category->sort_order) }}">
                                @error('sort_order')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mb-2 form-check">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active" value="1" {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">{{ trans('category.is_active') }}</label>
                            </div>

                            @if ($category->image)
                                <div class="col-12 mb-2">
                                    <img src="{{ asset('Uploads_Images/Category/' . $category->image) }}" height="100px" width="100px" alt="">
                                </div>
                            @endif

                            <div class="col-12 mb-2">
                                <label for="image" class="form-label">{{ trans('category.category_image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                                <div class="col-12 text-center mt-4">
                                    <a href="{{ route('category.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
