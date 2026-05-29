 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('brand.edit_brand') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('brand.edit_brand') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.brand') }}</li>
                     <li class="breadcrumb-item active">{{ trans('brand.edit_brand') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('brand.edit_brand') }}</h5>

                         <form action="{{ route('brand.update' , $brand->id) }}" method="POST" enctype="multipart/form-data">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="brand_name[ar]" class="form-label">{{ trans('brand.brand_name') }}</label>
                                    <input type="text" class="form-control" name="brand_name[ar]" id="brand_name[ar]" value="{{ old('brand_name.ar' , $brand->translate('ar')->brand_name) }}">
                                    @error('brand_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="brand_name[en]" class="form-label">{{ trans('brand.brand_name') }} en</label>
                                    <input type="text" class="form-control" name="brand_name[en]" id="brand_name[en]" value="{{ old('brand_name.en' , $brand->translate('en')->brand_name) }}">
                                    @error('brand_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="image" class="form-label">{{ trans('brand.image') }}</label>
                                    <input type="file" class="form-control" data-name="image" name="image" id="image">
                                    @error('image')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('brand.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                     <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
