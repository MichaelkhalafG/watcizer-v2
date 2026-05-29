<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ trans('dashboard.welcome') }}</title>
    <link href="{{ asset('DashAssets/img/favicon.webp') }}" rel="icon">
    <link href="{{ asset('DashAssets/img/apple-touch-icon.webp') }}" rel="apple-touch-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <style>
        /* إعدادات الصفحة */
        body, html {
            height: 100%;
            margin: 0;
            font-family: 'Cairo', sans-serif;
            color: white;
            text-align: center;
        }

        /* صورة الخلفية */
        .background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('/DashAssets/img/pexels-giallo-859895.jpg') center/cover no-repeat;
            filter: brightness(40%);
            z-index: -1;
            animation: zoomEffect 10s infinite alternate ease-in-out;
        }

        @keyframes zoomEffect {
            0% { transform: scale(1); }
            100% { transform: scale(1.1); }
        }

        /* شريط التنقل */
        .navbar {
            background: rgb(255 255 255 / 84%);
            padding: 15px;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            font-size: 1.8em;
            font-weight: bold;
            text-transform: uppercase;
            color: rgba(38, 38, 38, 0.9) !important;
            letter-spacing: 2px;
        }

        .navbar-brand img {
            height: 50px;
            margin-right: 10px;
        }

        .nav-link {
            color: rgba(38, 38, 38, 0.9) !important;
            font-size: 1.2em;
            padding: 8px 20px;
            border-radius: 20px;
            transition: 0.3s;
        }

        .nav-link:hover {
            background: #007bff;
            color: white !important;
        }

        /* قسم الترحيب */
        .hero {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            width: 100%;
        }

        .hero h1 {
            font-size: 4rem;
            font-weight: bold;
            text-shadow: 3px 3px 15px rgba(0, 123, 255, 0.6);
            color: white;
            animation: fadeIn 2s ease-in-out;
        }

        /* حركة دخول النص */
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(-20px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* استجابة للأجهزة الصغيرة */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1.2rem;
            }

            .navbar-brand img {
                height: 40px;
            }
        }
    </style>
</head>
<body>

    <!-- صورة الخلفية -->
    <div class="background"></div>

    <!-- شريط التنقل مع اللوجو -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="{{ route('dashboard') }}">
                <img src="{{ asset('DashAssets/img/logo.webp') }}" alt="logo Watchizer">
                Watchizer
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">

                    @if (Route::has('login'))
                        @auth
                            <li class="nav-item"><a href="{{ url('/admin/dashboard') }}" class="nav-link btn btn-primary text-light">{{ trans('sidebar.dashboard') }}</a></li>
                            <li class="nav-item">
                                <form action="{{ route('logout') }}" method="post">
                                    @csrf
                                    <a class="nav-link btn btn-primary text-light" href="#" onclick="event.preventDefault(); this.closest('form').submit();">{{ trans('dashboard.logout') }}</a>

                                </form>
                            </li>
                        @else
                            <li class="nav-item"><a href="{{ route('login') }}" class="nav-link btn btn-primary text-light">{{ trans('dashboard.login') }}</a></li>

                            @if (Route::has('register'))
                                <li class="nav-item"><a href="{{ route('register') }}" class="nav-link btn bbtn-primary text-light">{{ trans('dashboard.register') }}</a></li>
                            @endif
                        @endauth
                    @endif

                </ul>
            </div>
        </div>
    </nav>

    <!-- قسم الترحيب -->
    <div class="hero">
        <h1> {{ trans('dashboard.welcome') }}</h1>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


