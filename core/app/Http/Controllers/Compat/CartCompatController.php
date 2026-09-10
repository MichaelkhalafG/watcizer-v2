<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CartIdentity;
use App\Compat\CompatCart;
use App\Compat\CompatServices;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use App\Http\Middleware\CompatGuestCart;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The six legacy cart paths (CLEAN_CORE_STUDY §3.3 cart row), served from the shared commerce
 * tables with every catalog read on the clean tables.
 *
 * Error posture is copied, not improved. `AddToCart` catches `\Exception`, and a Laravel
 * `ValidationException` IS one, so a malformed add answers **500** with a random `ref` rather
 * than 422 — while `RemoveFromCart` catches `ValidationException` first and answers 422. Both are
 * reproduced exactly; the harness's D-19 rule absorbs the `ref` UUID, which cannot match across
 * two hosts by construction.
 */
class CartCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat) {}

    /** POST add_to_cart — replaces the line's quantity (the merge path is the only one that adds). */
    public function add(Request $request): JsonResponse
    {
        try {
            $data = Validator::make($request->all(), [
                'product_id' => ['nullable', 'integer', Rule::exists('catalog_products', 'id')->whereNull('deleted_at')],
                'offer_id' => 'nullable|integer|exists:offers,id',
                'quantity' => 'required|integer|min:1',
                'piece_price' => 'required|numeric|min:0',
                'total_price' => 'required|numeric|min:0',
                'type_stock' => 'nullable|in:Express,Market',
                'color_band' => 'nullable|string|max:7',
                'color_dial' => 'nullable|string|max:7',
            ])->validate();

            $productId = Val::nint($data, 'product_id');
            $offerId = Val::nint($data, 'offer_id');
            $quantity = Val::int($data, 'quantity');
            $typeStock = Val::nstr($data, 'type_stock');

            $catalog = $this->compat->cart->catalog($productId !== null ? [$productId] : [], $offerId !== null ? [$offerId] : []);

            if ($productId !== null) {
                $product = $catalog['products'][$productId] ?? null;
                if ($product !== null && $product['has_variants']) {
                    // Wave 3.5 invariant: the legacy frontend cannot choose a size, so a product
                    // that sells through variants is not addable here at all. Unreachable on
                    // Watchizer (no storefront-1 product has variants) and a loud refusal rather
                    // than a product-level decrement that no variant backs.
                    return response()->json(['success' => false, 'message' => 'This product requires selecting an option'], 422);
                }
                if ($product !== null) {
                    $available = $typeStock === 'Express' ? $product['express'] : $product['market'];
                    if ($quantity > $available) {
                        return response()->json(['success' => false, 'message' => 'Requested quantity exceeds available stock'], 422);
                    }
                }
            }
            if ($offerId !== null) {
                $offer = $catalog['offers'][$offerId] ?? null;
                if ($offer !== null && $quantity > $offer['stock']) {
                    return response()->json(['success' => false, 'message' => 'Requested quantity exceeds available offer stock'], 422);
                }
            }

            $cart = $this->compat->cart->resolve($this->identity($request));
            $this->compat->cart->upsertItem(Row::int($cart, 'id'), [
                'product_id' => $productId,
                'variant_id' => null,          // the compat layer never sets one (wave 3.5)
                'offer_id' => $offerId,
                'quantity' => $quantity,
                'piece_price' => Val::str($data, 'piece_price'),
                'total_price' => Val::str($data, 'total_price'),
                'type_stock' => $typeStock,
                'color_band' => Val::nstr($data, 'color_band'),
                'color_dial' => Val::nstr($data, 'color_dial'),
            ]);

            return response()->json(['success' => true, 'message' => 'Cart updated successfully'], 200);
        } catch (Throwable $e) {
            // Legacy catches \Exception here — a ValidationException included — so an invalid body
            // is a 500 with a ref, not a 422. Copied, not corrected.
            return $this->serverError($e);
        }
    }

    /** GET me/cart */
    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json($this->compat->cart->payload($this->compat->cart->find($this->identity($request))));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** DELETE delete_cart/{id} — one line, scoped to the caller's cart. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            if (! $this->compat->cart->deleteItem($this->identity($request), (int) $id)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * POST remove_from_cart — delete every line for one product (or offer). Idempotent: removing
     * something that is already gone is still a success.
     */
    public function remove(Request $request): JsonResponse
    {
        try {
            $data = Validator::make($request->all(), [
                'product_id' => 'nullable|integer',
                'offer_id' => 'nullable|integer',
            ])->validate();

            $productId = $request->filled('product_id') ? Val::nint($data, 'product_id') : null;
            $offerId = $request->filled('offer_id') ? Val::nint($data, 'offer_id') : null;
            if ($productId === null && $offerId === null) {
                return response()->json(['success' => false, 'message' => 'product_id or offer_id required'], 422);
            }

            $this->compat->cart->removeLine($this->identity($request), $productId, $offerId);

            return response()->json(['success' => true], 200);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** POST cart/validate — stock and price checks plus server-side totals. */
    public function validateCart(Request $request): JsonResponse
    {
        try {
            $cart = $this->compat->cart->find($this->identity($request));
            $items = $cart === null ? null : $this->compat->cart->items(Row::int($cart, 'id'));

            if ($items === null || $items->isEmpty()) {
                return response()->json(['valid' => true, 'warnings' => [], 'totals' => $this->compat->cart->emptyTotals()]);
            }
            $warnings = $this->compat->cart->warnings($items);

            return response()->json([
                'valid' => $warnings === [],
                'warnings' => $warnings,
                'totals' => $this->compat->cart->totals($items),
            ]);
        } catch (Throwable $e) {
            Log::error($e);

            return response()->json(['valid' => false, 'message' => 'An error occurred', 'ref' => (string) Str::uuid()], 500);
        }
    }

    /** POST cart/merge — fold a guest cart into the authenticated user's. */
    public function merge(Request $request): JsonResponse
    {
        try {
            $userId = CompatAuth::id($request);

            $guestToken = $request->input('guest_token');
            $guestToken = is_string($guestToken) && $guestToken !== '' ? $guestToken : $request->header(CompatGuestCart::HEADER);
            if (! is_string($guestToken) || $guestToken === '') {
                return response()->json(['message' => 'No guest cart to merge']);
            }

            if (! DB::table('carts')->where('guest_token', $guestToken)->exists()) {
                return response()->json(['message' => 'Guest cart not found']);
            }

            $this->compat->cart->merge($userId, $guestToken);

            return response()->json([
                'message' => 'Cart merged successfully',
                'cart' => $this->compat->cart->payload($this->compat->cart->find(CartIdentity::user($userId))),
            ]);
        } catch (Throwable $e) {
            Log::error($e);

            return response()->json(['error' => 'An error occurred', 'ref' => (string) Str::uuid()], 500);
        }
    }

    private function identity(Request $request): CartIdentity
    {
        $identity = $request->attributes->get(CompatGuestCart::ATTRIBUTE);
        if (! $identity instanceof CartIdentity) {
            throw new \RuntimeException('CompatGuestCart middleware did not run on a cart route.');
        }

        return $identity;
    }

    private function serverError(Throwable $e): JsonResponse
    {
        Log::error($e);

        return response()->json(['success' => false, 'message' => 'An error occurred', 'ref' => (string) Str::uuid()], 500);
    }

    /** Keeps PHPStan aware that the cart builder is the one this controller uses. */
    protected function cart(): CompatCart
    {
        return $this->compat->cart;
    }
}
