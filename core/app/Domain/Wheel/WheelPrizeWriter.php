<?php

declare(strict_types=1);

namespace App\Domain\Wheel;

use App\Domain\Activity\ActivityLog;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The dashboard's door for the wheel's prizes and terms (2026-11-08; the client's prize types, 2026-11-09). The rules
 * are HERE, so a console caller or a seeder is refused exactly as the screen is (the one-door rule of the promotions
 * writer):
 *
 *  - four to twelve segments, every one of a known type;
 *  - the odds are whole per cent and SUM TO 100 — the screen shows the live total, this refuses anything else;
 *  - a percentage is 1…100 with an optional cap in EGP, an optional category (of this storefront) with an optional
 *    minimum number of items, and a mode (`order` or `cheapest`; `cheapest` needs a category and a minimum of 2);
 *  - a fixed amount is above zero; free shipping and "better luck" carry no value;
 *  - a referral prize is the percentage BOTH get (1…100) with an optional cap;
 *  - a gift names one or more products of this storefront (ids); whether they are in stock is the spin's business;
 *  - both labels present; the colour a #rrggbb;
 *  - the validity 1…720 hours (default 48); the minimum order zero or more (empty = none).
 *
 * Saving REPLACES the storefront's rows (a segment is a line of the wheel, not a thing an order points at: a spin
 * snapshots the terms it was won with, so a replaced row changes only the next spins). Every save is activity-logged
 * with the before and the after.
 */
final class WheelPrizeWriter
{
    public const MAX_HOURS = 720;

    public const MAX_GIFTS = 30;

