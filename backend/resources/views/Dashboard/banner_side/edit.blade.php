@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('banner_side.edit_banner_side') }}
@endsection

@section('content')

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('banner_side.edit_banner_side') }}</h1>
            <nav>
                <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                <li class="breadcrumb-item">{{ trans('sidebar.banner_side') }}</li>
                    <li class="breadcrumb-item active">{{ trans('banner_side.edit_banner_side') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('banner_side.edit_banner_side') }}</h5>

                        <form action="{{ route('banner_side.update' , $banner_side->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('banner_side.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="offer_id" class="form-label">{{ trans('banner_side.offer_id') }}</label>
                                <select class="form-select select2" name="offer_id" id="offer_id">
                                    <option value="" selected>{{ trans('banner_side.select') }}{{ trans('banner_side.offer_id') }}</option>
                                    @foreach ($offer as $item)
                                        <option value="{{ $item->id }}" @selected(old('offer_id' , $banner_side->offer_id) == $item->id)>{{ $item->offer_name . ' - (' . $item->wa_code . ')' }}</option>
                                    @endforeach
                                </select>
                                @error('offer_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('banner_side.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection

@section('script')
<script>
    $( '.select2' ).select2( {
        theme: "bootstrap-5",
        width: $( this ).data( 'width' ) ? $( this ).data( 'width' ) : $( this ).hasClass( 'w-100' ) ? '100%' : 'style',
        placeholder: $( this ).data( 'placeholder' ),
    } );
</script>
@endsection
