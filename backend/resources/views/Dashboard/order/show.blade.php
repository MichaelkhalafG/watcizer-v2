@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('mainBtn.show') }} {{ trans('order.products') }}
@endsection

@section('content')

        <div class="row">
            <div class="pagetitle col-6">
                <h1>{{ trans('mainBtn.show') }} {{ trans('order.products') }}</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                        <li class="breadcrumb-item">{{ trans('sidebar.order') }}</li>
                        <li class="breadcrumb-item active">{{ trans('mainBtn.show') }} {{ trans('order.products') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- End Page Title -->
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="card">
                        <div class="card-body table-responsive">
                                <h5 class="card-title">{{ trans('mainBtn.show') }} {{ trans('order.products') }}</h5>

                                <div class="row shadow p-3 mb-4 bg-body-tertiary rounded">
                                    <div class="col-6"><b>{{ trans('order.order_number') }}</b> : {{ $order->order_number }}</div>
                                    <div class="col-6"><b>{{ trans('order.name_user') }}</b> : {{ $order->user->first_name . ' ' . $order->user->last_name}}</div>
                                    <div class="col-6"><b>{{ trans('order.phone_number') }}</b> : {{ $order->address->phone_number_one }}
                                        @if ($order->address->phone_number_two)
                                            {{   ' - ' . $order->address->phone_number_two }}
                                        @endif
                                    </div>
                                    <div class="col-6"><b>{{ trans('order.address') }}</b> : {{ $order->address->address_line . ' - ' . $order->address->shipping_city->city_name }}</div>
                                    <div class="col-6"><b>{{ trans('order.total_price_for_order') }}</b> : {{ $order->total_price_for_order*1 }} {{ trans('mainBtn.pounds') }}</div>
                                    <div class="col-6"><b>
                                        {{ trans('order.status') }}</b> :
                                        @if ($order->status == 'pending')
                                            <span class="badge rounded-pill text-bg-warning">Pending</span>
                                        @elseif ($order->status == 'processing')
                                            <span class="badge rounded-pill text-bg-secondary">Processing</span>
                                        @elseif ($order->status == 'completed')
                                            <span class="badge rounded-pill text-bg-success">Completed</span>
                                        @else
                                            <span class="badge rounded-pill text-bg-danger">Cancelled</span>
                                        @endif
                                    </div>
                                    <div class="col-6"><b>{{ trans('order.note') }}</b> : {{ $order->note }}</div>
                                    <div class="col-6"><b>
                                        {{ trans('order.payment_method') }}</b> :
                                        @if ($order->payment_method == 'cash')
                                            <span class="badge rounded-pill text-bg-info"> Cash</span>
                                        @else
                                        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-credit-card-fill text-dark"></i> Card
                                            <button type="button" class="btn btn-sm btn-primary"
                                            data-bs-toggle="modal" id="paymentStatus" data-bs-target="#orderModal" data-id="{{ $order->id }}">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </span>
                                        @endif
                                    </div>
                                    <div class="col-12"><b>{{ trans('mainBtn.action') }}</b> : <a class="btn btn-sm btn-info" href="{{ route('order.edit', $order->id ) }}" role="button">{{ trans('mainBtn.edit') }}  {{ trans('order.status') }}</a></div>
                                </div>

                                <!-- Table with stripped rows -->
                                <table class="table table-striped table-bordered" id="myTable">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('order.image') }}</th>
                                            <th>{{ trans('order.name_product') }}</th>
                                            <th>{{ trans('order.quantity') }}</th>
                                            <th>{{ trans('order.piece_price') }}</th>
                                            <th>{{ trans('order.total_price') }}</th>
                                            <th>{{ trans('order.color_band') }}</th>
                                            <th>{{ trans('order.color_dial') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($order->order_item as $item)
                                            <tr>
                                                @if ($item->product)
                                                    <td><img src="{{ asset('Uploads_Images/Product/' . $item->product->image) }}" alt="" width="120" height="120"></td>
                                                @elseif ($item->offer)
                                                    <td><img src="{{ asset('Uploads_Images/Offer/' . $item->offer->image) }}" alt="" width="120" height="120"></td>
                                                @endif
                                                <td>
                                                    @if ($item->offer_id == null)
                                                        <a href="{{ route('product.show' , $item->product_id) }}" class="link-dark">
                                                            {{ $item->product->product_title . ' - (' . $item->product->wa_code . ')' }}
                                                        <i class="text-primary bi bi-eye-fill"></i> </a>
                                                    @else
                                                        {{-- {{ $item->offer->offer_name }} --}}
                                                        <a href="{{ route('offer.show' , $item->offer_id) }}" class="link-dark">
                                                            {{ $item->offer->offer_name . ' - (' . $item->offer->wa_code . ')' }}
                                                        <i class="text-primary bi bi-eye-fill"></i> </a>
                                                    @endif
                                                </td>
                                                <td>{{ $item->quantity }}</td>
                                                <td>{{ $item->piece_price*1 }} {{ trans('mainBtn.pounds') }}</td>
                                                <td>{{ $item->total_price*1 }} {{ trans('mainBtn.pounds') }}</td>
                                                <td>
                                                    <span style="background-color:{{ $item->color_band }}; color: white; display: block; padding: 12px 12px; border-radius: 5px;"></span>
                                                </td>
                                                <td>
                                                    <span style="background-color:{{ $item->color_dial }}; color: white; display: block; padding: 12px 12px; border-radius: 5px;"></span>
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
