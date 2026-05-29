@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('sidebar.blog') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('sidebar.blog') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('sidebar.blog') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
        <div class="col-6 text-end">
            <a class="btn btn-primary" href="{{ route('blog.create') }}">{{ trans('blog.add') }}</a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body table-responsive">
                        <h5 class="card-title">{{ trans('sidebar.blog') }}</h5>

                        <!-- Table with stripped rows -->
                        <table class="table table-striped table-bordered" id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('blog.title') }}</th>
                                    <th>{{ trans('blog.image') }}</th>
                                    <th>{{ trans('mainBtn.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($blog as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->title }}</td>
                                        <td>
                                            <a data-lightbox="single-image" href="{{ asset('Uploads_Images/Blog/' . $item->image) }}"><img src="{{ asset('Uploads_Images/Blog/' . $item->image) }}" height="100px" width="100px" alt=""></a>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center pt-4">
                                                <a class="btn btn-sm btn-success" href="{{ route('blog.show', $item->id) }}" role="button">{{ trans('mainBtn.show') }}</a>

                                                <a class="btn btn-sm btn-info ms-2"
                                                    href="{{ route('blog.edit', $item->id) }}"
                                                    role="button">{{ trans('mainBtn.edit') }}</a>

                                                <form class="mb-0" action="{{ route('blog.destroy', $item->id) }}"
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
