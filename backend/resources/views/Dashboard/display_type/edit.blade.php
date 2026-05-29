 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('display_type.edit_display_type') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('display_type.edit_display_type') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.display_type') }}</li>
                     <li class="breadcrumb-item active">{{ trans('display_type.edit_display_type') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('display_type.edit_display_type') }}</h5>

                         <form action="{{ route('display_type.update' , $display_type->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="display_type_name[ar]" class="form-label">{{ trans('display_type.display_type_name') }}</label>
                                    <input type="text" class="form-control" name="display_type_name[ar]" id="display_type_name[ar]" value="{{ old('display_type_name.ar' , $display_type->translate('ar')->display_type_name) }}">
                                    @error('display_type_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="display_type_name[en]" class="form-label">{{ trans('display_type.display_type_name') }} en</label>
                                    <input type="text" class="form-control" name="display_type_name[en]" id="display_type_name[en]" value="{{ old('display_type_name.en' , $display_type->translate('en')->display_type_name) }}">
                                    @error('display_type_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('display_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
