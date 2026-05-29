@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('color.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('color.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.color') }}</li>
                    <li class="breadcrumb-item active">{{ trans('color.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('color.add') }}</h5>

                        <form action="{{ route('color.store') }}" method="POST">
                            @csrf

                            <div class="col-12 mb-2">
                                <label for="color_value" class="form-label">{{ trans('color.color') }}</label>
                                <input type="color" class="form-control form-control-color" name="color_value" id="color_value" value="{{ old('color') }}">
                                @error('color_value')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="color_name[ar]" class="form-label">{{ trans('color.color_name') }} ar</label>
                                <input type="text" class="form-control" name="color_name[ar]" data-name="color_name.ar" id="color_name[ar]" value="{{ old('color_name.ar') }}">
                                @error('color_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="color_name[en]" class="form-label">{{ trans('color.color_name') }} en</label>
                                <input type="text" class="form-control" data-name="color_name.en" name="color_name[en]" id="color_name[en]" value="{{ old('color_name.en') }}">
                                @error('color_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('color.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
