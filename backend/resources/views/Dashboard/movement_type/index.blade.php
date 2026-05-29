@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.movement_type') }}
@endsection

@section('content')

        <div class="row">
            <div class="pagetitle col-6">
                <h1>{{ trans('sidebar.movement_type') }}</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                        <li class="breadcrumb-item active">{{ trans('sidebar.movement_type') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- End Page Title -->
            <div class="col-6 text-end">
                <a class="btn btn-primary" href="{{ route('movement_type.create') }}">{{ trans('movement_type.add') }}</a>
            </div>
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="d-flex justify-content-start">
                        <a href="{{ route('movement_type.export') }}" class="btn btn-primary mb-1">{{ trans('mainBtn.export') }}</a>
                        <form action="{{ route('movement_type.import') }}" method="POST" enctype="multipart/form-data" class="mb-0 ms-2">
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
                                <h5 class="card-title">{{ trans('sidebar.movement_type') }}</h5>

                                <!-- Table with stripped rows -->
                                <table class="table table-striped table-bordered" id="myTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>{{ trans('movement_type.movement_type_name') }}</th>
                                            <th>{{ trans('mainBtn.action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($movement_type as $item)
                                            <tr>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>{{ $item->movement_type_name }}</td>
                                                <td>
                                                    @can('AnyAction')
                                                    <div class="d-flex justify-content-center">
                                                        <a class="btn btn-sm btn-info" href="{{ route('movement_type.edit', $item->id ) }}" role="button">{{ trans('mainBtn.edit') }}</a>
                                                        <form class="mb-0" action="{{ route('movement_type.destroy' , $item->id) }}" method="post">
                                                            @csrf
                                                            @method('delete')
                                                            <button type="submit" class="btn btn-sm btn-danger ms-2">{{ trans('mainBtn.delete') }}</button>
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
