@extends('Dashboard.layouts.master')
@section('title-head'){{ trans('product.manage_images') }}@endsection

@section('content')
<div class="row">
    <div class="pagetitle col-12">
        <h1>{{ trans('product.manage_images') }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ trans('sidebar.dashboard') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('product.index') }}">{{ trans('sidebar.product') }}</a></li>
                <li class="breadcrumb-item active">{{ $product->product_title }}</li>
            </ol>
        </nav>
    </div>
</div>

<section class="section">
<div class="row">
<div class="col-lg-12">

{{-- ════ MAIN IMAGE (برة) ════ --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-2">
            <h5 class="card-title mb-0">{{ trans('product.main_image_label') }}</h5>
            <span class="badge bg-primary">{{ trans('product.main_image_hint') }}</span>
        </div>
        <div class="d-flex align-items-center gap-4">
            <div class="main-img-wrap">
                <img src="{{ asset('Uploads_Images/Product/' . $product->image) }}"
                     alt="{{ $product->product_title }}"
                     class="main-thumb">
            </div>
            <div>
                <p class="text-muted small mb-2">{{ trans('product.main_image_desc') }}</p>
                <form action="{{ route('product.update', $product) }}" method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                    @csrf @method('PUT')
                    <input type="hidden" name="_only_image" value="1">
                    <input type="file" name="image" id="main_img_input" class="form-control form-control-sm" accept="image/*" style="max-width:260px">
                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ trans('product.replace_main_image') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- ════ GALLERY IMAGES (جوه) ════ --}}
