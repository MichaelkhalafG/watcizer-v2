@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('shape.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('shape.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.shape') }}</li>
                    <li class="breadcrumb-item active">{{ trans('shape.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('shape.add') }}</h5>

                        <form action="{{ route('shape.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="shape_name[ar]" class="form-label">{{ trans('shape.shape_name') }} ar</label>
                                <input type="text" class="form-control" name="shape_name[ar]" data-name="shape_name.ar"
                                    id="shape_name[ar]" value="{{ old('shape_name.ar') }}">
                                @error('shape_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="shape_name[en]" class="form-label">{{ trans('shape.shape_name') }} en</label>
                                <input type="text" class="form-control" data-name="shape_name.en" name="shape_name[en]"
                                    id="shape_name[en]" value="{{ old('shape_name.en') }}">
                                @error('shape_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('shape.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
