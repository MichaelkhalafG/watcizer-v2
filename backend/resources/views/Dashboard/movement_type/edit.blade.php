 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('movement_type.edit_movement_type') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('movement_type.edit_movement_type') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.movement_type') }}</li>
                     <li class="breadcrumb-item active">{{ trans('movement_type.edit_movement_type') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('movement_type.edit_movement_type') }}</h5>

                         <form action="{{ route('movement_type.update' , $movement_type->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="movement_type_name[ar]" class="form-label">{{ trans('movement_type.movement_type_name') }}</label>
                                    <input type="text" class="form-control" name="movement_type_name[ar]" id="movement_type_name[ar]" value="{{ old('movement_type_name.ar' , $movement_type->translate('ar')->movement_type_name) }}">
                                    @error('movement_type_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="movement_type_name[en]" class="form-label">{{ trans('movement_type.movement_type_name') }} en</label>
                                    <input type="text" class="form-control" name="movement_type_name[en]" id="movement_type_name[en]" value="{{ old('movement_type_name.en' , $movement_type->translate('en')->movement_type_name) }}">
                                    @error('movement_type_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('movement_type.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
