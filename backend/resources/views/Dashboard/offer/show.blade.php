@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('offer.show_offer') }}
@endsection
@section('css')
@endsection

@section('content')
    <link href="{{ asset('DashAssets/css/product_detail.css') }}" rel="stylesheet">

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('offer.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.offer') }}</li>
                    <li class="breadcrumb-item active">{{ trans('offer.show_offer') }} ( {{ $offer->offer_name }} )
                    </li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <div class="detail-container d-flex justify-content-between">

        <!-- Product Details -->
        <div class="offer-detail row">
            <!-- Images -->
            <div class="offer-images col-6">
                <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Offer') . '/' . $offer->image }}">
                    <img src="{{ asset('Uploads_Images/Offer') . '/' . $offer->image }}" alt="Main Product Image"
                        class="main-image" >
                </a>
            </div>

            <!-- Info -->
            <div class="offer-info col-6">
                <h1><b>{{ $offer->offer_name }} </b></h1>
                <p class="sku">{{ trans('offer.wa_code') }}: {{ $offer->wa_code }}</p>

                <div class="d-flex">
                    <h5 class="fw-bold text-decoration-line-through me-2">{{ $offer->selling_price * 1 }} {{ trans('mainBtn.pounds') }}</h5>
                    <h5 class="fw-bold">{{ $offer->sale_price_after_discount * 1 }}  {{ trans('mainBtn.pounds') }}</h5>
                </div>

                <h6 class="fw-bold">{{ trans('offer.stock') }}: {{ $offer->stock * 1 }}</h6>

                <!-- Options -->
                <div class="options">
                    <h4 class="option-label">{{ trans('offer.offer_specifications') }}:</h4>
                    <div class="row g-3">

                        <div class="col-lg-12">

                                <li>
                                    <b class="d-flax">{{ trans('offer.main_product_id') }} </b> : <a href="{{ route('product.show' , $offer->main_product_id) }}" class="link-dark"> {{ $offer->mainProduct->product_title . ' - (' . $offer->mainProduct->wa_code . ')' }} <i class="text-primary bi bi-eye-fill"></i> </a>
                                </li>

                                <li>
                                    <b>{{ trans('offer.gift_product_ids') }} </b> :
                                    @foreach ($offer->giftProducts() as $key)
                                        <li style="margin-right: 150px; display: flex;">
                                            <a href="{{ route('product.show' , $key->id) }}" class="link-dark"> {{ $key->product_title . ' - (' . $key->wa_code . ')' }} <i class="text-primary bi bi-eye-fill"></i> </a>
                                        </li>
                                    @endforeach
                                </li>

                                <li>
                                    <b class="d-flax">{{ trans('offer.category_type_id') }} </b> : {{ $offer->category_type->category_type_name }}
                                </li>

                                <li>
                                    <b class="d-flax">{{ trans('offer.average_rate') }} </b> :
                                    @if ($offer->average_rate)
                                        @php
                                            $fullStars = floor($offer->average_rate);
                                            $halfStar = $offer->average_rate - $fullStars >= 0.5 ? 1 : 0;
                                            $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                        @endphp
                                        @for ($i = 1; $i <= $fullStars; $i++)
                                            <i class="bi bi-star-fill"></i>
                                        @endfor

                                        @if ($halfStar == 1)
                                            <i class="bi bi-star-half"></i>
                                        @endif

                                        @for ($i = 1; $i <= $emptyStars; $i++)
                                            <i class="bi bi-star"></i>
                                        @endfor
                                    @endif
                                </li>

                                <li>
                                    <b class="d-flax">{{ trans('offer.in_season') }} </b> : {{ $offer->in_season == 'yes' ? trans('offer.yes'):trans('offer.no') }}
                                </li>

                                <li>
                                    <b class="d-flax">{{ trans('offer.short_description') }} </b> : {{ $offer->short_description }}
                                </li>

                                <li>
                                    <b class="d-flax">{{ trans('offer.long_description') }} </b> : {{ $offer->long_description }}
                                </li>

                        </div>

                        {{-- <div class="col-lg-6">

                            @if ($offer->long_description)
                                <li>
                                    <b>{{ trans('offer.long_description') }}</b> : {{ $offer->long_description }}
                                </li>
                            @endif

                        </div> --}}

                        {{-- <div class="col-lg-12"> --}}

                            {{-- @if ($offer->average_rate)
                                <li>
                                    <b>{{ trans('offer.average_rate') }}</b> :
                                    @php
                                        $fullStars = floor($offer->average_rate);
                                        $halfStar = $offer->average_rate - $fullStars >= 0.5 ? 1 : 0;
                                        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                    @endphp
                                    @for ($i = 1; $i <= $fullStars; $i++)
                                        <i class="bi bi-star-fill"></i>
                                    @endfor

                                    @if ($halfStar == 1)
                                        <i class="bi bi-star-half"></i>
                                    @endif

                                    @for ($i = 1; $i <= $emptyStars; $i++)
                                        <i class="bi bi-star"></i>
                                    @endfor
                                </li>
                            @endif

                            @foreach ($offer->offer_rating as $item)
                                <div class="card mb-1" style="max-width: 540px;">
                                    <div class="row g-0">
                                        <div class="col-md-12">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between">
                                                    <h5 class="card-title">{{ $item->user->name }}</h5>
                                                    <h6 class="mt-3">
                                                        @for ($i = 1; $i <= $item->rating; $i++)
                                                            <span>★</span>
                                                        @endfor
                                                        @for ($i = $item->rating + 1; $i <= 5; $i++)
                                                            <span>☆</span>
                                                        @endfor
                                                    </h6>
                                                </div>
                                            <p class="card-text">{{ $item->comment }}</p>
                                            <p class="card-text"><small class="text-body-secondary">{{ \Carbon\Carbon::parse($item->created_at)->format('Y-m-d H:i') }}</small></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                        </div> --}}

                </div>

            </div>
        </div>
    </div>

@endsection

@section('script')
    <script>
        lightbox.option({
            'resizeDuration': 200,
            'fadeDuration': 100,
            'imageFadeDuration': 100,
            'wrapAround': false,
            'albumLabel': "",
        });
    </script>
@endsection
