@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.order') }}
@endsection

@section('content')

        <div class="row">
            <div class="pagetitle col-6">
                <h1>{{ trans('sidebar.order') }}</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                        <li class="breadcrumb-item active">{{ trans('sidebar.order') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- End Page Title -->
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="row">
                        <div class="col-xxl-4 col-md-6 dashboard">
                            <div class="card info-card sales-card">
                                <div class="card-body">
                                <h5 class="card-title">{{ trans('order.orderCount') }}</h5>
                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-minecart-loaded"></i>
                                    </div>
                                    <div class="ps-3">
                                    <h6>{{ $order->count() }}</h6>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xxl-4 col-md-6 dashboard">
                            <div class="card info-card sales-card">
                                <div class="card-body">
                                <h5 class="card-title">{{ trans('order.total_price_all') }}</h5>
                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-cash-stack"></i>
                                    </div>
                                    <div class="ps-3">
                                    <h6>{{ number_format($totalPriceForOrder, 2) }} {{ trans('mainBtn.pounds') }}</h6>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xxl-4 col-md-6 dashboard">
                            <div class="card info-card sales-card">
                                <div class="card-body pt-2">
                                <div class="d-flex align-items-center">
                                    <div class="ps-3 pt-4">
                                        <div class="col-12"><b>{{ trans('order.pendingCount') }}</b>    : {{ $pendingCount }}</div>
                                        <div class="col-12"><b>{{ trans('order.processingCount') }}</b> : {{ $processingCount }}</div>
                                        <div class="col-12"><b>{{ trans('order.completedCount') }}</b>  : {{ $completedCount }}</div>
                                        <div class="col-12"><b>{{ trans('order.cancelledCount') }}</b>  : {{ $cancelledCount }}</div>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body table-responsive">
                                <h5 class="card-title">{{ trans('sidebar.order') }}</h5>

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
                                        @foreach ($order as $item)
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
