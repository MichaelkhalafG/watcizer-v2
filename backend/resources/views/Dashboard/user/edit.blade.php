 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('user.edit') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('user.edit') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.user') }}</li>
                     <li class="breadcrumb-item active">{{ trans('user.edit') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('user.edit') }}</h5>

                         <form action="{{ route('user.update', $user->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                             <div class="col-12">
                                <p><b> {{ trans('user.name') }} :</b> {{ $user->first_name . ' ' . $user->last_name }}</p>
                                <p><b>{{ trans('user.email') }} :</b> {{ $user->email }}</p>
                             </div>
                             <div class="col-12">
                                 <label for="type" class="form-label">{{ trans('user.type') }}</label>
                                 <select class="form-select" name="type" id="type">
                                     <option disabled selected>{{ trans('user.select') }} {{ trans('user.type') }}</option>
                                     <option value="SuperAdmin" @selected('SuperAdmin' == $user->type)>Super Admin</option>
                                     <option value="Admin" @selected('Admin' == $user->type)>Admin</option>
                                     <option value="User" @selected('User' == $user->type)>User</option>
                                 </select>
                                 @error('type')
                                     <p class="text-danger">{{ $message }}</p>
                                 @enderror
                             </div>

                             <div class="col-12 text-center mt-4">
                                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('mainBtn.close_btn') }}</button>
                                 <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                             </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
