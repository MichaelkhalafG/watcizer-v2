 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('gender.edit_gender') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('gender.edit_gender') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.gender') }}</li>
                     <li class="breadcrumb-item active">{{ trans('gender.edit_gender') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('gender.edit_gender') }}</h5>

                         <form action="{{ route('gender.update' , $gender->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="gender_name[ar]" class="form-label">{{ trans('gender.gender_name') }}</label>
                                    <input type="text" class="form-control" name="gender_name[ar]" id="gender_name[ar]" value="{{ old('gender_name.ar' , $gender->translate('ar')->gender_name) }}">
                                    @error('gender_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="gender_name[en]" class="form-label">{{ trans('gender.gender_name') }} en</label>
                                    <input type="text" class="form-control" name="gender_name[en]" id="gender_name[en]" value="{{ old('gender_name.en' , $gender->translate('en')->gender_name) }}">
                                    @error('gender_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('gender.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
