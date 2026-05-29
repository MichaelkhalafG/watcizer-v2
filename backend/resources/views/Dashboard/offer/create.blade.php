@extends('Dashboard.layouts.master')
@section('title-head')
    {{ trans('offer.add') }}
@endsection

@section('content')
    <div class="row">
        <div class="pagetitle col-6">
            <h1>{{ trans('offer.add') }}</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                    <li class="breadcrumb-item">{{ trans('sidebar.offer') }}</li>
                    <li class="breadcrumb-item active">{{ trans('offer.add') }}</li>
                </ol>
            </nav>
        </div><!-- End Page Title -->
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('offer.add') }}</h5>

                        <form action="{{ route('offer.store') }}" method="POST" class="row g-3" enctype="multipart/form-data">
                            @csrf

                            <div class="col-6">
                                <label for="offer_name[ar]" class="form-label">{{ trans('offer.offer_name') }} ar</label>
                                <input type="text" class="form-control" name="offer_name[ar]" id="offer_name[ar]" value="{{ old('offer_name.ar') }}">
                                @error('offer_name.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="offer_name[en]" class="form-label">{{ trans('offer.offer_name') }} en</label>
                                <input type="text" class="form-control" name="offer_name[en]" id="offer_name[en]" value="{{ old('offer_name.en') }}">
                                @error('offer_name.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-8">
                                <label for="main_product_id" class="form-label">{{ trans('offer.main_product_id') }}</label>
                                <select class="form-select select2" name="main_product_id" id="main_product_id">
                                    <option value="" selected>{{ trans('offer.select') }}{{ trans('offer.main_product_id') }}</option>
                                    @foreach ($product as $item)
                                        <option value="{{ $item->id }}" @selected(old('main_product_id') == $item->id)>{{ $item->product_title . ' - (' . $item->wa_code . ')' }}</option>
                                    @endforeach
                                </select>
                                @error('main_product_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-4">
                                <label for="in_season" class="form-label">{{ trans('offer.in_season') }}</label>
                                <select name="in_season" id="in_season" class="form-select">
                                    <option value="" selected disabled>{{ trans('offer.select') }}{{ trans('offer.in_season') }}</option>
                                    <option value="yes" @selected(old('in_season') == 'yes')>{{ trans('offer.yes') }}</option>
                                    <option value="no" @selected(old('in_season') == 'no')>{{ trans('offer.no') }}</option>
                                </select>
                                @error('in_season')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="gift_product_ids" class="form-label">{{ trans('offer.gift_product_ids') }}</label>
                                <div class="input-group">
                                    <select class="form-select select2" id="gift_product_select">
                                        <option value="" disabled selected>{{ trans('offer.gift_product_ids') }}</option>
                                        @foreach ($product as $item)
                                            <option value="{{ $item->id }}" data-name="{{ $item->product_title . ' - (' . $item->wa_code . ')'}}">
                                                {{ $item->product_title . ' - (' . $item->wa_code . ')' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="button" id="add-product" class="btn btn-primary">{{ trans('offer.add_product') }}</button>
                                </div>

                                <ul id="selected-products-list" class="list-group mt-3">
                                    @if (old('gift_product_ids'))
                                        @foreach (old('gift_product_ids') as $id)
                                            <li class="list-group-item">
                                                {{ $product->firstWhere('id', $id)->product_title . ' - (' . $product->firstWhere('id', $id)->wa_code . ')' ?? 'Unknown Product' }}
                                                <input type="hidden" name="gift_product_ids[]" value="{{ $id }}">
                                                <button type="button" class="btn btn-sm btn-danger remove-product">{{ trans('mainBtn.delete') }}</button>
                                            </li>
                                        @endforeach
                                    @endif
                                </ul>

                                @error('gift_product_ids')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="wa_code" class="form-label">{{ trans('offer.wa_code') }}</label>
                                <input type="text" class="form-control" name="wa_code" data-name="wa_code" id="wa_code" value="{{ old('wa_code') }}">
                                @error('wa_code')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="category_type_id" class="form-label">{{ trans('offer.category_type_id') }}</label>
                                <select class="form-select select2" name="category_type_id" id="category_type_id">
                                    <option value="" selected>{{ trans('offer.select') }}{{ trans('offer.category_type_id') }}</option>
                                    @foreach ($category_type as $item)
                                        <option value="{{ $item->id }}" @selected(old('category_type_id') == $item->id)>{{ $item->category_type_name }}</option>
                                    @endforeach
                                </select>
                                @error('category_type_id')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-4">
                                <label for="selling_price" class="form-label">{{ trans('offer.selling_price') }}</label>
                                <input oninput="this.value=this.value.replace(/[^0-9.]/g,'');" class="form-control" name="selling_price" id="selling_price" value="{{ old('selling_price') }}">
                                @error('selling_price')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-4">
                                <label for="sale_price_after_discount" class="form-label">{{ trans('offer.sale_price_after_discount') }}</label>
                                <input oninput="this.value=this.value.replace(/[^0-9.]/g,'');" class="form-control" name="sale_price_after_discount" id="sale_price_after_discount" value="{{ old('sale_price_after_discount') }}">
                                @error('sale_price_after_discount')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-4">
                                <label for="stock" class="form-label">{{ trans('offer.stock') }}</label>
                                <input type="number" min="0" class="form-control" name="stock" id="stock" value="{{ old('stock') }}">
                                @error('stock')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="short_description[ar]" class="form-label">{{ trans('offer.short_description') }} ar</label>
                                <textarea class="form-control" name="short_description[ar]" id="short_description[ar]" rows="1">{{ old('short_description.ar') }}</textarea>
                                @error('short_description.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="short_description[en]" class="form-label">{{ trans('offer.short_description') }} en</label>
                                <textarea class="form-control" name="short_description[en]" id="short_description[en]" rows="1">{{ old('short_description.en') }}</textarea>
                                @error('short_description.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="long_description[ar]" class="form-label">{{ trans('offer.long_description') }} ar</label>
                                <textarea class="form-control" name="long_description[ar]" id="long_description[ar]" rows="1">{{ old('long_description.ar') }}</textarea>
                                @error('long_description.ar')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label for="long_description[en]" class="form-label">{{ trans('offer.long_description') }} en</label>
                                <textarea class="form-control" name="long_description[en]" id="long_description[en]" rows="1">{{ old('long_description.en') }}</textarea>
                                @error('long_description.en')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="image" class="form-label">{{ trans('offer.image') }}</label>
                                <input type="file" class="form-control" name="image" id="image">
                                @error('image')
                                    <p class="text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-12 text-center mt-4">
                                <a href="{{ route('offer.index') }}" class="btn btn-secondary">{{ trans('mainBtn.close_btn') }}</a>
                                <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
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

    $(document).ready(function () {
        // عند النقر على زر "Add Product"
        $('#add-product').click(function () {
            const selectedOption = $('#gift_product_select option:selected');
            const productId = selectedOption.val();
            const productName = selectedOption.data('name');

            if (productId) {
                // إضافة المنتج إلى القائمة
                const productEntry = `
                    <li class="list-group-item">
                        ${productName}
                        <input type="hidden" name="gift_product_ids[]" value="${productId}">
                        <button type="button" class="btn btn-sm btn-danger remove-product">{{ trans('mainBtn.delete') }}</button>
                    </li>`;
                $('#selected-products-list').append(productEntry);
            }
        });

        // عند النقر على زر "Remove" لإزالة المنتج
        $(document).on('click', '.remove-product', function () {
            $(this).closest('li').remove();
        });
    });
</script>
@endsection