    /**
     * @param  list<array<string, mixed>>  $segments  the screen's rows, in wheel order
     * @param  array{ttl_hours?: mixed, min_order?: mixed}  $terms
     *
     * @throws ValidationException with the field the operator must fix
     */
    public static function save(int $storefrontId, array $segments, array $terms, ?int $actorId): void
    {
        [$rows, $ttl, $minOrder] = self::validated($storefrontId, $segments, $terms);

        $before = ['prizes' => WheelPrizes::forStorefront($storefrontId)] + Wheel::settings($storefrontId);

        DB::transaction(function () use ($storefrontId, $rows, $ttl, $minOrder): void {
            DB::table(WheelPrizes::TABLE)->where('storefront_id', $storefrontId)->delete();
            foreach ($rows as $position => $row) {
                DB::table(WheelPrizes::TABLE)->insert($row + [
                    'storefront_id' => $storefrontId,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Wheel::setTerms($storefrontId, $ttl, $minOrder);
        });

        $after = ['prizes' => WheelPrizes::forStorefront($storefrontId)] + Wheel::settings($storefrontId);
        ActivityLog::record(WheelPrizes::TABLE, $storefrontId, ActivityLog::UPDATED, $before, $after,
            label: ManageText::t('wheel.title', 'عجلة الحظ'), storefrontId: $storefrontId);
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  array{ttl_hours?: mixed, min_order?: mixed}  $terms
     * @return array{0: list<array<string, mixed>>, 1: int, 2: float|null}
     */
    private static function validated(int $storefrontId, array $segments, array $terms): array
    {
        $errors = [];
        $count = count($segments);
        if ($count < WheelPrizes::MIN_SEGMENTS || $count > WheelPrizes::MAX_SEGMENTS) {
            $errors['segments'] = ManageText::t('wheel.segments_count', 'العجلة تحتاج من :min إلى :max جائزة.', ['min' => WheelPrizes::MIN_SEGMENTS, 'max' => WheelPrizes::MAX_SEGMENTS]);
        }

        $rows = [];
        foreach ($segments as $i => $segment) {
            $type = trim(Coerce::str($segment['type'] ?? ''));
            $labelEn = trim(Coerce::str($segment['label_en'] ?? ''));
            $labelAr = trim(Coerce::str($segment['label_ar'] ?? ''));
            $odds = Coerce::nint($segment['odds'] ?? null);
            $value = Coerce::nfloat($segment['value'] ?? null);
            $cap = Coerce::nfloat($segment['cap'] ?? null);
            $color = strtoupper(trim(Coerce::str($segment['color'] ?? '')));
            $category = Coerce::nint($segment['scope_category_id'] ?? null);
            $minQuantity = Coerce::nint($segment['min_quantity'] ?? null);
            $mode = trim(Coerce::str($segment['apply_mode'] ?? WheelPrizes::MODE_ORDER));
            $gifts = self::giftIds($segment['gift_product_ids'] ?? null);

            if (! in_array($type, WheelPrizes::TYPES, true)) {
                $errors["segments.{$i}.type"] = ManageText::t('wheel.type_unknown', 'نوع الجائزة غير معروف.');
            }
            if ($labelEn === '' || mb_strlen($labelEn) > 80) {
                $errors["segments.{$i}.label_en"] = ManageText::t('wheel.label_required', 'اكتب اسم الجائزة (حتى 80 حرفًا).');
            }
            if ($labelAr === '' || mb_strlen($labelAr) > 80) {
                $errors["segments.{$i}.label_ar"] = ManageText::t('wheel.label_required', 'اكتب اسم الجائزة (حتى 80 حرفًا).');
            }
            if ($odds === null || $odds < 1 || $odds > 100) {
                $errors["segments.{$i}.odds"] = ManageText::t('wheel.odds_range', 'النسبة عدد صحيح من 1 إلى 100.');
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                $errors["segments.{$i}.color"] = ManageText::t('wheel.color_hex', 'اللون بصيغة #rrggbb.');
            }

            if ($type === 'percent_discount' || $type === 'referral') {
                if ($value === null || $value <= 0 || $value > 100) {
                    $errors["segments.{$i}.value"] = ManageText::t('wheel.percent_range', 'النسبة المئوية من 1 إلى 100.');
                }
                if ($cap !== null && $cap <= 0) {
                    $errors["segments.{$i}.cap"] = ManageText::t('wheel.cap_positive', 'الحد الأقصى بالجنيه أكبر من صفر، أو اتركه فارغًا.');
                }
            } elseif ($type === 'fixed_discount') {
                if ($value === null || $value <= 0) {
                    $errors["segments.{$i}.value"] = ManageText::t('wheel.amount_positive', 'المبلغ بالجنيه أكبر من صفر.');
                }
                $cap = null;
            } else {
                $value = null;
                $cap = null;
            }

            // the scope: a percentage's category, its minimum, its mode
            if ($type === 'percent_discount') {
                if ($category !== null && ! DB::table('storefront_categories')->where('id', $category)->where('storefront_id', $storefrontId)->exists()) {
                    $errors["segments.{$i}.scope_category_id"] = ManageText::t('wheel.category_unknown', 'اختر تصنيفًا من قائمة هذا المتجر.');
                }
                if ($minQuantity !== null && ($minQuantity < 1 || $minQuantity > 20)) {
                    $errors["segments.{$i}.min_quantity"] = ManageText::t('wheel.min_quantity_range', 'الحد الأدنى للقطع من 1 إلى 20، أو اتركه فارغًا.');
                }
                if ($minQuantity !== null && $category === null) {
                    $errors["segments.{$i}.min_quantity"] = ManageText::t('wheel.min_quantity_needs_category', 'الحد الأدنى للقطع يحتاج تصنيفًا.');
                }
                if (! in_array($mode, WheelPrizes::MODES, true)) {
                    $errors["segments.{$i}.apply_mode"] = ManageText::t('wheel.mode_unknown', 'طريقة التطبيق غير معروفة.');
                } elseif ($mode === WheelPrizes::MODE_CHEAPEST && ($category === null || ($minQuantity ?? 0) < 2)) {
                    $errors["segments.{$i}.apply_mode"] = ManageText::t('wheel.cheapest_needs', '«الأرخص» يحتاج تصنيفًا وحدًا أدنى قطعتين على الأقل.');
                }
            } else {
                $category = null;
                $minQuantity = null;
                $mode = WheelPrizes::MODE_ORDER;
            }

            // the gift list
            // an EMPTY gift list is allowed (the seed, a list being prepared): that prize is simply not drawn, and the
            // dashboard says so beside it
            if ($type === 'gift') {
                if (count($gifts) > self::MAX_GIFTS) {
                    $errors["segments.{$i}.gift_product_ids"] = ManageText::t('wheel.gift_too_many', 'قائمة الهدية حتى :max منتجًا.', ['max' => self::MAX_GIFTS]);
                } else {
                    $known = DB::table('storefront_product')->where('storefront_id', $storefrontId)->whereIn('product_id', $gifts)->pluck('product_id');
                    $knownIds = array_map(fn (mixed $id): int => Coerce::int($id), $known->all());
                    $missing = array_values(array_diff($gifts, $knownIds));
                    if ($missing !== []) {
                        $errors["segments.{$i}.gift_product_ids"] = ManageText::t('wheel.gift_unknown', 'منتج غير موجود في هذا المتجر: :ids.', ['ids' => implode(', ', $missing)]);
                    }
                }
            } else {
                $gifts = [];
            }

            $rows[] = [
                'label_en' => $labelEn,
                'label_ar' => $labelAr,
                'type' => $type,
                'value' => $value === null ? null : number_format($value, 2, '.', ''),
                'odds' => $odds ?? 0,
                'color' => $color,
                'cap' => $cap === null ? null : number_format($cap, 2, '.', ''),
                'scope_category_id' => $category,
                'min_quantity' => $minQuantity,
                'apply_mode' => $mode,
                'gift_product_ids' => $gifts === [] ? null : json_encode($gifts),
            ];
        }

        $total = WheelPrizes::oddsTotal($rows);
        if ($count > 0 && $total !== 100) {
            $errors['odds_total'] = ManageText::t('wheel.odds_total', 'مجموع النسب :total%. يجب أن يكون 100% بالضبط.', ['total' => $total]);
        }

        $ttl = Coerce::nint($terms['ttl_hours'] ?? null);
        if ($ttl === null || $ttl < 1 || $ttl > self::MAX_HOURS) {
            $errors['ttl_hours'] = ManageText::t('wheel.ttl_range', 'مدة صلاحية الكود من 1 إلى :max ساعة.', ['max' => self::MAX_HOURS]);
        }
        $minRaw = $terms['min_order'] ?? null;
        $minOrder = $minRaw === null || trim(Coerce::str($minRaw)) === '' ? null : Coerce::nfloat($minRaw);
        if ($minRaw !== null && trim(Coerce::str($minRaw)) !== '' && ($minOrder === null || $minOrder < 0)) {
            $errors['min_order'] = ManageText::t('wheel.min_order_range', 'الحد الأدنى للطلب صفر أو أكثر، أو اتركه فارغًا.');
        }
        if ($minOrder !== null && $minOrder <= 0) {
            $minOrder = null;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$rows, $ttl ?? Wheel::DEFAULT_TTL_HOURS, $minOrder];
    }

    /**
     * The screen sends the gift list as an array of ids or as a comma-separated text; a seeder as a JSON string.
     *
     * @return list<int>
     */
    private static function giftIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                return WheelPrizes::idList($trimmed);
            }
            $raw = preg_split('/[\s,]+/', $trimmed) ?: [];
        }
        $out = [];
        foreach (Coerce::arr($raw) as $id) {
            $int = Coerce::nint($id);
            if ($int !== null && $int > 0 && ! in_array($int, $out, true)) {
                $out[] = $int;
            }
        }

        return $out;
    }
}
