 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('mainBtn.edit') }}  {{ trans('order.status') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('mainBtn.edit') }}  {{ trans('order.status') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.order') }}</li>
                     <li class="breadcrumb-item active">{{ trans('mainBtn.edit') }}  {{ trans('order.status') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('mainBtn.edit') }}  {{ trans('order.status') }}</h5>

                         <form action="{{ route('order.update' , $order->id) }}" method="POST">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="status" class="form-label">{{ trans('order.status') }}</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="pending" @selected(old('status' , $order->status) == 'pending')>pending</option>
                                        <option value="processing" @selected(old('status' , $order->status) == 'processing')>processing</option>
                                        <option value="completed" @selected(old('status' , $order->status) == 'completed')>completed</option>
                                        <option value="cancelled" @selected(old('status' , $order->status) == 'cancelled')>cancelled</option>
                                    </select>
                                    @error('status')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('order.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                    <button type="submit" class="btn btn-primary">{{ trans('mainBtn.edit') }}</button>
                                 </div>

                         </form>

                     </div>
                 </div>

             </div>
         </div>
     </section>
 @endsection
