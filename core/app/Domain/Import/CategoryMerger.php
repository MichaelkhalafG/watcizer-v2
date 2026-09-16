<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Catalog\CategoryTreeWriter;
use App\Support\Coerce;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Merges the mapped tree into a storefront's real one: finds what exists, creates only what does
 * not (wave 4D importers).
 *
 * ── "Make the merged tree make sense as a shop, not a pile of both" ──────────────────────────
 *
 * That is the developer's instruction, and the whole design follows from it. The import does NOT
 * copy WooCommerce's tree. {@see CategoryMap} decides, per leaf, what our tree should say, and
 * this class is the part that puts it there:
 *
 *   • a node we ALREADY have is used as it is — nothing is renamed, re-parented or duplicated,
 *     so `Watches > Automatic` receives the imported automatics and keeps its Arabic name, its
 *     slug and its position in the menu;
 *   • a node we need and do not have is created ONCE, with both names, in the place the map says;
 *   • nothing is created that no imported product actually uses, so a run that imports only
 *     watches does not leave eight empty bag sections behind it.
 *
 * Node paths are SLUG paths (`fashion/bags/tote-bag`), resolved against the real tree by walking
 * the slugs. They are not the materialised `path` column, which is a path of ids and different on
 * every storefront.
 *
 * Every creation here goes through {@see CategoryTreeWriter}, which is the one door — so the
 * PreSwitch refusal, the Arabic-name requirement, the slug uniqueness and the cache bump all
 * happen exactly as they do for the screen.
 */
final class CategoryMerger
{
    /** slug path => node id, for this storefront. */
    /** @var array<string, int> */
    private array $resolved = [];

    /** @var list<string> */
    private array $created = [];

    public function __construct(private readonly CategoryTreeWriter $tree) {}

    /**
     * The node id for a slug path, creating the node (and any missing ancestor) if the map
     * declares it.
     *
     * @throws RuntimeException when the path is neither in the tree nor in the map
     */
    public function node(int $storefrontId, string $slugPath): int
    {
        $key = $storefrontId.'|'.$slugPath;
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $existing = self::find($storefrontId, $slugPath);
        if ($existing !== null) {
            return $this->resolved[$key] = $existing;
        }

        $definition = CategoryMap::NEW_NODES[$slugPath] ?? null;
        if ($definition === null) {
            throw new RuntimeException(
                "The category map points at [{$slugPath}], which is not in this storefront's tree and is not "
                .'declared in CategoryMap::NEW_NODES. Add it there, with both names, or correct the mapping row.'
            );
        }

        // The parent first — recursion handles `electronics` before `electronics/chargers`.
        $parentId = $definition['parent'] === null ? null : $this->node($storefrontId, $definition['parent']);

        $slug = self::lastSegment($slugPath);
        $node = $this->tree->create(
            $storefrontId,
            $parentId,
            ['ar' => $definition['ar'], 'en' => $definition['en']],
            ['slug' => $slug],
        );

        $id = Coerce::int($node->getKey());
        $this->created[] = $storefrontId.':'.$slugPath;

        return $this->resolved[$key] = $id;
    }

    /**
     * Node paths this run actually created, for the report. Empty on a second run, which is the
     * proof that the merge is idempotent.
     *
     * @return list<string>
     */
    public function created(): array
    {
        return $this->created;
    }

    /**
     * Find a node by walking its slugs from the root of THIS storefront.
     *
     * Slug-by-slug rather than `where('slug', last)`: slugs are unique per storefront today, but
     * the tree is a tree, and resolving `bags/tote-bag` by its last segment alone would quietly
     * accept a `tote-bag` hanging somewhere else.
     */
    public static function find(int $storefrontId, string $slugPath): ?int
    {
        $parentId = null;
        $id = null;
        $walked = [];

        foreach (explode('/', $slugPath) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $walked[] = $segment;

            $row = DB::table('storefront_categories')
                ->where('storefront_id', $storefrontId)
                ->where('slug', $segment)
                ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'))
                ->when($parentId !== null, fn ($q) => $q->where('parent_id', $parentId))
                ->first(['id']);

            /*
             * A slug is unique PER STOREFRONT, not per parent — so the node we want may be wearing
             * a de-duplicated slug. `electronics/accessories` took `accessories` first, and the
             * Fashion one was created as `accessories-2`; a lookup by slug then found nothing and
             * the import tried to create it again on every row.
             *
             * So the second question is the node's declared NAME under the same parent, which is
             * what the map asserts and what two different sections cannot share.
             */
            if (! is_object($row)) {
                $id = self::byDeclaredName($storefrontId, implode('/', $walked), $parentId);
                if ($id === null) {
                    return null;
                }
                $parentId = $id;

                continue;
            }

            $id = Coerce::int($row->id ?? null);
            $parentId = $id;
        }

        return $id;
    }

    /**
     * The node the map declares at this path, found by its EN name under the expected parent.
     */
    private static function byDeclaredName(int $storefrontId, string $path, ?int $parentId): ?int
    {
        $declared = CategoryMap::NEW_NODES[$path] ?? null;
        if ($declared === null) {
            return null;
        }

        $row = DB::table('storefront_categories as c')
            ->join('storefront_category_translations as t', function (JoinClause $join): void {
                $join->on('t.storefront_category_id', '=', 'c.id')->where('t.locale', '=', 'en');
            })
            ->where('c.storefront_id', $storefrontId)
            ->where('t.name', $declared['en'])
            ->when($parentId === null, fn ($q) => $q->whereNull('c.parent_id'))
            ->when($parentId !== null, fn ($q) => $q->where('c.parent_id', $parentId))
            ->first(['c.id']);

        return is_object($row) ? Coerce::int($row->id ?? null) : null;
    }

    private static function lastSegment(string $slugPath): string
    {
        $parts = explode('/', $slugPath);

        return trim((string) end($parts));
    }
}
