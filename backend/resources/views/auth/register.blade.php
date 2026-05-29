@extends('Dashboard.layouts.master2')
@section('title-head', 'Register')
@section('content')
    <div class="container">

        <style>
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

            .form-control {
                background: rgb(255 255 255 / 50%) !important;
            }

            .form-label {
                color: rgb(38, 38, 38, 0.9) !important;
            }
        </style>
        <div class="background"></div>

        <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">


                        <div class="card mb-3" style="background:rgb(255 255 255 / 84%); !important;">

                            <div class="d-flex justify-content-center py-4 shadow p-3 bg-body-tertiary rounded" >
                                <a class="logo d-flex align-items-center w-auto" href="{{ route('dashboard') }}">
                                    <img src="{{ asset('DashAssets/img/logo.webp') }}" alt="logo Watchizer">
                                    <span class="d-none d-lg-block">Watchizer</span>
                                </a>
                            </div><!-- End Logo -->

                            <div class="card-body">

                                <div class="pt-2 pb-2">
                                    <h5 class="card-title text-center pb-0 fs-4">{{ trans('register.h2') }}</h5>
                                </div>

                                <form class="row g-3 needs-validation" method="POST" action="{{ route('register') }}">
                                    @csrf

                                    <div class="col-6">
                                        <label for="first_name" class="form-label">{{ trans('register.first_name') }}</label>
                                        <input type="text" name="first_name" class="form-control" id="first_name" value="{{ old('first_name') }}" required>
                                        <div class="invalid-feedback">Please, enter your first name!</div>
                                        @error('first_name')
                                            <p class="text-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="col-6">
                                        <label for="last_name" class="form-label">{{ trans('register.last_name') }}</label>
                                        <input type="text" name="last_name" class="form-control" id="last_name" value="{{ old('last_name') }}" required>
                                        <div class="invalid-feedback">Please, enter your last name!</div>
                                        @error('last_name')
                                            <p class="text-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <label for="email" class="form-label">{{ trans('register.email') }}</label>
                                        <input type="email" name="email" class="form-control" id="email" value="{{ old('email') }}" required>
                                        <div class="invalid-feedback">Please enter a valid Email adddress!</div>
                                        @error('email')
                                            <p class="text-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <label for="password" class="form-label">{{ trans('register.password') }}</label>
                                        <input type="password" name="password" class="form-control" id="password" required>
                                        <div class="invalid-feedback">Please enter your password!</div>
                                        @error('password')
                                            <p class="text-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <label for="password_confirmation" class="form-label">{{ trans('register.confirm_password') }}</label>
                                        <input type="password" name="password_confirmation" class="form-control" id="password_confirmation" required>
                                        <div class="invalid-feedback">Please enter your password!</div>
                                        @error('password_confirmation')
                                            <p class="text-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <button class="btn btn-primary w-100" type="submit">{{ trans('register.h2') }}</button>
                                    </div>
                                    <div class="col-12">
                                        <p class="small mb-0">{{ trans('register.an_account') }} <a href="{{ route('login') }}">{{ trans('register.login') }}</a>
                                        </p>
                                    </div>
                                </form>

                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </section>

    </div>


@endsection
