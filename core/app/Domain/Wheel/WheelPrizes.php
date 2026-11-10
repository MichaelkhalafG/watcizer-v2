<?php

declare(strict_types=1);

namespace App\Domain\Wheel;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The wheel's PRIZES — the segments the team authors on the dashboard (Michael, 2026-11-08; the client's final list,
 * 2026-11-09). Per storefront, in wheel order: a label in both languages, a TYPE, a value, odds in whole per cent, a
 * colour, and the type's own terms. The writer ({@see WheelPrizeWriter}) refuses anything outside the rules, so this
 * reader can trust what it finds.
 *
 * THE TYPES:
 *   percent_discount   `value` %, optional `cap` EGP; optionally scoped to ONE category (and its children) with a
 *                      minimum number of items in it, and `apply_mode`: `order` (off every eligible item) or
 *                      `cheapest` (off ONE unit, the cheapest eligible — "10 % off the 2nd watch" is percent 10,
 *                      category watches, min quantity 2, cheapest)
 *   fixed_discount     `value` EGP off the order
 *   free_shipping      the delivery charged
 *   none               "better luck" — no prize, no code; the phone and the device have spun for this round
 *   referral           "invite a friend" — the winner gets an invite code; a friend's FIRST order gets `value` % off
 *                      (seven days); when that order is DELIVERED the winner gets a `value` % code of their own (48 h)
 *   gift               a free product from the prize's LIST (`gift_product_ids`), one that is in stock, chosen at the
 *                      spin; a list with nothing in stock is NOT DRAWN — its odds are spread over the others
 *
 * Not every spin wins: `none` is a real segment. The storefront-level terms — the code's validity, an optional
 * minimum order, the round — live in `storefronts.settings.wheel` ({@see Wheel::settings()}).
 */
final class WheelPrizes
{
    public const TABLE = 'storefront_wheel_prizes';

    /** @var list<string> */
    public const TYPES = ['percent_discount', 'fixed_discount', 'free_shipping', 'none', 'referral', 'gift'];

    public const MODE_ORDER = 'order';

    public const MODE_CHEAPEST = 'cheapest';

    /** @var list<string> */
    public const MODES = [self::MODE_ORDER, self::MODE_CHEAPEST];

    public const MIN_SEGMENTS = 4;

    public const MAX_SEGMENTS = 12;

    /** Days a friend has to place their first order with an invite. */
    public const INVITE_DAYS = 7;

