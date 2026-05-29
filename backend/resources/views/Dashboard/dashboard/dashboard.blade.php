@extends('Dashboard.layouts.master')
@section('title-head' , 'Home')
@section('css')
    {{-- <link href="{{ asset('DashAssets/css/dashboard.css') }}" rel="stylesheet"> --}}
@endsection
@section('content')
<div class="pagetitle">
    <h1>{{ trans('sidebar.dashboard') }}</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('dashboard.home') }}</a></li>
        <li class="breadcrumb-item active">{{ trans('sidebar.dashboard') }}</li>
      </ol>
    </nav>
  </div><!-- End Page Title -->

  <section class="section dashboard">
    <div class="row">

      <!-- Left side columns -->
      <div class="col-lg-12">
        <div class="row">

          <!-- Sales Card -->
          <div class="col-xxl-4 col-md-6">
            <div class="card info-card revenue-card">

              <div class="filter">
                <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start">
                        <h6>Filter</h6>
                    </li>

                    <li><a class="dropdown-item get_sales" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.today') }}" data-filter-en="{{ trans('dashboard.today') }}">{{ trans('dashboard.today') }}</a></li>

                    <li><a class="dropdown-item get_sales" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_month') }}" data-filter-en="{{ trans('dashboard.this_month') }}">{{ trans('dashboard.this_month') }}</a></li>

                    <li><a class="dropdown-item get_sales" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>
                </ul>
              </div>

              <div class="card-body">
                <h5 class="card-title">{{ trans('dashboard.sales') }} <span>|</span> <span id="filterDisplaySales">{{ trans('dashboard.today') }}</span></h5>

                <div class="d-flex align-items-center">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-cart"></i>
                  </div>
                  <div class="ps-3">
                    <h6 id="order-count">0</h6>
                    <span class="text-success small pt-1 fw-bold" id="percentage-change-sales">0%</span>

                  </div>
                </div>
              </div>

            </div>
          </div><!-- End Sales Card -->

          <!-- profit Card -->
          <div class="col-xxl-4 col-md-6">
            <div class="card info-card revenue-card">

              <div class="filter">
                <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                  <li class="dropdown-header text-start">
                    <h6>Filter</h6>
                  </li>

                  <li><a class="dropdown-item get_profit" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.today') }}" data-filter-en="{{ trans('dashboard.today') }}">{{ trans('dashboard.today') }}</a></li>

                  <li><a class="dropdown-item get_profit" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_month') }}" data-filter-en="{{ trans('dashboard.this_month') }}">{{ trans('dashboard.this_month') }}</a></li>

                  <li><a class="dropdown-item get_profit" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>
                </ul>
              </div>

              <div class="card-body">
                <h5 class="card-title">{{ trans('dashboard.profit') }} <span>|</span> <span id="filterDisplayProfit">{{ trans('dashboard.today') }}</span></h5>
                <div class="d-flex align-items-center">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-currency-dollar"></i>
                  </div>
                  <div class="ps-3">
                    <h6><span id="profit">0</span> {{ trans('mainBtn.pounds') }}</h6>
                    <span class="text-success small pt-1 fw-bold" id="percentage-change-profit">0%</span>

                  </div>
                </div>
              </div>

            </div>
          </div><!-- End profit Card -->

          <!-- order total price Card -->
          <div class="col-xxl-4 col-md-6">
            <div class="card info-card revenue-card">

              <div class="filter">
                <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                  <li class="dropdown-header text-start">
                    <h6>Filter</h6>
                  </li>

                  <li><a class="dropdown-item get_order_total_price" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.today') }}" data-filter-en="{{ trans('dashboard.today') }}">{{ trans('dashboard.today') }}</a></li>

                  <li><a class="dropdown-item get_order_total_price" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_month') }}" data-filter-en="{{ trans('dashboard.this_month') }}">{{ trans('dashboard.this_month') }}</a></li>

                  <li><a class="dropdown-item get_order_total_price" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>
                </ul>
              </div>

              <div class="card-body">
                <h5 class="card-title">{{ trans('dashboard.order_total_price') }} <span>|</span> <span id="filterDisplayTotalOrder">{{ trans('dashboard.today') }}</span></h5>
                <div class="d-flex align-items-center">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-currency-dollar"></i>
                  </div>
                  <div class="ps-3">
                    <h6><span id="TotalOrder">0</span> {{ trans('mainBtn.pounds') }}</h6>
                    <span class="text-success small pt-1 fw-bold" id="percentage-change-total-order">0%</span>

                  </div>
                </div>
              </div>

            </div>
          </div><!-- End order total price Card -->

          <!-- order total price + shipping Card -->
          <div class="col-xxl-4 col-md-6">
            <div class="card info-card revenue-card">

              <div class="filter">
                <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                  <li class="dropdown-header text-start">
                    <h6>Filter</h6>
                  </li>

                  <li><a class="dropdown-item get_order_total_price_shipping" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.today') }}" data-filter-en="{{ trans('dashboard.today') }}">{{ trans('dashboard.today') }}</a></li>

                  <li><a class="dropdown-item get_order_total_price_shipping" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_month') }}" data-filter-en="{{ trans('dashboard.this_month') }}">{{ trans('dashboard.this_month') }}</a></li>

                  <li><a class="dropdown-item get_order_total_price_shipping" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>
                </ul>
              </div>

              <div class="card-body">
                <h5 class="card-title">{{ trans('dashboard.order_total_price') }} + {{ trans('dashboard.shipping') }} <span>|</span> <span id="filterDisplayTotalOrderShipping">{{ trans('dashboard.today') }}</span></h5>
                <div class="d-flex align-items-center">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-currency-dollar"></i>
                  </div>
                  <div class="ps-3">
                    <h6><span id="TotalOrderShipping">0</span> {{ trans('mainBtn.pounds') }}</h6>
                    <span class="text-success small pt-1 fw-bold" id="percentage-change-total-order-Shipping">0%</span>

                  </div>
                </div>
              </div>

            </div>
          </div><!-- End order total price + shipping Card -->

          <!-- Customers Card -->
          <div class="col-xxl-4 col-md-6">

            <div class="card info-card revenue-card">

              <div class="filter">
                <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start">
                        <h6>Filter</h6>
                    </li>

                    <li><a class="dropdown-item get_customer" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.today') }}" data-filter-en="{{ trans('dashboard.today') }}">{{ trans('dashboard.today') }}</a></li>

                    <li><a class="dropdown-item get_customer" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_month') }}" data-filter-en="{{ trans('dashboard.this_month') }}">{{ trans('dashboard.this_month') }}</a></li>

                    <li><a class="dropdown-item get_customer" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>

                </ul>
              </div>

              <div class="card-body">
                <h5 class="card-title">{{ trans('dashboard.customer') }} <span>|</span> <span id="filterDisplayCustomer">{{ trans('dashboard.today') }}</span></h5>

                <div class="d-flex align-items-center">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-people"></i>
                  </div>
                  <div class="ps-3">
                    <h6 id="customer-count">0</h6>
                    <span class="text-danger small pt-1 fw-bold" id="percentage-change-customer">0%</span>

                  </div>
                </div>

              </div>
            </div>

          </div><!-- End Customers Card -->

          <!-- Top Selling -->
          <div class="col-12">
            <div class="card top-selling shadow-lg rounded-3">
                <div class="filter">
                    <a class="icon" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                        <li class="dropdown-header text-start">
                            <h6>Filter</h6>
                        </li>
                        <li><a class="dropdown-item get_top_selling" href="#" data-filter="today" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.today') }}</a></li>
                        <li><a class="dropdown-item get_top_selling" href="#" data-filter="this_month" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_month') }}</a></li>
                        <li><a class="dropdown-item get_top_selling" href="#" data-filter="this_year" data-filter-ar="{{ trans('dashboard.this_year') }}" data-filter-en="{{ trans('dashboard.this_year') }}">{{ trans('dashboard.this_year') }}</a></li>
                    </ul>
                </div>

                <div class="card-body pb-0">
                    <h5 class="card-title text-center text-primary fw-bold">{{ trans('dashboard.top_selling') }} | <span class="text-secondary" id="filterDisplayTopSelling"> {{ trans('dashboard.today') }}</span></h5>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle text-center" id="top-selling-table">
                            <thead class="table-primary">
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('dashboard.Image') }}</th>
                                    <th>{{ trans('dashboard.name_product') }}</th>
                                    <th>{{ trans('dashboard.Price') }}</th>
                                    <th>{{ trans('dashboard.sold') }}</th>
                                    <th>{{ trans('dashboard.profit_product') }}</th>
                                </tr>
                            </thead>
                            <tbody>

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- End Top Selling -->

        </div>
      </div><!-- End Left side columns -->

    </div>
  </section>
