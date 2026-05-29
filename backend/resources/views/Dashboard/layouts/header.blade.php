<!-- ======= Header ======= -->
<header id="header" class="header fixed-top d-flex align-items-center">

<div class="d-flex align-items-center justify-content-between">
    <a href="{{ route('dashboard') }}" class="logo d-flex align-items-center">
    <img src="{{ asset('DashAssets/img/logo.webp') }}" alt="">
    <span class="d-none d-lg-block">Watchizer</span>
    </a>
    <i class="bi bi-list toggle-sidebar-btn"></i>
</div><!-- End Logo -->

{{-- <div class="search-bar">
    <form class="search-form d-flex align-items-center" method="POST" action="#">
    <input type="text" name="query" placeholder="Search" title="Enter search keyword">
    <button type="submit" title="Search"><i class="bi bi-search"></i></button>
    </form>
</div><!-- End Search Bar --> --}}

<nav class="header-nav ms-auto">
    <ul class="d-flex align-items-center">

    <li class="nav-item d-block d-lg-none">
        <a class="nav-link nav-icon search-bar-toggle " href="#">
        <i class="bi bi-search"></i>
        </a>
    </li><!-- End Search Icon-->
    <div class="dropdown nav-itemd-none d-md-flex">
        <button class="btn d-flex nav-item nav-link pl-0 country-flag1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            @if (App::getLocale() == 'ar')

                <span class="avatar country-Flag mr-0 align-self-center bg-transparent"><img src="{{URL::asset('DashAssets/img/flags/eg_flag.png')}}" alt="img"></span>

            @else

                <span class="avatar country-Flag mr-0 align-self-center bg-transparent"><img src="{{URL::asset('DashAssets/img/flags/us_flag.jpg')}}" alt="img"></span>

            @endif
        </button>
        <ul class="dropdown-menu">
            @foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
            <li>
                <a rel="alternate" hreflang="{{ $localeCode }}" href="{{ LaravelLocalization::getLocalizedURL($localeCode, null, [], true) }}" class="dropdown-item d-flex">
                    @if ($properties['native'] == 'English')
                        <i class="flag-icon flag-icon-us"></i>
                    @elseif($properties['native'] == 'العربية')
                        <i class="flag-icon flag-icon-eg"></i>
                    @endif
                    {{$properties['native']}}
                </a>
            </li>
          @endforeach
        </ul>
      </div>

    <li class="nav-item dropdown pe-3">

        <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
        <img src="
        @if(auth()->user()->image)
            {{ asset('Uploads_Images/User/' . auth()->user()->image) }}
        @else
            {{ asset('DashAssets/img/user_default.png') }}
        @endif
         " alt="Profile" class="rounded-circle">
        <span class="d-none d-md-block dropdown-toggle ps-2">{{ auth()->user()->first_name . ' ' . auth()->user()->last_name }}</span>
        </a><!-- End Profile Iamge Icon -->

        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
        <li class="dropdown-header">
            <h6>{{ auth()->user()->first_name . ' ' . auth()->user()->last_name }}</h6>
            <span>{{ auth()->user()->type }}</span>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li>

        {{-- <li>
            <a class="dropdown-item d-flex align-items-center" href="users-profile.html">
            <i class="bi bi-person"></i>
            <span>My Profile</span>
            </a>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li>

        <li>
            <a class="dropdown-item d-flex align-items-center" href="users-profile.html">
            <i class="bi bi-gear"></i>
            <span>Account Settings</span>
            </a>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li>

        <li>
            <a class="dropdown-item d-flex align-items-center" href="pages-faq.html">
            <i class="bi bi-question-circle"></i>
            <span>Need Help?</span>
            </a>
        </li>
        <li>
            <hr class="dropdown-divider">
        </li> --}}

        <li>
            <form action="{{ route('logout') }}" method="post">
                @csrf
                <a class="dropdown-item d-flex align-items-center" href="#" onclick="event.preventDefault(); this.closest('form').submit();">
                <i class="bi bi-box-arrow-right"></i>
                <span>{{ trans('dashboard.logout') }}</span>
            </form>
            </a>
        </li>

        </ul><!-- End Profile Dropdown Items -->
    </li><!-- End Profile Nav -->

    </ul>
</nav><!-- End Icons Navigation -->

</header>
<!-- End Header -->
