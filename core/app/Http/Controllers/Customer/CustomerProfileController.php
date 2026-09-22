<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerPayload;
use App\Domain\Customers\CustomerTokens;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * `updateProfile`, `updatePassword`, `DELETE me/avatar` (Phase 1, piece 3, 2026-09-21).
 *
 * ── The caller is the TOKEN, never the payload ──────────────────────────────────────────────
 *
 * All three routes sit behind `compat.auth` and resolve the subject from the verified JWT. The
 * legacy app reached this shape the hard way: these actions used `User::find($request->id)`, so any
 * client could name somebody else's account, and the fix — *"scoped strictly to the authenticated
 * caller … (IDOR)"* — is still in the comments over there. Reproducing the behaviour means
 * reproducing the FIXED behaviour, and nothing here reads an id from the request.
 *
 * ── The error format is the legacy one, and it is not Laravel's ─────────────────────────────
 *
 * These two endpoints answered validation failures as **`{"error": {field: [messages]}}`** with a
 * hand-rolled `Validator`, not as Laravel's `{message, errors}` — a difference that exists because
 * they were written by hand rather than with `$request->validate()`. The account screen keys off
 * the STATUS rather than the body, so this could be tidied; it is not, because "the storefront is
 * unchanged" is a property worth more than consistency this phase, and a shape nobody has to think
 * about is one nobody has to debug at 2 a.m. on cutover night.
 */
final class CustomerProfileController extends Controller
{
    public function __construct(
        private readonly CustomerAccounts $accounts,
        private readonly CustomerTokens $tokens,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            // Looser than registration's `01xxxxxxxxx` on purpose: the legacy rule here is what it
            // is, and tightening it would refuse a number a customer already has saved.
            'phone_number' => 'nullable|string|max:20',
        ];

        /*
         * `image` is validated ONLY when a file actually arrived — the legacy fix, kept.
         *
         * The rule used to run unconditionally, so the SPA posting the existing avatar URL as a
         * string failed with 422 on every name-or-phone-only edit. That is the sort of bug that
         * comes back the moment somebody "simplifies" this method.
         */
        if ($request->hasFile('image')) {
            $rules['image'] = 'image|mimes:jpeg,png,jpg,webp|max:5120';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user = $this->accounts->updateProfile(
            $this->caller($request),
            [
                'first_name' => Coerce::str($request->input('first_name')),
                'last_name' => Coerce::str($request->input('last_name')),
                'phone_number' => Coerce::nstr($request->input('phone_number')),
            ],
            $request->file('image') instanceof UploadedFile ? $request->file('image') : null,
        );

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => CustomerPayload::of($user),
        ]);
    }

    public function password(Request $request): JsonResponse
    {
        $user = $this->caller($request);

        /*
         * A social-login account starts password-less and SETS one here without supplying a current
         * one — the only route it has to ever having a password. Everybody else must confirm
         * theirs. The rule set changes accordingly, exactly as the legacy controller's did, and the
         * real check lives in the writer so a caller cannot arrive by another road.
         */
        $hasPassword = Coerce::nstr($user->getAttribute('password')) !== null;

        $rules = ['new_password' => 'required|min:'.CustomerAccounts::MIN_PASSWORD.'|confirmed'];
        if ($hasPassword) {
            $rules['current_password'] = 'required';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $this->accounts->changePassword(
                $user,
                Coerce::nstr($request->input('current_password')),
                Coerce::str($request->input('new_password')),
            );
        } catch (ValidationException $e) {
            /*
             * 401, not 422, when the current password is wrong — the status the account screen
             * turns into "Current password is incorrect". Any other refusal from the writer is a
             * validation failure and keeps the 422 shape.
             */
            if (array_key_exists('current_password', $e->errors())) {
                return response()->json(['error' => 'The current password is incorrect.'], 401);
            }

            return response()->json(['error' => $e->errors()], 422);
        }

        /*
         * A FRESH token, because the change just invalidated every token this customer holds —
         * including the one that made this request (M1v, "log out everywhere").
         *
         * Additive to the legacy `{message}` shape, so nothing breaks today: the storefront ignores
         * the extra key, its next request 401s on the dead token, and `api.jsx`'s interceptor signs
         * the customer out. That is a working outcome and the honest one — they changed their
         * password and have to sign in again. When the storefront is touched (Phase 4) it reads
         * this key and the session simply continues, with no backend change needed then.
         */
        return response()->json([
            'message' => 'Password updated successfully',
            'token' => $this->tokens->issue($user->refresh()),
        ]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $this->accounts->removeAvatar($this->caller($request));

        return response()->json([
            'message' => 'Avatar removed',
            'user' => CustomerPayload::of($user),
        ]);
    }

    private function caller(Request $request): User
    {
        return User::query()->findOrFail(CompatAuth::id($request));
    }
}