@endsection

@section('script')
<script>
    $(document).ready(function () {
        loadSalesData('today');
        loadProfitData('today');
        loadTotalOrderData('today');
        loadTotalOrderShippingData('today');
        loadCustomerData('today');
        loadTopSellingProducts('today');

        $('.get_sales').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadSalesData(filter, filterAr, filterEn);
        });

        $('.get_profit').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadProfitData(filter, filterAr, filterEn);
        });

        $('.get_order_total_price').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadTotalOrderData(filter, filterAr, filterEn);
        });

        $('.get_order_total_price_shipping').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadTotalOrderShippingData(filter, filterAr, filterEn);
        });

        $('.get_customer').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadCustomerData(filter, filterAr, filterEn);
        });

        $('.get_top_selling').on('click', function (e) {
            e.preventDefault();

            let filter = $(this).data('filter');
            let filterAr = $(this).data('filter-ar');
            let filterEn = $(this).data('filter-en');

            loadTopSellingProducts(filter, filterAr, filterEn);
        });

        function loadSalesData(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_sales") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    $('#order-count').text(response.orderCount);
                    $('#percentage-change-sales').text(response.percentageChange + '%');

                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplaySales').text(filterEn);
                    } else {
                        $('#filterDisplaySales').text(filterAr);
                    }
                },
                error: function (error) {
                    console.error('Error fetching sales data:', error);
                }
            });
        }

        function loadProfitData(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_profit") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    let profit = parseFloat(response.profit)
                    $('#profit').text(profit.toFixed(2)*1);
                    $('#percentage-change-profit').text(response.percentageChange + '%');

                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplayProfit').text(filterEn);
                    } else {
                        $('#filterDisplayProfit').text(filterAr);
                    }
                },
                error: function (error) {
                    console.error('Error fetching profit data:', error);
                }
            });
        }

        function loadTotalOrderData(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_order_total_price") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    let total_price = parseFloat(response.total_price)
                    $('#TotalOrder').text(total_price.toFixed(2)*1);
                    $('#percentage-change-total-order').text(response.percentageChange + '%');

                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplayTotalOrder').text(filterEn);
                    } else {
                        $('#filterDisplayTotalOrder').text(filterAr);
                    }
                },
                error: function (error) {
                    console.error('Error fetching profit data:', error);
                }
            });
        }

        function loadTotalOrderShippingData(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_order_total_price_shipping") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    let total_price_shipping = parseFloat(response.total_price_shipping)
                    $('#TotalOrderShipping').text(total_price_shipping.toFixed(2)*1);
                    $('#percentage-change-total-order-Shipping').text(response.percentageChange + '%');

                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplayTotalOrderShipping').text(filterEn);
                    } else {
                        $('#filterDisplayTotalOrderShipping').text(filterAr);
                    }
                },
                error: function (error) {
                    console.error('Error fetching profit data:', error);
                }
            });
        }

        function loadCustomerData(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_customer") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    $('#customer-count').text(response.newCustomers);
                    $('#percentage-change-customer').text(response.percentageChange + '%');

                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplayCustomer').text(filterEn);
                    } else {
                        $('#filterDisplayCustomer').text(filterAr);
                    }
                },
                error: function (error) {
                    console.error('Error fetching profit data:', error);
                }
            });
        }

        function loadTopSellingProducts(filter, filterAr = '{{ trans("dashboard.today") }}', filterEn = '{{ trans("dashboard.today") }}') {
            $.ajax({
                url: '{{ route("dashboard.get_top_selling") }}',
                method: 'GET',
                data: { filter: filter },
                success: function (response) {
                    let tableBody = $('#top-selling-table tbody');
                    tableBody.empty();

                    if (response.top_selling_products.length === 0) {
                        let noDataMessage = '';
                            noDataMessage = '<tr><td colspan="6" class="text-center text-danger">{{ trans('dashboard.no_data_message') }}</td></tr>';
                        tableBody.append(noDataMessage);
                    } else {
                        response.top_selling_products.forEach(function (product, index) {

                            let imageUrl = product.image ? `{{ asset('Uploads_Images/Product/') }}/${product.image}` : 'path/to/default/image.jpg';
                            let productUrl =  `{{ url('admin/product/') }}/${product.id}`;
                            let productName = product.product_title ? product.product_title : 'N/A';
                            let selling_price = parseFloat(product.selling_price) || 0;
                            let total_profit = parseFloat(product.total_profit) || 0;

                            let row = `<tr>
                                <td>${index + 1}</td>
                                <td><img src="${imageUrl}" width="50" height="50" alt="${productName}"></td>
                                <td class="text-primary fw-bold"><a href="${productUrl}">${productName} (${product.wa_code})</a></td>
                                <td>${selling_price.toFixed(2)} {{ trans('mainBtn.pounds') }}</td>
                                <td>${product.total_sold}</td>
                                <td>${total_profit.toFixed(2)} {{ trans('mainBtn.pounds') }}</td>
                            </tr>`;
                            tableBody.append(row);
                        });
                    }
                    if ($('html').attr('lang') === 'en') {
                        $('#filterDisplayTopSelling').text(filterEn);
                    } else {
                        $('#filterDisplayTopSelling').text(filterAr);
                    }
                    // $('.card-title span').text('| ' + ($('html').attr('lang') === 'en' ? filterEn : filterAr));
                },
                error: function (error) {
                    console.error('Error fetching top selling products:', error);
                }
            });
        }
    });

</script>
@endsection
