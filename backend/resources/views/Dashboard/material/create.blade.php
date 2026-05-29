@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('material.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('material.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.material') }}</li>
                    <li class="breadcrumb-item active">{{ trans('material.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('material.add') }}</h5>

                        <form action="{{ route('material.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="material_name[ar]" class="form-label">{{ trans('material.material_name') }} ar</label>
                                <input type="text" class="form-control" name="material_name[ar]" data-name="material_name.ar"
                                    id="material_name[ar]" value="{{ old('material_name.ar') }}">
                                @error('material_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="material_name[en]" class="form-label">{{ trans('material.material_name') }} en</label>
                                <input type="text" class="form-control" data-name="material_name.en" name="material_name[en]"
                                    id="material_name[en]" value="{{ old('material_name.en') }}">
                                @error('material_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('material.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
