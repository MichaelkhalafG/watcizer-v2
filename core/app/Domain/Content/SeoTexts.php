<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Activity\ActivityLog;
use App\Domain\Seo\IndexNow;
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

    /**
     * 'landing' (SEO Phase 2, 2026-11-14): a brand × gender / family page, keyed `brand/family/gender`
     * (App\Domain\Seo\LandingPages).
     *
     * @var list<string>
     */
    public const TYPES = ['brand', 'category', 'landing'];

    /** The quality gate's two verdicts (App\Domain\Content\QualityGate). */
    public const PASSED = 'passed';

    public const HELD = 'held';

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
     * `intro` (2026-11-14) is the text above the products; `gate` the quality gate's verdict on that language ('passed',
     * 'held' or null — never checked, or changed since).
     *
     * @return array{type: string, key: string, en: array{intro: string|null, description: string|null, meta_description: string|null, gate: string|null}|null, ar: array{intro: string|null, description: string|null, meta_description: string|null, gate: string|null}|null}|null
     */
    public static function bilingual(int $storefrontId, string $type, string $key): ?array
    {
        $out = ['type' => $type, 'key' => $key, 'en' => null, 'ar' => null];
        $rows = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('subject_type', $type)
            ->where('subject_key', $key)->get(['locale', 'intro', 'description', 'meta_description', 'gate_status']);
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $locale = Row::str($r, 'locale');
            $intro = self::clean(Row::nstr($r, 'intro'));
            $description = self::clean(Row::nstr($r, 'description'));
            $meta = self::clean(Row::nstr($r, 'meta_description'));
            if (($locale === 'en' || $locale === 'ar') && ($intro !== null || $description !== null || $meta !== null)) {
                $out[$locale] = ['intro' => $intro, 'description' => $description, 'meta_description' => $meta, 'gate' => Row::nstr($r, 'gate_status')];
            }
        }

        return $out['en'] === null && $out['ar'] === null ? null : $out;
    }

    /**
     * Every text of a storefront, `type => key => locale => {description, meta_description}`, for the dashboard.
     *
     * @return array<string, array<string, array<string, array{intro: string|null, description: string|null, meta_description: string|null, gate: string|null, gate_reasons: list<string>}>>>
     */
    public static function all(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('storefront_id', $storefrontId)->get() as $raw) {
            $r = Row::cast($raw);
            $out[Row::str($r, 'subject_type')][Row::str($r, 'subject_key')][Row::str($r, 'locale')] = [
                'intro' => Row::nstr($r, 'intro'),
                'description' => Row::nstr($r, 'description'),
                'meta_description' => Row::nstr($r, 'meta_description'),
                'gate' => Row::nstr($r, 'gate_status'),
                'gate_reasons' => self::reasons(Row::nstr($r, 'gate_report')),
            ];
        }

        return $out;
    }

    /**
     * Save both languages of one page's text; an empty field is stored as null. Logged, old → new. `intro` is written only
     * when the caller sends it (a caller that never knew it keeps it). A language whose words changed loses its gate
     * verdict: it is checked again before a landing page can be indexed on it.
     *
     * @param  array<string, array{intro?: string|null, description?: string|null, meta_description?: string|null}>  $byLocale
     */
    public static function save(int $storefrontId, string $type, string $key, array $byLocale, ?int $actorId, ?string $label = null): void
    {
        foreach (self::LOCALES as $locale) {
            if (! array_key_exists($locale, $byLocale)) {
                continue;
            }
            $where = ['storefront_id' => $storefrontId, 'subject_type' => $type, 'subject_key' => $key, 'locale' => $locale];
            $beforeRow = DB::table(self::TABLE)->where($where)->first(['intro', 'description', 'meta_description']);
            $old = is_object($beforeRow) ? Row::cast($beforeRow) : null;
            $before = ['description' => $old === null ? null : Row::nstr($old, 'description'),
                'meta_description' => $old === null ? null : Row::nstr($old, 'meta_description')];
            $after = [
                'description' => self::clean(Coerce::nstr($byLocale[$locale]['description'] ?? null)),
                'meta_description' => self::clean(Coerce::nstr($byLocale[$locale]['meta_description'] ?? null)),
            ];
            $intro = array_key_exists('intro', $byLocale[$locale])
                ? self::clean(Coerce::nstr($byLocale[$locale]['intro']))
                : ($old === null ? null : Row::nstr($old, 'intro'));
            $changed = $old === null || $after['description'] !== Row::nstr($old, 'description')
                || $after['meta_description'] !== Row::nstr($old, 'meta_description') || $intro !== Row::nstr($old, 'intro');
            DB::table(self::TABLE)->updateOrInsert($where, $after + ['intro' => $intro, 'updated_by' => $actorId, 'updated_at' => now(),
                'created_at' => $old !== null ? DB::raw('created_at') : now()]
                + ($changed ? ['gate_status' => null, 'gate_report' => null, 'gate_checked_at' => null] : []));
            // IndexNow (2026-11-14): the page whose text changed — queued, sent after go-live
            $path = $type === 'brand' || $type === 'landing' ? '/brand/'.$key : ($type === 'category' ? (str_contains($key, '/') ? '/subtypes/'.substr((string) strrchr($key, '/'), 1) : '/category/'.$key) : null);
            if ($path !== null) {
                app(IndexNow::class)->queue($storefrontId, [$path], 'page text changed');
            }
            ActivityLog::record(self::TABLE, null, ActivityLog::UPDATED,
                ['description_'.$locale => $before['description'], 'meta_description_'.$locale => $before['meta_description']],
                ['description_'.$locale => $after['description'], 'meta_description_'.$locale => $after['meta_description']],
                label: $label ?? ($type.': '.$key), storefrontId: $storefrontId);
        }
    }

    /**
     * The quality gate's verdict on one language of one page's text (written by `seo:gate`). Not logged as a change of
     * the text; the caller flushes the storefront once after a run.
     *
     * @param  list<string>  $reasons  why it was held (empty when it passed)
     */
    public static function recordGate(int $storefrontId, string $type, string $key, string $locale, string $status, array $reasons): void
    {
        DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('subject_type', $type)->where('subject_key', $key)
            ->where('locale', $locale)->update([
                'gate_status' => $status,
                'gate_report' => json_encode($reasons, JSON_UNESCAPED_UNICODE),
                'gate_checked_at' => now(),
            ]);
    }

    /** @return list<string> */
    private static function reasons(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? array_values(array_map(fn (mixed $r): string => Coerce::str($r), $decoded)) : [];
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
