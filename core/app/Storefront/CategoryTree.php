<?php

namespace App\Storefront;

use App\Models\Storefront\StorefrontCategory;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The storefront category tree for the read API, cached per version (`sf:{id}:tree:v{n}`).
 *
 * Only nodes that pass the permanent visibility rule (study §3.3, StorefrontCategory::
 * scopeVisibleInMenu) are in the tree, the menu and the sitemap; product counts include the
 * whole subtree (materialised path). `path` in the API is the slug path ("watches/chronograph"),
 * never the internal id path. Node ids are stable but never a contract (§2.9.6).
 */
final class CategoryTree
{
    /** @var array{nodes: array<int, array<string, mixed>>, roots: list<int>}|null */
    private ?array $data = null;

    public function __construct(private readonly StorefrontCache $cache, private readonly StorefrontContext $ctx) {}

    /** @return array{nodes: array<int, array<string, mixed>>, roots: list<int>} */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $sf = $this->ctx->id();
        $locales = $this->ctx->locales();

        /** @var array{nodes: array<int, array<string, mixed>>, roots: list<int>} $data */
        $data = $this->cache->remember($sf, 'tree', '', config()->integer('storefront.ttl.tree'), fn () => self::load($sf, $locales));

        return $this->data = $data;
    }

    /**
     * @param  list<string>  $locales
     * @return array{nodes: array<int, array<string, mixed>>, roots: list<int>}
     */
    private static function load(int $storefrontId, array $locales): array
    {
        $visible = StorefrontCategory::query()->visibleInMenu($storefrontId)
            ->select(['storefront_categories.id', 'storefront_categories.parent_id', 'storefront_categories.depth', 'storefront_categories.path', 'storefront_categories.slug', 'storefront_categories.image_path', 'storefront_categories.image_hidden', 'storefront_categories.sort_order', 'storefront_categories.updated_at'])
            ->orderBy('storefront_categories.depth')->orderBy('storefront_categories.sort_order')->orderBy('storefront_categories.id')
            ->get();
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int, true> $hidden nodes whose picture the team deleted: they show none, and never fall back */
        $hidden = [];
        foreach ($visible as $node) {
            $id = (int) $node->id;
            $own = is_string($node->image_path) && trim($node->image_path) !== '';
            if ((bool) $node->image_hidden) {
                $hidden[$id] = true;
            }
            $nodes[$id] = [
                'id' => $id,
                'parent_id' => $node->parent_id === null ? null : (int) $node->parent_id,
                'depth' => (int) $node->depth,
                'id_path' => (string) $node->path,
                'slug' => (string) $node->slug,
                'path' => '',
                /*
                 * The picture (Part 5, 2026-11-05): the node's own; else — filled in below — the cover of its most
                 * recently added product a shopper can buy here (CategoryImages); and NONE when the team deleted it
                 * (`image_hidden`), which is "show nothing", never "fall back again". `image_source` says which.
                 */
                'image' => $own && ! isset($hidden[$id]) ? ImageUrl::object((string) $node->image_path, null, null, null, null) : null,
                'image_source' => $own && ! isset($hidden[$id]) ? 'own' : null,
                'sort_order' => (int) $node->sort_order,
                'updated_at' => $node->updated_at?->toJSON(),
                'name' => [],
                'product_count' => 0,
                'children' => [],
            ];
        }
        if ($nodes === []) {
            return ['nodes' => [], 'roots' => []];
        }
        foreach (DB::table('storefront_category_translations')->select(['storefront_category_id', 'locale', 'name'])->whereIn('storefront_category_id', array_keys($nodes))->whereIn('locale', $locales)->orderBy('id')->get() as $t) {
            $id = Row::int($t, 'storefront_category_id');
            /** @var array<string, string> $name */
            $name = $nodes[$id]['name'];
            $name[Row::str($t, 'locale')] = Row::str($t, 'name');
            $nodes[$id]['name'] = $name;
        }
        // Subtree product counts: visible + active products placed on the node or any descendant.
        $counts = DB::table('storefront_categories as c')
            ->join('storefront_categories as node', 'node.path', 'like', DB::raw("CONCAT(c.path, '%')"))
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'node.id')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
            ->where('c.storefront_id', $storefrontId)
            ->whereIn('c.id', array_keys($nodes))
            ->where('sp.is_visible', 1)->where('cp.is_active', 1)->whereNull('cp.deleted_at')
            ->groupBy('c.id')
            ->selectRaw('c.id AS id, COUNT(DISTINCT scp.product_id) AS n')
            ->get();
        foreach ($counts as $c) {
            $nodes[Row::int($c, 'id')]['product_count'] = Row::int($c, 'n');
        }
        // The fallback picture, for every node that has none of its own and was not told to show none.
        $missing = [];
        foreach ($nodes as $id => $node) {
            if ($node['image'] === null && ! isset($hidden[$id])) {
                $missing[$id] = Val::str($node, 'id_path');
            }
        }
        foreach (CategoryImages::fallbacks($storefrontId, $missing) as $id => $image) {
            $nodes[$id]['image'] = $image;
            $nodes[$id]['image_source'] = 'product';
        }
        // Slug paths, children lists (a visible node whose parent is invisible becomes a root).
        $roots = [];
        foreach ($nodes as $id => $node) {
            $parent = Val::nint($node, 'parent_id');
            if ($parent !== null && isset($nodes[$parent])) {
                $children = Val::intList($nodes[$parent], 'children');
                $children[] = $id;
                $nodes[$parent]['children'] = $children;
            } else {
                $roots[] = $id;
            }
        }
        foreach ($nodes as $id => $node) {
            $segments = [];
            $cursor = $id;
            while (is_int($cursor) && isset($nodes[$cursor])) {
                array_unshift($segments, Val::str($nodes[$cursor], 'slug'));
                $cursor = Val::nint($nodes[$cursor], 'parent_id');
            }
            $nodes[$id]['path'] = implode('/', $segments);
        }

        return ['nodes' => $nodes, 'roots' => $roots];
    }

    /** @return array<string, mixed>|null */
    public function node(int $id): ?array
    {
        return $this->data()['nodes'][$id] ?? null;
    }

    /**
     * Resolve a slug path ("watches/chronograph") or a bare slug to a visible node.
     *
     * @return array<string, mixed>|null
     */
    public function byPath(string $path): ?array
    {
        $path = trim($path, '/');
        foreach ($this->data()['nodes'] as $node) {
            if ($node['path'] === $path) {
                return $node;
            }
        }
        if (! str_contains($path, '/')) {
            foreach ($this->data()['nodes'] as $node) {
                if ($node['slug'] === $path) {
                    return $node;
                }
            }
        }

        return null;
    }

    /** @return list<int> the node and every visible descendant */
    public function subtreeIds(int $id): array
    {
        $node = $this->node($id);
        if ($node === null) {
            return [];
        }
        $prefix = Val::str($node, 'id_path');
        $ids = [];
        foreach ($this->data()['nodes'] as $nid => $n) {
            if (str_starts_with(Val::str($n, 'id_path'), $prefix)) {
                $ids[] = $nid;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> ancestors first, the node last */
    public function breadcrumb(int $id): array
    {
        $out = [];
        $cursor = $id;
        while (is_int($cursor)) {
            $node = $this->node($cursor);
            if ($node === null) {
                break;
            }
            array_unshift($out, self::ref($node));
            $cursor = Val::nint($node, 'parent_id');
        }

        return $out;
    }

    /**
     * Public reference of a node.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function ref(array $node): array
    {
        return ['id' => $node['id'], 'slug' => $node['slug'], 'path' => $node['path'], 'depth' => $node['depth'], 'name' => $node['name']];
    }

    /**
     * Public node with counts and image (no children).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function item(array $node): array
    {
        return self::ref($node) + ['image' => $node['image'], 'image_source' => $node['image_source'] ?? null, 'sort_order' => $node['sort_order'], 'product_count' => $node['product_count'], 'updated_at' => $node['updated_at']];
    }

    /**
     * Nested tree of visible nodes.
     *
     * @return list<array<string, mixed>>
     */
    public function nested(): array
    {
        return $this->branch($this->data()['roots']);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function branch(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $node = $this->node($id);
            if ($node === null) {
                continue;
            }
            $out[] = self::item($node) + ['children' => $this->branch(Val::intList($node, 'children'))];
        }

        return $out;
    }

    /**
     * Direct visible children as items.
     *
     * @return list<array<string, mixed>>
     */
    public function children(int $id): array
    {
        $node = $this->node($id);
        if ($node === null) {
            return [];
        }
        $out = [];
        foreach (Val::intList($node, 'children') as $cid) {
            $child = $this->node($cid);
            if ($child !== null) {
                $out[] = self::item($child);
            }
        }

        return $out;
    }
}
