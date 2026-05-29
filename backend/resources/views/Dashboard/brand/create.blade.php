@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('brand.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('brand.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.brand') }}</li>
                    <li class="breadcrumb-item active">{{ trans('brand.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('brand.add') }}</h5>

                        <form action="{{ route('brand.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <div class="col-12">
                                <label for="brand_name[ar]" class="form-label">{{ trans('brand.brand_name') }} ar</label>
                                <input type="text" class="form-control" name="brand_name[ar]" data-name="brand_name.ar"
                                    id="brand_name[ar]" value="{{ old('brand_name.ar') }}">
                                @error('brand_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="brand_name[en]" class="form-label">{{ trans('brand.brand_name') }} en</label>
                                <input type="text" class="form-control" data-name="brand_name.en" name="brand_name[en]"
                                    id="brand_name[en]" value="{{ old('brand_name.en') }}">
                                @error('brand_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('brand.image') }}</label>
                                <input type="file" class="form-control" data-name="image" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('brand.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
