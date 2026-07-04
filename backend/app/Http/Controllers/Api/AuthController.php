<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Events\PasswordReset;
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

        } catch (ValidationException $e) {
            // Let Laravel render the proper 422 with field errors.
            throw $e;
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

            // Rate limit: max 5 attempts per minute, keyed by email + IP.
            $throttleKey = Str::lower($request->input('email')) . '|' . $request->ip();
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                return response()->json([
                    'error' => "Too many login attempts. Please try again in {$seconds} seconds.",
                ], 429);
            }

            $data_request = $request->only(['email' , 'password']);

            if (! $token = Auth::guard('api')->attempt($data_request)) {
                RateLimiter::hit($throttleKey, 60);
                return response()->json(['error' => 'Invalid email or password.'], 401);
            }

            RateLimiter::clear($throttleKey);

            $user = Auth::guard('api')->user();
            $user->token = $token;

            // Track activity for the re-engagement campaign (non-fatal).
            try {
                $user->forceFill(['last_login_at' => now()])->save();
            } catch (\Throwable $e) {
                Log::warning('last_login_at update failed: ' . $e->getMessage());
            }

            // Merge any guest cart into the now-authenticated user's cart.
            // (register() delegates to login(), so this covers both flows.)
            $guestToken = request()->header('X-Guest-Token');
            if ($guestToken) {
                (new \App\Services\MergeGuestCart())->merge($user->id, $guestToken);
            }

            return response()->json($user);

        } catch (ValidationException $e) {
            // Let Laravel render the proper 422 with field errors.
            throw $e;
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
                // Fire the email-verification notification (non-fatal if mail fails).
                try {
                    $user->sendEmailVerificationNotification();
                } catch (\Exception $mailError) {
                    Log::warning('Verification email failed to send: ' . $mailError->getMessage());
                }

                return $this->login($request);
            }


            return response()->json(['success' => true, 'message' => 'something wrong'], 500);

        } catch (ValidationException $e) {
            // Let Laravel render the proper 422 with field errors.
            throw $e;
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
        // Scope strictly to the authenticated caller. Previously this used
        // User::find($request->id), letting any client target an arbitrary user
        // (IDOR). The route now sits behind auth:api and we resolve the JWT user.
        $user = auth('api')->user() ?? $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Social-auth accounts start password-less: they SET a password here
        // without supplying a current one. Everyone else must confirm theirs.
        $hasPassword = !empty($user->password);

        $rules = ['new_password' => 'required|min:8|confirmed'];
        if ($hasPassword) {
            $rules['current_password'] = 'required';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if ($hasPassword && !Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'The current password is incorrect.'], 401);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully'], 200);
    }

    public function updateProfile(Request $request)
    {
        // Authenticated caller only (was User::find($request->id) → IDOR).
        $user = auth('api')->user() ?? $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $rules = [
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
        ];
        // Only validate `image` when a file is actually uploaded. The old rule
        // ran unconditionally, so the SPA posting the existing avatar URL string
        // failed with 422 on every name/phone-only edit.
        if ($request->hasFile('image')) {
            $rules['image'] = 'image|mimes:jpeg,png,jpg,webp|max:5120';
        }

        $validator = Validator::make($request->all(), $rules);
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

        // Return the fresh user so the SPA can sync its auth store without a
        // second round-trip to the (now-removed) public /all_user endpoint.
        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $user->fresh(),
        ], 200);
    }

    /**
     * Remove the authenticated user's avatar (deletes the file + clears column).
     */
    public function removeAvatar(Request $request)
    {
        $user = auth('api')->user() ?? $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($user->image) {
            $path = public_path('Uploads_Images/User/' . $user->image);
            if (is_file($path)) {
                @unlink($path);
            }
            $user->image = null;
            $user->save();
        }

        return response()->json([
            'message' => 'Avatar removed',
            'user'    => $user->fresh(),
        ], 200);
    }

    /**
     * Authenticated user (JWT). Protected by the auth:api guard.
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Send a password reset link. Always returns success so the response never
     * reveals whether an email is registered (enumeration protection).
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email'], [
            'email.required' => 'Please enter your email address.',
            'email.email'    => 'Please enter a valid email address.',
        ]);

        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Exception $e) {
            Log::warning('Password reset link failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ], 200);
    }

    /**
     * Complete a password reset using the emailed token.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset.'], 200);
        }

        return response()->json(['error' => __($status)], 422);
    }

    /**
     * Verify a user's email via the signed link (id + sha1(email) hash).
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json(['error' => 'Invalid or expired verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $user->markEmailAsVerified();

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }

    /**
     * Resend the verification email to the authenticated user (1/min).
     */
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $key = 'resend-verification|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'error' => "Please wait {$seconds} seconds before requesting another email.",
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.'], 200);
    }
}
