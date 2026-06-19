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
use Illuminate\Http\Request;
use App\Models\PaymentStatus;
use App\Mail\OrderCreatedMail;
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

            $userId = null;
            if ($request->filled('user_id') && (int) $request->user_id > 0) {
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
            $addresses = Address::where('user_id', $authId)->get();
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
            return response()->json($cart);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function DeleteCart($id)
    {
        try {
            $authId = auth()->id();
            CartItem::whereHas('cart', fn($q) => $q->where('user_id', $authId))
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
    //  Order
    // =========================================================================
    public function AddOrder(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'user_id'               => 'nullable|integer',
                'address_id'            => 'required|integer|exists:addresses,id',
                'total_price_for_order' => 'required|numeric|min:0',
                'payment_method'        => 'required|in:cash,paymob,whatsapp',
                'note'                  => 'nullable|string|max:1000',
                'guest_name'            => 'nullable|string|max:255',
                'guest_phone'           => 'nullable|string|max:20',
                'guest_email'           => 'nullable|email|max:255',
                'address_line'          => 'nullable|string',
                'shipping_city_id'      => 'nullable|integer',
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

            // ── Authoritative total (never trust the client) ──────────────────
            // Recompute from the SAME items the order is built from ($cartItems),
            // pricing products by sale-price-then-list, offers by their price,
            // and add the server-side shipping cost for the chosen address.
            $serverItemsTotal = $cartItems->sum(function ($item) {
                $i = is_array($item) ? (object) $item : $item;
                if (!empty($i->product_id)) {
                    $p = Product::find($i->product_id);
                    $price = $p?->sale_price_after_discount ?? $p?->selling_price ?? 0;
                } elseif (!empty($i->offer_id)) {
                    $price = optional(Offer::find($i->offer_id))->price ?? 0;
                } else {
                    $price = 0;
                }
                return (float) $price * (int) ($i->quantity ?? 0);
            });

            $shippingCost = (float) optional(
                optional(Address::with('shippingCity')->find($request->address_id))->shippingCity
            )->shipping_cost;

            $serverTotal = $serverItemsTotal + $shippingCost;

            if (abs($serverTotal - (float) $request->total_price_for_order) > 1) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Price mismatch — please refresh and try again',
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
                'payment_method'        => $request->payment_method,
                'order_number'          => $orderNumber,
                'note'                  => $request->note,
                'status'                => $request->payment_method === 'cash' ? 'processing' : 'pending',
                'guest_name'            => $request->guest_name  ?? null,
                'guest_email'           => $request->guest_email ?? null,
            ]);

            // ── Order items + stock ───────────────────────────────────────────
            foreach ($cartItems as $item) {
                $itemData = is_array($item) ? (object) $item : $item;

                OrderItem::create([
                    'order_id'    => $order->id,
                    'product_id'  => $itemData->product_id ?? null,
                    'offer_id'    => $itemData->offer_id   ?? null,
                    'quantity'    => $itemData->quantity,
                    'piece_price' => $itemData->piece_price,
                    'total_price' => $itemData->total_price,
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
            if (in_array($request->payment_method, ['cash', 'whatsapp'])) {
                $this->sendOrderEmails($order, $isGuest, $request->payment_method);
            }

            // ── Paymob ────────────────────────────────────────────────────────
            if ($request->payment_method === 'paymob') {
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
        if ($paymentMethod === 'cash') {
            $customerEmail = !$isGuest
                ? optional($order->user)->email
                : $order->guest_email;

            if ($customerEmail) {
                try {
                    Mail::to($customerEmail)->queue(new OrderCreatedMail($order, 'customer'));
                    \Log::info('Customer email sent to: ' . $customerEmail . ' | Order: ' . $order->order_number);
                } catch (\Exception $e) {
                    \Log::error('Customer email FAILED | Order: ' . $order->order_number, ['exception' => $e]);
                }
            }
        }

        foreach (config('order.admin_emails') as $email) {
            try {
                Mail::to($email)->queue(new OrderCreatedMail($order, 'admin'));
                \Log::info('Admin email sent to: ' . $email . ' | Order: ' . $order->order_number);
            } catch (\Exception $e) {
                \Log::error('Admin email FAILED | To: ' . $email . ' | Order: ' . $order->order_number, ['exception' => $e]);
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
                'special_reference' => (string) $order->id,
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

            // Session failed → restore the stock that AddOrder already committed.
            foreach ($order->order_item as $item) {
                if (empty($item->product_id)) {
                    continue;
                }
                $field = $item->type_stock === 'Express' ? 'stock' : 'market_stock';
                Product::where('id', $item->product_id)->increment($field, $item->quantity);
            }

            $order->delete();
            return response()->json([
                'success'      => false,
                'message'      => 'Payment session failed',
                'paymob_error' => $paymobResponse->json(),
            ], 422);

        } catch (\Exception $e) {
            // Exception during session creation → restore committed stock too.
            foreach ($order->order_item as $item) {
                if (empty($item->product_id)) {
                    continue;
                }
                $field = $item->type_stock === 'Express' ? 'stock' : 'market_stock';
                Product::where('id', $item->product_id)->increment($field, $item->quantity);
            }

            $order->delete();
            throw $e;
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
            $orders = Order::where('user_id', $authId)->with('order_item')->get();
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