    /**
     * The client's seven prizes (2026-11-09): the first seed of every storefront's wheel. Odds sum to 100. The
     * percentage prizes are capped at EGP 1,500. The scoped prizes name their category by SLUG (`seededCategory()`
     * resolves it on the storefront at seed time; absent, the prize is seeded without a category and the dashboard
     * says it needs one). The gift lists are the team's: seeded empty, so those prizes are not drawn until the team
     * fills them.
     *
     * @var list<array{label_en: string, label_ar: string, type: string, value: float|null, odds: int, color: string, cap: float|null, scope_slug: string|null, min_quantity: int|null, apply_mode: string}>
     */
    public const DEFAULTS = [
        ['label_en' => 'Free shipping', 'label_ar' => 'شحن مجاني', 'type' => 'free_shipping', 'value' => null, 'odds' => 25, 'color' => '#0F9D6E', 'cap' => null, 'scope_slug' => null, 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => '10% off the 2nd watch', 'label_ar' => 'خصم ١٠٪ على الساعة الثانية', 'type' => 'percent_discount', 'value' => 10.0, 'odds' => 20, 'color' => '#1F4FD8', 'cap' => 1500.0, 'scope_slug' => 'watches', 'min_quantity' => 2, 'apply_mode' => 'cheapest'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => 'Better luck next time', 'label_ar' => 'حظًا أوفر المرة القادمة', 'type' => 'none', 'value' => null, 'odds' => 20, 'color' => '#6B7280', 'cap' => null, 'scope_slug' => null, 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => '30% off wallets', 'label_ar' => 'خصم ٣٠٪ على المحافظ', 'type' => 'percent_discount', 'value' => 30.0, 'odds' => 15, 'color' => '#C8102E', 'cap' => 1500.0, 'scope_slug' => 'wallets', 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => 'Invite a friend: 15% for both', 'label_ar' => 'ادعُ صديقًا: خصم ١٥٪ لكما', 'type' => 'referral', 'value' => 15.0, 'odds' => 10, 'color' => '#7B3FE4', 'cap' => 1500.0, 'scope_slug' => null, 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => 'Free perfume gift', 'label_ar' => 'هدية: عطر مجاني', 'type' => 'gift', 'value' => null, 'odds' => 5, 'color' => '#E0457B', 'cap' => null, 'scope_slug' => null, 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
        ['label_en' => 'Free sunglasses gift', 'label_ar' => 'هدية: نظارة شمسية مجانية', 'type' => 'gift', 'value' => null, 'odds' => 5, 'color' => '#F28C28', 'cap' => null, 'scope_slug' => null, 'min_quantity' => null, 'apply_mode' => 'order'], // i18n-exempt: DATA — a prize's own Arabic name, seeded once, edited on the dashboard
    ];

    /** The storefront's category with this slug (any depth), for the seed; null when the storefront has none. */
    public static function seededCategory(int $storefrontId, ?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        return Coerce::nint(DB::table('storefront_categories')->where('storefront_id', $storefrontId)->where('slug', $slug)->orderBy('depth')->value('id'));
    }

    /**
     * A storefront's prizes, in wheel order, each with its terms — and, for a gift, the products of its list that are
     * in stock on the storefront (`gift_available`), so the spin and the dashboard know what can be drawn.
     *
     * @return list<array{id: int, position: int, label_en: string, label_ar: string, type: string, value: float|null, odds: int, color: string, cap: float|null, scope_category_id: int|null, min_quantity: int|null, apply_mode: string, gift_product_ids: list<int>, gift_available: list<int>}>
     */
    public static function forStorefront(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('storefront_id', $storefrontId)->orderBy('position')->orderBy('id')->get() as $raw) {
            $row = Row::cast($raw);
            $type = Row::str($row, 'type');
            $gifts = self::idList(Row::nstr($row, 'gift_product_ids'));
            $mode = Row::nstr($row, 'apply_mode') ?? self::MODE_ORDER;
            $out[] = [
                'id' => Row::int($row, 'id'),
                'position' => Row::int($row, 'position'),
                'label_en' => Row::str($row, 'label_en'),
                'label_ar' => Row::str($row, 'label_ar'),
                'type' => $type,
                'value' => Row::nfloat($row, 'value'),
                'odds' => Row::int($row, 'odds'),
                'color' => Row::str($row, 'color'),
                'cap' => Row::nfloat($row, 'cap'),
                'scope_category_id' => Row::nint($row, 'scope_category_id'),
                'min_quantity' => Row::nint($row, 'min_quantity'),
                'apply_mode' => in_array($mode, self::MODES, true) ? $mode : self::MODE_ORDER,
                'gift_product_ids' => $gifts,
                'gift_available' => $type === 'gift' ? self::inStock($storefrontId, $gifts) : [],
            ];
        }

        return $out;
    }

    /**
     * The prizes that CAN be drawn today: every prize but a gift whose list has nothing in stock. A prize list is drawn
     * over these with each prize's own odds as its weight — so an undrawable gift's odds spread over the others in
     * proportion (Michael, 2026-11-09).
     *
     * @param  list<array<string, mixed>>  $prizes  {@see self::forStorefront()}
     * @return list<array<string, mixed>>
     */
    public static function drawable(array $prizes): array
    {
        return array_values(array_filter($prizes, fn (array $p): bool => ! self::isUndrawable($p)));
    }

    /** @param  array<string, mixed>  $prize */
    public static function isUndrawable(array $prize): bool
    {
        return Coerce::str($prize['type'] ?? '') === 'gift' && Coerce::arr($prize['gift_available'] ?? []) === [];
    }

