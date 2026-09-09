<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use App\Support\LegacyJwt;
use App\Support\Val;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * `POST add_address`, `GET me/addresses`, `DELETE me/addresses/{id}`, `GET me/orders`.
 *
 * `add_address` is deliberately NOT behind the auth middleware, because the legacy route is not:
 * it prefers the JWT subject when there is one and falls back to a `user_id` in the body, so a
 * guest can create the address their order will reference. The other three are authenticated and
 * scoped to the caller, which is what makes guessing an id useless.
 */
class AccountCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat) {}

    /** POST add_address */
    public function addAddress(Request $request): JsonResponse
    {
        try {
            $data = Validator::make($request->all(), [
                'user_id' => 'nullable|integer',
                'shipping_city_id' => 'required|integer|exists:shipping_cities,id',
                'address_line' => 'required|string|min:3|max:500',
                'phone_number_one' => 'required|string|min:7|max:20',
                'phone_number_two' => 'nullable|string|max:20',
            ])->validate();

            // Prefer the authenticated caller so a logged-in user's address is always tied to
            // them; guests fall back to the optional user_id in the payload.
            $userId = LegacyJwt::userId($request);
            if ($userId === null && $request->filled('user_id')) {
                $claimed = Val::nint($data, 'user_id');
                $userId = $claimed !== null && $claimed > 0 ? $claimed : null;
            }

            // `phone_number_tow` is a real typo in the legacy body handling, still accepted as an
            // alias by the running app; the frontend has sent both spellings at different times.
            $phoneTwo = $data['phone_number_two'] ?? $request->input('phone_number_tow');

            $id = $this->compat->account->createAddress(
                $userId,
                null,
                Val::int($data, 'shipping_city_id'),
                trim(Val::str($data, 'address_line')),
                trim(Val::str($data, 'phone_number_one')),
                is_string($phoneTwo) ? $phoneTwo : null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Address added successfully',
                'id' => $id,
                'address_id' => $id,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** GET me/addresses */
    public function addresses(Request $request): JsonResponse
    {
        try {
            return response()->json($this->compat->account->addresses(CompatAuth::id($request), app()->getLocale()));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** DELETE me/addresses/{id} */
    public function deleteAddress(Request $request, string $id): JsonResponse
    {
        try {
            if (! $this->compat->account->deleteAddress(CompatAuth::id($request), (int) $id)) {
                return response()->json(['message' => 'Address not found'], 404);
            }

            return response()->json(['message' => 'Address deleted'], 200);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** GET me/orders */
    public function orders(Request $request): JsonResponse
    {
        try {
            return response()->json($this->compat->account->orders(CompatAuth::id($request), app()->getLocale()));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function serverError(Throwable $e): JsonResponse
    {
        Log::error($e);

        return response()->json(['success' => false, 'message' => 'An error occurred', 'ref' => (string) Str::uuid()], 500);
    }
}
