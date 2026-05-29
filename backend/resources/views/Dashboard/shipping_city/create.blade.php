@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('shipping_city.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('shipping_city.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.shipping_city') }}</li>
                    <li class="breadcrumb-item active">{{ trans('shipping_city.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('shipping_city.add') }}</h5>

                        <form action="{{ route('shipping_city.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="city_name[ar]" class="form-label">{{ trans('shipping_city.city_name') }} ar</label>
                                <input type="text" class="form-control" name="city_name[ar]" id="city_name[ar]" value="{{ old('city_name.ar') }}">
                                @error('city_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="city_name[en]" class="form-label">{{ trans('shipping_city.city_name') }} en</label>
                                <input type="text" class="form-control" name="city_name[en]"
                                    id="city_name[en]" value="{{ old('city_name.en') }}">
                                @error('city_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="shipping_cost" class="form-label">{{ trans('shipping_city.shipping_cost') }}</label>
                                <input type="number" min="0" class="form-control" name="shipping_cost"
                                    id="shipping_cost" value="{{ old('shipping_cost') }}">
                                @error('shipping_cost')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('shipping_city.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
