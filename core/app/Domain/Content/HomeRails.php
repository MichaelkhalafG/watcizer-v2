<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The home page's rails (`storefront_home_rails`, M1y) — reading them for `catalog/home` and the
 * dashboard, and writing them for the dashboard. One class so the two sides cannot disagree about
 * what a valid rail is.
 *
 * @phpstan-type Rail array{id: int, kind: string, target_id: ?int, product_ids: list<int>, title_en: ?string, title_ar: ?string, position: int, is_active: bool, card_count: int}
 */
final class HomeRails
{
    public const OFFERS = 'offers';

    public const FEATURED = 'featured';

    public const NEWEST = 'newest';

    public const GRADE = 'grade';

    public const BRAND = 'brand';

    public const CATEGORY_TYPE = 'category_type';

    /**
     * Products picked by hand, in order (M2e, 2026-10-02). It has no target to be named after, so
     * BOTH titles are required; its card count is the number of products picked.
     */
    public const CUSTOM = 'custom';

    public const KINDS = [self::OFFERS, self::FEATURED, self::NEWEST, self::GRADE, self::BRAND, self::CATEGORY_TYPE, self::CUSTOM];

    /** Kinds that name a target: a grade, a brand, a top-level category. */
    public const TARGETED = [self::GRADE, self::BRAND, self::CATEGORY_TYPE];

    public const MAX_CARDS = 24;

    /**
     * Every rail of a storefront, in order — the dashboard's list.
     *
     * @return list<Rail>
     */
    public static function all(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->orderBy('position')->orderBy('id')->get() as $raw) {
            $out[] = self::rail($raw);
        }

        return $out;
    }

    /** @return list<Rail> */
    public static function active(int $storefrontId): array
    {
        return array_values(array_filter(self::all($storefrontId), fn (array $r): bool => $r['is_active']));
    }

    /** @return Rail|null */
    public static function find(int $storefrontId, int $id): ?array
    {
        $raw = DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)->first();

        return is_object($raw) ? self::rail($raw) : null;
    }

    /**
     * Add a rail at the END of the list.
     *
     * @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data
     */
    public static function create(int $storefrontId, array $data): int
    {
        self::assertValid($data);
        $last = DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->max('position');

        return (int) DB::table('storefront_home_rails')->insertGetId([
            ...self::columns($data),
            'storefront_id' => $storefrontId,
            'position' => (is_numeric($last) ? (int) $last : 0) + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data */
    public static function update(int $storefrontId, int $id, array $data): void
    {
        self::assertValid($data);
        DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)
            ->update([...self::columns($data), 'updated_at' => now()]);
    }

    public static function delete(int $storefrontId, int $id): void
    {
        DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)->delete();
    }

    /**
     * Put the rails in exactly this order (positions 10, 20, 30…). The list must name every rail of
     * the storefront once — a partial or stale list (another tab added a rail meanwhile) is refused
     * rather than guessed at.
     *
     * @param  list<int>  $ids
     */
    public static function reorder(int $storefrontId, array $ids): void
    {
        $existing = array_map(fn (array $r): int => $r['id'], self::all($storefrontId));
        $sorted = $ids;
        sort($sorted);
        sort($existing);
        if ($sorted !== $existing) {
            throw new InvalidArgumentException('The order must list every rail exactly once.');
        }
        DB::transaction(function () use ($storefrontId, $ids): void {
            foreach ($ids as $i => $id) {
                DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)
                    ->update(['position' => ($i + 1) * 10, 'updated_at' => now()]);
            }
        });
    }

    /** @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data */
    private static function assertValid(array $data): void
    {
        if (! in_array($data['kind'], self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown rail kind {$data['kind']}.");
        }
        if (in_array($data['kind'], self::TARGETED, true) !== ($data['target_id'] !== null)) {
            throw new InvalidArgumentException('A grade, brand or category rail needs a target; the others take none.');
        }
        if ($data['kind'] === self::CUSTOM) {
            $picks = $data['product_ids'] ?? [];
            if ($picks === [] || count($picks) > self::MAX_CARDS || count(array_unique($picks)) !== count($picks)) {
                throw new InvalidArgumentException('A custom rail needs 1 to '.self::MAX_CARDS.' different products.');
            }
            if (trim($data['title_ar'] ?? '') === '' || trim($data['title_en'] ?? '') === '') {
                throw new InvalidArgumentException('A custom rail needs a title in both languages.');
            }
        }
        if ($data['card_count'] < 1 || $data['card_count'] > self::MAX_CARDS) {
            throw new InvalidArgumentException('Card count out of range.');
        }
    }

    /**
     * @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int}  $data
     * @return array<string, mixed>
     */
    private static function columns(array $data): array
    {
        $title = fn (?string $t): ?string => $t === null || trim($t) === '' ? null : trim($t);

        $custom = $data['kind'] === self::CUSTOM;

        return [
            'kind' => $data['kind'],
            'target_id' => $data['target_id'],
            // Only a custom rail keeps picks; switching a rail to another kind drops them.
            'product_ids' => $custom ? (string) json_encode($data['product_ids'] ?? []) : null,
            'title_en' => $title($data['title_en']),
            'title_ar' => $title($data['title_ar']),
            'is_active' => $data['is_active'],
            // A custom rail shows exactly what was picked.
            'card_count' => $custom ? count($data['product_ids'] ?? []) : $data['card_count'],
        ];
    }

    /**
     * A stored JSON list of product ids, in order, positive ints only.
     *
     * @return list<int>
     */
    private static function ids(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true);

        return array_values(array_filter(is_array($decoded) ? $decoded : [], fn (mixed $id): bool => is_int($id) && $id > 0));
    }

    /** @return Rail */
    private static function rail(object $raw): array
    {
        $row = Row::cast($raw);

        return [
            'id' => Row::int($row, 'id'),
            'kind' => Row::str($row, 'kind'),
            'target_id' => Row::nint($row, 'target_id'),
            'product_ids' => self::ids(Row::nstr($row, 'product_ids')),
            'title_en' => Row::nstr($row, 'title_en'),
            'title_ar' => Row::nstr($row, 'title_ar'),
            'position' => Row::int($row, 'position'),
            'is_active' => Row::bool($row, 'is_active'),
            'card_count' => Row::int($row, 'card_count'),
        ];
    }
}
