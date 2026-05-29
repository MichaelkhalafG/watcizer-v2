@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('grade.edit_grade') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('grade.edit_grade') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.grade') }}</li>
                    <li class="breadcrumb-item active">{{ trans('grade.edit_grade') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('grade.edit_grade') }}</h5>

                        <form action="{{ route('grade.update', $grade->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-12">
                                <label for="grade_name[ar]" class="form-label">{{ trans('grade.grade_name') }}</label>
                                <input type="text" class="form-control" name="grade_name[ar]" id="grade_name[ar]"
                                    value="{{ old('grade_name.ar', $grade->translate('ar')->grade_name) }}">
                                @error('grade_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="grade_name[en]" class="form-label">{{ trans('grade.grade_name') }} en</label>
                                <input type="text" class="form-control" name="grade_name[en]" id="grade_name[en]"
                                    value="{{ old('grade_name.en', $grade->translate('en')->grade_name) }}">
                                @error('grade_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="description[ar]" class="form-label">{{ trans('grade.description') }}</label>
                                <textarea  class="form-control" name="description[ar]" id="description[ar]" cols="30" rows="2">{{ old('description.ar', $grade->translate('ar')->description) }}</textarea>
                                @error('description.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="description[en]" class="form-label">{{ trans('grade.description') }} en</label>
                                <textarea  class="form-control" name="description[en]" id="description[en]" cols="30" rows="2">{{ old('description.en', $grade->translate('en')->description) }}</textarea>
                                @error('description.en')
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
                                <a href="{{ route('grade.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
