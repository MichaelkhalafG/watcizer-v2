@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.offer') }}
@endsection

@section('content')

        <div class="row">
            <div class="pagetitle col-6">
                <h1>{{ trans('sidebar.offer') }}</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                        <li class="breadcrumb-item active">{{ trans('sidebar.offer') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- End Page Title -->
            <div class="col-6 text-end">
                <a class="btn btn-primary" href="{{ route('offer.create') }}">{{ trans('offer.add') }}</a>
            </div>
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="card">
                        <div class="card-body table-responsive">
                                <h5 class="card-title">{{ trans('sidebar.offer') }}</h5>

                                <!-- Table with stripped rows -->
                                <table class="table table-striped table-bordered" id="myTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>{{ trans('offer.offer_name') }}</th>
                                            <th>{{ trans('offer.main_product_id') }}</th>
                                            <th>{{ trans('offer.gift_product_ids') }}</th>
                                            <th>{{ trans('offer.category_type_id') }}</th>
                                            <th>{{ trans('offer.price') }}</th>
                                            <th>{{ trans('offer.stock') }}</th>
                                            <th>{{ trans('offer.average_rate') }}</th>
                                            <th>{{ trans('offer.image') }}</th>
                                            <th>{{ trans('mainBtn.action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($offer as $item)
                                            <tr>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>{{ $item->offer_name }}</td>
                                                <td><a href="{{ route('product.show' , $item->main_product_id) }}" class="link-dark"> {{ $item->mainProduct->product_title . ' - (' . $item->mainProduct->wa_code . ')' }} <i class="text-primary bi bi-eye-fill"></i> </a> </td>
                                                <td>
                                                    @foreach ($item->giftProducts() as $key)
                                                    <a href="{{ route('product.show' , $key->id) }}" class="link-dark"> {{ $key->product_title . ' - (' . $key->wa_code . ')' }} <i class="text-primary bi bi-eye-fill"></i> </a> <br>
                                                    @endforeach
                                                </td>
                                                <td>{{ $item->category_type->category_type_name }}</td>
                                                <td>
                                                    @if ($item->sale_price_after_discount != null)
                                                        {{ $item->sale_price_after_discount * 1 }}
                                                    @else
                                                        {{ $item->selling_price * 1 }}
                                                    @endif
                                                    {{ trans('mainBtn.pounds') }}
                                                </td>
                                                <td>{{ $item->stock }}</td>
                                                <td>
                                                    @if ($item->average_rate)
                                                        @php
                                                            $fullStars = floor($item->average_rate);
                                                            $halfStar = $item->average_rate - $fullStars >= 0.5 ? 1 : 0;
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
                                                </td>
                                                <td>
                                                    <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Offer/' . $item->image) }}"><img src="{{ asset('Uploads_Images/Offer/' . $item->image) }}" height="100px" width="100px" alt=""></a>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-center pt-4">
                                                            <a class="btn btn-sm btn-success" href="{{ route('offer.show', $item->id) }}" role="button">{{ trans('mainBtn.show') }}</a>

                                                            <a class="btn btn-sm btn-info ms-2" href="{{ route('offer.edit', $item->id) }}" role="button">{{ trans('mainBtn.edit') }}</a>

                                                            <form class="mb-0" action="{{ route('offer.destroy' , $item->id) }}"
                                                                method="post">
                                                                @csrf
                                                                @method('delete')
                                                                <button type="submit"
                                                                    class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
                                                            </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <!-- End Table with stripped rows -->

                        </div>
                    </div>

                </div>
            </div>
        </section>

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
