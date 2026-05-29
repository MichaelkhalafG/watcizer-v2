@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('blog.show_blog') }}
@endsection
@section('css')
@endsection

@section('content')
    <link href="{{ asset('DashAssets/css/product_detail.css') }}" rel="stylesheet">

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('blog.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.blog') }}</li>
                    <li class="breadcrumb-item active">{{ trans('blog.show_blog') }}
                    </li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <style>
       .main-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 10px;
        }
        .gallery img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 10px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .gallery img:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        p.lead {
            max-width: 90%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            text-align: center;
            margin: 0 auto;
            background-color: rgba(0, 123, 255, 0.1);
            border-left: 4px solid #007bff;
            padding: 10px 15px;
            font-weight: bold;
        }
    </style>

    <section class="container text-center my-5">
        <img src="{{ asset('Uploads_Images/Blog/' . $blog->image) }}" class="main-image shadow-sm">
    </section>

    <!-- نص وعنوان -->
    <section class="container text-center mb-5 row">
            <h2 class="mb-3">{{ $blog->title }}</h2>
            <p class="lead">{{ $blog->text }}</p>
    </section>

    <!-- معرض الصور -->
    <section class="container">
        <div class="row g-3">
            @foreach ($blog_image as $item)
                <div class="col-md-4">
                    <img src="{{ asset('Uploads_Images/Blog_image/' . $item->image) }}" class="img-fluid shadow-sm">
                </div>
            @endforeach
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
