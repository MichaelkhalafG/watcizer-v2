@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('blog.edit_blog') }}
@endsection

@section('content')

    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('blog.edit_blog') }}</h1>
            <nav>
                <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                <li class="breadcrumb-item">{{ trans('sidebar.blog') }}</li>
                    <li class="breadcrumb-item active">{{ trans('blog.edit_blog') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('blog.edit_blog') }}</h5>

                        <form action="{{ route('blog.update' , $blog->id) }}" class="row" method="POST" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="col-6">
                                <label for="title[ar]" class="form-label">{{ trans('blog.title') }}</label>
                                <input type="text" class="form-control" name="title[ar]" id="title[ar]" value="{{ old('title.ar' , $blog->translate('ar')->title) }}">
                                @error('title.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="title[en]" class="form-label">{{ trans('blog.title') }} en</label>
                                <input type="text" class="form-control" name="title[en]" id="title[en]" value="{{ old('title.en' , $blog->translate('en')->title) }}">
                                @error('title.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="text[ar]" class="form-label">{{ trans('blog.text') }}</label>
                                <textarea class="form-control" name="text[ar]" id="text[ar]">{{ old('text.ar' , $blog->translate('ar')->text) }}</textarea>
                                @error('text.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="text[en]" class="form-label">{{ trans('blog.text') }} en</label>
                                <textarea class="form-control" name="text[en]" id="text[en]">{{ old('text.en' , $blog->translate('en')->text) }}</textarea>
                                @error('text.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <style>
                                .preview-container {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 10px;
                                    margin-top: 15px;
                                }

                                .preview-img {
                                    width: 150px;
                                    height: 150px;
                                    border-radius: 10px;
                                    object-fit: cover;
                                    border: 2px solid #007bff;
                                    padding: 5px;
                                }
                            </style>
                            <div class="col-6">
                                <label for="image" class="form-label">{{ trans('blog.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                                <img id="singlePreview" class="preview-img" alt="preview image" style="display: none;"/>
                            </div>

                            <div class="col-6">
                                <label for="many_image" class="form-label">{{ trans('blog.many_image') }}</label>
                                <input type="file" class="form-control" name="many_image[]" multiple id="many_image">
                                @error('many_image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                                <div id="multiPreviewContainer" class="preview-container" style="display: none;"></div>
                            </div>

                                <div class="col-12 text-center mt-4">
                                    <a href="{{ route('blog.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                </div>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        $(document).ready(function () {
            $("#image").change(function () {
                let file = this.files[0];
                if (file) {
                    let reader = new FileReader();
                    reader.onload = function (e) {
                        $("#singlePreview").attr("src", e.target.result).show();
                    };
                    reader.readAsDataURL(file);
                }
            });

            $("#many_image").change(function () {
                let files = this.files;
                let previewContainer = $("#multiPreviewContainer");

                previewContainer.empty();
                if (files.length > 0) {
                    previewContainer.show();

                    $.each(files, function (index, file) {
                        let reader = new FileReader();
                        reader.onload = function (e) {
                            let img = $("<img>")
                                .attr("src", e.target.result)
                                .addClass("preview-img")
                                .css({
                                    width: "120px",
                                    height: "120px",
                                    margin: "5px",
                                    borderRadius: "10px",
                                    border: "2px solid #007bff",
                                    objectFit: "cover"
                                });

                            previewContainer.append(img);
                        };
                        reader.readAsDataURL(file);
                    });
                } else {
                    previewContainer.hide();
                }
            });


        });
    </script>
@endsection