<div class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="card-title mb-0">
                {{ trans('product.gallery_images_label') }}
                <span class="badge bg-secondary ms-2">{{ $images->count() }} {{ trans('product.images_count') }}</span>
            </h5>
        </div>

        {{-- Upload zone --}}
        <form action="{{ route('product.images.store', $product) }}" method="POST"
              enctype="multipart/form-data" id="upload-form">
            @csrf
            <div class="upload-zone" id="drop-zone">
                <div class="upload-zone-inner" onclick="document.getElementById('gallery_files').click()">
                    <div class="upload-icon">
                        <i class="bi bi-cloud-upload"></i>
                    </div>
                    <p class="upload-text">{{ trans('product.drop_images_here') }}</p>
                    <p class="upload-sub">{{ trans('product.upload_formats') }}</p>
                </div>
                <input type="file" id="gallery_files" name="images[]"
                       multiple accept="image/*" class="d-none">
            </div>

            {{-- Preview before upload --}}
            <div id="preview-grid" class="img-grid mt-3 d-none"></div>

            <div id="upload-actions" class="mt-3 d-none">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-upload me-1"></i> {{ trans('product.upload_selected') }}
                    (<span id="selected-count">0</span>)
                </button>
                <button type="button" class="btn btn-outline-secondary ms-2" onclick="clearPreview()">
                    {{ trans('product.clear_selection') }}
                </button>
            </div>
        </form>

        <hr class="my-4">

        {{-- Existing gallery --}}
        @if($images->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-images" style="font-size:2rem;opacity:.4"></i>
                <p class="mt-2">{{ trans('product.no_gallery_images') }}</p>
            </div>
        @else
            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle me-1"></i>{{ trans('product.drag_to_sort') }}
            </p>
            <div class="img-grid sortable" id="gallery-grid">
                @foreach($images as $img)
                <div class="img-card" data-id="{{ $img->id }}">
                    <div class="img-card-inner">
                        <img src="{{ asset('Uploads_Images/Product_image/' . $img->image) }}"
                             alt="{{ $img->alt_en ?? $product->product_title }}"
                             class="gallery-thumb">

                        {{-- Cover badge --}}
                        @if($img->is_cover)
                            <span class="cover-badge">{{ trans('product.cover') }}</span>
                        @endif

                        {{-- Actions --}}
                        <div class="img-actions">
                            @if(!$img->is_cover)
                            <form action="{{ route('product.images.cover', $img) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn-img-action btn-cover" title="{{ trans('product.set_as_cover') }}">
                                    <i class="bi bi-star"></i>
                                </button>
                            </form>
                            @else
                            <span class="btn-img-action btn-cover active" title="{{ trans('product.current_cover') }}">
                                <i class="bi bi-star-fill"></i>
                            </span>
                            @endif

                            <form action="{{ route('product.images.destroy', $img) }}" method="POST"
                                  class="d-inline"
                                  onsubmit="return confirm('{{ trans('product.delete_image_confirm') }}')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-img-action btn-delete" title="{{ trans('product.delete_image') }}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>

                        {{-- Drag handle --}}
                        <div class="drag-handle">
                            <i class="bi bi-grip-vertical"></i>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif

    </div>
</div>

</div>
</div>
</section>

<style>
.main-thumb { width:120px;height:120px;object-fit:cover;border-radius:10px;border:2px solid var(--color-border-secondary) }
.upload-zone { border:2px dashed var(--color-border-secondary);border-radius:12px;padding:30px;text-align:center;cursor:pointer;transition:border-color .2s }
.upload-zone:hover,.upload-zone.dragover { border-color:var(--color-text-info) }
.upload-zone-inner { pointer-events:none }
.upload-icon { font-size:2.5rem;color:var(--color-text-tertiary);margin-bottom:8px }
.upload-text { font-size:15px;font-weight:500;color:var(--color-text-primary);margin:0 }
.upload-sub { font-size:12px;color:var(--color-text-tertiary);margin:4px 0 0 }
.img-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px }
.img-card { border-radius:10px;overflow:hidden;border:1.5px solid var(--color-border-tertiary);background:var(--color-background-secondary) }
.img-card-inner { position:relative }
.gallery-thumb { width:100%;aspect-ratio:1;object-fit:cover;display:block }
.cover-badge { position:absolute;top:6px;left:6px;background:#378ADD;color:#fff;font-size:10px;font-weight:600;padding:2px 7px;border-radius:4px }
.img-actions { position:absolute;top:6px;right:6px;display:flex;flex-direction:column;gap:4px;opacity:0;transition:opacity .15s }
.img-card:hover .img-actions { opacity:1 }
.btn-img-action { width:28px;height:28px;border-radius:6px;border:none;display:flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer }
.btn-cover { background:#fff8e1;color:#e6a817 }
.btn-cover.active { background:#e6a817;color:#fff }
.btn-delete { background:#fdecea;color:#d32f2f }
.drag-handle { position:absolute;bottom:6px;left:50%;transform:translateX(-50%);color:var(--color-text-tertiary);font-size:14px;cursor:grab;opacity:0;transition:opacity .15s }
.img-card:hover .drag-handle { opacity:1 }
.img-card.sortable-chosen { opacity:.7;transform:scale(.97) }
</style>
@endsection

@section('script')
{{-- SortableJS for drag & drop --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
// ── Drag & drop to sort ──────────────────────────────
const grid = document.getElementById('gallery-grid');
if (grid) {
    new Sortable(grid, {
        animation: 150,
        handle: '.drag-handle',
        chosenClass: 'sortable-chosen',
        onEnd: function () {
            const order = [...grid.querySelectorAll('.img-card')].map(c => c.dataset.id);
            fetch('{{ route("product.images.sort") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ order })
            });
        }
    });
}

// ── Drag & drop file upload ──────────────────────────
const zone = document.getElementById('drop-zone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});

document.getElementById('gallery_files').addEventListener('change', function () {
    handleFiles(this.files);
});

function handleFiles(files) {
    const grid   = document.getElementById('preview-grid');
    const actions= document.getElementById('upload-actions');
    const count  = document.getElementById('selected-count');
    const dt     = new DataTransfer();

    // Add to existing input
    const inp = document.getElementById('gallery_files');
    [...(inp.files || [])].forEach(f => dt.items.add(f));
    [...files].forEach(f => dt.items.add(f));
    inp.files = dt.files;

    grid.innerHTML = '';
    [...inp.files].forEach((file, i) => {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'img-card';
            div.innerHTML = `
                <div class="img-card-inner">
                    <img src="${e.target.result}" class="gallery-thumb" style="opacity:.85">
                    <span class="cover-badge" style="background:#28a745">New</span>
                </div>`;
            grid.appendChild(div);
        };
        reader.readAsDataURL(file);
    });

    count.textContent = inp.files.length;
    grid.classList.remove('d-none');
    actions.classList.remove('d-none');
}

function clearPreview() {
    document.getElementById('gallery_files').value = '';
    document.getElementById('preview-grid').innerHTML = '';
    document.getElementById('preview-grid').classList.add('d-none');
    document.getElementById('upload-actions').classList.add('d-none');
    document.getElementById('selected-count').textContent = '0';
}
</script>
@endsection