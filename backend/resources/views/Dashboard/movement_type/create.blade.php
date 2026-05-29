@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('movement_type.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('movement_type.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.movement_type') }}</li>
                    <li class="breadcrumb-item active">{{ trans('movement_type.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('movement_type.add') }}</h5>

                        <form action="{{ route('movement_type.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="movement_type_name[ar]" class="form-label">{{ trans('movement_type.movement_type_name') }} ar</label>
                                <input type="text" class="form-control" name="movement_type_name[ar]" data-name="movement_type_name.ar"
                                    id="movement_type_name[ar]" value="{{ old('movement_type_name.ar') }}">
                                @error('movement_type_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="movement_type_name[en]" class="form-label">{{ trans('movement_type.movement_type_name') }} en</label>
                                <input type="text" class="form-control" data-name="movement_type_name.en" name="movement_type_name[en]"
                                    id="movement_type_name[en]" value="{{ old('movement_type_name.en') }}">
                                @error('movement_type_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('movement_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
