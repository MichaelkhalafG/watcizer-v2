@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.product') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.product') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.product') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
        <div class="col-6 text-end">
            <a class="btn btn-primary" href="{{ route('product.create') }}">{{ trans('product.add') }}</a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="d-flex justify-content-start">
                    <a href="{{ route('product.export') }}" class="btn btn-primary mb-1">{{ trans('mainBtn.export') }}</a>
                    <form action="{{ route('product.import') }}" method="POST" enctype="multipart/form-data" class="mb-0 ms-2">
                        @csrf
                        <div class="input-group">
                            <input type="file" name="import" class="form-control">
                            <button class="btn btn-primary" type="submit">{{ trans('mainBtn.import') }}</button>
                        </div>
                        @error('import')
                            <p class="text-danger">{{ $message }}</p>
                        @enderror
                    </form>
                </div>

                <div class="card">
                    <div class="card-body table-responsive" >
                        <div class="row">
                            <h5 class="card-title col-6">{{ trans('sidebar.product') }}</h5>

                            <form method="GET" action="{{ route('product.index') }}" class="align-items-end gy-2 gx-3 d-flex justify-content-center col-6">

                                <div class="col-md-4 input-group">
                                    <input type="number" class="form-control" name="quantity" id="quantity" value="{{ old('quantity', $quantity) }}" placeholder="{{ trans('product.qty_filter') }}" min="0">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-funnel-fill"></i> {{ trans('product.filter') }}
                                    </button>
                                    @if(request()->has('quantity') || $quantity !== null)
                                        <a href="{{ route('product.index', ['clear_filter' => 1]) }}" class="btn btn-danger">
                                            <i class="bi bi-x-circle me-1"></i> {{ trans('product.delete_filter') }}
                                        </a>
                                    @endif
                                </div>

                            </form>
                        </div>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('product.product_title') }}</th>
                                    <th>{{ trans('product.wa_code') }}</th>
                                    <th>{{ trans('product.stock') }}</th>
                                    <th>{{ trans('product.market_stock') }}</th>
                                    <th>{{ trans('product.active') }}</th>
                                    <th>{{ trans('product.product_image') }}</th>
                                    <th>{{ trans('product.created_by') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($product as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->product_title }}</td>
                                        <td>{{ $item->wa_code }}</td>
                                        <td>{{ $item->stock*1 }}</td>
                                        <td>{{ $item->market_stock*1 }}</td>
                                        <td>{{ $item->active == 1 ? trans('product.yes') : trans('product.no') }}</td>
                                        <td>
                                            <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Product/' . $item->image) }}"><img src="{{ asset('Uploads_Images/Product/' . $item->image) }}" height="100px" width="100px" alt=""></a>
                                        </td>
                                        <td>{{ $item->created_by_first_name . ' ' . $item->created_by_last_name }}</td>
                                        <td>
                                            <div class="d-flex justify-content-center pt-4">
                                                <a class="btn btn-sm btn-success" href="{{ route('product.show', $item->id) }}" role="button">{{ trans('mainBtn.show') }}</a>

                                                @if (auth()->user()->id == $item->created_by || auth()->user()->type == 'SuperAdmin')
                                                    <a class="btn btn-sm btn-info ms-2" href="{{ route('product.edit', $item->id) }}" role="button">{{ trans('mainBtn.edit') }}</a>
                                                @endif

                                                @can('AnyAction')
                                                    <form class="mb-0" action="{{ route('product.destroy', $item->id) }}"
                                                        method="post">
                                                        @csrf
                                                        @method('delete')
                                                        <button type="submit"
                                                            class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
                                                    </form>
                                                @endcan

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
