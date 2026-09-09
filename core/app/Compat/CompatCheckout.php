<?php

namespace App\Compat;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientOfferStock;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * The checkout half of the wave-3 compat layer: `POST add_order` and `GET callback_payment`,
 * reproducing `OrderController::AddOrder` / `CallbackPayment` while every stock mutation goes
 * through {@see InventoryService}.
 *
 * Two behaviours are reproduced on purpose even though they look like defects, because the
 * running frontend depends on them and the harness compares bytes:
 *
 *  • the buyer is taken from the request body (`user_id`), not from the JWT. A caller can
 *    therefore attribute an order to another account. It reads nothing back, so it is
 *    mis-attribution rather than disclosure — but it IS a real weakness and it is on the wave-4
 *    auth list, to be closed together with the frontend that sends the field.
 *  • `items[]` from the client is the authoritative SET of lines. Prices are always recomputed
 *    server-side, so this is not a pricing hole; it exists because a stale DB cart row used to
 *    resurrect a line the shopper had removed and reject the checkout as a total mismatch.
 *
 * NOT reproduced, and flagged: the order e-mails. The legacy `sendOrderEmails()` renders three
 * blade templates that have not been ported to core. Instead of pretending, every order that
 * would have sent mail writes an `integration_outbox` row (`channel = mail`), so the queue of
 * what is owed is exact and nothing is silently dropped. Porting the mailables is a named
 * switch-night prerequisite.
 */
final class CompatCheckout
{
    public const MAIL_CHANNEL = 'mail';

    public function __construct(
        private readonly CompatCart $cart,
        private readonly InventoryService $inventory,
        private readonly int $storefrontId,
    ) {}

    /**
     * A resolved, server-priced line: what the order will actually store.
     *
     * @param  array<int, array{product_id: int|null, offer_id: int|null, quantity: int, type_stock: string|null, color_band: string|null, color_dial: string|null}>  $lines
     * @return array{lines: list<array<string, mixed>>, total: float}|array{error: array<string, mixed>, status: int}
     */
    public function priceLines(array $lines): array
    {
        $productIds = [];
        $offerIds = [];
        foreach ($lines as $line) {
            if ($line['product_id'] !== null) {
                $productIds[$line['product_id']] = true;
            } elseif ($line['offer_id'] !== null) {
                $offerIds[$line['offer_id']] = true;
            }
        }
        $catalog = $this->cart->catalog(array_keys($productIds), array_keys($offerIds));

        $priced = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $entity = null;
            if ($line['product_id'] !== null) {
                $entity = $catalog['products'][$line['product_id']] ?? null;
            } elseif ($line['offer_id'] !== null) {
                $entity = $catalog['offers'][$line['offer_id']] ?? null;
            }
            $qty = $line['quantity'];
            if ($entity === null || $qty < 1) {
                return ['error' => ['success' => false, 'message' => 'One of the items is no longer available.'], 'status' => 422];
            }
            $piece = CompatCart::catalogPrice($entity['selling'], $entity['sale']);
            $lineTotal = round($piece * $qty, 2);
            $priced[] = $line + ['piece_price' => $piece, 'total_price' => $lineTotal];
            $total += $lineTotal;
        }

