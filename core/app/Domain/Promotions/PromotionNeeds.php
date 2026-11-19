<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * WHAT AN OFFER NEEDS IN THE CART, SAID PLAINLY (2026-11-19, the offer builder). A rule whose qualifying items are OTHER
 * items than the discounted ones — "30% off a wallet with any watch" — tells a shopper who has the wallet but not the
 * watch what to add, in both languages, naming the offer it unlocks: "Add an item from Watches to your cart to use this
 * offer: 30% off a wallet with any watch. Your code is kept." Read for the coupon status `needs_qualifier`
 * ({@see CouponStatus::messages()}), for a typed code and for a wheel code alike.
 */
final class PromotionNeeds
{
    /** The prefixes the wheel puts on its rules' labels; the sentence names the offer itself. */
    private const LABEL_PREFIXES = ['Spin the wheel: ', 'عجلة الحظ: ']; // i18n-exempt: STOREFRONT: the shopper sentence, both languages returned for the page to pick

    /**
     * @return array{needs_en: string, needs_ar: string}
     */
    public static function forRule(int $ruleId, ?string $labelEn = null, ?string $labelAr = null): array
    {
        $rule = DB::table('promotion_rules')->where('id', $ruleId)->first(['name', 'label_en', 'label_ar']);
        $name = $rule === null ? '' : Row::str(Row::cast($rule), 'name');
        $labelEn = self::bare($labelEn ?? ($rule === null ? null : Row::nstr(Row::cast($rule), 'label_en'))) ?? $name;
        $labelAr = self::bare($labelAr ?? ($rule === null ? null : Row::nstr(Row::cast($rule), 'label_ar'))) ?? $labelEn;
        $things = self::qualifierNames($ruleId);
        $en = $things['en'] === [] ? 'the qualifying item' : self::thing($things, 'en');
        $ar = $things['ar'] === [] ? 'المنتج المطلوب' : self::thing($things, 'ar'); // i18n-exempt: STOREFRONT: the shopper sentence, both languages returned for the page to pick

        return [
            'needs_en' => "Add {$en} to your cart to use this offer: {$labelEn}. Your code is kept.",
            'needs_ar' => "أضف {$ar} إلى سلتك لتستخدم هذا العرض: {$labelAr}. كودك محفوظ.", // i18n-exempt: STOREFRONT — the shopper's sentence, both languages returned side by side for the page to pick
        ];
    }

    /**
     * The qualifying scope's names, per language: categories and brands as groups ("an item from Watches"), products by
     * their titles.
     *
     * @return array{en: list<string>, ar: list<string>, products: bool}
     */
    public static function qualifierNames(int $ruleId): array
    {
        $qualify = PromotionScopes::of($ruleId)[PromotionScopes::ROLE_QUALIFY] ?? [];
        $out = ['en' => [], 'ar' => [], 'products' => false];
        $add = function (iterable $rows) use (&$out): void {
            $by = [];
            foreach ($rows as $raw) {
                if (! is_object($raw)) {
                    continue;
                }
                $row = Row::cast($raw);
                $by[Row::int($row, 'id')][Row::str($row, 'locale')] = Row::nstr($row, 'name');
            }
            foreach ($by as $names) {
                $out['en'][] = $names['en'] ?? $names['ar'] ?? '';
                $out['ar'][] = $names['ar'] ?? $names['en'] ?? '';
            }
        };
        $nodes = array_merge($qualify['category'] ?? [], $qualify['sub_type'] ?? []);
        if ($nodes !== []) {
            $add(DB::table('storefront_category_translations')->whereIn('storefront_category_id', $nodes)->whereIn('locale', ['en', 'ar'])
                ->get(['storefront_category_id as id', 'locale', 'name']));
        }
        if (($qualify['brand'] ?? []) !== []) {
            $add(DB::table('catalog_brand_translations')->whereIn('brand_id', $qualify['brand'])->whereIn('locale', ['en', 'ar'])
                ->get(['brand_id as id', 'locale', 'name']));
        }
        if (($qualify['product'] ?? []) !== []) {
            $out['products'] = true;
            $add(DB::table('catalog_product_translations')->whereIn('product_id', $qualify['product'])->whereIn('locale', ['en', 'ar'])
                ->get(['product_id as id', 'locale', 'title as name']));
        }
        $out['en'] = array_values(array_filter($out['en'], fn (string $n): bool => $n !== ''));
        $out['ar'] = array_values(array_filter($out['ar'], fn (string $n): bool => $n !== ''));

        return $out;
    }

    /** @param  array{en: list<string>, ar: list<string>, products: bool}  $things */
    private static function thing(array $things, string $lang): string
    {
        $names = $lang === 'ar' ? $things['ar'] : $things['en'];
        if ($things['products']) {
            return $lang === 'ar' ? implode(' أو ', $names) : implode(' or ', $names); // i18n-exempt: STOREFRONT: the shopper sentence, both languages returned for the page to pick
        }
        $list = $lang === 'ar' ? implode(' أو ', $names) : implode(' or ', $names); // i18n-exempt: STOREFRONT: the shopper sentence, both languages returned for the page to pick

        return $lang === 'ar' ? "منتجًا من {$list}" : "an item from {$list}"; // i18n-exempt: STOREFRONT: the shopper sentence, both languages returned for the page to pick
    }

    private static function bare(?string $label): ?string
    {
        $label = $label === null ? '' : trim($label);
        foreach (self::LABEL_PREFIXES as $prefix) {
            if (str_starts_with($label, $prefix)) {
                $label = trim(substr($label, strlen($prefix)));
            }
        }

        return $label === '' ? null : $label;
    }
}