    /**
     * The sum of the odds of a list of prizes.
     *
     * @param  list<array<string, mixed>>  $prizes
     */
    public static function oddsTotal(array $prizes): int
    {
        $total = 0;
        foreach ($prizes as $prize) {
            $total += Coerce::int($prize['odds'] ?? null);
        }

        return $total;
    }

    /**
     * The prize for a roll over a list of prizes weighted by their odds — the roll from `random_int()` on the SERVER
     * (the browser only animates to the segment it is told). Explicit so a test can name a roll (1…total) and know
     * the prize. Returns the prize AND its index IN THE LIST GIVEN (callers map it onto the wheel's segments).
     *
     * @param  list<array<string, mixed>>  $prizes
     * @return array{index: int, prize: array<string, mixed>}
     */
    public static function pick(array $prizes, ?int $roll = null): array
    {
        if ($prizes === []) {
            throw new InvalidArgumentException('the wheel has no prizes to draw');
        }
        $total = self::oddsTotal($prizes);
        if ($total < 1) {
            throw new InvalidArgumentException('the prizes have no odds');
        }
        $roll ??= random_int(1, $total);
        if ($roll < 1 || $roll > $total) {
            throw new InvalidArgumentException('a roll is 1…'.$total);
        }
        $edge = 0;
        foreach ($prizes as $index => $prize) {
            $edge += Coerce::int($prize['odds'] ?? null);
            if ($roll <= $edge) {
                return ['index' => $index, 'prize' => $prize];
            }
        }

        return ['index' => count($prizes) - 1, 'prize' => $prizes[count($prizes) - 1]];
    }

    /**
     * The segments as the storefront draws them — WITHOUT the odds, which are the shop's business, and with what a
     * shopper needs to read the prize (the type, the value, the colour, whether it can be drawn today).
     *
     * @return list<array{id: int, label: array{en: string, ar: string}, type: string, value: float|null, color: string, cap: float|null}>
     */
    public static function segments(int $storefrontId): array
    {
        $out = [];
        // a prize that cannot be drawn right now (a gift list with nothing in stock) is NOT on the wheel a visitor sees
        // (Michael, 2026-11-09 evening: nobody is shown a prize they cannot win); the spin's index counts the same list
        foreach (self::forStorefront($storefrontId) as $prize) {
            if (self::isUndrawable($prize)) {
                continue;
            }
            $out[] = [
                'id' => $prize['id'],
                'label' => ['en' => $prize['label_en'], 'ar' => $prize['label_ar']],
                'type' => $prize['type'],
                'value' => $prize['value'],
                'color' => $prize['color'],
                'cap' => $prize['cap'],
            ];
        }

        return $out;
    }

    /**
     * The position of a prize id on the wheel, for the disc to turn to; null when the prize is gone.
     *
     * @param  list<array<string, mixed>>  $prizes
     */
    public static function indexOf(array $prizes, ?int $prizeId): ?int
    {
        foreach ($prizes as $index => $prize) {
            if ($prizeId !== null && Coerce::int($prize['id'] ?? null) === $prizeId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Of a gift list, the products a shopper could receive today: visible on the storefront, active, and with stock in
     * one bucket. The same reading the engine makes for a free-product reward (PromotionEngine::deliverable), batched.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public static function inStock(int $storefrontId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $rows = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->whereIn('p.id', $productIds)
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('sp.is_visible', 1)
            ->where(function (Builder $q): void {
                $q->where('p.stock_express', '>', 0)->orWhere('p.stock_market', '>', 0);
            })
            ->pluck('p.id');
        $available = array_map(fn (mixed $id): int => Coerce::int($id), $rows->all());
        // the list's own order, so "the first in stock" is the team's first choice
        $out = [];
        foreach ($productIds as $id) {
            if (in_array($id, $available, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** @return list<int> */
    public static function idList(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            $int = Coerce::nint($id);
            if ($int !== null && $int > 0 && ! in_array($int, $out, true)) {
                $out[] = $int;
            }
        }

        return $out;
    }
}
