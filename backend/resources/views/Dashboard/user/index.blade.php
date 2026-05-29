@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.user') }}
@endsection

@section('content')

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.user') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.user') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
        <div class="col-6 text-end">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#Add">
                {{ trans('user.add') }}
              </button>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('sidebar.user') }}</h5>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('user.name') }}</th>
                                    <th>{{ trans('user.email') }}</th>
                                    <th>{{ trans('user.type') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($user as $item)

                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->first_name . ' ' . $item->last_name }}</td>
                                        <td>{{ $item->email }}</td>
                                        <td>{{ $item->type }}</td>
                                        <td>
                                            <div class="d-flex justify-content-center">
                                                <a class="btn btn-sm btn-info" href="{{ route('user.edit', $item->id ) }}">{{ trans('mainBtn.edit') }}</a>
                                                {{-- <form class="mb-0" action="{{ route('user.destroy' , $item->id) }}" method="post">
                                                    @csrf
                                                    @method('delete')
                                                    <button type="submit" class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
                                                </form> --}}
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
        @extends('Dashboard.user.create')

@section('script')

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            @if ($errors->any())
                var errorModal = new bootstrap.Modal(document.getElementById('Add'), {});
                errorModal.show();
            @endif
        });
    </script>


@endsection
