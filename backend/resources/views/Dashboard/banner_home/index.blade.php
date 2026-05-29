@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.banner_home') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.banner_home') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.banner_home') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
        <div class="col-6 text-end">
            <a class="btn btn-primary" href="{{ route('banner_home.create') }}">{{ trans('banner_home.add') }}</a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body table-responsive">
                        <h5 class="card-title">{{ trans('sidebar.banner_home') }}</h5>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('banner_home.image') }}</th>
                                    <th>{{ trans('banner_home.type_show') }}</th>
                                    <th>{{ trans('banner_home.product_id') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($banner_home as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>
                                            <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Banner_home/' . $item->image) }}"><img src="{{ asset('Uploads_Images/Banner_home/' . $item->image) }}" height="100px" width="300px" alt=""></a>
                                        </td>
                                        <td>{{ $item->type_show }}</td>
                                        <td>
                                            @if ($item->offer_id != null)
                                            <a href="{{ route('offer.show' , $item->offer_id) }}" class="link-dark"> {{ $item->offer->offer_name . ' - (' . $item->offer->wa_code . ')' }} <i class="text-primary bi bi-eye-fill"></i> </a>

                                            @else
                                                {{ trans('banner_home.no_product') }}
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center">
                                                <a class="btn btn-sm btn-info"
                                                    href="{{ route('banner_home.edit', $item->id) }}"
                                                    role="button">{{ trans('mainBtn.edit') }}</a>
                                                <form class="mb-0" action="{{ route('banner_home.destroy', $item->id) }}"
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
