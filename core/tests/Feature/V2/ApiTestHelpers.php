<?php

namespace Tests\Feature\V2;

use App\Storefront\StorefrontCache;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data-relative helpers for the v2 API suites: the local database is the production copy
 * (341 products after the transform), so tests pick real rows instead of assuming fixtures.
 */
final class ApiTestHelpers
{
    public const SF = 'watchizer';

    public static function base(string $path = ''): string
    {
        return '/api/v2/'.self::SF.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** Flush every versioned cache key of storefront 1 (what the dashboard events will do). */
    public static function flush(): void
    {
        app(StorefrontCache::class)->flush(1);
    }

    /** A visible product slug with a primary depth-2 placement. */
    public static function visibleSlug(): string
    {
        $slug = DB::table('storefront_product as sp')
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->join('storefront_category_product as scp', fn (JoinClause $j) => $j->on('scp.product_id', '=', 'sp.product_id')->where('scp.storefront_id', '=', 1)->where('scp.is_primary', '=', 1))
            ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->orderBy('sp.product_id')
            ->value('sp.slug');
        if (! is_string($slug)) {
            throw new RuntimeException('No visible placed product in the local database — run core:transform first.');
        }

        return $slug;
    }

    /**
     * A visible depth-2 node with few products: {id, slug, path, product ids}.
     *
     * @return array{id: int, slug: string, path: string, products: list<int>}
     */
    public static function smallSubtree(): array
    {
        $rows = DB::table('storefront_categories as c')
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'c.id')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', 1))
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->join('storefront_categories as parent', 'parent.id', '=', 'c.parent_id')
            ->where('c.storefront_id', 1)->where('c.depth', 2)->where('c.is_active', 1)->where('c.show_in_menu', 1)
            ->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->groupBy('c.id', 'c.slug', 'parent.slug')
            ->selectRaw('c.id AS id, c.slug AS slug, parent.slug AS parent_slug, COUNT(*) AS n, GROUP_CONCAT(scp.product_id) AS ids')
            ->orderBy('n')->orderBy('c.id')
            ->first();
        if ($rows === null) {
            throw new RuntimeException('No populated depth-2 node in the local database.');
        }
        $ids = array_map('intval', explode(',', Row::str($rows, 'ids')));

        return ['id' => Row::int($rows, 'id'), 'slug' => Row::str($rows, 'slug'), 'path' => Row::str($rows, 'parent_slug').'/'.Row::str($rows, 'slug'), 'products' => $ids];
    }

    /** @return array<string, mixed> */
    public static function arr(mixed $v): array
    {
        return is_array($v) ? Val::arr(['v' => $v], 'v') : [];
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $item) {
            if (is_array($item)) {
                $out[] = self::arr($item);
            }
        }

        return $out;
    }

    public static function str(mixed $v): string
    {
        return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
    }

    /** @return list<string> slugs (paths) of the nodes in the tree response, depth-first */
    public static function treePaths(mixed $tree): array
    {
        $out = [];
        if (! is_array($tree)) {
            return $out;
        }
        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }
            $out[] = Val::nstr($node, 'path') ?? '';
            foreach (self::treePaths($node['children'] ?? []) as $p) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
