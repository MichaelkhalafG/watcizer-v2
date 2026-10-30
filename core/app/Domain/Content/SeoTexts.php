<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Activity\ActivityLog;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * The introduction and meta description of a BRAND page and a CATEGORY page, per storefront and language (M1y,
 * 2026-10-30). Read by the v2 listing (it rides on the listing answer the facet pages already fetch, as
 * `meta.context`), written by the dashboard's "Page texts" screen and by `seo:seed-texts`.
 */
final class SeoTexts
{
    public const TABLE = 'core_seo_texts';

    /** @var list<string> */
    public const TYPES = ['brand', 'category'];

    /** @var list<string> */
    public const LOCALES = ['ar', 'en'];

    /** @return array{type: string, key: string, description: string|null, meta_description: string|null}|null */
    public static function for(int $storefrontId, string $type, string $key, string $locale): ?array
    {
        $row = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('subject_type', $type)
            ->where('subject_key', $key)->where('locale', $locale)->first(['description', 'meta_description']);
        if (! is_object($row)) {
            return null;
        }
        $r = Row::cast($row);
        $description = self::clean(Row::nstr($r, 'description'));
        $meta = self::clean(Row::nstr($r, 'meta_description'));
        if ($description === null && $meta === null) {
            return null;
        }

        return ['type' => $type, 'key' => $key, 'description' => $description, 'meta_description' => $meta];
    }

    /**
     * Both languages of one page's text at once, for the v2 listing's `meta.context` (one query). The storefront asks
     * the listing in one language and renders the page in either, so the answer carries both. Null when neither
     * language has anything.
     *
     * @return array{type: string, key: string, en: array{description: string|null, meta_description: string|null}|null, ar: array{description: string|null, meta_description: string|null}|null}|null
     */
    public static function bilingual(int $storefrontId, string $type, string $key): ?array
    {
        $out = ['type' => $type, 'key' => $key, 'en' => null, 'ar' => null];
        $rows = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('subject_type', $type)
            ->where('subject_key', $key)->get(['locale', 'description', 'meta_description']);
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $locale = Row::str($r, 'locale');
            $description = self::clean(Row::nstr($r, 'description'));
            $meta = self::clean(Row::nstr($r, 'meta_description'));
            if (($locale === 'en' || $locale === 'ar') && ($description !== null || $meta !== null)) {
                $out[$locale] = ['description' => $description, 'meta_description' => $meta];
            }
        }

        return $out['en'] === null && $out['ar'] === null ? null : $out;
    }

    /**
     * Every text of a storefront, `type => key => locale => {description, meta_description}`, for the dashboard.
     *
     * @return array<string, array<string, array<string, array{description: string|null, meta_description: string|null}>>>
     */
    public static function all(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('storefront_id', $storefrontId)->get() as $raw) {
            $r = Row::cast($raw);
            $out[Row::str($r, 'subject_type')][Row::str($r, 'subject_key')][Row::str($r, 'locale')] = [
                'description' => Row::nstr($r, 'description'),
                'meta_description' => Row::nstr($r, 'meta_description'),
            ];
        }

        return $out;
    }

    /**
     * Save both languages of one page's text; an empty field is stored as null. Logged, old → new.
     *
     * @param  array<string, array{description?: string|null, meta_description?: string|null}>  $byLocale
     */
    public static function save(int $storefrontId, string $type, string $key, array $byLocale, ?int $actorId, ?string $label = null): void
    {
        foreach (self::LOCALES as $locale) {
            if (! array_key_exists($locale, $byLocale)) {
                continue;
            }
            $where = ['storefront_id' => $storefrontId, 'subject_type' => $type, 'subject_key' => $key, 'locale' => $locale];
            $beforeRow = DB::table(self::TABLE)->where($where)->first(['description', 'meta_description']);
            $before = is_object($beforeRow) ? ['description' => Row::nstr(Row::cast($beforeRow), 'description'),
                'meta_description' => Row::nstr(Row::cast($beforeRow), 'meta_description')] : ['description' => null, 'meta_description' => null];
            $after = [
                'description' => self::clean(Coerce::nstr($byLocale[$locale]['description'] ?? null)),
                'meta_description' => self::clean(Coerce::nstr($byLocale[$locale]['meta_description'] ?? null)),
            ];
            DB::table(self::TABLE)->updateOrInsert($where, $after + ['updated_by' => $actorId, 'updated_at' => now(),
                'created_at' => is_object($beforeRow) ? DB::raw('created_at') : now()]);
            ActivityLog::record(self::TABLE, null, ActivityLog::UPDATED,
                ['description_'.$locale => $before['description'], 'meta_description_'.$locale => $before['meta_description']],
                ['description_'.$locale => $after['description'], 'meta_description_'.$locale => $after['meta_description']],
                label: $label ?? ($type.': '.$key), storefrontId: $storefrontId);
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
