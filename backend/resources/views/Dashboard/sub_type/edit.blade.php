@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sub_type.edit_sub_type') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sub_type.edit_sub_type') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.sub_type') }}</li>
                    <li class="breadcrumb-item active">{{ trans('sub_type.edit_sub_type') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('sub_type.edit_sub_type') }}</h5>

                        <form action="{{ route('sub_type.update', $sub_type->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-12">
                                <label for="sub_type_name[ar]"
                                    class="form-label">{{ trans('sub_type.sub_type_name') }}</label>
                                <input type="text" class="form-control" name="sub_type_name[ar]" id="sub_type_name[ar]"
                                    value="{{ old('sub_type_name.ar', $sub_type->translate('ar')->sub_type_name) }}">
                                @error('sub_type_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="sub_type_name[en]" class="form-label">{{ trans('sub_type.sub_type_name') }}
                                    en</label>
                                <input type="text" class="form-control" name="sub_type_name[en]" id="sub_type_name[en]"
                                    value="{{ old('sub_type_name.en', $sub_type->translate('en')->sub_type_name) }}">
                                @error('sub_type_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('grade.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('sub_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
