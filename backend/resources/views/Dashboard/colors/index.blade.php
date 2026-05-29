@extends('Dashboard.layouts.master')
@section('title-head')Colors@endsection

@section('content')
<div class="pagetitle">
    <h1>Colors Management</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item active">Colors</li>
    </ol></nav>
</div>

<section class="section">
<div class="card">
<div class="card-body pt-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title mb-0">All Colors ({{ $colors->count() }})</h5>
        <a href="{{ route('new-colors.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Add Color
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-3">
        @forelse($colors as $color)
        <div class="col-6 col-md-3 col-lg-2">
            <div class="color-card border rounded-3 p-3 text-center position-relative">
                {{-- Color swatch --}}
                <div class="color-swatch mx-auto mb-2"
                     style="width:50px;height:50px;border-radius:50%;background:{{ $color->hex }};border:3px solid #eee;box-shadow:0 2px 8px rgba(0,0,0,.15)">
                </div>
                <div class="fw-600 small">{{ $color->name_en }}</div>
                <div class="text-muted" style="font-size:11px">{{ $color->name_ar }}</div>
                <div class="text-muted mt-1" style="font-size:10px;font-family:monospace">{{ $color->hex }}</div>

                {{-- Status badge --}}
                <span class="badge {{ $color->is_active ? 'bg-success' : 'bg-secondary' }} mt-1" style="font-size:9px">
                    {{ $color->is_active ? 'Active' : 'Inactive' }}
                </span>

                {{-- Actions --}}
                <div class="color-actions mt-2 d-flex justify-content-center gap-2">
                    <a href="{{ route('new-colors.edit', $color) }}"
                       class="btn btn-outline-primary btn-sm" style="padding:2px 8px;font-size:11px">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form action="{{ route('new-colors.destroy', $color) }}" method="POST"
                          onsubmit="return confirm('Delete this color?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm" style="padding:2px 8px;font-size:11px">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @empty
        <div class="col-12">
            <div class="text-center py-5 text-muted">
                <i class="bi bi-palette" style="font-size:3rem;opacity:.3"></i>
                <p class="mt-2">No colors yet. <a href="{{ route('new-colors.create') }}">Add one</a></p>
            </div>
        </div>
        @endforelse
    </div>

</div>
</div>
</section>
@endsection