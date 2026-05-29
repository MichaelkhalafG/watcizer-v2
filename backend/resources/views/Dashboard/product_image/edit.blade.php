 @extends('Dashboard.layouts.master')
 @section('title-head')
     {{ trans('product_image.edit_product_image') }}
 @endsection

 @section('content')

     <div class="row">
         <div class="pagetitle col-6">
             <h1>{{ trans('product_image.edit_product_image') }}</h1>
             <nav>
                 <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.product_image') }}</li>
                     <li class="breadcrumb-item active">{{ trans('product_image.edit_product_image') }}</li>
                 </ol>
             </nav>
         </div><!-- End Page Title -->
     </div>

     <section class="section">
         <div class="row">
             <div class="col-lg-12">

                 <div class="card">
                     <div class="card-body">
                         <h5 class="card-title">{{ trans('product_image.edit_product_image') }}</h5>

                         <form action="{{ route('product_image.update' , $product_image->id) }}" method="POST" enctype="multipart/form-data">
                             @csrf
                             @method('PUT')

                                <div class="col-12">
                                    <label for="product_id" class="form-label">{{ trans('product_image.product_name') }}</label>
                                    <select class="form-select select2" name="product_id" id="product_id">
                                        <option value="" selected>{{ trans('product_image.select') }}{{ trans('product_image.product_name') }}</option>
                                        @foreach ($product as $item)
                                            <option value="{{ $item->id }}" @selected(old('product_id' , $product_image->product_id) == $item->id)>{{ $item->product_title . ' - (' . $item->wa_code . ')' }}</option>
                                        @endforeach
                                    </select>
                                    @error('product_id')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="image" class="form-label">{{ trans('product_image.image') }}</label>
                                    <input type="file" class="form-control" name="image" id="image">
                                    @error('image')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                 <div class="col-12 text-center mt-4">
                                    <a href="{{ route('product_image.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
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
    $( '.select2' ).select2( {
        theme: "bootstrap-5",
        width: $( this ).data( 'width' ) ? $( this ).data( 'width' ) : $( this ).hasClass( 'w-100' ) ? '100%' : 'style',
        placeholder: $( this ).data( 'placeholder' ),
    } );
</script>
@endsection
