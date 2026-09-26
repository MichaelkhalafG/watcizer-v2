<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;

/**
 * What a shopper may PAY with at checkout, and the one check `add_order` makes before an order
 * exists (batch 1, 2026-09-26).
 *
 * ── Why this is separate from MethodList ─────────────────────────────────────────────────────
 *
 * {@see MethodList} answers "which row wins each key" and routes the money; it deliberately lists
 * an enabled row even when it cannot take a payment, so the dashboard shows the problem. A SHOPPER
 * must never be offered such a row. This class narrows the winners to the ones that can actually
 * be paid with — the row has a usable integration id and its contract is live (enabled AND holding
 * complete credentials, the same gate PaymentInitiator and the callback ask) — and it is what both
 * the v2 list and the `add_order` check read, so the list a shopper sees and the check their order
 * meets cannot disagree.
 *
 * ── The check, and what it leaves alone ──────────────────────────────────────────────────────
 *
 *   • A posted `payment_method_id` must be one of THIS storefront's offered rows, its key must
 *     agree with the legacy `payment_method` string (a card order may not name the cash row — the
 *     order used to be created and then cancelled with "Payment session failed"), and the order
 *     total must sit inside the row's limits.
 *   • A legacy card order (no id) is refused when the storefront's Paymob contract is LIVE and no
 *     card row is offered. That closes the wave-3 fallback: with a live contract, a disabled card
 *     row used to fall through to the global `.env` account instead of being refused.
 *   • Cash on delivery and WhatsApp without an id are not checked at all — cash is always offered,
 *     independent of the method rows, exactly as before (developer decision).
 *
 * Refusals carry both languages: the storefront shows the one the shopper is reading, which the
 * request's Accept-Language does not reliably say.
 */
final class CheckoutMethods
{
    public const UNAVAILABLE = 'payment_method_unavailable';

    public const BELOW_MINIMUM = 'payment_method_below_minimum';

    public const ABOVE_MAXIMUM = 'payment_method_above_maximum';

