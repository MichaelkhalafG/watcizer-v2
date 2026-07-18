<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">
<ul class="sidebar-nav" id="sidebar-nav">

    {{-- ═══════════════════════════════
         📊 DASHBOARD
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('dashboard') ? '' : 'collapsed' }}" href="{{ route('dashboard') }}">
            <i class="bi bi-grid"></i>
            <span>{{ trans('sidebar.dashboard') }}</span>
        </a>
    </li>

    {{-- ═══════════════════════════════
         📦 PRODUCTS
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('product.*','brand.*','offer.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-products" data-bs-toggle="collapse" href="#">
            <i class="bi bi-box-seam"></i>
            <span>Products</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-products" class="nav-content collapse {{ request()->routeIs('product.*','brand.*','offer.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('product.index') }}"
                   class="{{ request()->routeIs('product.index','product.show','product.edit') ? 'active' : '' }}">
                    <i class="bi bi-bag"></i><span>All Products</span>
                </a>
            </li>
            <li>
                <a href="{{ route('product.create') }}"
                   class="{{ request()->routeIs('product.create') ? 'active' : '' }}">
                    <i class="bi bi-plus-circle"></i><span>Add Product</span>
                </a>
            </li>
            <li>
                <a href="{{ route('brand.index') }}"
                   class="{{ request()->routeIs('brand.*') ? 'active' : '' }}">
                    <i class="bi bi-bookmark-star"></i><span>{{ trans('sidebar.brand') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('offer.index') }}"
                   class="{{ request()->routeIs('offer.*') ? 'active' : '' }}">
                    <i class="bi bi-tag"></i><span>{{ trans('sidebar.offer') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         📂 CLASSIFICATION
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('category_type.*','sub_type.*','grade.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-classification" data-bs-toggle="collapse" href="#">
            <i class="bi bi-diagram-3"></i>
            <span>Classification</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-classification" class="nav-content collapse {{ request()->routeIs('category_type.*','sub_type.*','grade.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('category_type.index') }}"
                   class="{{ request()->routeIs('category_type.*') ? 'active' : '' }}">
                    <i class="bi bi-collection"></i><span>{{ trans('sidebar.category_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('sub_type.index') }}"
                   class="{{ request()->routeIs('sub_type.*') ? 'active' : '' }}">
                    <i class="bi bi-diagram-2"></i><span>{{ trans('sidebar.sub_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('grade.index') }}"
                   class="{{ request()->routeIs('grade.*') ? 'active' : '' }}">
                    <i class="bi bi-award"></i><span>{{ trans('sidebar.grade') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         ⚙️ PRODUCT SPECS
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('color.*','material.*','shape.*','feature.*','movement_type.*','display_type.*','closure_type.*','size_type.*','gender.*','new-colors.*','new-sizes.*','products.variants.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-specs" data-bs-toggle="collapse" href="#">
            <i class="bi bi-sliders"></i>
            <span>Product Specs</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-specs" class="nav-content collapse {{ request()->routeIs('color.*','material.*','shape.*','feature.*','movement_type.*','display_type.*','closure_type.*','size_type.*','gender.*','new-colors.*','new-sizes.*','products.variants.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('color.index') }}"
                   class="{{ request()->routeIs('color.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.color') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('material.index') }}"
                   class="{{ request()->routeIs('material.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.material') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('shape.index') }}"
                   class="{{ request()->routeIs('shape.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.shape') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('feature.index') }}"
                   class="{{ request()->routeIs('feature.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.feature') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('movement_type.index') }}"
                   class="{{ request()->routeIs('movement_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.movement_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('display_type.index') }}"
                   class="{{ request()->routeIs('display_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.display_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('closure_type.index') }}"
                   class="{{ request()->routeIs('closure_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.closure_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('size_type.index') }}"
                   class="{{ request()->routeIs('size_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.size_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('gender.index') }}"
                   class="{{ request()->routeIs('gender.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.gender') }}</span>
                </a>
            </li>

            {{-- Variants (kept — not part of the requested list, but retained per
                 "only hide Categories"; these power the Product-Variant screens) --}}
            <li class="sidebar-sub-header" style="padding:8px 15px 2px;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px">
                Variants
            </li>
            <li>
                <a href="{{ route('new-colors.index') }}"
                   class="{{ request()->routeIs('new-colors.*') ? 'active' : '' }}">
                    <i class="bi bi-palette"></i><span>Variant Colors</span>
                    <span class="badge bg-primary ms-auto" style="font-size:9px">NEW</span>
                </a>
            </li>
            <li>
                <a href="{{ route('new-sizes.index') }}"
                   class="{{ request()->routeIs('new-sizes.*') ? 'active' : '' }}">
                    <i class="bi bi-rulers"></i><span>Variant Sizes</span>
                    <span class="badge bg-primary ms-auto" style="font-size:9px">NEW</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         🎨 APPEARANCE
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('banner_home.*','banner_side.*','banner_bottom.*','blog.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-appearance" data-bs-toggle="collapse" href="#">
            <i class="bi bi-images"></i>
            <span>Appearance</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-appearance" class="nav-content collapse {{ request()->routeIs('banner_home.*','banner_side.*','banner_bottom.*','blog.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('banner_home.index') }}"
                   class="{{ request()->routeIs('banner_home.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.banner_home') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('banner_side.index') }}"
                   class="{{ request()->routeIs('banner_side.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.banner_side') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('banner_bottom.index') }}"
                   class="{{ request()->routeIs('banner_bottom.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.banner_bottom') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('blog.index') }}"
                   class="{{ request()->routeIs('blog.*') ? 'active' : '' }}">
                    <i class="bi bi-pencil-square"></i><span>{{ trans('sidebar.blog') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         📋 ORDERS
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('order.*','report.*','product_rating.*','offer_rating.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-orders" data-bs-toggle="collapse" href="#">
            <i class="bi bi-cart-check-fill"></i>
            <span>Orders</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-orders" class="nav-content collapse {{ request()->routeIs('order.*','report.*','product_rating.*','offer_rating.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('order.index') }}"
                   class="{{ request()->routeIs('order.*') ? 'active' : '' }}">
                    <i class="bi bi-receipt"></i><span>{{ trans('sidebar.order') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('report.index') }}"
                   class="{{ request()->routeIs('report.*') ? 'active' : '' }}">
                    <i class="bi bi-file-earmark-bar-graph"></i><span>{{ trans('sidebar.order_reports') }}</span>
                </a>
            </li>

            {{-- Ratings (kept — customer feedback; retained per "only hide Categories") --}}
            <li class="sidebar-sub-header" style="padding:8px 15px 2px;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px">
                Ratings
            </li>
            <li>
                <a href="{{ route('product_rating.index') }}"
                   class="{{ request()->routeIs('product_rating.*') ? 'active' : '' }}">
                    <i class="bi bi-star"></i><span>{{ trans('sidebar.product_rating') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('offer_rating.index') }}"
                   class="{{ request()->routeIs('offer_rating.*') ? 'active' : '' }}">
                    <i class="bi bi-star-half"></i><span>{{ trans('sidebar.offer_rating') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         👥 USERS
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('user.*','shipping_city.*') ? '' : 'collapsed' }}"
           data-bs-target="#grp-users" data-bs-toggle="collapse" href="#">
            <i class="bi bi-people"></i>
            <span>Users</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="grp-users" class="nav-content collapse {{ request()->routeIs('user.*','shipping_city.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('user.index') }}"
                   class="{{ request()->routeIs('user.*') ? 'active' : '' }}">
                    <i class="bi bi-person"></i><span>{{ trans('sidebar.user') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('shipping_city.index') }}"
                   class="{{ request()->routeIs('shipping_city.*') ? 'active' : '' }}">
                    <i class="bi bi-truck"></i><span>{{ trans('sidebar.shipping_city') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- NOTE: The "Categories" link (categories table CRUD) was removed from the
         sidebar — that table is no longer used by product creation or the
         storefront. The category.* routes, controller, model and views are all
         kept intact and remain reachable directly by URL. --}}

</ul>
</aside>
<!-- End Sidebar -->