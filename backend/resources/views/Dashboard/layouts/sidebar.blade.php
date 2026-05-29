<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">
<ul class="sidebar-nav" id="sidebar-nav">

    {{-- Dashboard --}}
    <li class="nav-item">
        <a class="nav-link" href="{{ route('dashboard') }}">
            <i class="bi bi-grid"></i>
            <span>{{ trans('sidebar.dashboard') }}</span>
        </a>
    </li>

    {{-- ═══════════════════════════════
         CATALOG
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('category.*','product.*','new-colors.*','new-sizes.*','products.variants.*','brand.*','offer.*') ? '' : 'collapsed' }}"
           data-bs-target="#catalog" data-bs-toggle="collapse" href="#">
            <i class="bi bi-box-seam"></i>
            <span>Catalog</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="catalog" class="nav-content collapse {{ request()->routeIs('category.*','product.*','new-colors.*','new-sizes.*','products.variants.*','brand.*','offer.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            {{-- Categories --}}
            <li>
                <a href="{{ route('category.index') }}"
                   class="{{ request()->routeIs('category.*') ? 'active' : '' }}">
                    <i class="bi bi-diagram-3"></i>
                    <span>Categories</span>
                </a>
            </li>

            {{-- Products --}}
            <li>
                <a href="{{ route('product.index') }}"
                   class="{{ request()->routeIs('product.*') ? 'active' : '' }}">
                    <i class="bi bi-bag"></i>
                    <span>{{ trans('sidebar.product') }}</span>
                </a>
            </li>

            {{-- Colors (New) --}}
            <li>
                <a href="{{ route('new-colors.index') }}"
                   class="{{ request()->routeIs('new-colors.*') ? 'active' : '' }}">
                    <i class="bi bi-palette"></i>
                    <span>Colors</span>
                    <span class="badge bg-primary ms-auto" style="font-size:9px">NEW</span>
                </a>
            </li>

            {{-- Sizes (New) --}}
            <li>
                <a href="{{ route('new-sizes.index') }}"
                   class="{{ request()->routeIs('new-sizes.*') ? 'active' : '' }}">
                    <i class="bi bi-rulers"></i>
                    <span>Sizes</span>
                    <span class="badge bg-primary ms-auto" style="font-size:9px">NEW</span>
                </a>
            </li>

            {{-- Brands --}}
            <li>
                <a href="{{ route('brand.index') }}"
                   class="{{ request()->routeIs('brand.*') ? 'active' : '' }}">
                    <i class="bi bi-bookmark-star"></i>
                    <span>{{ trans('sidebar.brand') }}</span>
                </a>
            </li>

            {{-- Offers --}}
            <li>
                <a href="{{ route('offer.index') }}"
                   class="{{ request()->routeIs('offer.*') ? 'active' : '' }}">
                    <i class="bi bi-tag"></i>
                    <span>{{ trans('sidebar.offer') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         SALES
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('order.*','shipping_city.*') ? '' : 'collapsed' }}"
           data-bs-target="#sales" data-bs-toggle="collapse" href="#">
            <i class="bi bi-cart-check-fill"></i>
            <span>Sales</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="sales" class="nav-content collapse {{ request()->routeIs('order.*','shipping_city.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('order.index') }}"
                   class="{{ request()->routeIs('order.*') ? 'active' : '' }}">
                    <i class="bi bi-receipt"></i>
                    <span>{{ trans('sidebar.order') }}</span>
                </a>
            </li>

            <li>
                <a href="{{ route('shipping_city.index') }}"
                   class="{{ request()->routeIs('shipping_city.*') ? 'active' : '' }}">
                    <i class="bi bi-truck"></i>
                    <span>{{ trans('sidebar.shipping_city') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         CONTENT
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('banner_home.*','banner_side.*','banner_bottom.*','blog.*') ? '' : 'collapsed' }}"
           data-bs-target="#content" data-bs-toggle="collapse" href="#">
            <i class="bi bi-images"></i>
            <span>Content</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="content" class="nav-content collapse {{ request()->routeIs('banner_home.*','banner_side.*','banner_bottom.*','blog.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('banner_home.index') }}"
                   class="{{ request()->routeIs('banner_home.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i>
                    <span>{{ trans('sidebar.banner_home') }}</span>
                </a>
            </li>

            <li>
                <a href="{{ route('banner_side.index') }}"
                   class="{{ request()->routeIs('banner_side.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i>
                    <span>{{ trans('sidebar.banner_side') }}</span>
                </a>
            </li>

            <li>
                <a href="{{ route('banner_bottom.index') }}"
                   class="{{ request()->routeIs('banner_bottom.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i>
                    <span>{{ trans('sidebar.banner_bottom') }}</span>
                </a>
            </li>

            <li>
                <a href="{{ route('blog.index') }}"
                   class="{{ request()->routeIs('blog.*') ? 'active' : '' }}">
                    <i class="bi bi-pencil-square"></i>
                    <span>{{ trans('sidebar.blog') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         ANALYTICS
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('report.*') ? '' : 'collapsed' }}"
           data-bs-target="#analytics" data-bs-toggle="collapse" href="#">
            <i class="bi bi-bar-chart-line"></i>
            <span>Analytics</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="analytics" class="nav-content collapse {{ request()->routeIs('report.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            <li>
                <a href="{{ route('report.index') }}"
                   class="{{ request()->routeIs('report.*') ? 'active' : '' }}">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>{{ trans('sidebar.order_reports') }}</span>
                </a>
            </li>

        </ul>
    </li>

    {{-- ═══════════════════════════════
         SETTINGS (Product Attributes)
    ═══════════════════════════════ --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('grade.*','sub_type.*','category_type.*','color.*','closure_type.*','display_type.*','size_type.*','shape.*','material.*','feature.*','movement_type.*','gender.*','user.*','product_rating.*','offer_rating.*','product_image.*') ? '' : 'collapsed' }}"
           data-bs-target="#settings" data-bs-toggle="collapse" href="#">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
            <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="settings" class="nav-content collapse {{ request()->routeIs('grade.*','sub_type.*','category_type.*','color.*','closure_type.*','display_type.*','size_type.*','shape.*','material.*','feature.*','movement_type.*','gender.*','user.*','product_rating.*','offer_rating.*','product_image.*') ? 'show' : '' }}"
            data-bs-parent="#sidebar-nav">

            {{-- Users --}}
            <li>
                <a href="{{ route('user.index') }}"
                   class="{{ request()->routeIs('user.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span>{{ trans('sidebar.user') }}</span>
                </a>
            </li>

            {{-- General --}}
            <li>
                <a href="{{ route('category.index') }}"
                   class="{{ request()->routeIs('category.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i>
                    <span>{{ trans('sidebar.category') }}</span>
                </a>
            </li>

            {{-- Product Attributes --}}
            <li class="sidebar-sub-header" style="padding:8px 15px 2px;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px">
                Product Attributes
            </li>

            <li>
                <a href="{{ route('grade.index') }}"
                   class="{{ request()->routeIs('grade.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.grade') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('sub_type.index') }}"
                   class="{{ request()->routeIs('sub_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.sub_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('category_type.index') }}"
                   class="{{ request()->routeIs('category_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.category_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('color.index') }}"
                   class="{{ request()->routeIs('color.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.color') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('closure_type.index') }}"
                   class="{{ request()->routeIs('closure_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.closure_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('display_type.index') }}"
                   class="{{ request()->routeIs('display_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.display_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('size_type.index') }}"
                   class="{{ request()->routeIs('size_type.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.size_type') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('shape.index') }}"
                   class="{{ request()->routeIs('shape.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.shape') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('material.index') }}"
                   class="{{ request()->routeIs('material.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.material') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('gender.index') }}"
                   class="{{ request()->routeIs('gender.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.gender') }}</span>
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

            {{-- Ratings --}}
            <li class="sidebar-sub-header" style="padding:8px 15px 2px;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px">
                Ratings
            </li>
            <li>
                <a href="{{ route('product_rating.index') }}"
                   class="{{ request()->routeIs('product_rating.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.product_rating') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('offer_rating.index') }}"
                   class="{{ request()->routeIs('offer_rating.*') ? 'active' : '' }}">
                    <i class="bi bi-circle"></i><span>{{ trans('sidebar.offer_rating') }}</span>
                </a>
            </li>

        </ul>
    </li>

</ul>
</aside>
<!-- End Sidebar -->