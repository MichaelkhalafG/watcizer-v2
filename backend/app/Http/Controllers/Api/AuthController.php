<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Drivers\Gd\Driver;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    public function AllUser()
    {
        try {
            $user = User::all();
            return response()->json($user);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|string|email',
                'password' => 'required|string',
            ]);

            $data_request = $request->only(['email' , 'password']);

            if (! $token = Auth::guard('api')->attempt($data_request)) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $user = Auth::guard('api')->user();
            $user->token = $token;

            // Merge any guest cart into the now-authenticated user's cart.
            // (register() delegates to login(), so this covers both flows.)
            $guestToken = request()->header('X-Guest-Token');
            if ($guestToken) {
                (new \App\Services\MergeGuestCart())->merge($user->id, $guestToken);
            }

            return response()->json($user);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'first_name'   => ['required', 'string', 'max:255'],
                'last_name'    => ['required', 'string', 'max:255'],
                'email'        => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'password'     => ['required', 'confirmed', Rules\Password::defaults()],
                'phone_number' => ['nullable', 'regex:/^01[0-9]{9}$/' , 'size:11'],
                'image'        => ['nullable' , 'image' , 'mimes:png,jpg,webp,gif' , 'max:3072'],
            ]);

            $user = new User;
            $user->first_name   = $request->first_name;
            $user->last_name    = $request->last_name;
            $user->email        = $request->email;
            $user->password     = Hash::make($request->password);
            $user->phone_number = $request->phone_number;

            if ($request->hasFile('image'))
            {
                $image   = $request->file('image');
                $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
                $manager = new ImageManager(new Driver());
                $img     = $manager->read($image);
                $img->toWebp()->save(public_path('/Uploads_Images/User/' . $NewName));

                $user->image        = $NewName;
            }

            $user->save();

            if ($user) {
                return $this->login($request);
            }


            return response()->json(['success' => true, 'message' => 'something wrong'], 500);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->validate([
                'token'   => ['required', 'string'],
            ]);
            $user = JWTAuth::parseToken()->authenticate();

            JWTAuth::invalidate($request->token);

            return response()->json(['success' => true, 'message' => 'Logout successful'], 200);

        } catch (JWTException $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function updatePassword(Request $request)
    {
        $user = User::find($request->id);

        $validator = Validator::make($request->all(), [
            'id'               => 'required',
            'current_password' => 'required',
            'new_password'     => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'The current password is incorrect.'], 401);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully'], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = User::find($request->id);

        $validator = Validator::make($request->all(), [
            'id'           => 'required',
            'first_name'   => 'nullable|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user->first_name   = $request->first_name;
        $user->last_name    = $request->last_name;
        $user->phone_number = $request->phone_number;

        if ($request->hasFile('image'))
        {
            $image   = $request->file('image');
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/User/' . $NewName));

            $user->image        = $NewName;
        }

        $user->save();

        return response()->json(['message' => 'Profile updated successfully'], 200);
    }
}
