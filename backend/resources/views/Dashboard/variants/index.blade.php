@extends('Dashboard.layouts.master')
@section('title-head')Variants - {{ $product->translate('en')->product_title }}@endsection

@section('content')
<div class="pagetitle">
    <h1>Product Variants</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ route('product.index') }}">Products</a></li>
        <li class="breadcrumb-item active">Variants</li>
    </ol></nav>
</div>

<section class="section">

{{-- Product Info --}}
<div class="card mb-3">
<div class="card-body py-2 d-flex align-items-center gap-3">
    @if($product->image)
    <img src="{{ asset('Uploads_Images/Product/' . $product->image) }}"
         style="width:50px;height:50px;object-fit:cover;border-radius:8px">
    @endif
    <div>
        <div class="fw-600">{{ $product->translate('en')->product_title }}</div>
        <small class="text-muted">Base Price: {{ number_format($product->selling_price) }} EGP</small>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('products.variants.create', $product) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Add Variants
        </a>
        <a href="{{ route('product.edit', $product) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Product
        </a>
    </div>
</div>
</div>

{{-- Variants Table --}}
<div class="card">
<div class="card-body pt-3">
    <h5 class="card-title">
        Variants
        <span class="badge bg-primary ms-1">{{ $variants->count() }}</span>
    </h5>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if($variants->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-grid-3x3-gap" style="font-size:3rem;opacity:.3"></i>
        <p class="mt-2">No variants yet.</p>
        <a href="{{ route('products.variants.create', $product) }}" class="btn btn-primary">
            Add Variants
        </a>
    </div>
    @else
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th>Variant</th>
                <th>Color</th>
                <th>Size</th>
                <th>Stock</th>
                <th>Price Modifier</th>
                <th>SKU</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($variants as $variant)
            <tr>
                <td>
                    <strong>{{ $variant->getLabel() }}</strong>
                </td>
                <td>
                    @if($variant->color)
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:20px;height:20px;border-radius:50%;background:{{ $variant->color->hex }};border:1px solid #ddd"></div>
                        {{ $variant->color->name_en }}
                    </div>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>
                <td>{{ $variant->size?->name_en ?? '—' }}</td>
                <td>
                    <form action="{{ route('products.variants.update', [$product, $variant]) }}"
                          method="POST" class="d-flex align-items-center gap-1">
                        @csrf @method('PUT')
                        <input type="number" name="stock" value="{{ $variant->stock }}"
                               min="0" class="form-control form-control-sm" style="width:70px">
                        <input type="hidden" name="price_modifier" value="{{ $variant->price_modifier }}">
                        <input type="hidden" name="is_active" value="{{ $variant->is_active ? 1 : 0 }}">
                        <button class="btn btn-sm btn-outline-primary" title="Save stock">
                            <i class="bi bi-check"></i>
                        </button>
                    </form>
                </td>
                <td>
                    @if($variant->price_modifier != 0)
                        <span class="{{ $variant->price_modifier > 0 ? 'text-danger' : 'text-success' }}">
                            {{ $variant->price_modifier > 0 ? '+' : '' }}{{ $variant->price_modifier }} EGP
                        </span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td><code style="font-size:11px">{{ $variant->sku ?? '—' }}</code></td>
                <td>
                    <span class="badge {{ $variant->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $variant->is_active ? 'Active' : 'Inactive' }}
                    </span>
                    @if($variant->stock == 0)
                        <span class="badge bg-danger ms-1">Out of Stock</span>
                    @endif
                </td>
                <td>
                    <form action="{{ route('products.variants.destroy', [$product, $variant]) }}"
                          method="POST" onsubmit="return confirm('Delete this variant?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    {{-- Summary --}}
    <div class="mt-3 p-3 bg-light rounded-3">
        <div class="row text-center">
            <div class="col-3">
                <div class="fw-700 text-primary" style="font-size:1.5rem">{{ $variants->count() }}</div>
                <small class="text-muted">Total Variants</small>
            </div>
            <div class="col-3">
                <div class="fw-700 text-success" style="font-size:1.5rem">{{ $variants->where('stock', '>', 0)->count() }}</div>
                <small class="text-muted">In Stock</small>
            </div>
            <div class="col-3">
                <div class="fw-700 text-danger" style="font-size:1.5rem">{{ $variants->where('stock', 0)->count() }}</div>
                <small class="text-muted">Out of Stock</small>
            </div>
            <div class="col-3">
                <div class="fw-700 text-info" style="font-size:1.5rem">{{ $variants->sum('stock') }}</div>
                <small class="text-muted">Total Stock</small>
            </div>
        </div>
    </div>
    @endif

</div>
</div>
</section>
@endsection