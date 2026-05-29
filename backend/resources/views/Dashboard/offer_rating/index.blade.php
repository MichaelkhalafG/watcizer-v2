@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.offer_rating') }}
@endsection

@section('content')

        <div class="row">
            <div class="pagetitle col-6">
                <h1>{{ trans('sidebar.offer_rating') }}</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                        <li class="breadcrumb-item active">{{ trans('sidebar.offer_rating') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- End Page Title -->
            <div class="col-6 text-end">
                <a class="btn btn-primary" href="{{ route('offer_rating.create') }}">{{ trans('offer_rating.add') }}</a>
            </div>
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="card">
                        <div class="card-body table-responsive">
                                <h5 class="card-title">{{ trans('sidebar.offer_rating') }}</h5>

                                <!-- Table with stripped rows -->
                                <table class="table table-striped table-bordered" id="myTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>{{ trans('offer_rating.offer_id') }}</th>
                                            <th>{{ trans('offer_rating.user_id') }}</th>
                                            <th>{{ trans('offer_rating.rating') }}</th>
                                            <th>{{ trans('offer_rating.comment') }}</th>
                                            <th>{{ trans('offer_rating.date') }}</th>
                                            <th>{{ trans('mainBtn.action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($offer_rating as $item)
                                            <tr>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>{{ $item->offer->mainProduct->product_title . ' - (' . $item->offer->mainProduct->wa_code . ')' }}</td>
                                                <td>{{ $item->user->first_name . ' ' . $item->user->last_name }}</td>
                                                <td>
                                                    @for ($i = 1; $i <= $item->rating; $i++)
                                                    <span>★</span>
                                                    @endfor
                                                    @for ($i = $item->rating + 1; $i <= 5; $i++)
                                                    <span>☆</span>
                                                    @endfor
                                                </td>
                                                <td>{{ $item->comment }}</td>
                                                <td>{{ \Carbon\Carbon::parse($item->created_at)->format('Y-m-d h:i A') }}</td>
                                                <td>
                                                    <div class="d-flex justify-content-center">
                                                        <a class="btn btn-sm btn-info" href="{{ route('offer_rating.edit', $item->id ) }}" role="button">{{ trans('mainBtn.edit') }}</a>
                                                        <form class="mb-0" action="{{ route('offer_rating.destroy' , $item->id) }}" method="post">
                                                            @csrf
                                                            @method('delete')
                                                            <button type="submit" class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
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
