@if (session()->has('success'))

    <div class="alert alert-success alert-dismissible fade show text-center m-auto col-6" id="successMessage" role="alert">
        {{session('success')}}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

@endif

@if (Session::has('error'))

    <div class="alert alert-danger alert-dismissible fade show text-center m-auto col-6" id="successMessage" role="alert">
        {{ Session::get('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

@endif

@if(session('validationErrors'))
    <div class="alert alert-danger">
        <ul>
            @foreach(session('validationErrors') as $error)
                <li>
                    {{ $error }}
                </li>
            @endforeach
        </ul>
    </div>
@endif

