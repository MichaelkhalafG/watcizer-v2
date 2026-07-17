<?php

namespace App\Http\Controllers\Api;

use App\Models\Cart;
use App\Models\User;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Address;
use App\Models\Product;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\ShippingCity;
use App\Services\MergeGuestCart;
use Illuminate\Http\Request;
use App\Models\PaymentStatus;
use App\Mail\OrderConfirmation;
use App\Mail\AdminOrderNotification;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    // =========================================================================
    //  Shipping Cities
    // =========================================================================
    public function ShowShippingCity()
    {
        try {
            $shippingCities = Cache::remember('ShowShippingCity', now()->addMinutes(10), function () {
                return ShippingCity::with('translations')->get();
            });
            return response()->json($shippingCities);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    // =========================================================================
    //  Address
    // =========================================================================
    public function AddAddress(Request $request)
    {
        try {
            $request->validate([
                'user_id'          => 'nullable|integer',
                'shipping_city_id' => 'required|integer|exists:shipping_cities,id',
                'address_line'     => 'required|string|min:3|max:500',
                'phone_number_one' => 'required|string|min:7|max:20',
                'phone_number_two' => 'nullable|string|max:20',
            ]);

            // Prefer the authenticated caller so a logged-in user's address is
            // always tied to them (and can't be spoofed onto another user_id).
            // Guests (no JWT) fall back to the optional user_id in the payload.
            $userId = auth('api')->id();
            if (!$userId && $request->filled('user_id') && (int) $request->user_id > 0) {
                $userId = (int) $request->user_id;
            }

            $address = Address::create([
                'user_id'          => $userId,
                'shipping_city_id' => (int) $request->shipping_city_id,
                'address_line'     => trim($request->address_line),
                'phone_number_one' => trim($request->phone_number_one),
                'phone_number_two' => $request->phone_number_two ?? $request->phone_number_tow ?? null,
            ]);

            Cache::forget('ShowAddress');

            return response()->json([
                'success'    => true,
                'message'    => 'Address added successfully',
                'id'         => $address->id,
                'address_id' => $address->id,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function ShowAddress()
    {
        try {
            $authId = auth('api')->id();
            $addresses = Address::where('user_id', $authId)
                ->with('shippingCity.translations')
                ->get();
            return response()->json($addresses);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    /**
     * Delete one of the authenticated user's addresses. Scoped by user_id so a
     * caller can never delete another user's address by guessing an id.
     */
    public function DeleteAddress(Request $request, $id)
    {
        try {
            $authId = auth('api')->id();
            $address = Address::where('user_id', $authId)->findOrFail($id);
            $address->delete();
            Cache::forget('ShowAddress');
            return response()->json(['message' => 'Address deleted'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Address not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    // =========================================================================
    //  Cart
    // =========================================================================
    /**
     * Resolve the caller's cart from the identity set by GuestCartMiddleware
     * (['user_id' => …] for logged-in users, ['guest_token' => …] for guests).
     * Guest carts expire after 7 days; user carts never expire.
     */
    private function resolveCart(array $identity): Cart
    {
        $defaults = isset($identity['user_id'])
            ? ['expires_at' => null]
            : ['expires_at' => now()->addDays(7)];

        return Cart::firstOrCreate($identity, $defaults);
    }

    public function AddToCart(Request $request)
    {
        try {
            $request->validate([
                // identity (user_id / guest_token) comes from GuestCartMiddleware
                'product_id'  => 'nullable|integer|exists:products,id',
                'offer_id'    => 'nullable|integer|exists:offers,id',
                'quantity'    => 'required|integer|min:1',
                'piece_price' => 'required|numeric|min:0',
                'total_price' => 'required|numeric|min:0',
                'type_stock'  => 'nullable|in:Express,Market',
                'color_band'  => 'nullable|string|max:7',
                'color_dial'  => 'nullable|string|max:7',
            ]);

            $product = $request->product_id ? Product::find($request->product_id) : null;
            $offer   = $request->offer_id   ? Offer::find($request->offer_id)     : null;

            if ($product) {
                $field = $request->type_stock === 'Express' ? 'stock' : 'market_stock';
                if ($request->quantity > $product->{$field}) {
                    return response()->json(['success' => false, 'message' => 'Requested quantity exceeds available stock'], 422);
                }
            }
            if ($offer && $request->quantity > $offer->stock) {
                return response()->json(['success' => false, 'message' => 'Requested quantity exceeds available offer stock'], 422);
            }

            $cart = $this->resolveCart($request->identity);
            $cart->cart_item()->updateOrCreate(
                [
                    'product_id' => $request->product_id,
                    'offer_id'   => $request->offer_id,
                    'color_band' => $request->color_band,
                    'color_dial' => $request->color_dial,
                    'type_stock' => $request->type_stock,
                ],
                [
                    'quantity'    => $request->quantity,
                    'piece_price' => $request->piece_price,
                    'total_price' => $request->total_price,
                ]
            );

            return response()->json(['success' => true, 'message' => 'Cart updated successfully'], 200);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function ShowCart(Request $request)
    {
        try {
            $cart = Cart::where($request->identity)
                        ->with('cart_item')
                        ->first();
            // Keep the cart_item shape the frontend relies on, plus server-side
            // totals and live stock/price warnings.
            return response()->json($this->cartPayload($cart));
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function DeleteCart(Request $request, $id)
    {
        try {
            // Owner comes from GuestCartMiddleware: ['user_id' => …] for logged-in
            // users, ['guest_token' => …] for guests. Plain auth()->id() used the
            // web guard and returned null for the JWT SPA (and never matched guests).
            $identity = $request->input('identity');
            if (empty($identity) || !is_array($identity)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            CartItem::whereHas('cart', fn($q) => $q->where($identity))
                ->findOrFail($id)
                ->delete();
            return response()->json(['success' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    // =========================================================================
    //  Cart validation / merge / totals
    // =========================================================================

    /**
     * Validate the cart against the live catalog at checkout time: flags
     * out-of-stock lines and price changes, and returns server-side totals.
     */
    public function validateCart(Request $request)
    {
        try {
            $cart = Cart::where($request->identity)->with('cart_item')->first();

            if (!$cart || $cart->cart_item->isEmpty()) {
                return response()->json([
                    'valid'    => true,
                    'warnings' => [],
                    'totals'   => $this->emptyTotals(),
                ]);
            }

            $warnings = $this->cartWarnings($cart);

            return response()->json([
                'valid'    => empty($warnings),
                'warnings' => $warnings,
                'totals'   => $this->calculateTotals($cart),
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'valid'   => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid(),
            ], 500);
        }
    }

    /**
     * Merge a guest cart into the authenticated user's cart. Delegates to the
     * same MergeGuestCart service used on login (increments quantities), so the
     * behaviour is identical across both entry points.
     */
    public function mergeCart(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Must be authenticated'], 401);
            }

            $guestToken = $request->input('guest_token') ?: $request->header('X-Guest-Token');
            if (!$guestToken) {
                return response()->json(['message' => 'No guest cart to merge']);
            }

            $guestCart = Cart::where('guest_token', $guestToken)->first();
            if (!$guestCart) {
                return response()->json(['message' => 'Guest cart not found']);
            }

            (new MergeGuestCart())->merge((int) $user->id, $guestToken);

            $userCart = Cart::where('user_id', $user->id)->with('cart_item')->first();

            return response()->json([
                'message' => 'Cart merged successfully',
                'cart'    => $this->cartPayload($userCart),
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'error' => 'An error occurred',
                'ref'   => \Illuminate\Support\Str::uuid(),
            ], 500);
        }
    }

    /**
     * Cart JSON for the frontend: the raw cart (incl. cart_item) plus totals and
     * live warnings. Returns a safe empty shape when there is no cart yet.
     */
    private function cartPayload(?Cart $cart): array
    {
        if (!$cart) {
            return ['cart_item' => [], 'totals' => $this->emptyTotals(), 'warnings' => []];
        }
        $cart->loadMissing('cart_item');
        return array_merge($cart->toArray(), [
            'totals'   => $this->calculateTotals($cart),
            'warnings' => $this->cartWarnings($cart),
        ]);
    }

    /**
     * Build id→model maps for the products/offers referenced by the cart's
     * items (CartItem's eager relation is unusable, so we resolve manually).
     */
    private function cartCatalog($items): array
    {
        $products = Product::whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->get()->keyBy('id');
        $offers = Offer::whereIn('id', $items->pluck('offer_id')->filter()->unique())
            ->get()->keyBy('id');
        return [$products, $offers];
    }

    /**
     * The price the storefront actually charges for a catalog entity (product or
     * offer): the sale price ONLY when it is a REAL discount (0 < sale < selling),
     * otherwise the selling price.
     *
     * This MUST mirror the frontend exactly (ProductCard / ProductDetailClient /
     * Checkout all use `sale > 0 && sale < selling ? sale : selling`). The old
     * server rule (`sale > 0 ? sale : selling`) diverged whenever
     * sale_price_after_discount was ≥ selling_price (e.g. set equal, or a bad
     * data entry), so the server total didn't match the client's and every
     * checkout was rejected with "Order total mismatch". Kept in one place so
     * AddOrder pricing and cartWarnings() can never drift apart again.
     */
    private function catalogPrice($entity): float
    {
        $selling = (float) $entity->selling_price;
        $sale    = (float) $entity->sale_price_after_discount;

        return round(($sale > 0 && $sale < $selling) ? $sale : $selling, 2);
    }

    /**
     * Per-line stock / price warnings against the current catalog.
     */
    private function cartWarnings(Cart $cart): array
    {
        $items = $cart->cart_item;
        [$products, $offers] = $this->cartCatalog($items);
        $warnings = [];

        foreach ($items as $item) {
            $message = null;

            if ($item->product_id) {
                $product = $products->get($item->product_id);
                if (!$product) {
                    $message = 'Product no longer available';
                } else {
                    $currentStock = $item->type_stock === 'Express'
                        ? (int) $product->stock
                        : (int) $product->market_stock;
                    $currentPrice = $this->catalogPrice($product);

                    if ($currentStock < $item->quantity) {
                        $message = "Only {$currentStock} available";
                    } elseif (abs($currentPrice - (float) $item->piece_price) > 0.01) {
                        $message = "Price changed to {$currentPrice}";
                    }
                }
            } elseif ($item->offer_id) {
                $offer = $offers->get($item->offer_id);
                if (!$offer) {
                    $message = 'Offer no longer available';
                } else {
                    $currentPrice = $this->catalogPrice($offer);

                    if ((int) $offer->stock < $item->quantity) {
                        $message = "Only {$offer->stock} available";
                    } elseif (abs($currentPrice - (float) $item->piece_price) > 0.01) {
                        $message = "Price changed to {$currentPrice}";
                    }
                }
            }

            if ($message) {
                $warnings[] = ['item_id' => $item->id, 'message' => $message];
            }
        }

        return $warnings;
    }

    /**
     * Server-side totals: subtotal, shipping, savings. There is NO free-shipping
     * rule — shipping is always the selected governorate's cost, but that is only
     * known once an address is chosen at checkout, so it is 0 here and added in
     * AddOrder (which uses the address's shipping_cost).
     */
    private function calculateTotals(Cart $cart): array
    {
        $items = $cart->cart_item;
        $subtotal = $items->sum(fn ($item) => $item->quantity * (float) $item->piece_price);

        return [
            'subtotal'      => round($subtotal, 2),
            'shipping'      => 0,
            'shipping_note' => 'Shipping calculated at checkout by governorate',
            'tax'           => 0,
            'total'         => round($subtotal, 2),
            'item_count'    => (int) $items->sum('quantity'),
            'savings'       => $this->calculateSavings($items),
        ];
    }

    /**
     * Total saved vs. original prices across product + offer lines.
     */
    private function calculateSavings($items): float
    {
        [$products, $offers] = $this->cartCatalog($items);
        $savings = 0;

        foreach ($items as $item) {
            $entity = $item->product_id
                ? $products->get($item->product_id)
                : $offers->get($item->offer_id);

            if ($entity) {
                $original = (float) $entity->selling_price;
                $sale     = (float) $entity->sale_price_after_discount;
                if ($sale > 0 && $sale < $original) {
                    $savings += ($original - $sale) * $item->quantity;
                }
            }
        }

        return round($savings, 2);
    }

    private function emptyTotals(): array
    {
        return [
            'subtotal'      => 0,
            'shipping'      => 0,
            'shipping_note' => 'Shipping calculated at checkout by governorate',
            'tax'           => 0,
            'total'         => 0,
            'item_count'    => 0,
            'savings'       => 0,
        ];
    }

    // =========================================================================
    //  Order
    // =========================================================================
    public function AddOrder(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'user_id'               => 'nullable|integer',
                // Either an existing address_id OR inline address fields (guests).
                'address_id'            => 'required_without:address_line|nullable|integer|exists:addresses,id',
                'address_line'          => 'required_without:address_id|nullable|string|min:3|max:500',
                'shipping_city_id'      => 'required_without:address_id|nullable|integer|exists:shipping_cities,id',
                'phone'                 => 'required_without:address_id|nullable|string|min:7|max:20',
                'total_price_for_order' => 'required|numeric|min:0',
                // 'card' is the user-facing label for the Paymob (card) flow.
                'payment_method'        => 'required|in:cash,card,paymob,whatsapp',
                'note'                  => 'nullable|string|max:1000',
                'guest_name'            => 'nullable|string|max:255',
                'guest_phone'           => 'nullable|string|max:20',
                'guest_email'           => 'nullable|email|max:255',
                'items'                 => 'nullable|array',
                'items.*.product_id'    => 'nullable|integer|exists:products,id',
                'items.*.offer_id'      => 'nullable|integer|exists:offers,id',
                'items.*.quantity'      => 'required_with:items|integer|min:1',
                'items.*.piece_price'   => 'required_with:items|numeric|min:0',
                'items.*.total_price'   => 'required_with:items|numeric|min:0',
                'items.*.type_stock'    => 'nullable|in:Express,Market',
                'items.*.color_band'    => 'nullable|string',
                'items.*.color_dial'    => 'nullable|string',
            ]);

            $userId  = ($request->filled('user_id') && (int) $request->user_id > 0)
                ? (int) $request->user_id
                : null;
            $isGuest = is_null($userId);

            // Map the user-facing 'card' option to the internal Paymob flow.
            $paymentMethod = $request->payment_method === 'card'
                ? 'paymob'
                : $request->payment_method;

            // ── Resolve / create shipping address ─────────────────────────────
            // Logged-in users pass an existing address_id; guests pass inline
            // fields, which we persist (tied to their guest token) so the order
            // and the Paymob billing data have a real address row to reference.
            if (!$request->filled('address_id')) {
                $guestToken = $request->identity['guest_token'] ?? $request->header('X-Guest-Token');
                $address = Address::create([
                    'user_id'          => $userId,
                    'guest_token'      => $userId ? null : $guestToken,
                    'shipping_city_id' => (int) $request->shipping_city_id,
                    'address_line'     => trim($request->address_line),
                    'phone_number_one' => trim($request->phone ?? $request->guest_phone ?? ''),
                ]);
                // Downstream (totals, order row, Paymob) all read $request->address_id.
                $request->merge(['address_id' => $address->id]);
            }

            // ── Cart items ────────────────────────────────────────────────────
            if (!$isGuest) {
                $cart = Cart::where('user_id', $userId)->with('cart_item')->first();
                if ($cart && $cart->cart_item->isNotEmpty()) {
                    // Primary: server-side DB cart
                    $cartItems = $cart->cart_item;
                } elseif (!empty($request->input('items'))) {
                    // Fallback: client-supplied items[] (session cart)
                    $cartItems = collect($request->input('items'));
                } else {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Cart is empty'], 422);
                }
            } else {
                $cartItems = collect($request->input('items', []));
                if ($cartItems->isEmpty()) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Cart is empty'], 422);
                }
            }

            // ── Authoritative per-line pricing (never trust the client) ───────
            // Recompute EACH line from DB catalog prices and remember the result
            // keyed by the cart-item's position, so the OrderItem rows below store
            // SERVER prices — the client's piece_price / total_price are ignored.
            // Money columns are DECIMAL(12,2); round to 2dp so .99 / .50 survive.
            $serverLines      = [];
            $serverItemsTotal = 0.0;

            foreach ($cartItems as $idx => $item) {
                $i = is_array($item) ? (object) $item : $item;

                // Resolve the catalog entity for this line.
                if (!empty($i->product_id)) {
                    $entity = Product::find($i->product_id);
                } elseif (!empty($i->offer_id)) {
                    $entity = Offer::find($i->offer_id);
                } else {
                    $entity = null;
                }

                $qty = (int) ($i->quantity ?? 0);
                if (!$entity || $qty < 1) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'One of the items is no longer available.',
                    ], 422);
                }

                // Price each line the SAME way the storefront does (see catalogPrice()):
                // the sale price ONLY when it is a real discount (0 < sale < selling),
                // otherwise the selling price. Offers store selling_price /
                // sale_price_after_discount (no `price` column) — priced identically.
                $piece     = $this->catalogPrice($entity);
                $lineTotal = round($piece * $qty, 2);

                $serverLines[$idx] = ['piece_price' => $piece, 'total_price' => $lineTotal];
                $serverItemsTotal += $lineTotal;
            }

            $serverItemsTotal = round($serverItemsTotal, 2);

            $shippingCost = round((float) optional(
                optional(Address::with('shippingCity')->find($request->address_id))->shippingCity
            )->shipping_cost, 2);

            // Authoritative order total — computed entirely server-side from DB
            // prices + the address's shipping cost. This is what we STORE, so a
            // tampered client value can never change the charged amount.
            $serverTotal = round($serverItemsTotal + $shippingCost, 2);

            // Reject a tampered / stale client total (beyond a 1-cent rounding
            // epsilon). Prices now match exactly for a fresh cart; a real mismatch
            // means the client's totals are out of date and must be re-confirmed.
            $clientTotal = round((float) $request->input('total_price_for_order'), 2);
            if (abs($clientTotal - $serverTotal) > 0.01) {
                DB::rollBack();
                return response()->json([
                    'success'      => false,
                    'message'      => 'Order total mismatch — your cart may be out of date. Please review and try again.',
                    'server_total' => $serverTotal,
                ], 422);
            }

            // ── Order number ──────────────────────────────────────────────────
            $lastNumber = DB::table('orders')
                ->lockForUpdate()
                ->selectRaw('MAX(CAST(order_number AS UNSIGNED)) as m')
                ->value('m') ?? 0;

            $orderNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // ── Create order ──────────────────────────────────────────────────
            $order = Order::create([
                'user_id'               => $userId,
                'address_id'            => $request->address_id,
                'total_price_for_order' => $serverTotal,
                'payment_method'        => $paymentMethod,
                'order_number'          => $orderNumber,
                'note'                  => $request->note,
                'status'                => $paymentMethod === 'cash' ? 'processing' : 'pending',
                'guest_name'            => $request->guest_name  ?? null,
                'guest_email'           => $request->guest_email ?? null,
                'guest_phone'           => $request->guest_phone ?? $request->phone ?? null,
                'guest_token'           => $isGuest
                    ? ($request->identity['guest_token'] ?? $request->header('X-Guest-Token'))
                    : null,
            ]);

            // ── Order items + stock ───────────────────────────────────────────
            foreach ($cartItems as $idx => $item) {
                $itemData = is_array($item) ? (object) $item : $item;

                // Server-computed line prices from the pass above (same $cartItems,
                // same keys). Never store the client-sent piece_price / total_price.
                $line = $serverLines[$idx] ?? null;
                if ($line === null) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Pricing error',
                        'ref'     => \Illuminate\Support\Str::uuid(),
                    ], 500);
                }

                OrderItem::create([
                    'order_id'    => $order->id,
                    'product_id'  => $itemData->product_id ?? null,
                    'offer_id'    => $itemData->offer_id   ?? null,
                    'quantity'    => $itemData->quantity,
                    'piece_price' => $line['piece_price'],
                    'total_price' => $line['total_price'],
                    'type_stock'  => $itemData->type_stock  ?? null,
                    'color_band'  => $itemData->color_band  ?? null,
                    'color_dial'  => $itemData->color_dial  ?? null,
                ]);

                if (!empty($itemData->product_id)) {
                    // Atomic conditional decrement: only succeeds if enough stock
                    // remains, so concurrent orders cannot oversell (P1-7).
                    $field = ($itemData->type_stock ?? null) === 'Express' ? 'stock' : 'market_stock';

                    $updated = Product::where('id', $itemData->product_id)
                        ->where($field, '>=', $itemData->quantity)
                        ->decrement($field, $itemData->quantity);

                    if ($updated === 0) {
                        DB::rollBack();
                        return response()->json([
                            'success'    => false,
                            'message'    => 'Insufficient stock',
                            'product_id' => $itemData->product_id,
                        ], 422);
                    }
                } elseif (!empty($itemData->offer_id)) {
                    $offer = Offer::find($itemData->offer_id);
                    if ($offer) {
                        $offer->stock -= $itemData->quantity;
                        if ($offer->stock < 0) {
                            DB::rollBack();
                            return response()->json(['success' => false, 'message' => 'Insufficient offer stock'], 422);
                        }
                        $offer->save();
                    }
                }
            }

            // ── Clear DB cart for logged-in users ─────────────────────────────
            if (!$isGuest && isset($cart)) {
                $cart->cart_item()->delete();
                $cart->delete();
            }

            DB::commit();

            // ── Send Emails ───────────────────────────────────────────────────
            if (in_array($paymentMethod, ['cash', 'whatsapp'])) {
                $this->sendOrderEmails($order, $isGuest, $paymentMethod);
            }

            // ── Paymob ────────────────────────────────────────────────────────
            if ($paymentMethod === 'paymob') {
                return $this->handlePaymobPayment($order, $request, $isGuest);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Order placed successfully',
                'order_number' => $order->order_number,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    // =========================================================================
    //  Send Emails Helper
    // =========================================================================
    private function sendOrderEmails(Order $order, bool $isGuest, string $paymentMethod): void
    {
        // Emails send SYNCHRONOUSLY (QUEUE_CONNECTION=sync, mailables no longer
        // implement ShouldQueue) so the customer/admins are notified the instant
        // an order is placed — no worker/cron needed. Each send is wrapped so an
        // SMTP failure only logs and NEVER breaks the order response (this runs
        // after DB::commit(), so the order is already safely persisted).

        // ── Customer confirmation ─────────────────────────────────────────────
        // Only once payment is settled: COD orders, or a successful Paymob
        // payment (CallbackPayment calls this with 'cash'). Guest-safe.
        if ($paymentMethod === 'cash') {
            $customerEmail = !$isGuest
                ? optional($order->user)->email
                : $order->guest_email;

            if ($customerEmail) {
                try {
                    Mail::to($customerEmail)->send(new OrderConfirmation($order));
                    \Log::info('Customer confirmation sent to: ' . $customerEmail . ' | Order: ' . $order->order_number);
                } catch (\Throwable $e) {
                    \Log::error('Customer confirmation FAILED | Order: ' . $order->order_number, ['exception' => $e->getMessage()]);
                }
            }
        }

        // ── Admin notifications ───────────────────────────────────────────────
        // Recipients: config('watchizer.admin_emails') (the 4 admins, env-overridable).
        foreach (config('watchizer.admin_emails', []) as $email) {
            try {
                Mail::to($email)->send(new AdminOrderNotification($order));
                \Log::info('Admin notification sent to: ' . $email . ' | Order: ' . $order->order_number);
            } catch (\Throwable $e) {
                \Log::error('Admin notification FAILED | To: ' . $email . ' | Order: ' . $order->order_number, ['exception' => $e->getMessage()]);
            }
        }
    }

    // =========================================================================
    //  Paymob
    // =========================================================================
    private function handlePaymobPayment($order, $request, $isGuest)
    {
        try {
            $authToken = env('PAYMOB_SECRET_KEY');
            $publicKey = env('PAYMOB_PUBLIC_KEY');

            if (!$authToken || !$publicKey) {
                throw new \Exception('Paymob credentials not configured');
            }

            if (!$isGuest && $order->user) {
                $firstName = $order->user->first_name ?? 'Guest';
                $lastName  = $order->user->last_name  ?? 'User';
                $email     = $order->user->email      ?? 'guest@example.com';
            } else {
                $parts     = explode(' ', trim($request->guest_name ?? 'Guest User'), 2);
                $firstName = $parts[0] ?: 'Guest';
                $lastName  = $parts[1] ?? 'User';
                $email     = $request->guest_email ?? 'guest@example.com';
            }

            $address     = Address::with('shippingCity')->find($request->address_id);
            $streetLine  = $address->address_line     ?? 'N/A';
            $phoneNumber = $address->phone_number_one  ?? $request->guest_phone ?? '01000000000';

            $cityName = 'Cairo';
            if ($address && $address->shippingCity) {
                try {
                    $cityName = $address->shippingCity->translate('en')->city_name
                                ?? $address->shippingCity->city_name
                                ?? 'Cairo';
                } catch (\Exception $ex) {
                    $cityName = $address->shippingCity->city_name ?? 'Cairo';
                }
            }

            $paymobResponse = Http::withHeaders([
                'Authorization' => "Token $authToken",
                'Content-Type'  => 'application/json',
            ])->post('https://accept.paymob.com/v1/intention/', [
                'amount'          => $order->total_price_for_order * 100,
                'currency'        => 'EGP',
                'payment_methods' => [4988969, 4627487, 3961568],
                'billing_data'    => [
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'street'       => $streetLine,
                    'phone_number' => $phoneNumber,
                    'city'         => $cityName,
                    'country'      => 'Egypt',
                    'email'        => $email,
                ],
                // Paymob requires special_reference to be globally unique FOREVER —
                // reusing the raw order id collides ("An Order with ref: N already
                // exists") on any retry or id reuse. Suffix a nonce for uniqueness;
                // the numeric order-id prefix is preserved so the callback's
                // Order::find(merchant_order_id) still resolves it via int coercion.
                'special_reference' => $order->id . '-' . time(),
            ]);

            if ($paymobResponse->successful()) {
                return response()->json([
                    'success'      => true,
                    'order_number' => $order->order_number,
                    'redirect_url' => 'https://accept.paymob.com/unifiedcheckout/?publicKey='
                                      . $publicKey
                                      . '&clientSecret='
                                      . $paymobResponse['client_secret'],
                ], 200);
            }

            // Session failed → restore the stock that AddOrder already committed and
            // CANCEL the order (don't delete — order_items / payment_statuses
            // reference it with ON DELETE RESTRICT, so a delete throws 1451; the
            // cancelled record is also kept for debugging/audit).
            foreach ($order->order_item as $item) {
                if (empty($item->product_id)) {
                    continue;
                }
                $field = $item->type_stock === 'Express' ? 'stock' : 'market_stock';
                Product::where('id', $item->product_id)->increment($field, $item->quantity);
            }

            $order->update(['status' => 'cancelled']);
            return response()->json([
                'success'      => false,
                'message'      => 'Payment session failed',
                'order_number' => $order->order_number,
                'paymob_error' => $paymobResponse->json(),
            ], 422);

        } catch (\Exception $e) {
            // Exception during session creation → restore committed stock and cancel
            // the order. Return a clean error instead of re-throwing (which became a
            // 500) so the client gets an actionable message.
            Log::error($e);
            foreach ($order->order_item as $item) {
                if (empty($item->product_id)) {
                    continue;
                }
                $field = $item->type_stock === 'Express' ? 'stock' : 'market_stock';
                Product::where('id', $item->product_id)->increment($field, $item->quantity);
            }

            $order->update(['status' => 'cancelled']);
            return response()->json([
                'success'      => false,
                'message'      => 'Payment could not be initiated. Please try again or choose Cash on Delivery.',
                'order_number' => $order->order_number,
            ], 422);
        }
    }

    // =========================================================================
    //  Paymob Callback
    // =========================================================================
    public function CallbackPayment(Request $request)
    {
        try {
            // ── Verify Paymob HMAC signature BEFORE touching any order ────────
            if (! $this->isValidPaymobHmac($request)) {
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            // ── Idempotency: ignore replays of an already-processed txn ───────
            $transactionId = $request->input('id');
            if ($transactionId && PaymentStatus::where('pay_transaction_id', $transactionId)->exists()) {
                return response()->json(['message' => 'Already processed'], 200);
            }

            PaymentStatus::create([
                'order_id'           => $request->merchant_order_id,
                'pay_order_id'       => $request->order,
                'pay_transaction_id' => $request->id,
                'amount_cents'       => $request->amount_cents,
                'success'            => $request->success === 'true' || $request->success === true,
            ]);

            if ($request->merchant_order_id) {
                $order = Order::find($request->merchant_order_id);
                if ($order) {
                    $isSuccess     = $request->success === 'true' || $request->success === true;
                    $order->status = $isSuccess ? 'processing' : 'cancelled';
                    $order->save();

                    if ($isSuccess) {
                        $isGuest = is_null($order->user_id);
                        $this->sendOrderEmails($order, $isGuest, 'cash');
                    } else {
                        // Payment failed/abandoned → restore the reserved stock.
                        foreach ($order->order_item as $item) {
                            if (empty($item->product_id)) {
                                continue;
                            }
                            $field = $item->type_stock === 'Express' ? 'stock' : 'market_stock';
                            Product::where('id', $item->product_id)->increment($field, $item->quantity);
                        }
                    }
                }
            }

            return redirect('https://watchizereg.com/');
        } catch (\Exception $e) {
            return redirect('https://watchizereg.com/?payment_error=1');
        }
    }

    /**
     * Verify the HMAC signature Paymob sends with every transaction callback.
     * Fails closed: a missing secret or missing/incorrect signature is rejected.
     */
    private function isValidPaymobHmac(Request $request): bool
    {
        $secret = env('PAYMOB_HMAC_SECRET');
        if (! $secret) {
            return false;
        }

        $received = $request->input('hmac');
        if (! $received) {
            return false;
        }

        // Documented Paymob field order for transaction-callback HMAC.
        $keys = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];

        $concatenated = '';
        foreach ($keys as $key) {
            $concatenated .= $this->paymobHmacField($request, $key);
        }

        $computed = hash_hmac('sha512', $concatenated, $secret);

        return hash_equals($computed, (string) $received);
    }

    /**
     * Read one HMAC field, tolerating both the nested server callback
     * ("obj.order.id", "obj.source_data.pan") and the flattened redirect
     * callback ("order", "source_data.pan" / "source_data_pan").
     */
    private function paymobHmacField(Request $request, string $key): string
    {
        if ($key === 'order') {
            $candidates = ['obj.order.id', 'order.id', 'order'];
        } elseif (str_starts_with($key, 'source_data.')) {
            $suffix = substr($key, strlen('source_data.'));
            $candidates = ['obj.' . $key, $key, 'source_data_' . $suffix];
        } else {
            $candidates = ['obj.' . $key, $key];
        }

        foreach ($candidates as $candidate) {
            $value = $request->input($candidate);
            if ($value !== null) {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return (string) $value;
            }
        }

        return '';
    }

    // =========================================================================
    //  Show Orders
    // =========================================================================
    public function ShowOrder()
    {
        try {
            $authId = auth('api')->id();
            // Eager-load the address + its shipping city so the account page can
            // show the delivery governorate; newest orders first.
            $orders = Order::where('user_id', $authId)
                ->with(['order_item', 'address.shippingCity.translations'])
                ->orderByDesc('id')
                ->get();
            return response()->json($orders);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }
}