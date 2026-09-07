<?php

namespace App\Compat;

use App\Models\Storefront\StorefrontCategory;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * The legacy `category_types` / `sub_types` view of the storefront-1 category tree.
 *
 * Nodes come from `storefront_categories` (depth 1 = category type, depth 2 = sub type paired
 * under its type). Only nodes that pass the permanent visibility rule (study §3.3,
 * StorefrontCategory::scopeVisibleInMenu) are listed in `catalog/meta` and the sitemap;
 * a sub type mirrored under two types is emitted once (lowest node id). Native nodes without
 * a legacy id get the fallback id `1000000 + node id` (§3.5.1).
 */
final class CompatCategories
{
    public const NATIVE_ID_OFFSET = 1_000_000;

    /** @var array<int, array<string, mixed>>|null node id => node */
    private ?array $nodes = null;

    /** @var array<int, bool>|null node id => visible */
    private ?array $visible = null;

    public function __construct(private readonly int $storefrontId) {}

    /**
     * All nodes of the storefront (id => {id, parent_id, depth, path, slug, image_path, is_active, show_in_menu,
     * sort_order, legacy_source, legacy_id, legacy_parent_id, created_at, updated_at, names: {locale: {id, name}}}).
     *
     * @return array<int, array<string, mixed>>
     */
    public function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }
        $nodes = [];
        $rows = DB::table('storefront_categories')
            ->select(['id', 'parent_id', 'depth', 'path', 'slug', 'image_path', 'is_active', 'show_in_menu', 'sort_order', 'legacy_source', 'legacy_id', 'legacy_parent_id', 'created_at', 'updated_at'])
            ->where('storefront_id', $this->storefrontId)
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $nodes[$id] = [
                'id' => $id,
                'parent_id' => Row::nint($row, 'parent_id'),
                'depth' => Row::int($row, 'depth'),
                'path' => Row::str($row, 'path'),
                'slug' => Row::str($row, 'slug'),
                'image_path' => Row::nstr($row, 'image_path'),
                'is_active' => Row::bool($row, 'is_active'),
                'show_in_menu' => Row::bool($row, 'show_in_menu'),
                'sort_order' => Row::int($row, 'sort_order'),
                'legacy_source' => Row::nstr($row, 'legacy_source'),
                'legacy_id' => Row::nint($row, 'legacy_id'),
                'legacy_parent_id' => Row::nint($row, 'legacy_parent_id'),
                'created_at' => Row::nstr($row, 'created_at'),
                'updated_at' => Row::nstr($row, 'updated_at'),
                'names' => [],
            ];
        }
        $tr = DB::table('storefront_category_translations')
            ->select(['id', 'storefront_category_id', 'locale', 'name'])
            ->whereIn('storefront_category_id', array_keys($nodes))
            ->orderBy('id')
            ->get();
        foreach ($tr as $row) {
            $nodeId = Row::int($row, 'storefront_category_id');
            if (isset($nodes[$nodeId])) {
                /** @var array<string, array{id: int, name: string}> $names */
                $names = $nodes[$nodeId]['names'];
                $names[Row::str($row, 'locale')] = ['id' => Row::int($row, 'id'), 'name' => Row::str($row, 'name')];
                $nodes[$nodeId]['names'] = $names;
            }
        }

        return $this->nodes = $nodes;
    }

    /** @return array<int, bool> node id => passes scopeVisibleInMenu */
    public function visibility(): array
    {
        if ($this->visible !== null) {
            return $this->visible;
        }
        $ids = StorefrontCategory::query()->visibleInMenu($this->storefrontId)->pluck('storefront_categories.id');
        $visible = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                $visible[$id] = true;
            } elseif (is_string($id) && ctype_digit($id)) {
                $visible[(int) $id] = true;
            }
        }

        return $this->visible = $visible;
    }

    public function isVisible(int $nodeId): bool
    {
        return $this->visibility()[$nodeId] ?? false;
    }

    /**
     * The legacy id the compat layer emits for a node (legacy_id, or the native fallback).
     *
     * @param  array<string, mixed>  $node
     */
    public static function legacyIdOf(array $node): int
    {
        $legacyId = Val::nint($node, 'legacy_id');

        return $legacyId ?? self::NATIVE_ID_OFFSET + Val::int($node, 'id');
    }

    /**
     * Visible depth-1 nodes as legacy category types, ordered by legacy id (= legacy PK order).
     *
     * @return list<array<string, mixed>>
     */
    public function categoryTypes(): array
    {
        $out = [];
        foreach ($this->nodes() as $node) {
            if ($node['depth'] !== 1 || $node['legacy_source'] === 'category_root' || ! $this->isVisible(Val::int($node, 'id'))) {
                continue;
            }
            $out[self::legacyIdOf($node)] = $node;
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Visible depth-2 nodes as legacy sub types, one per legacy id, ordered by legacy id.
     *
     * @return list<array<string, mixed>>
     */
    public function subTypes(): array
    {
        $out = [];
        foreach ($this->nodes() as $node) {
            if ($node['depth'] !== 2 || $node['legacy_source'] === 'category' || ! $this->isVisible(Val::int($node, 'id'))) {
                continue;
            }
            $legacyId = self::legacyIdOf($node);
            if (! isset($out[$legacyId])) {
                $out[$legacyId] = $node;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** @param  array<mixed>  $node */
    public function name(array $node, string $locale): ?string
    {
        $names = $node['names'] ?? null;
        if (! is_array($names)) {
            return null;
        }
        $t = $names[$locale] ?? null;

        return is_array($t) && is_string($t['name'] ?? null) ? $t['name'] : null;
    }

    /** @return array<string, mixed>|null the first node (lowest id) with this legacy source + id */
    public function findLegacy(string $source, int $legacyId): ?array
    {
        foreach ($this->nodes() as $node) {
            if ($node['legacy_source'] === $source && $node['legacy_id'] === $legacyId) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function node(int $id): ?array
    {
        return $this->nodes()[$id] ?? null;
    }

    /**
     * Legacy `translations[]` rows for a node: {id, locale, <fk>, <name column>} in the given locale order.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $localeOrder
     * @return list<array<string, mixed>>
     */
    public function translationRows(array $node, string $fk, string $nameColumn, array $localeOrder): array
    {
        $rows = [];
        $legacyId = self::legacyIdOf($node);
        foreach ($localeOrder as $locale) {
            $t = is_array($node['names'] ?? null) ? ($node['names'][$locale] ?? null) : null;
            if (! is_array($t)) {
                continue;
            }
            $rows[] = ['id' => $t['id'], 'locale' => $locale, $fk => $legacyId, $nameColumn => $t['name']];
        }

        return $rows;
    }
}
