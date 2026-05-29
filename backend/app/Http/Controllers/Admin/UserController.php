<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $user = User::all();
        return view('Dashboard.user.index' , compact('user'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|lowercase|email|max:255|unique:users,email',
            'password'   => ['required', Password::defaults()],
            'type'       => 'required|string',
        ]);

        $user = new User;
        $user->first_name = $request->first_name;
        $user->last_name  = $request->last_name;
        $user->email      = $request->email;
        $user->password   = Hash::make($request->password);
        $user->type       = $request->type;
        $user->save();

        return back()->with('success' , trans('messages.add'));
    }

    public function edit(User $user)
    {
        return view('Dashboard.user.edit' , compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'type'         => 'required|string',
        ]);

        $user->type = $request->type;

        $user->save();

        return redirect(route('user.index'))->with('success' , trans('messages.edit'));
    }

    // public function destroy(User $user)
    // {
    //     $user_count = $user->withCount('address')->findOrFail($user->id);
    //     if ($user_count->address_count > 0) {
    //         return back()->with('error' , trans('messages.undelete'));
    //     }

    //     $user->delete();

    //     return back()->with('success' , trans('messages.delete'));
    // }
}
