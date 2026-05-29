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

                            <div class="col-12">
                                <label for="category_name[ar]" class="form-label">{{ trans('category.category_name') }} ar</label>
                                <input type="text" class="form-control" name="category_name[ar]" data-name="category_name.ar" id="category_name[ar]" value="{{ old('category_name.ar') }}">
                                @error('category_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="category_name[en]" class="form-label">{{ trans('category.category_name') }} en</label>
                                <input type="text" class="form-control" data-name="category_name.en" name="category_name[en]" id="category_name[en]" value="{{ old('category_name.en') }}">
                                @error('category_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="color_value" class="form-label">{{ trans('category.color_value') }}</label>
                                <input type="color" class="form-control form-control-color mb-2" data-name="color_value" name="color_value" id="color_value" value="{{ old('color_value') }}">
                                @error('color_value')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="category_image" class="form-label">{{ trans('category.category_image') }}</label>
                                <input type="file" class="form-control" data-name="category_image" name="category_image" id="category_image">
                                @error('category_image')
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
