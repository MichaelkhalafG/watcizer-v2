@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('feature.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('feature.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.feature') }}</li>
                    <li class="breadcrumb-item active">{{ trans('feature.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('feature.add') }}</h5>

                        <form action="{{ route('feature.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="feature_name[ar]" class="form-label">{{ trans('feature.feature_name') }} ar</label>
                                <input type="text" class="form-control" name="feature_name[ar]" data-name="feature_name.ar"
                                    id="feature_name[ar]" value="{{ old('feature_name.ar') }}">
                                @error('feature_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="feature_name[en]" class="form-label">{{ trans('feature.feature_name') }} en</label>
                                <input type="text" class="form-control" data-name="feature_name.en" name="feature_name[en]"
                                    id="feature_name[en]" value="{{ old('feature_name.en') }}">
                                @error('feature_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('feature.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
