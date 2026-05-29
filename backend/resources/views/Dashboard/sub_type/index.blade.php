@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.sub_type') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.sub_type') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.sub_type') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
        <div class="col-6 text-end">
            <a class="btn btn-primary" href="{{ route('sub_type.create') }}">{{ trans('sub_type.add') }}</a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="d-flex justify-content-start">
                    <a href="{{ route('sub_type.export') }}" class="btn btn-primary mb-1">{{ trans('mainBtn.export') }}</a>
                    <form action="{{ route('sub_type.import') }}" method="POST" enctype="multipart/form-data"
                        class="mb-0 ms-2">
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
                    <div class="card-body table-responsive">
                        <h5 class="card-title">{{ trans('sidebar.sub_type') }}</h5>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('sub_type.sub_type_name') }}</th>
                                    <th>{{ trans('sub_type.image') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sub_type as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->sub_type_name }}</td>
                                        <td class="text-center">
                                            @if ($item->image != null)
                                                <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Sub_type/' . $item->image) }}"><img src="{{ asset('Uploads_Images/Sub_type/' . $item->image) }}" height="100px" width="100px" alt=""></a>
                                            @else
                                                ---
                                            @endif
                                        </td>
                                        <td>
                                            @can('AnyAction')
                                                <div class="d-flex justify-content-center">
                                                    <a class="btn btn-sm btn-info"
                                                        href="{{ route('sub_type.edit', $item->id) }}"
                                                        role="button">{{ trans('mainBtn.edit') }}</a>
                                                    <form class="mb-0" action="{{ route('sub_type.destroy', $item->id) }}"
                                                        method="post">
                                                        @csrf
                                                        @method('delete')
                                                        <button type="submit"
                                                            class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
                                                    </form>
                                                </div>
                                            @endcan
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
