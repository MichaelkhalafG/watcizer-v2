@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('offer_rating.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('offer_rating.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.offer_rating') }}</li>
                    <li class="breadcrumb-item active">{{ trans('offer_rating.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('offer_rating.add') }}</h5>

                        <form action="{{ route('offer_rating.store') }}" method="POST">
                            @csrf

                            <div class="col-12">
                                <label for="offer_id" class="form-label">{{ trans('offer_rating.offer_id') }}</label>
                                <select class="form-select select2" name="offer_id" id="offer_id">
                                    <option value="" selected>{{ trans('offer_rating.select') }}{{ trans('offer_rating.offer_id') }}</option>
                                    @foreach ($offer as $item)
                                        <option value="{{ $item->id }}" @selected(old('offer_id') == $item->id)>{{ $item->mainProduct->product_title . ' - (' . $item->mainProduct->wa_code . ')' }}</option>
                                    @endforeach
                                </select>
                                @error('offer_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="rating" class="form-label">{{ trans('offer_rating.rating') }}</label>
                                <input type="number" min="1" max="5" class="form-control" name="rating" id="rating" value="{{ old('rating') }}">
                                @error('rating')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="comment" class="form-label">{{ trans('offer_rating.comment') }}</label>
                                <textarea class="form-control" name="comment" id="comment" cols="30" rows="3">{{ old('comment') }}</textarea>
                                @error('comment')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('offer_rating.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
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
