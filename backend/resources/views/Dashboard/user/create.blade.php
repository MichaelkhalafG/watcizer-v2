<!-- Modal -->
<div class="modal fade" id="Add" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h1 class="modal-title fs-5" id="exampleModalLabel">{{ trans('user.add') }}</h1>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="{{ route('user.store') }}" method="POST">
            @csrf

            <div class="modal-body row g-3">

                <div class="col-6">
                    <label for="first_name" class="form-label">{{ trans('user.first_name') }}</label>
                    <input type="text" class="form-control" name="first_name" id="first_name">
                    @error('first_name')
                        <p class="text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-6">
                    <label for="last_name" class="form-label">{{ trans('user.last_name') }}</label>
                    <input type="text" class="form-control" name="last_name" id="last_name">
                    @error('last_name')
                        <p class="text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-12">
                    <label for="email" class="form-label">{{ trans('user.email') }}</label>
                    <input type="email" class="form-control" name="email" id="email">
                    @error('email')
                        <p class="text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-12">
                    <label for="password" class="form-label">{{ trans('user.password') }}</label>
                    <input type="password" class="form-control" name="password" id="password">
                    @error('password')
                        <p class="text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-12">
                    <label for="type" class="form-label">{{ trans('user.type') }}</label>
                    <select class="form-select" name="type" id="type">
                        <option disabled selected>{{ trans('user.select') }} {{ trans('user.type') }}</option>
                        <option value="SuperAdmin">Super Admin</option>
                        <option value="Admin">Admin</option>
                        <option value="User">User</option>
                    </select>
                    @error('type')
                        <p class="text-danger">{{ $message }}</p>
                    @enderror
                </div>

            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('mainBtn.close_btn') }}</button>
            <button type="submit" class="btn btn-primary">{{ trans('mainBtn.add_btn') }}</button>
            </div>
        </form>
      </div>
    </div>
  </div>

