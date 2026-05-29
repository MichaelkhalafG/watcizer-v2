@extends('Dashboard.layouts.master')
@section('title-head')Sizes@endsection

@section('content')
<div class="pagetitle">
    <h1>Sizes Management</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item active">Sizes</li>
    </ol></nav>
</div>

<section class="section">
<div class="card">
<div class="card-body pt-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title mb-0">All Sizes</h5>
        <a href="{{ route('new-sizes.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Add Size
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @foreach($sizes as $type => $group)
    <div class="mb-4">
        <h6 class="text-muted text-uppercase fw-bold mb-2" style="font-size:11px;letter-spacing:.5px">
            <i class="bi bi-tag me-1"></i>
            {{ \App\Models\NewSize::TYPES[$type] ?? ucfirst($type) }}
            <span class="badge bg-light text-dark ms-1">{{ $group->count() }}</span>
        </h6>
        <div class="d-flex flex-wrap gap-2">
            @foreach($group as $size)
            <div class="size-chip border rounded-2 px-3 py-2 d-flex align-items-center gap-2"
                 style="background:#f8f9fa;min-width:80px">
                <div>
                    <div class="fw-500 small">{{ $size->name_en }}</div>
                    <div class="text-muted" style="font-size:10px">{{ $size->name_ar }}</div>
                </div>
                <div class="ms-auto d-flex gap-1">
                    <a href="{{ route('new-sizes.edit', $size) }}"
                       class="btn btn-outline-primary" style="padding:1px 6px;font-size:10px">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form action="{{ route('new-sizes.destroy', $size) }}" method="POST"
                          onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger" style="padding:1px 6px;font-size:10px">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endforeach

    @if($sizes->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-rulers" style="font-size:3rem;opacity:.3"></i>
        <p class="mt-2">No sizes yet. <a href="{{ route('new-sizes.create') }}">Add one</a></p>
    </div>
    @endif

</div>
</div>
</section>
@endsection