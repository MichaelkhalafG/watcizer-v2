<!-- Title -->
<title> @yield('title-head')  </title>

<!-- Favicons -->
<link href="{{ asset('DashAssets/img/favicon.webp') }}" rel="icon">
<link href="{{ asset('DashAssets/img/apple-touch-icon.webp') }}" rel="apple-touch-icon">

<!-- Google Fonts -->
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

<!-- Vendor CSS Files -->
<link href="{{ asset('DashAssets/vendor/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/boxicons/css/boxicons.min.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/quill/quill.snow.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/quill/quill.bubble.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/remixicon/remixicon.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/simple-datatables/style.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/select2/css/select2.min.css') }}" rel="stylesheet">
<link href="{{ asset('DashAssets/vendor/lightbox2/css/lightbox2.min.css') }}" rel="stylesheet">
@yield('css')
@if (App::getLocale() == 'ar')

    <link href="{{ asset('DashAssets/vendor/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('DashAssets/css/style-rtl.css') }}" rel="stylesheet">
    <link href="{{ asset('DashAssets/vendor/select2/css/select2-bootstrap-5-theme.rtl.min.css') }}" rel="stylesheet">

@else

    <link href="{{ asset('DashAssets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('DashAssets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('DashAssets/vendor/select2/css/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
@endif