    /** @var array<int, bool> contract id → live, for the length of one request */
    private array $live = [];

    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * The methods a shopper may pick, in the storefront's order — what v2 serves. No provider is
     * named: the shopper picks a method, and which contract is behind it is the shop's business.
     *
     * @return list<array{id: int, method: string, label: array{ar: string, en: string}, icon: string|null, min_total: float|null, max_total: float|null}>
     */
    public function offered(int $storefrontId): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'method' => $row['method'],
            'label' => $row['label'],
            'icon' => $row['icon'],
            'min_total' => $row['min_total'],
            'max_total' => $row['max_total'],
        ], $this->rows($storefrontId));
    }

    /**
     * Why this order may not be paid the way it asks, or null when it may.
     *
     * @return array{code: string, messages: array{ar: string, en: string}}|null
     */
    public function refusal(int $storefrontId, ?string $postedId, string $legacyMethod, float $total): ?array
    {
        $legacy = mb_strtolower(trim($legacyMethod));
        $rows = $this->rows($storefrontId);

        if ($postedId === null || trim($postedId) === '') {
            if (! in_array($legacy, ['card', 'paymob'], true) || ! $this->paymobLive($storefrontId)) {
                return null;                    // cash, WhatsApp, or not cut over: exactly as before
            }
            foreach ($rows as $row) {
                if ($row['method'] === 'card' && ! $row['offline']) {
                    return self::limitRefusal($row, $total);
                }
            }

            return self::unavailable();
        }

        $id = ctype_digit(trim($postedId)) ? (int) trim($postedId) : null;
        foreach ($rows as $row) {
            if ($row['id'] !== $id) {
                continue;
            }
            $agrees = match ($legacy) {
                'cash' => $row['method'] === OfflineProvider::COD,
                'whatsapp' => $row['method'] === OfflineProvider::WHATSAPP,
                'card', 'paymob' => ! $row['offline']
                    && ! in_array($row['method'], [OfflineProvider::COD, OfflineProvider::WHATSAPP], true),
                default => false,
            };

            return $agrees ? self::limitRefusal($row, $total) : self::unavailable();
        }

        return self::unavailable();             // unknown, disabled, unusable or another shop's row
    }

    /** Whether this storefront's Paymob contract is live: enabled and holding complete credentials. */
    public function paymobLive(int $storefrontId): bool
    {
        $id = Coerce::nint(DB::table('storefront_payment_providers')
            ->where('storefront_id', $storefrontId)->where('provider', PaymobProvider::KEY)->value('id'));

        return $id !== null && $this->contractLive($id);
    }

    /**
     * @return list<array{id: int, method: string, label: array{ar: string, en: string}, icon: string|null, min_total: float|null, max_total: float|null, offline: bool}>
     */
    private function rows(int $storefrontId): array
    {
        $english = [];
        foreach (MethodList::forCustomer($storefrontId, 'en') as $row) {
            $english[$row['id']] = $row['label'];
        }
        $admin = [];
        foreach (MethodList::forAdmin($storefrontId, 'ar') as $row) {
            $admin[$row['id']] = $row;
        }

        $winners = MethodList::forCustomer($storefrontId, 'ar');
        $settings = self::settings(array_map(fn (array $row): int => $row['id'], $winners));

        $out = [];
        foreach ($winners as $row) {
            $full = $admin[$row['id']] ?? null;
            if ($full === null || $full['unusable'] !== null || ! $this->contractLive($full['provider_id'])) {
                continue;
            }
            // A row with no English label: forCustomer falls back to the KEY, and "bank_installment"
            // on the English site reads worse than the Arabic label does.
            $en = $english[$row['id']] ?? $row['method'];

            $out[] = [
                'id' => $row['id'],
                'method' => $row['method'],
                'label' => ['ar' => $row['label'], 'en' => $en === $row['method'] ? $row['label'] : $en],
                'icon' => $row['icon'],
                'min_total' => self::amount($settings[$row['id']]['min_total'] ?? null),
                'max_total' => self::amount($settings[$row['id']]['max_total'] ?? null),
                'offline' => in_array($full['provider'], [OfflineProvider::COD, OfflineProvider::WHATSAPP], true),
            ];
        }

        return $out;
    }

    private function contractLive(int $providerId): bool
    {
        if (! array_key_exists($providerId, $this->live)) {
            $contract = StorefrontPaymentProvider::query()->whereKey($providerId)->where('is_enabled', true)->first();
            $this->live[$providerId] = $contract instanceof StorefrontPaymentProvider
                && $this->registry->for($contract) !== null
                && $contract->credentialsComplete($this->registry->credentialFields(Coerce::str($contract->getAttribute('provider'))));
        }

        return $this->live[$providerId];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private static function settings(array $ids): array
    {
        $out = [];
        foreach (DB::table('storefront_payment_methods')->whereIn('id', $ids)->get(['id', 'settings']) as $raw) {
            $decoded = is_string($raw->settings ?? null) ? json_decode($raw->settings, true) : null;
            $settings = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $settings[(string) $key] = $value;
            }
            $out[Coerce::int($raw->id ?? 0)] = $settings;
        }

        return $out;
    }

    private static function amount(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * @param  array{label: array{ar: string, en: string}, min_total: float|null, max_total: float|null}  $row
     * @return array{code: string, messages: array{ar: string, en: string}}|null
     */
    private static function limitRefusal(array $row, float $total): ?array
    {
        $total = round($total, 2);
        if ($row['min_total'] !== null && $total < $row['min_total']) {
            $amount = self::money($row['min_total']);

            return ['code' => self::BELOW_MINIMUM, 'messages' => [
                'ar' => "الدفع عبر {$row['label']['ar']} متاح للطلبات من {$amount} ج.م فأكثر. لم يُسجَّل طلبك ولم يُخصم أي مبلغ — اختر طريقة دفع أخرى وأكمل الطلب.", // i18n-exempt: SHOPPER-facing checkout text, returned beside its own English ('en') and chosen by the storefront's language — not dashboard text; both halves asserted in CheckoutMethodsTest
                'en' => "Paying with {$row['label']['en']} is available on orders of EGP {$amount} or more. Your order hasn't been placed and nothing was charged — choose another payment method to complete it.",
            ]];
        }
        if ($row['max_total'] !== null && $total > $row['max_total']) {
            $amount = self::money($row['max_total']);

            return ['code' => self::ABOVE_MAXIMUM, 'messages' => [
                'ar' => "الدفع عبر {$row['label']['ar']} متاح للطلبات حتى {$amount} ج.م. لم يُسجَّل طلبك ولم يُخصم أي مبلغ — اختر طريقة دفع أخرى وأكمل الطلب.", // i18n-exempt: SHOPPER-facing checkout text, returned beside its own English ('en') and chosen by the storefront's language — not dashboard text; both halves asserted in CheckoutMethodsTest
                'en' => "Paying with {$row['label']['en']} is available on orders of up to EGP {$amount}. Your order hasn't been placed and nothing was charged — choose another payment method to complete it.",
            ]];
        }

        return null;
    }

    /** @return array{code: string, messages: array{ar: string, en: string}} */
    private static function unavailable(): array
    {
        return ['code' => self::UNAVAILABLE, 'messages' => [
            'ar' => 'طريقة الدفع هذه غير متاحة حاليًا. لم يُسجَّل طلبك ولم يُخصم أي مبلغ — اختر طريقة دفع أخرى وأكمل الطلب.', // i18n-exempt: SHOPPER-facing checkout text, returned beside its own English ('en') and chosen by the storefront's language — not dashboard text; both halves asserted in CheckoutMethodsTest
            'en' => "This payment method isn't available right now. Your order hasn't been placed and nothing was charged — choose another payment method to complete it.",
        ]];
    }

    private static function money(float $value): string
    {
        return number_format($value, floor($value) === $value ? 0 : 2);
    }
}
