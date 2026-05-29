@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('size_type.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('size_type.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.size_type') }}</li>
                    <li class="breadcrumb-item active">{{ trans('size_type.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('size_type.add') }}</h5>

                        <form action="{{ route('size_type.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="size_type_name[ar]" class="form-label">{{ trans('size_type.size_type_name') }} ar</label>
                                <input type="text" class="form-control" name="size_type_name[ar]" data-name="size_type_name.ar"
                                    id="size_type_name[ar]" value="{{ old('size_type_name.ar') }}">
                                @error('size_type_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="size_type_name[en]" class="form-label">{{ trans('size_type.size_type_name') }} en</label>
                                <input type="text" class="form-control" data-name="size_type_name.en" name="size_type_name[en]"
                                    id="size_type_name[en]" value="{{ old('size_type_name.en') }}">
                                @error('size_type_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('size_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
