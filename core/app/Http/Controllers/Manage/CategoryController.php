<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\CategoryTreeWriter;
use App\Domain\Catalog\FamilyForCategory;
use App\Domain\Catalog\PreSwitch;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * /manage/storefronts/{storefront}/categories — the per-storefront category tree (scope item 4).
 *
 * ── The tree shows the team what the RULE is doing, not what they wish it did ────────────────
 *
 * Menu visibility is a dynamic rule of the read layer, decided 2026-09-06 and permanent
 * (study §3.3): a node appears in menus **iff** it is active, flagged for the menu, AND it or a
 * descendant holds at least one product that is visible on this storefront, active and not
 * soft-deleted. Nothing stamps it; a zero-product node disappears by itself.
 *
 * A dashboard that only showed `is_active` / `show_in_menu` would therefore let the team fight the
 * rule — flag a node for the menu, see nothing on the storefront, flag it again. So every row
 * carries THREE facts: the two flags they control, the node's live product count, and the computed
 * answer `in_menu` with the reason it is what it is. That is the difference between a tree they
 * can operate and one they argue with.
 *
 * ── Scope ────────────────────────────────────────────────────────────────────────────────────
 *
 * The URL names the storefront, so `EnsureStorefrontScope` runs on the group and a caller scoped
 * to another storefront gets **404** (study §3.11.14). Child rows are resolved by
 * {@see CategoryTreeWriter} inside a storefront-scoped query, so another storefront's node id is
 * "not there" and never "not yours".
 */
final class CategoryController
{
    public function __construct(private readonly CategoryTreeWriter $tree) {}

