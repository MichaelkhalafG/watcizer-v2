<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CartIdentity;
use App\Compat\CompatCheckout;
use App\Compat\CompatServices;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientOfferStock;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatGuestCart;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * `POST add_order` and `GET callback_payment` — the two paths that mutate stock, moved onto
 * {@see InventoryService} (CLEAN_CORE_STUDY §3.3, §4.2).
 *
 * This is the endpoint the whole write-switch exists for: while it ran on the legacy app it
 * decremented legacy `products.stock`, which stops being the truth the moment the clean tables
 * are authoritative (risk register R2-01). Every response shape, every 422 message and the
 * order-number lock are unchanged; only where the stock number lives has moved.
 */
class CheckoutCompatController extends Controller
{
    public function __construct(
        private readonly CompatServices $compat,
        private readonly InventoryService $inventory,
    ) {}

    /** POST add_order */
    public function addOrder(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = Validator::make($request->all(), [
                'user_id' => 'nullable|integer',
                'address_id' => 'required_without:address_line|nullable|integer|exists:addresses,id',
                'address_line' => 'required_without:address_id|nullable|string|min:3|max:500',
                'shipping_city_id' => 'required_without:address_id|nullable|integer|exists:shipping_cities,id',
                'phone' => 'required_without:address_id|nullable|string|min:7|max:20',
                'total_price_for_order' => 'required|numeric|min:0',
                'payment_method' => 'required|in:cash,card,paymob,whatsapp',
                'note' => 'nullable|string|max:1000',
                'guest_name' => 'nullable|string|max:255',
                'guest_phone' => 'nullable|string|max:20',
                'guest_email' => 'nullable|email|max:255',
                'items' => 'nullable|array',
                'items.*.product_id' => ['nullable', 'integer', Rule::exists('catalog_products', 'id')->whereNull('deleted_at')],
                'items.*.offer_id' => 'nullable|integer|exists:offers,id',
                'items.*.quantity' => 'required_with:items|integer|min:1',
                'items.*.piece_price' => 'required_with:items|numeric|min:0',
                'items.*.total_price' => 'required_with:items|numeric|min:0',
                'items.*.type_stock' => 'nullable|in:Express,Market',
                'items.*.color_band' => 'nullable|string',
                'items.*.color_dial' => 'nullable|string',
            ])->validate();

            // The buyer comes from the BODY, not the JWT — legacy behaviour, reproduced; see the
            // class docblock of App\Compat\CompatCheckout for why and what is owed.
            $claimed = $request->filled('user_id') ? Val::nint($data, 'user_id') : null;
            $userId = $claimed !== null && $claimed > 0 ? $claimed : null;
            $isGuest = $userId === null;
            $guestToken = $this->guestToken($request);

            $paymentMethod = Val::str($data, 'payment_method') === 'card' ? 'paymob' : Val::str($data, 'payment_method');

            // ── shipping address ────────────────────────────────────────────────
            $addressId = $request->filled('address_id') ? Val::nint($data, 'address_id') : null;
            if ($addressId === null) {
                $phone = $request->input('phone') ?? $request->input('guest_phone') ?? '';
                $addressId = $this->compat->account->createAddress(
                    $userId,
                    $guestToken,
                    Val::int($data, 'shipping_city_id'),
                    trim(Val::str($data, 'address_line')),
                    trim(is_scalar($phone) ? (string) $phone : ''),
                    null,
                );
            }

            // ── the lines ───────────────────────────────────────────────────────
            $cart = null;
            $lines = $this->requestLines($request);
            if (! $isGuest) {
                $cart = $this->compat->cart->find(CartIdentity::user($userId));
                if ($lines === [] && $cart !== null) {
                    $lines = $this->cartLines(Row::int($cart, 'id'));
                }
            }
            if ($lines === []) {
                DB::rollBack();

                return response()->json(['success' => false, 'message' => 'Cart is empty'], 422);
            }

            // ── authoritative server-side pricing ───────────────────────────────
            $priced = $this->compat->checkout->priceLines($lines);
            if (isset($priced['error'])) {
                DB::rollBack();

                return response()->json($priced['error'], 422);
            }

            $serverTotal = round($priced['total'] + $this->compat->checkout->shippingCost($addressId), 2);
            $clientTotal = round((float) Val::str($data, 'total_price_for_order'), 2);
            if (abs($clientTotal - $serverTotal) > 0.01) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order total mismatch — your cart may be out of date. Please review and try again.',
                    'server_total' => $serverTotal,
                ], 422);
            }

            $orderId = $this->compat->checkout->placeOrder(
                $userId,
                $guestToken,
                $addressId,
                $serverTotal,
                $paymentMethod,
                $this->nullableString($request->input('note')),
                $this->nullableString($request->input('guest_name')),
                $this->nullableString($request->input('guest_email')),
                $this->nullableString($request->input('guest_phone') ?? $request->input('phone')),
                $priced['lines'],
            );

            // Clear the cart so an ordered/removed line can never inflate the NEXT order's total.
            if (! $isGuest && $cart !== null) {
                $this->compat->cart->destroy(Row::int($cart, 'id'));
            } elseif ($isGuest && $guestToken !== null) {
                $guestCart = $this->compat->cart->find(CartIdentity::guest($guestToken));
                if ($guestCart !== null) {
                    $this->compat->cart->destroy(Row::int($guestCart, 'id'));
                }
            }

            DB::commit();

            if (in_array($paymentMethod, ['cash', 'whatsapp'], true)) {
                $this->compat->checkout->recordOwedMail($orderId, $paymentMethod === 'cash' ? ['customer', 'admin'] : ['admin'], $this->customerEmail($userId, $this->nullableString($request->input('guest_email'))));
            }

            if ($paymentMethod === 'paymob') {
                return $this->paymob($request, $orderId, $userId, $addressId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'order_number' => $this->compat->checkout->orderNumber($orderId),
            ], 200);
        } catch (InsufficientStock|InsufficientOfferStock $e) {
            DB::rollBack();
            $response = CompatCheckout::insufficientStockResponse($e);

            return response()->json($response['body'], $response['status']);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error($e);

            return response()->json(['success' => false, 'message' => 'An error occurred', 'ref' => (string) Str::uuid()], 500);
        }
    }

    /**
     * GET callback_payment — Paymob's transaction callback.
     *
     * Four things happen in ONE transaction (review 🟠-4): the `payment_statuses` row, the order's
     * status change, the e-mail record and the stock release. The first version wrote them as
     * separate statements, so a failure between them could leave a payment recorded against an
     * order that was never updated, or an order cancelled whose stock was never returned. They are
     * one unit now, and `payment_statuses.pay_transaction_id` carries a UNIQUE index (M1e) so a
     * Paymob retry that races the idempotency check is refused by the database rather than
     * processed twice.
     *
     * It also verifies the AMOUNT (review 🟡-5). Paymob's `amount_cents` must equal the order's
     * own total to the cent; a mismatch is **failed closed** — the payment row is recorded for the
     * audit trail, the order is left exactly as it was, and the shopper is redirected with the
     * error flag. Marking an order paid on a callback whose amount disagrees with the order is
     * how an underpayment becomes a shipped parcel. This is a deliberate deviation from the
     * legacy behaviour, which never compared the two (D-23).
     */
    public function callbackPayment(Request $request): JsonResponse|RedirectResponse
    {
        try {
            /** @var array<string, mixed> $input */
            $input = $request->all();
            if (! $this->compat->checkout->isValidPaymobHmac($input)) {
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            $transactionId = $request->input('id');
            $transactionId = is_scalar($transactionId) && (string) $transactionId !== '' ? (int) $transactionId : null;
            if ($transactionId !== null && DB::table('payment_statuses')->where('pay_transaction_id', $transactionId)->exists()) {
                return response()->json(['message' => 'Already processed'], 200);
            }

            $success = $request->input('success');
            $isSuccess = $success === 'true' || $success === true;
            $merchantOrderId = $request->input('merchant_order_id');
            $orderId = is_scalar($merchantOrderId) && (int) $merchantOrderId > 0 ? (int) $merchantOrderId : null;
            $amountCents = is_scalar($request->input('amount_cents')) ? (int) $request->input('amount_cents') : null;

            $amountMismatch = $this->paymobAmountMismatch($orderId, $amountCents);

            DB::transaction(function () use ($transactionId, $orderId, $merchantOrderId, $request, $amountCents, $isSuccess, $amountMismatch): void {
                $now = now();
                DB::table('payment_statuses')->insert([
                    'order_id' => is_scalar($merchantOrderId) ? (int) $merchantOrderId : null,
                    'pay_order_id' => is_scalar($request->input('order')) ? (int) $request->input('order') : null,
                    'pay_transaction_id' => $transactionId,
                    'amount_cents' => $amountCents,
                    // An amount that disagrees with the order is never recorded as a success,
                    // whatever Paymob said.
                    'success' => $isSuccess && $amountMismatch === null ? 'true' : 'false',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($amountMismatch !== null) {
                    // Fail closed: the payment is on record, the ORDER is untouched. Neither
                    // marked paid nor cancelled — a human decides what a mismatched amount meant.
                    return;
                }

                if ($orderId === null) {
                    return;
                }
                $order = $this->compat->checkout->order($orderId);
                if ($order === null) {
                    return;
                }

                DB::table('orders')->where('id', $orderId)->update(['status' => $isSuccess ? 'processing' : 'cancelled', 'updated_at' => now()]);
                if ($isSuccess) {
                    $this->compat->checkout->recordOwedMail($orderId, ['customer', 'admin'], $this->customerEmail(Row::nint($order, 'user_id'), Row::nstr($order, 'guest_email')));
                } else {
                    $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $this->compat->storefrontId);
                }
            });

            if ($amountMismatch !== null) {
                Log::error('Paymob callback amount does not match the order total; order left untouched.', $amountMismatch);

                return redirect(config()->string('compat.payment_return_url').'?payment_error=1');
            }

            return redirect(config()->string('compat.payment_return_url'));
        } catch (Throwable $e) {
            Log::error($e);

            return redirect(config()->string('compat.payment_return_url').'?payment_error=1');
        }
    }

    /**
     * Does the callback's `amount_cents` disagree with what the order says it costs?
     *
     * Returns null when the two agree (or when there is nothing to compare against, which is the
     * legacy shape for a callback carrying no resolvable order), and the log context otherwise.
     * Compared in integer cents with a one-cent tolerance, because the order total is a
     * DECIMAL(12,2) and the multiplication by 100 is floating point.
     *
     * @return array<string, mixed>|null
     */
    private function paymobAmountMismatch(?int $orderId, ?int $amountCents): ?array
    {
        if ($orderId === null || $amountCents === null) {
            return null;
        }
        $order = $this->compat->checkout->order($orderId);
        if ($order === null) {
            return null;
        }

        $expected = (int) round(((float) Row::money($order, 'total_price_for_order')) * 100);
        if (abs($expected - $amountCents) <= 1) {
            return null;
        }

        return [
            'order_id' => $orderId,
            'order_number' => Row::str($order, 'order_number'),
            'expected_amount_cents' => $expected,
            'callback_amount_cents' => $amountCents,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The client-sent `items[]`, normalised. This is the authoritative SET of lines; prices are
     * always recomputed, so trusting the client for the set is not a pricing hole.
     *
     * @return array<int, array{product_id: int|null, offer_id: int|null, quantity: int, type_stock: string|null, color_band: string|null, color_dial: string|null}>
     */
    private function requestLines(Request $request): array
    {
        $items = $request->input('items');
        if (! is_array($items)) {
            return [];
        }
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $lines[] = [
                'product_id' => Val::nint($item, 'product_id'),
                'offer_id' => Val::nint($item, 'offer_id'),
                'quantity' => Val::int($item, 'quantity'),
                'type_stock' => Val::nstr($item, 'type_stock'),
                'color_band' => Val::nstr($item, 'color_band'),
                'color_dial' => Val::nstr($item, 'color_dial'),
            ];
        }

        return $lines;
    }

    /**
     * The DB cart, used only when a logged-in client posted no `items[]` at all.
     *
     * @return array<int, array{product_id: int|null, offer_id: int|null, quantity: int, type_stock: string|null, color_band: string|null, color_dial: string|null}>
     */
    private function cartLines(int $cartId): array
    {
        $lines = [];
        foreach ($this->compat->cart->items($cartId) as $item) {
            $lines[] = [
                'product_id' => Row::nint($item, 'product_id'),
                'offer_id' => Row::nint($item, 'offer_id'),
                'quantity' => Row::int($item, 'quantity'),
                'type_stock' => Row::nstr($item, 'type_stock'),
                'color_band' => Row::nstr($item, 'color_band'),
                'color_dial' => Row::nstr($item, 'color_dial'),
            ];
        }

        return $lines;
    }

    /**
     * The Paymob branch. Structurally complete, locally unproven: the keys are developer-handled
     * and are never in this repo, so with none configured the intention call throws and the
     * caller sees the legacy "Payment could not be initiated" 422 — the same branch the legacy
     * code takes for the same reason.
     */
    private function paymob(Request $request, int $orderId, ?int $userId, int $addressId): JsonResponse
    {
        $orderNumber = $this->compat->checkout->orderNumber($orderId);
        $order = $this->compat->checkout->order($orderId);
        $amount = $order === null ? 0.0 : (float) Row::money($order, 'total_price_for_order');

        try {
            $result = $this->compat->checkout->createPaymobIntention($amount, $this->billingData($request, $userId, $addressId), $orderId);

            if ($result['ok']) {
                return response()->json(['success' => true, 'order_number' => $orderNumber, 'redirect_url' => $result['redirect_url']], 200);
            }

            // Session refused → give the reserved stock back and CANCEL the order (never delete:
            // order_items and payment_statuses reference it with ON DELETE RESTRICT).
            $this->cancelAndRelease($orderId);

            return response()->json([
                'success' => false,
                'message' => 'Payment session failed',
                'order_number' => $orderNumber,
                'paymob_error' => $result['error'],
            ], 422);
        } catch (Throwable $e) {
            Log::error($e);
            $this->cancelAndRelease($orderId);

            return response()->json([
                'success' => false,
                'message' => 'Payment could not be initiated. Please try again or choose Cash on Delivery.',
                'order_number' => $orderNumber,
            ], 422);
        }
    }

    private function cancelAndRelease(int $orderId): void
    {
        $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $this->compat->storefrontId);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function billingData(Request $request, ?int $userId, int $addressId): array
    {
        $first = 'Guest';
        $last = 'User';
        $email = 'guest@example.com';

        $user = $userId === null ? null : DB::table('users')->where('id', $userId)->first();
        if ($user !== null) {
            $first = Row::nstr($user, 'first_name') ?? 'Guest';
            $last = Row::nstr($user, 'last_name') ?? 'User';
            $email = Row::nstr($user, 'email') ?? 'guest@example.com';
        } else {
            $parts = explode(' ', trim((string) ($this->nullableString($request->input('guest_name')) ?? 'Guest User')), 2);
            $first = ($parts[0] !== '' ? $parts[0] : 'Guest');
            $last = $parts[1] ?? 'User';
            $email = $this->nullableString($request->input('guest_email')) ?? 'guest@example.com';
        }

        $address = DB::table('addresses')->where('id', $addressId)->first();
        $street = $address === null ? 'N/A' : (Row::nstr($address, 'address_line') ?? 'N/A');
        $phone = $address === null ? null : Row::nstr($address, 'phone_number_one');
        $phone ??= $this->nullableString($request->input('guest_phone')) ?? '01000000000';

        $city = 'Cairo';
        if ($address !== null) {
            $name = DB::connection('legacy')->table('shipping_city_translations')
                ->where('shipping_city_id', Row::int($address, 'shipping_city_id'))
                ->where('locale', 'en')->value('city_name');
            if (is_string($name) && $name !== '') {
                $city = $name;
            }
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'street' => $street,
            'phone_number' => $phone,
            'city' => $city,
            'country' => 'Egypt',
            'email' => $email,
        ];
    }

    private function customerEmail(?int $userId, ?string $guestEmail): ?string
    {
        if ($userId === null) {
            return $guestEmail;
        }
        $email = DB::table('users')->where('id', $userId)->value('email');

        return is_string($email) ? $email : null;
    }

    private function guestToken(Request $request): ?string
    {
        $identity = $request->attributes->get(CompatGuestCart::ATTRIBUTE);
        if ($identity instanceof CartIdentity && $identity->guestToken !== null) {
            return $identity->guestToken;
        }
        $header = $request->header(CompatGuestCart::HEADER);

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
