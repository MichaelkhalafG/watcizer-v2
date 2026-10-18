<?php

namespace App\Storefront;

use App\Compat\CompatCart;
use App\Compat\CompatCheckout;
use App\Domain\Analytics\MetaConversions;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientOfferStock;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderCustomer;
use App\Domain\Orders\UnpaidOrders;
use App\Domain\Payment\CheckoutMethods;
use App\Domain\Payment\PaymentInitiator;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionOutcome;
use App\Domain\Promotions\PromotionSkips;
use App\Transform\Row;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The v2 CHECKOUT of one storefront (B7 step S2, round 6, 2026-10-18): `POST /api/v2/{storefront}/orders`.
 *
 * Built BESIDE compat's `add_order` and never through its controller: the compat layer takes Watchizer's real
 * orders, so nothing in `App\Compat` or in the order / payment / inventory / promotion / mail domains is edited.
 * What is REUSED is called, unchanged, with the path's storefront — the same way compat calls it with its own:
 *   • `CompatCheckout::placeOrder()` — THE order writer: the order row, its lines, the granted reward lines, and
 *     `commitOrder()` in one transaction; the ONE order-number sequence under its `lockForUpdate` (shared by both
 *     shops — backlog ORDER-NUMBER-SHARED); constructed here with `$this->ctx->id()`, so the row's
 *     `storefront_id` is ALWAYS the path's storefront (Michael, round 6: "the v2 path always writes storefront_id,
 *     every time"); a storefront id below 1 is refused before anything is written;
 *   • `CheckoutMethods::refusal()` — before any write: cash is always offered, a card without a live contract for
 *     THIS storefront is refused;
 *   • `UnpaidOrders::supersedeFor()`, the promotion engine and its skip counter, `PromotionDiscounts`,
 *     `MetaConversions::recordSignals()`, `OrderMailer::placedNow()` and `PaymentInitiator::initiate()` — each with
 *     this storefront's id.
 *
 * What differs from compat, and why: the LINES come from the shopper's v2 cart on THIS storefront (variant-aware,
 * S1), never from the request body; each is re-checked at the door (`StorefrontCart::check()`) and re-priced on
 * the server (the valid-sale rule plus the variant's `price_delta`); the client's total is still the guard — a
 * mismatch is refused with the server's number, as on compat. Errors are stable codes, not legacy sentences.
 *
 * PAYMENT, exactly how far it goes: cash on delivery and WhatsApp place the order; a CARD reaches
 * `PaymentInitiator`, which starts a payment through the storefront's OWN contract — so on a storefront with a
 * live, complete contract (a `storefront_payment_providers` row with credentials, and an enabled card method on
 * it) a card order works with no further code. Brand Fashion has no such row: `CheckoutMethods` refuses the card
 * before anything is written. The contract is the only thing missing (for Paymob; MyFatoorah, Brand Fashion's
 * contracted provider per C-1, also needs its provider code).
 */
final class StorefrontCheckout
{
    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCart $carts,
        private readonly InventoryService $inventory,
        private readonly CheckoutMethods $methods,
        private readonly UnpaidOrders $unpaid,
        private readonly PromotionEngine $promotions,
        private readonly PromotionSkips $skips,
        private readonly OrderMailer $mailer,
        private readonly PaymentInitiator $initiator,
    ) {}

    /**
     * Place the order. `$data` is the validated body; `$addressId` an address already proved to be the buyer's (or
     * null, when the body carries a new one).
     *
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>}
     */
    public function place(Request $request, ?int $userId, ?string $guestToken, ?int $addressId, array $data): array
    {
        $storefrontId = $this->ctx->id();
        if ($storefrontId < 1) {
            // The path resolved no storefront: an order without one is never written (Michael, round 6).
            throw new LogicException('A v2 order cannot be written without its storefront.');
        }
        $legacyMethod = is_string($data['payment_method'] ?? null) ? $data['payment_method'] : 'cash';
        $postedMethodId = is_scalar($data['payment_method_id'] ?? null) ? (string) $data['payment_method_id'] : null;
        $clientTotal = round(is_numeric($data['total'] ?? null) ? (float) $data['total'] : -1.0, 2);

        // Before anything is written: may this order be paid the way it asks, on THIS storefront?
        $refusal = $this->methods->refusal($storefrontId, $postedMethodId, $legacyMethod, $clientTotal);
        if ($refusal !== null) {
            return ['status' => 422, 'body' => ['error' => $refusal['code'], 'messages' => $refusal['messages']]];
        }
        $paymentMethod = $legacyMethod === 'card' ? 'paymob' : $legacyMethod;

        $cart = $this->carts->find($userId, $guestToken);
        $cartLines = $cart === null ? [] : $this->carts->lines($cart);
        if ($cart === null || $cartLines === []) {
            return ['status' => 422, 'body' => ['error' => 'cart_empty']];
        }

        // Every line re-checked and re-priced on the server, from the cart — never from the body.
        $priced = [];
        $subtotal = 0.0;
        foreach ($cartLines as $line) {
            $check = $this->carts->check($line['product_id'], $line['variant_id'], $line['bucket'], $line['quantity']);
            if ($check['refusal'] !== null) {
                return ['status' => 422, 'body' => ['error' => 'line_unavailable', 'line' => $line['id'], 'reason' => $check['refusal']]];
            }
            $lineTotal = round($check['price'] * $line['quantity'], 2);
            $priced[] = [
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'offer_id' => null,
                'quantity' => $line['quantity'],
                'type_stock' => $check['bucket'],
                'color_band' => null,
                'color_dial' => null,
                'piece_price' => number_format($check['price'], 2, '.', ''),
                'total_price' => number_format($lineTotal, 2, '.', ''),
            ];
            $subtotal += $lineTotal;
        }
        $subtotal = round($subtotal, 2);

        $outer = DB::transactionLevel();
        DB::beginTransaction();
        try {
            // The shopper's own unpaid card orders on this storefront give their units back first (as on compat).
            $this->unpaid->supersedeFor($userId, $userId === null ? $guestToken : null, $storefrontId);

            if ($addressId === null) {
                $addressId = $this->createAddress($userId, $guestToken, $data);
            }
            $shipping = $this->shippingCost($addressId);
            $serverTotal = round($subtotal + $shipping, 2);
            if (abs($clientTotal - $serverTotal) > 0.01) {
                self::unwindTo($outer);

                return ['status' => 422, 'body' => ['error' => 'total_mismatch', 'server_total' => $serverTotal]];
            }

            $promotion = $this->evaluatePromotions($storefrontId, $priced, $subtotal, $shipping, $paymentMethod);
            if ($promotion->discount > 0.0) {
                $serverTotal = round(max(0.0, $serverTotal - $promotion->discount), 2);
            }

            // THE order writer, unchanged, with the path's storefront.
            $writer = new CompatCheckout(new CompatCart($storefrontId), $this->inventory, $storefrontId);
            $orderId = $writer->placeOrder(
                $userId,
                $guestToken,
                $addressId,
                $serverTotal,
                $paymentMethod,
                self::str($data['note'] ?? null),
                self::str($data['guest_name'] ?? null),
                self::str($data['guest_email'] ?? null),
                self::str($data['guest_phone'] ?? null) ?? self::str($data['phone'] ?? null),
                $priced,
                $promotion,
            );
            PromotionDiscounts::record($orderId, $promotion);
            MetaConversions::recordSignals($orderId, $request);
            $this->carts->clear($cart);

            DB::commit();
        } catch (InsufficientStock|InsufficientOfferStock $e) {
            self::unwindTo($outer);

            return ['status' => 422, 'body' => ['error' => 'insufficient_stock', 'product_id' => $e instanceof InsufficientStock ? $e->productId : null]];
        } catch (Throwable $e) {
            self::unwindTo($outer);
            Log::error($e);

            return ['status' => 500, 'body' => ['error' => 'server_error', 'ref' => (string) Str::uuid()]];
        }

        // After the commit, as on compat: the attempt's skipped promotions, the e-mail, the payment.
        $this->skips->record($promotion->skipped, $storefrontId);
        if (in_array($paymentMethod, ['cash', 'whatsapp'], true)) {
            $this->mailer->placedNow($orderId, notifyCustomer: $paymentMethod === 'cash');
        }
        $order = $writer->order($orderId);
        $body = [
            'order_number' => $writer->orderNumber($orderId),
            'status' => $order === null ? null : Row::str($order, 'status'),
            'total' => $order === null ? $serverTotal : (float) Row::money($order, 'total_price_for_order'),
            'payment_method' => $paymentMethod,
        ];
        if ($paymentMethod === 'paymob') {
            return $this->startPayment($request, $storefrontId, $orderId, $addressId, $postedMethodId ?? $legacyMethod, $body);
        }

        return ['status' => 201, 'body' => ['data' => $body]];
    }

    /**
     * A card order: start the payment through THIS storefront's own contract. No live contract (it went dark
     * between `refusal()` and here) or a provider refusal → the order is cancelled and its stock released, as on
     * compat — never another storefront's account.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function startPayment(Request $request, int $storefrontId, int $orderId, int $addressId, string $postedMethod, array $body): array
    {
        try {
            $started = $this->initiator->initiate(
                $request,
                $storefrontId,
                app()->getLocale(),
                $postedMethod,
                $orderId,
                is_string($body['order_number']) ? $body['order_number'] : '',
                is_numeric($body['total']) ? (float) $body['total'] : 0.0,
                $this->billing($orderId, $addressId),
            );
            if ($started !== null && $started->ok && $started->checkoutUrl !== null) {
                return ['status' => 201, 'body' => ['data' => $body + ['redirect_url' => $started->checkoutUrl]]];
            }
            $this->cancelAndRelease($orderId, $storefrontId);

            return ['status' => 422, 'body' => ['error' => 'payment_not_started', 'order_number' => $body['order_number'], 'provider' => $started?->failureReason]];
        } catch (Throwable $e) {
            Log::error($e);
            $this->cancelAndRelease($orderId, $storefrontId);

            return ['status' => 422, 'body' => ['error' => 'payment_not_started', 'order_number' => $body['order_number']]];
        }
    }

    private function cancelAndRelease(int $orderId, int $storefrontId): void
    {
        $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $storefrontId);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * @param  list<array<string, mixed>>  $priced
     */
    private function evaluatePromotions(int $storefrontId, array $priced, float $subtotal, float $shipping, string $paymentMethod): PromotionOutcome
    {
        $lines = [];
        foreach ($priced as $line) {
            $lines[] = new CartLine(
                productId: is_int($line['product_id']) ? $line['product_id'] : null,
                variantId: is_int($line['variant_id']) ? $line['variant_id'] : null,
                offerId: null,
                quantity: is_int($line['quantity']) ? $line['quantity'] : 0,
                unitPrice: is_string($line['piece_price']) ? (float) $line['piece_price'] : 0.0,
                typeStock: is_string($line['type_stock']) ? $line['type_stock'] : null,
            );
        }

        return $this->promotions->evaluate(new CartSnapshot(
            storefrontId: $storefrontId,
            lines: $lines,
            subtotal: $subtotal,
            shippingCost: $shipping,
            paymentMethod: $paymentMethod,
        ));
    }

    /** The governorate's shipping cost for an address (the same statement compat uses). */
    private function shippingCost(int $addressId): float
    {
        $cost = DB::table('addresses')
            ->join('shipping_cities', 'shipping_cities.id', '=', 'addresses.shipping_city_id')
            ->where('addresses.id', $addressId)
            ->value('shipping_cities.shipping_cost');

        return round((float) (is_scalar($cost) ? $cost : 0), 2);
    }

    /**
     * A new address for this buyer (the same columns compat's account writes).
     *
     * @param  array<string, mixed>  $data
     */
    private function createAddress(?int $userId, ?string $guestToken, array $data): int
    {
        $phone = self::str($data['phone'] ?? null) ?? self::str($data['guest_phone'] ?? null) ?? '';

        return (int) DB::table('addresses')->insertGetId([
            'user_id' => $userId,
            'guest_token' => $userId !== null ? null : $guestToken,
            'shipping_city_id' => is_numeric($data['shipping_city_id'] ?? null) ? (int) $data['shipping_city_id'] : 0,
            'address_line' => trim(self::str($data['address_line'] ?? null) ?? ''),
            'phone_number_one' => trim($phone),
            'phone_number_two' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Who is paying, for the provider's page — from the ORDER that now exists (`OrderCustomer`, the one definition
     * the dashboard and the e-mails use), with the placeholders the provider requires for a blank field.
     *
     * @return array<string, string>
     */
    private function billing(int $orderId, int $addressId): array
    {
        $customer = OrderCustomer::of($orderId);
        $parts = explode(' ', $customer->name ?? 'Guest User', 2);
        $address = DB::table('addresses')->where('id', $addressId)->first();

        return [
            'first_name' => $parts[0] !== '' ? $parts[0] : 'Guest',
            'last_name' => $parts[1] ?? 'User',
            'street' => $address === null ? 'N/A' : (Row::nstr(Row::cast($address), 'address_line') ?? 'N/A'),
            'phone_number' => $customer->phone ?? '01000000000',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'email' => $customer->email ?? 'guest@example.com',
        ];
    }

    /** Roll back to the level found, and no further (compat's `unwindTo`, 2026-10-05). */
    private static function unwindTo(int $outer): void
    {
        while (DB::transactionLevel() > $outer) {
            DB::rollBack();
        }
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }
}
