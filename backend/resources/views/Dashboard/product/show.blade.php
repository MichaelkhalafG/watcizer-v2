@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('product.show_product') }}
@endsection
@section('css')
@endsection

@section('content')
    <link href="{{ asset('DashAssets/css/product_detail.css') }}" rel="stylesheet">

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('product.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.product') }}</li>
                    <li class="breadcrumb-item active">{{ trans('product.show_product') }} ( {{ $product->product_title }} )
                    </li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <div class="detail-container">

        <!-- Product Details -->
        <div class="product-detail">
            <!-- Images -->
            <div class="product-images">
                <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Product') . '/' . $product->image }}">
                    <img src="{{ asset('Uploads_Images/Product') . '/' . $product->image }}" alt="Main Product Image"
                        class="main-image" >
                </a>
                <div class="thumbnail-images">
                    @foreach ($product->product_image as $item)
                        <form class="mb-0" action="{{ route('product_image.destroy' , $item->id) }}" method="post">
                            @csrf
                            @method('delete')
                            <button type="submit" class="btn btn-sm btn-danger ms-2">x</button>
                        </form>
                        <a data-lightbox="single-image"
                            href="{{ asset('Uploads_Images/Product_image') . '/' . $item->image }}">
                            <img src="{{ asset('Uploads_Images/Product_image') . '/' . $item->image }}" alt="Thumbnail 1">
                        </a>
                    @endforeach
                </div>
            </div>

            <!-- Info -->
            <div class="product-info">
                <h1><b>{{ $product->product_title }} {{ $product->brand->brand_name }} </b></h1>
                <p class="sku">{{ trans('product.wa_code') }}: {{ $product->wa_code }} |
                    {{ $product->brand->brand_name }}</p>
                @if ($product->sale_price_after_discount > 0)
                    <div class="d-flex">
                        <h5 class="fw-bold text-decoration-line-through me-2">{{ $product->selling_price * 1 }} {{ trans('mainBtn.pounds') }}</h5>
                        <h5 class="fw-bold me-2">- %{{ $product->percentage_discount * 1 }}</h5>
                        <h5 class="fw-bold">{{ $product->sale_price_after_discount * 1 }}  {{ trans('mainBtn.pounds') }}</h5>
                    </div>
                @else
                    <h5 class="fw-bold">{{ $product->selling_price * 1 }} {{ trans('mainBtn.pounds') }}</h5>
                @endif
                <h6 class="fw-bold">{{ trans('product.purchase_price') }}: {{ $product->purchase_price * 1 }} {{ trans('mainBtn.pounds') }}</h6>
                <h6 class="fw-bold">{{ trans('product.stock') }}: {{ $product->stock * 1 }}</h6>
                <h6 class="fw-bold">{{ trans('product.market_stock') }}: {{ $product->market_stock * 1 }}</h6>

                <!-- Options -->
                <div class="options">
                    <h4 class="option-label">{{ trans('product.product_specifications') }}:</h4>
                    <div class="row g-3">

                        <div class="col-lg-6">

                            @if ($product->model_name)
                                <li>
                                    <b>{{ trans('product.model_name') }}</b> : {{ $product->model_name }}
                                </li>
                            @endif

                            @if ($product->grade)
                                <li>
                                    <b>{{ trans('product.grade_id') }}</b> : {{ $product->grade->grade_name }}
                                </li>
                            @endif

                            @if ($product->sub_type)
                                <li>
                                    <b>{{ trans('product.sub_type_id') }}</b> : {{ $product->sub_type->sub_type_name }}
                                </li>
                            @endif

                            @if ($product->category_type)
                                <li>
                                    <b>{{ trans('product.category_type_id') }}</b> :
                                    {{ $product->category_type->category_type_name }}
                                </li>
                            @endif

                            @if ($product->closure_type)
                                <li>
                                    <b>{{ trans('product.band_closure_id') }}</b> :
                                    {{ $product->closure_type->closure_type_name }}
                                </li>
                            @endif

                            @if ($product->shape)
                                <li>
                                    <b>{{ trans('product.case_shape_id') }}</b> : {{ $product->shape->shape_name }}
                                </li>
                            @endif

                            @if ($product->movement_type)
                                <li>
                                    <b>{{ trans('product.watch_movement_id') }}</b> :
                                    {{ $product->movement_type->movement_type_name }}
                                </li>
                            @endif

                            @if ($product->water_resistance && $product->waterResistanceSizeType)
                                <li>
                                    <b>{{ trans('product.water_resistance') }}</b> : {{ $product->water_resistance * 1 }}
                                    {{ $product->waterResistanceSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->case_thickness && $product->caseThicknessSizeType)
                                <li>
                                    <b>{{ trans('product.case_thickness') }}</b> : {{ $product->case_thickness * 1 }}
                                    {{ $product->caseThicknessSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->dialGlassMaterial)
                                <li>
                                    <b>{{ trans('product.dial_glass_material_id') }}</b> :
                                    {{ $product->dialGlassMaterial->material_name }}
                                </li>
                            @endif

                            @if ($product->watch_width && $product->watchWidthSizeType)
                                <li>
                                    <b>{{ trans('product.watch_width') }}</b> : {{ $product->watch_width * 1 }}
                                    {{ $product->watchWidthSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->warranty_years)
                                <li>
                                    <b>{{ trans('product.warranty_years') }}</b> : {{ $product->warranty_years }}
                                </li>
                            @endif

                            @if ($product->interchangeable_strap === 0 || $product->interchangeable_strap == 1)
                                <li>
                                    <b>{{ trans('product.interchangeable_strap') }}</b> :
                                    {{ $product->interchangeable_strap == 1 ? trans('product.yes') : trans('product.no') }}
                                </li>
                            @endif

                            @if ($product->active == 0 || $product->active == 1)
                                <li>
                                    <b>{{ trans('product.active') }}</b> :
                                    {{ $product->active == 1 ? trans('product.yes') : trans('product.no') }}
                                </li>
                            @endif

                        </div>

                        <div class="col-lg-6">

                            @if ($product->model_number)
                                <li>
                                    <b>{{ trans('product.model_number') }}</b> : {{ $product->model_number }}
                                </li>
                            @endif

                            @if ($product->display_type)
                                <li>
                                    <b>{{ trans('product.dial_display_type_id') }}</b> :
                                    {{ $product->display_type->display_type_name }}
                                </li>
                            @endif

                            @if ($product->case_size && $product->caseSizeType)
                                <li>
                                    <b>{{ trans('product.case_size') }}</b> : {{ $product->case_size * 1 }}
                                    {{ $product->caseSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->bandMaterial)
                                <li>
                                    <b>{{ trans('product.band_material_id') }}</b> :
                                    {{ $product->bandMaterial->material_name }}
                                </li>
                            @endif

                            @if ($product->band_length && $product->bandSizeType)
                                <li>
                                    <b>{{ trans('product.band_length') }}</b> : {{ $product->band_length * 1 }}
                                    {{ $product->bandSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->band_width && $product->bandWidthSizeType)
                                <li>
                                    <b>{{ trans('product.band_width') }}</b> : {{ $product->band_width * 1 }}
                                    {{ $product->bandWidthSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->dialCaseMaterial)
                                <li>
                                    <b>{{ trans('product.dial_case_material_id') }}</b> :
                                    {{ $product->dialCaseMaterial->material_name }}
                                </li>
                            @endif

                            @if ($product->watch_height && $product->watchHeightSizeType)
                                <li>
                                    <b>{{ trans('product.watch_height') }}</b> : {{ $product->watch_height * 1 }}
                                    {{ $product->watchHeightSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->watch_length && $product->watchLengthSizeType)
                                <li>
                                    <b>{{ trans('product.watch_length') }}</b> : {{ $product->watch_length * 1 }}
                                    {{ $product->watchLengthSizeType->size_type_name }}
                                </li>
                            @endif

                            @if ($product->interchangeable_dial === 0 || $product->interchangeable_dial == 1)
                                <li>
                                    <b>{{ trans('product.interchangeable_dial') }}</b> :
                                    {{ $product->interchangeable_dial == 1 ? trans('product.yes') : trans('product.no') }}
                                </li>
                            @endif

                            @if ($product->watch_box === 0 || $product->watch_box == 1)
                                <li>
                                    <b>{{ trans('product.watch_box') }}</b> :
                                    {{ $product->watch_box == 1 ? trans('product.yes') : trans('product.no') }}
                                </li>
                            @endif

                            @if ($product->sku_unique)
                                <li>
                                    <b>{{ trans('product.SKU') }}</b> : {{ $product->sku_unique }}
                                </li>
                            @endif

                            @if ($product->stone)
                                <li>
                                    <b>{{ trans('product.stone') }}</b> : {{ $product->stone }}
                                </li>
                            @endif

                            @if ($product->country)
                                <li>
                                    <b>{{ trans('product.country') }}</b> : {{ $product->country }}
                                </li>
                            @endif

                            @if ($product->search_keywords)
                                <li>
                                    <b>{{ trans('product.search_keywords') }}</b> : {{ $product->search_keywords }}
                                </li>
                            @endif

                        </div>

                        <div class="col-lg-12">

                            @if ($product->average_rate)
                                <li>
                                    <b>{{ trans('product.average_rate') }}</b> :
                                    @php
                                        $fullStars = floor($product->average_rate);
                                        $halfStar = $product->average_rate - $fullStars >= 0.5 ? 1 : 0;
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

                            @if ($product->feature)
                                <li>
                                    <b>{{ trans('product.feature_id') }}</b> :
                                    @foreach ($product->feature as $item)
                                        {{ $item->feature_name }} @if (!$loop->last)
                                            -
                                        @endif
                                    @endforeach
                                </li>
                            @endif

                            @if ($product->gender)
                                <li>
                                    <b>{{ trans('product.gender_id') }}</b> :
                                    @foreach ($product->gender as $item)
                                        {{ $item->gender_name }} @if (!$loop->last)
                                            -
                                        @endif
                                    @endforeach
                                </li>
                            @endif

                            @if ($product->dialColor->count() > 0)
                                <div class="d-flex">
                                    <li></li>
                                    <b>{{ trans('product.dial_color_id') }}</b> :
                                    @foreach ($product->dialColor as $item)
                                        <span style="background-color:{{ $item->color_value }}; color: white; display: block; padding: 2px 4px; margin-right: 4px; border-radius: 5px;">{{ $item->color_name ?? trans('product.none_name') }}</span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($product->bandColor->count() > 0)
                                <div class="d-flex mt-1">
                                    <li></li>
                                    <b>{{ trans('product.band_color_id') }}</b> :
                                    @foreach ($product->bandColor as $item)
                                        <span style="background-color:{{ $item->color_value }}; color: white; display: block; padding: 2px 4px; margin-right: 4px; border-radius: 5px;">{{ $item->color_name ?? trans('product.none_name') }} </span>
                                    @endforeach
                                </div>
                            @endif

                        </div>

                        @if ($product->short_description)
                            <li>
                                <b>{{ trans('product.short_description') }}</b> : {{ $product->short_description }}
                            </li>
                        @endif

                        @if ($product->long_description)
                            <li>
                                <b>{{ trans('product.long_description') }}</b> : {{ $product->long_description }}
                            </li>
                        @endif

                        @foreach ($product->product_rating as $item)
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

                    </div>

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
