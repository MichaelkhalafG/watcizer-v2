 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('shape.edit_shape') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('shape.edit_shape') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.shape') }}</li>
                     <li class="breadcrumb-item active">{{ trans('shape.edit_shape') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('shape.edit_shape') }}</h5>

                         <form action="{{ route('shape.update' , $shape->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="shape_name[ar]" class="form-label">{{ trans('shape.shape_name') }}</label>
                                    <input type="text" class="form-control" name="shape_name[ar]" id="shape_name[ar]" value="{{ old('shape_name.ar' , $shape->translate('ar')->shape_name) }}">
                                    @error('shape_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="shape_name[en]" class="form-label">{{ trans('shape.shape_name') }} en</label>
                                    <input type="text" class="form-control" name="shape_name[en]" id="shape_name[en]" value="{{ old('shape_name.en' , $shape->translate('en')->shape_name) }}">
                                    @error('shape_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('shape.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