        return ['lines' => $priced, 'total' => round($total, 2)];
    }

    /** The governorate's shipping cost for an address, rounded — 0.0 when either row is missing. */
    public function shippingCost(?int $addressId): float
    {
        if ($addressId === null) {
            return 0.0;
        }
        $cost = DB::table('addresses')
            ->join('shipping_cities', 'shipping_cities.id', '=', 'addresses.shipping_city_id')
            ->where('addresses.id', $addressId)
            ->value('shipping_cities.shipping_cost');

        return round((float) (is_scalar($cost) ? $cost : 0), 2);
    }

    /**
     * `str_pad(MAX(CAST(order_number AS UNSIGNED)) + 1, 6, '0', STR_PAD_LEFT)` under a
     * `lockForUpdate`, which serialises order creation exactly as the legacy app did. The lock is
     * the reason `orders` was never duplicated into the clean schema (study §2.6).
     */
    public function nextOrderNumber(): string
    {
        $max = DB::table('orders')->lockForUpdate()->selectRaw('MAX(CAST(order_number AS UNSIGNED)) as m')->value('m');

        return str_pad((string) ((int) (is_numeric($max) ? $max : 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Insert the order and its lines, reserve the stock through the ledger, empty the cart.
     * Runs inside the caller's transaction; an InsufficientStock rolls the whole thing back.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function placeOrder(
        ?int $userId,
        ?string $guestToken,
        int $addressId,
        float $total,
        string $paymentMethod,
        ?string $note,
        ?string $guestName,
        ?string $guestEmail,
        ?string $guestPhone,
        array $lines,
    ): int {
        $now = now();
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'address_id' => $addressId,
            'total_price_for_order' => $total,
            'payment_method' => $paymentMethod,
            'order_number' => $this->nextOrderNumber(),
            'note' => $note,
            'status' => $paymentMethod === 'cash' ? 'processing' : 'pending',
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'guest_token' => $userId === null ? $guestToken : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($lines as $line) {
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => Val::nint($line, 'product_id'),
                'offer_id' => Val::nint($line, 'offer_id'),
                'quantity' => Val::int($line, 'quantity'),
                'piece_price' => Val::str($line, 'piece_price'),
                'total_price' => Val::str($line, 'total_price'),
                'type_stock' => Val::nstr($line, 'type_stock'),
                'color_band' => Val::nstr($line, 'color_band'),
                'color_dial' => Val::nstr($line, 'color_dial'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // One door for stock. The legacy loop decremented in place between item inserts; the
        // ledger reads the persisted lines back, so the movements can never disagree with what
        // the order says it sold.
        $this->inventory->commitOrder($orderId, Actor::user($userId), $this->storefrontId);

        return $orderId;
    }

    /** The order row, or null. */
    public function order(int $orderId): ?stdClass
    {
        $row = DB::table('orders')->where('id', $orderId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    public function orderNumber(int $orderId): string
    {
        $value = DB::table('orders')->where('id', $orderId)->value('order_number');

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The legacy 422 body for a failed reservation. Kept next to the exception so the two never
     * drift: `product_id` is present for a product line and absent for an offer line.
     *
     * @return array{body: array<string, mixed>, status: int}
     */
    public static function insufficientStockResponse(InsufficientStock|InsufficientOfferStock $e): array
    {
        if ($e instanceof InsufficientStock) {
            return ['body' => ['success' => false, 'message' => 'Insufficient stock', 'product_id' => $e->productId], 'status' => 422];
        }

        return ['body' => ['success' => false, 'message' => 'Insufficient offer stock'], 'status' => 422];
    }

    // ── Paymob ───────────────────────────────────────────────────────────────

    /**
     * Verify the HMAC Paymob sends with every transaction callback, over the 20 documented fields
     * in their documented order. Fails closed: no secret or no signature means rejected.
     *
     * @param  array<string, mixed>  $input
     */
    public function isValidPaymobHmac(array $input): bool
    {
        $secret = config('services.paymob.hmac_secret');
        if (! is_string($secret) || $secret === '') {
            return false;
        }
        $received = data_get($input, 'hmac');
        if (! is_scalar($received) || (string) $received === '') {
            return false;
        }

        $keys = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
        $concatenated = '';
        foreach ($keys as $key) {
            $concatenated .= self::paymobField($input, $key);
        }

        return hash_equals(hash_hmac('sha512', $concatenated, $secret), (string) $received);
    }

    /**
     * One HMAC field, tolerating both the nested server callback (`obj.order.id`) and the
     * flattened redirect callback (`order`, `source_data_pan`).
     *
     * @param  array<string, mixed>  $input
     */
    public static function paymobField(array $input, string $key): string
    {
        if ($key === 'order') {
            $candidates = ['obj.order.id', 'order.id', 'order'];
        } elseif (str_starts_with($key, 'source_data.')) {
            $suffix = substr($key, strlen('source_data.'));
            $candidates = ['obj.'.$key, $key, 'source_data_'.$suffix];
        } else {
            $candidates = ['obj.'.$key, $key];
        }

        foreach ($candidates as $candidate) {
            /** @var mixed $value */
            $value = data_get($input, $candidate);
            if ($value !== null) {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return is_scalar($value) ? (string) $value : '';
            }
        }

        return '';
    }

    /**
     * Create the Paymob intention and return the unified-checkout URL.
     *
     * Paymob keys are developer-handled and never live in this repo, so this path is
     * STRUCTURALLY complete and locally UNPROVEN: with no keys configured it throws, which is the
     * same branch the legacy code takes, and the caller answers with the legacy 422. Only the
     * cash-on-delivery flow is exercised end to end by the harness.
     *
     * @param  array<string, mixed>  $billing
     * @return array{ok: true, redirect_url: string}|array{ok: false, error: mixed}
     */
    public function createPaymobIntention(float $amount, array $billing, int $orderId): array
    {
        $authToken = config('services.paymob.secret_key');
        $publicKey = config('services.paymob.public_key');
        if (! is_string($authToken) || $authToken === '' || ! is_string($publicKey) || $publicKey === '') {
            throw new RuntimeException('Paymob credentials not configured');
        }

        $methods = config('services.paymob.payment_methods');
        $response = Http::withHeaders([
            'Authorization' => "Token {$authToken}",
            'Content-Type' => 'application/json',
        ])->post('https://accept.paymob.com/v1/intention/', [
            'amount' => $amount * 100,
            'currency' => 'EGP',
            'payment_methods' => is_array($methods) ? array_values($methods) : [],
            'billing_data' => $billing,
            // Paymob requires special_reference to be globally unique forever; the numeric order
            // id stays the prefix so the callback's int coercion still resolves the order.
            'special_reference' => $orderId.'-'.time(),
        ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $response->json()];
        }

        $clientSecret = $response->json('client_secret');

        return ['ok' => true, 'redirect_url' => 'https://accept.paymob.com/unifiedcheckout/?publicKey='.$publicKey.'&clientSecret='.(is_scalar($clientSecret) ? (string) $clientSecret : '')];
    }

    /**
     * The e-mail core does not yet send, recorded rather than dropped. One row per order, so the
     * mail wave (or a legacy-side drain) can replay exactly what is owed.
     *
     * @param  list<string>  $kinds  customer | admin
     */
    public function recordOwedMail(int $orderId, array $kinds, ?string $customerEmail): void
    {
        DB::table('integration_outbox')->insert([
            'channel' => self::MAIL_CHANNEL,
            'event' => 'order.placed',
            'aggregate_type' => 'orders',
            'aggregate_id' => $orderId,
            'payload' => (string) json_encode([
                'order_id' => $orderId,
                'order_number' => $this->orderNumber($orderId),
                'kinds' => $kinds,
                'customer_email' => $customerEmail,
                'note' => 'core does not render the legacy order mailables yet (wave-3 flag)',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Row helper re-export so the controller does not need two imports for one call. */
    public static function int(stdClass $row, string $column): int
    {
        return Row::int($row, $column);
    }
}
