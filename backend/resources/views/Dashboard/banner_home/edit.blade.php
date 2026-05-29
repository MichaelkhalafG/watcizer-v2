@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('banner_home.edit_banner_home') }}
@endsection

@section('content')

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('banner_home.edit_banner_home') }}</h1>
            <nav>
                <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                <li class="breadcrumb-item">{{ trans('sidebar.banner_home') }}</li>
                    <li class="breadcrumb-item active">{{ trans('banner_home.edit_banner_home') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('banner_home.edit_banner_home') }}</h5>

                        <form action="{{ route('banner_home.update' , $banner_home->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('banner_home.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 mt-2">
                                <label for="type_show" class="form-label">{{ trans('banner_home.type_show') }}</label>
                                <select class="form-select select2" name="type_show" id="type_show">
                                    <option value="" selected>{{ trans('banner_home.select') }}{{ trans('banner_home.type_show') }}</option>
                                    <option value="pc" @selected($banner_home->type_show == 'pc')>Pc</option>
                                    <option value="mob" @selected($banner_home->type_show == 'mob')>Mob</option>
                                </select>
                                @error('type_show')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="offer_id" class="form-label">{{ trans('banner_home.product_id') }}</label>
                                <select class="form-select select2" name="offer_id" id="offer_id">
                                    <option value="" selected>{{ trans('banner_home.select') }}{{ trans('banner_home.product_id') }}</option>
                                    @foreach ($offer as $item)
                                        <option value="{{ $item->id }}" @selected(old('offer_id' , $banner_home->offer_id) == $item->id)>{{ $item->offer_name . ' - (' . $item->wa_code . ')' }}</option>
                                    @endforeach
                                </select>
                                @error('offer_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                                <div class="col-12 text-center mt-4">
                                    <a href="{{ route('banner_home.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
