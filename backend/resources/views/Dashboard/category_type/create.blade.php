@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('category_type.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('category_type.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.category_type') }}</li>
                    <li class="breadcrumb-item active">{{ trans('category_type.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('category_type.add') }}</h5>

                        <form action="{{ route('category_type.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <div class="col-12">
                                <label for="category_type_name[ar]" class="form-label">{{ trans('category_type.category_type_name') }} ar</label>
                                <input type="text" class="form-control" name="category_type_name[ar]" data-name="category_type_name.ar" id="category_type_name[ar]" value="{{ old('category_type_name.ar') }}">
                                @error('category_type_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="category_type_name[en]" class="form-label">{{ trans('category_type.category_type_name') }} en</label>
                                <input type="text" class="form-control" data-name="category_type_name.en" name="category_type_name[en]" id="category_type_name[en]" value="{{ old('category_type_name.en') }}">
                                @error('category_type_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('category_type.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('category_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection

