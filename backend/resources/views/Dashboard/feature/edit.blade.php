 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('feature.edit_feature') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('feature.edit_feature') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.feature') }}</li>
                     <li class="breadcrumb-item active">{{ trans('feature.edit_feature') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('feature.edit_feature') }}</h5>

                         <form action="{{ route('feature.update' , $feature->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="feature_name[ar]" class="form-label">{{ trans('feature.feature_name') }}</label>
                                    <input type="text" class="form-control" name="feature_name[ar]" id="feature_name[ar]" value="{{ old('feature_name.ar' , $feature->translate('ar')->feature_name) }}">
                                    @error('feature_name.ar')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="feature_name[en]" class="form-label">{{ trans('feature.feature_name') }} en</label>
                                    <input type="text" class="form-control" name="feature_name[en]" id="feature_name[en]" value="{{ old('feature_name.en' , $feature->translate('en')->feature_name) }}">
                                    @error('feature_name.en')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('feature.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
