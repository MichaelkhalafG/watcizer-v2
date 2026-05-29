@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.order_reports') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.order_reports') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.order_reports') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card shadow-lg p-3 mb-2 bg-body-tertiary rounded">
                    <div class="">

                        <form action="{{ route('report.store') }}" method="POST" class="row">
                            @csrf

                            <div class="col-3">
                                <label for="start_date" class="form-label">{{ trans('order_reports.start_date') }}</label>
                                <input type="date" class="form-control" name="start_date" id="start_date" value="{{ request('start_date') }}">
                                @error('start_date')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-3">
                                <label for="end_date" class="form-label">{{ trans('order_reports.end_date') }}</label>
                                <input type="date" class="form-control" name="end_date" id="end_date" value="{{ request('end_date') }}">
                                @error('end_date')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-3">
                                <label for="status" class="form-label">{{ trans('order_reports.status') }}</label>
                                <select name="status" id="status" class="form-select">
                                    <option selected disabled>{{ trans('order_reports.select') }} {{ trans('order_reports.status') }}</option>
                                    <option value="">All</option>
                                    <option value="pending" @if(request('status') == 'pending') selected @endif>pending</option>
                                    <option value="processing" @if(request('status') == 'processing') selected @endif>processing</option>
                                    <option value="completed" @if(request('status') == 'completed') selected @endif>completed</option>
                                    <option value="cancelled" @if(request('status') == 'cancelled') selected @endif>cancelled</option>
                                </select>
                                @error('status')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-3">
                                <label for="user" class="form-label">{{ trans('order_reports.user') }}</label>
                                <select name="user" id="user" class="form-select">
                                    <option selected disabled>{{ trans('order_reports.select') }} {{ trans('order_reports.user') }}</option>
                                    @foreach ($user as $item)
                                        <option value="{{ $item->id }}" @if(request('user') == $item->id) selected @endif>{{ $item->first_name . ' ' . $item->last_name }}</option>
                                    @endforeach
                                </select>
                                @error('user')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-2">
                                <button type="submit" class="btn btn-primary" name="action" value="view">{{ trans('order_reports.view_report') }}</button>
                                <button type="submit" class="btn btn-success" name="action" value="export">{{ trans('order_reports.excel_export') }}</button>
                                {{-- <button type="submit" class="btn btn-danger"> <i class="bi bi-trash"></i> </button> --}}
                                <a href="{{ route('report.index') }}" class="btn btn-danger"><i class="bi bi-trash"></i></a>
                            </div>

                        </form>

                    </div>
                </div>

                <div class="card">
                    <div class="card-body table-responsive">
                        <h5 class="card-title">{{ trans('sidebar.order_reports') }}</h5>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>{{ trans('order.order_number') }}</th>
                                    <th>{{ trans('order.name_user') }}</th>
                                    <th>{{ trans('order.address') }}</th>
                                    <th>{{ trans('order.total_price_for_order') }}</th>
                                    <th>{{ trans('order.status') }}</th>
                                    <th>{{ trans('order.payment_method') }}</th>
                                    <th>{{ trans('order.phone_number') }}</th>
                                    <th>{{ trans('order.order_date') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @isset($orders)
                                    @foreach ($orders as $item)
                                        <tr>
                                            <td>{{ $item->order_number }}</td>
                                            <td>{{ $item->user->first_name . ' ' . $item->user->last_name}}</td>
                                            <td>
                                                {{ $item->address->address_line . ' - ' . $item->address->shipping_city->city_name }}
                                            </td>
                                            <td>{{ $item->total_price_for_order*1 }} {{ trans('mainBtn.pounds') }}</td>
                                            <td>
                                                @if ($item->status == 'pending')
                                                    <span class="badge rounded-pill text-bg-warning">Pending</span>
                                                @elseif ($item->status == 'processing')
                                                    <span class="badge rounded-pill text-bg-secondary">Processing</span>
                                                @elseif ($item->status == 'completed')
                                                    <span class="badge rounded-pill text-bg-success">Completed</span>
                                                @else
                                                    <span class="badge rounded-pill text-bg-danger">Cancelled</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($item->payment_method == 'cash')
                                                    <span class="badge rounded-pill text-bg-info"> Cash</span>
                                                @else
                                                    <span class="badge rounded-pill text-bg-primary"><i class="bi bi-credit-card-fill text-dark"></i> Card
                                                        <button type="button" class="btn btn-sm btn-primary"
                                                        data-bs-toggle="modal" id="paymentStatus" data-bs-target="#orderModal" data-id="{{ $item->id }}">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                    </span>
                                                @endif
                                            </td>
                                            <td>{{ $item->address->phone_number_one }}</td>
                                            <td>{{ \Carbon\Carbon::parse($item->created_at)->format('Y-m-d h:i A') }}</td>
                                            <td>
                                                <div class="d-flex justify-content-center">
                                                    <a class="btn btn-sm btn-info" href="{{ route('order.edit', $item->id ) }}" role="button">{{ trans('mainBtn.edit') }} {{ trans('order.status') }}</a>
                                                    <a class="btn btn-sm btn-success ms-2" href="{{ route('order.show', $item->id ) }}" role="button">{{ trans('mainBtn.show') }} {{ trans('order.products') }}</a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endisset
                            </tbody>
                        </table>
                        <!-- End Table with stripped rows -->

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection

<div class="modal fade" id="orderModal" tabindex="-1" aria-labelledby="orderModal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h1 class="modal-title fs-5" id="orderModal">{{ trans('order.payment_status') }}</h1>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
            <div class="modal-body" id="modalContent">

            </div>
        </div>
    </div>
</div>

@section('script')
    <script>
        $(document).on('click', '#paymentStatus', function() {
            let orderId = $(this).data('id');
            let modalContent = $('#modalContent');

            modalContent.html('Loading...');

            $.ajax({
                url: `/admin/order/payment/${orderId}`,
                method: 'GET',
                success: function(response) {
                    modalContent.html(response);
                },
                error: function() {
                    modalContent.html('<div class="text-danger">Error loading data.</div>');
                }
            });
        });

    </script>

@endsection
