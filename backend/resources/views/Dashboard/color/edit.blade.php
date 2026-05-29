 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('color.edit_color') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('color.edit_color') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.color') }}</li>
                     <li class="breadcrumb-item active">{{ trans('color.edit_color') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('color.edit_color') }}</h5>

                         <form action="{{ route('color.update' , $color->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="color_value" class="form-label">{{ trans('color.color') }}</label>
                                    <input type="color" class="form-control form-control-color" name="color_value" id="color_value" value="{{ old('color_value' , $color->color_value) }}">
                                    @error('color_value')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="color_name[ar]" class="form-label">{{ trans('color.color_name') }} ar</label>
                                    <input type="text" class="form-control" name="color_name[ar]" data-name="color_name.ar" id="color_name[ar]" value="{{ old('color_name.ar'  , $color->translate('ar')->color_name ?? '') }}">
                                    @error('color_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="color_name[en]" class="form-label">{{ trans('color.color_name') }} en</label>
                                    <input type="text" class="form-control" data-name="color_name.en" name="color_name[en]" id="color_name[en]" value="{{ old('color_name.en'  , $color->translate('en')->color_name ?? '') }}">
                                    @error('color_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('color.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