    public function index(Request $request, Storefront $storefront): Response
    {
        return Inertia::render('Manage/Categories/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'nodes' => self::tree($storefront->id),
            'max_depth' => CategoryTreeWriter::MAX_DEPTH,
            'visibility_rule' => 'يظهر التصنيف في القوائم فقط إذا كان مفعّلًا ومعروضًا في القائمة وبه (أو بفروعه) منتج واحد على الأقل '
                .'ظاهر على هذا المتجر ومفعّل وغير محذوف. هذه قاعدة تلقائية في طبقة القراءة — لا يوجد زر لتجاوزها.',
            'pre_switch' => PreSwitch::state('category'),
            // Whether THIS storefront's tree may be edited at all yet. A non-primary tree mirrors
            // legacy until the write-switch, so an edit here would be overwritten by the next
            // transform run — the screen says so instead of letting the operator find out
            // (AGENTS §2.24, study §3.13).
            'tree_sync' => PreSwitch::treeState($storefront->id),
        ]);
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $this->tree->create($storefront->id, self::parentId($data), self::names($data), self::attrs($data));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tree' => $e->getMessage()]);
        }

        return back()->with('status', 'تم إنشاء التصنيف.');
    }

    public function update(Request $request, Storefront $storefront, int $category): RedirectResponse
    {
        $data = $this->validated($request);
        $slug = Coerce::nstr($data['slug'] ?? null);

        try {
            $this->tree->rename($storefront->id, $category, self::names($data), $slug === '' ? null : $slug);
            $this->tree->setFlags(
                $storefront->id,
                $category,
                array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
                array_key_exists('show_in_menu', $data) ? (bool) $data['show_in_menu'] : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tree' => $e->getMessage()]);
        }

        return back()->with('status', 'تم حفظ التصنيف.');
    }

    /** Move a node (and its subtree) under a new parent, or to the root. */
    public function move(Request $request, Storefront $storefront, int $category): RedirectResponse
    {
        $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $parent = $request->input('parent_id');

        try {
            $this->tree->move($storefront->id, $category, Coerce::nint($parent));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tree' => $e->getMessage()]);
        }

        return back()->with('status', 'تم نقل التصنيف وفروعه.');
    }

    /** Reorder one level of siblings. */
    public function reorder(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $parent = $request->input('parent_id');
        $moved = $this->tree->reorder(
            $storefront->id,
            Coerce::nint($parent),
            Coerce::orderedIntList($request->input('ids')),
        );

        return back()->with('status', "تم ترتيب {$moved} تصنيفًا.");
    }

    public function destroy(Request $request, Storefront $storefront, int $category): RedirectResponse
    {
        try {
            $this->tree->delete($storefront->id, $category);
        } catch (RuntimeException $e) {
            return back()->withErrors(['tree' => $e->getMessage()]);
        }

        return back()->with('status', 'تم حذف التصنيف.');
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /**
     * The whole tree in `path` order — which is depth-first order by construction, so the screen
     * renders it without recursion and without a second query per level.
     *
     * Three derived facts per node:
     *   • `products` — placements of a LIVE, visible product on this storefront (what the rule
     *     counts), and `products_any` — every placement, so the delete button can explain itself.
     *   • `in_menu` — the computed answer of the §3.3 rule, from the same set-based query the read
     *     layer uses (`StorefrontCategory::nodeIdsWithVisibleProducts`), never a re-implementation.
     *   • `family` — what a product placed here would become, from the same resolver the transform
     *     uses. The team can see that "Bags" yields `bag` BEFORE they put 200 products in it.
     *
     * The family is derived for the WHOLE TREE AT ONCE (review 🟠-2). It used to call
     * `FamilyForCategory::forNode()` inside the row loop, and that method spends two queries per
     * call — the node's path, then the English names — so a 300-node tree cost 600 queries and the
     * screen got slower every time the team added a category. `explainMany()` is the same rule with
     * the same resolver, batched: two queries for any number of nodes. Total query count for this
     * screen is now a constant.
     *
     * @return list<array<string, mixed>>
     */
    private static function tree(int $storefrontId): array
    {
        $visibleNodeIds = StorefrontCategory::nodeIdsWithVisibleProducts($storefrontId);

        /** @var Collection<int|string, mixed> $counts */
        $counts = DB::table('storefront_category_product as scp')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
            ->where('scp.storefront_id', $storefrontId)
            ->where('sp.is_visible', true)
            ->where('cp.is_active', true)
            ->whereNull('cp.deleted_at')
            ->selectRaw('scp.storefront_category_id as node, COUNT(*) as live')
            ->groupBy('scp.storefront_category_id')
            ->pluck('live', 'node');

        $anyCounts = DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)
            ->selectRaw('storefront_category_id as node, COUNT(*) as total')
            ->groupBy('storefront_category_id')
            ->pluck('total', 'node');

        $childCounts = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->whereNotNull('parent_id')
            ->selectRaw('parent_id, COUNT(*) as kids')
            ->groupBy('parent_id')
            ->pluck('kids', 'parent_id');

        $rows = DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $join): void {
                $join->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $join): void {
                $join->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->where('c.storefront_id', $storefrontId)
            ->orderBy('c.path')
            ->get([
                'c.id', 'c.parent_id', 'c.depth', 'c.path', 'c.slug', 'c.icon', 'c.image_path',
                'c.is_active', 'c.show_in_menu', 'c.sort_order', 'c.legacy_source', 'c.legacy_id',
                'ar.name as name_ar', 'en.name as name_en',
            ]);

        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        $families = (new FamilyForCategory)->explainMany($ids);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $live = Coerce::int($counts[$id] ?? null);
            $any = Coerce::int($anyCounts[$id] ?? null);
            $kids = Coerce::int($childCounts[$id] ?? null);
            $active = Row::bool($row, 'is_active');
            $inMenuFlag = Row::bool($row, 'show_in_menu');
            $hasVisible = in_array($id, $visibleNodeIds, true);
            $legacySource = Row::nstr($row, 'legacy_source');

            $out[] = [
                'id' => $id,
                'parent_id' => Row::nint($row, 'parent_id'),
                'depth' => Row::int($row, 'depth'),
                'path' => Row::str($row, 'path'),
                'slug' => Row::str($row, 'slug'),
                'icon' => Row::nstr($row, 'icon') ?? '',
                'image_path' => Row::nstr($row, 'image_path') ?? '',
                'name' => [
                    'ar' => Row::nstr($row, 'name_ar') ?? '',
                    'en' => Row::nstr($row, 'name_en') ?? '',
                ],
                'is_active' => $active,
                'show_in_menu' => $inMenuFlag,
                'sort_order' => Row::int($row, 'sort_order'),
                'legacy_source' => $legacySource,
                'legacy_id' => Row::nint($row, 'legacy_id'),
                'children' => $kids,
                'products' => $live,
                'products_any' => $any,
                'in_menu' => $active && $inMenuFlag && $hasVisible,
                'in_menu_reason' => match (true) {
                    ! $active => 'معطّل',
                    ! $inMenuFlag => 'مستبعد من القائمة يدويًا',
                    ! $hasVisible => 'لا يوجد منتج ظاهر فيه أو في فروعه',
                    default => 'ظاهر',
                },
                // Derived with the transform's own rule and config, so the answer here and the
                // answer a product save computes are the same answer. A node whose `path` is
                // malformed carries NO family — same as the product form's dropdown — because a
                // guessed family on this screen is worse than a visible gap.
                'family' => isset($families[$id]) ? $families[$id]['family'] : '',
                'may_delete' => $kids === 0 && $any === 0 && $legacySource === null,
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[] = ['value' => (string) $id, 'label' => Row::nstr($row, 'name') ?? ('#'.$id)];
        }

        return $out;
    }

    // ── request plumbing ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return Coerce::arr($request->validate([
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'slug' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string', 'max:64'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'show_in_menu' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-2147483648', 'max:2147483647'],
        ]));
    }

    /** @param  array<string, mixed>  $data */
    private static function parentId(array $data): ?int
    {
        return Coerce::nint($data['parent_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function names(array $data): array
    {
        return Coerce::pair($data['name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{slug: string|null, icon: string|null, image_path: string|null, is_active: bool, show_in_menu: bool}
     */
    private static function attrs(array $data): array
    {
        return [
            'slug' => Coerce::nstr($data['slug'] ?? null),
            'icon' => Coerce::nstr($data['icon'] ?? null),
            'image_path' => Coerce::nstr($data['image_path'] ?? null),
            'is_active' => Coerce::bool($data['is_active'] ?? null, true),
            'show_in_menu' => Coerce::bool($data['show_in_menu'] ?? null, true),
        ];
    }
}
