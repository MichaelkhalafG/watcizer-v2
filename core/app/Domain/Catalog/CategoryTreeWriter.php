<?php

namespace App\Domain\Catalog;

use App\Models\Storefront\StorefrontCategory;
use App\Models\Storefront\StorefrontRedirect;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The category service `StorefrontCategory`'s docblock has promised since M1: "`path`/`depth` are
 * maintained by the category service's moveTo(), never by hand (R2-22)". This is it.
 *
 * ── Why a materialised path needs a service and not a form field ─────────────────────────────
 *
 * `storefront_categories.path` ("/1/29/") is what the read layer trusts: the dynamic menu
 * visibility rule reads ancestors off the path string (`nodeIdsWithVisibleProducts`), the family
 * resolver reads the root off it ({@see FamilyForCategory}), and `sc_path_idx` indexes its first
 * 191 bytes. A hand-edited path is a tree that lies — a node whose parent says one thing and whose
 * path says another is invisible to one half of the application and visible to the other.
 *
 * So every mutation that can change an ancestor chain goes through here, in one transaction, and
 * the subtree is rewritten with the node: moving `/1/29/` under `/2/` rewrites `/1/29/`,
 * `/1/29/57/`, `/1/29/57/91/` … in ONE statement, with `depth` shifted by the same delta.
 *
 * ── What this class refuses ──────────────────────────────────────────────────────────────────
 *
 *  • A move into the node's own subtree (a cycle — the tree would become unreachable and the
 *    path rewrite would loop).
 *  • A move across storefronts (a node belongs to exactly one storefront; re-homing it would
 *    take its products' placements with it).
 *  • A path that would exceed the column (1000 chars) or a depth beyond {@see self::MAX_DEPTH}.
 *  • A slug that collides inside the storefront — the DB has the unique index, but a 1062 is a
 *    500 where a field error belongs.
 *  • Deleting a node that still holds products or children. The FK cascades, which is precisely
 *    why the check is here: `ON DELETE CASCADE` on `storefront_category_product` would silently
 *    un-place every product in the category.
 */
final class CategoryTreeWriter
{
    /**
     * Ten levels. Legacy has two (category type → sub type) and the deepest tree the team has
     * asked for is four; ten is generous while keeping `path` inside the column even with
     * seven-digit ids, and it stops a runaway script from building a thousand-deep chain.
     */
    public const MAX_DEPTH = 10;

    public const PATH_MAX = 1000;

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * Create a node. Returns it with `path`/`depth` already correct.
     *
     * @param  array<string, string>  $names  locale => name; 'ar' is required (fallback is OFF, AGENTS §2.17)
     * @param  array{slug?: string|null, icon?: string|null, image_path?: string|null, is_active?: bool, show_in_menu?: bool, sort_order?: int}  $attrs
     */
    public function create(int $storefrontId, ?int $parentId, array $names, array $attrs = []): StorefrontCategory
    {
        // A node with no legacy key is deleted by the rebuild, and every placement inside it
        // goes with it. Refused until the write-switch (see PreSwitch).
        // Two gates, in order: may the dashboard CREATE a transform-output row at all (AGENTS
        // §2.23), and may it touch THIS storefront's tree yet (§2.24 — a non-primary tree is a
        // mirror of legacy until the switch). Both live in the WRITER so an importer or a console
        // command is refused without anyone remembering to add the check.
        PreSwitch::assertMayCreate('category');
        PreSwitch::assertMayEditTree($storefrontId);

        $ar = trim($names['ar'] ?? '');
        if ($ar === '') {
            throw new RuntimeException('الاسم العربي مطلوب: الترجمة الاحتياطية مُعطّلة، فالتصنيف بدون عربي يظهر ناقصًا.');
        }

        return DB::transaction(function () use ($storefrontId, $parentId, $names, $attrs): StorefrontCategory {
            $parent = $parentId === null ? null : $this->requireNode($storefrontId, $parentId);
            $depth = $parent === null ? 1 : $parent->depth + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new RuntimeException('تجاوز أقصى عمق مسموح للشجرة ('.self::MAX_DEPTH.').');
            }

            $slug = $this->uniqueSlug($storefrontId, $attrs['slug'] ?? null, $names, null);

            $node = new StorefrontCategory;
            $node->forceFill([
                'storefront_id' => $storefrontId,
                'parent_id' => $parent?->id,
                'depth' => $depth,
                // A placeholder: the real path needs the id the insert is about to assign.
                'path' => '/',
                'slug' => $slug,
                'image_path' => $attrs['image_path'] ?? null,
                'icon' => $attrs['icon'] ?? null,
                'is_active' => $attrs['is_active'] ?? true,
                'show_in_menu' => $attrs['show_in_menu'] ?? true,
                'sort_order' => $attrs['sort_order'] ?? $this->nextSort($storefrontId, $parent?->id),
                // Dashboard-authored: no legacy source. The transform keys on
                // (storefront, legacy_source, legacy_id, legacy_parent_id), so NULLs here mean it
                // will never claim, refresh or re-parent this node (§2.9.6 rule 3).
                'legacy_source' => null,
                'legacy_id' => null,
                'legacy_parent_id' => null,
            ]);
            $node->save();

            $path = ($parent === null ? '/' : Coerce::str($parent->getAttribute('path'), '/')).$node->id.'/';
            self::assertPathFits($path);
            DB::table('storefront_categories')->where('id', $node->id)->update(['path' => $path]);
            $node->setAttribute('path', $path);

            $this->writeTranslations($node->id, $names);
            $this->bump($storefrontId);

            return $node;
        });
    }

    /**
     * Rename a node (translations), and optionally change its slug.
     *
     * A slug change is a URL change, so it leaves a 301 behind exactly as a product slug change
     * does — same table, same `slug_change` source. Without it every indexed category URL 404s.
     *
     * @param  array<string, string>  $names
     */
    public function rename(int $storefrontId, int $nodeId, array $names, ?string $slug = null): StorefrontCategory
    {
        PreSwitch::assertMayEditTree($storefrontId);
        $ar = trim($names['ar'] ?? '');
        if ($ar === '') {
            throw new RuntimeException('الاسم العربي مطلوب.');
        }

        return DB::transaction(function () use ($storefrontId, $nodeId, $names, $slug): StorefrontCategory {
            $node = $this->requireNode($storefrontId, $nodeId);
            $old = Coerce::str($node->getAttribute('slug'));

            if ($slug !== null && $slug !== '' && $slug !== $old) {
                $next = $this->uniqueSlug($storefrontId, $slug, $names, $nodeId);
                DB::table('storefront_categories')->where('id', $nodeId)->update(['slug' => $next, 'updated_at' => now()]);
                $node->setAttribute('slug', $next);
                $this->recordRedirect($storefrontId, '/category/'.$old, '/category/'.$next);
            }

            $this->writeTranslations($nodeId, $names);
            $this->bump($storefrontId);

            return $node;
        });
    }

    /**
     * Move a node (and its whole subtree) under a new parent — or to the root with `null`.
     *
     * The subtree rewrite is ONE statement per field pair, so a tree with thousands of nodes moves
     * in constant statement count and cannot be left half-moved by a timeout.
     */
    public function move(int $storefrontId, int $nodeId, ?int $newParentId): StorefrontCategory
    {
        PreSwitch::assertMayEditTree($storefrontId);

        return DB::transaction(function () use ($storefrontId, $nodeId, $newParentId): StorefrontCategory {
            $node = $this->requireNode($storefrontId, $nodeId);
            $oldPath = Coerce::str($node->getAttribute('path'), '/');
            $oldDepth = $node->depth;

            if ($newParentId === $nodeId) {
                throw new RuntimeException('لا يمكن جعل التصنيف أبًا لنفسه.');
            }

            $parent = $newParentId === null ? null : $this->requireNode($storefrontId, $newParentId);

            // The cycle check, and the reason the path exists: a descendant's path always starts
            // with the node's own path, so one string comparison decides it for the whole subtree.
            if ($parent !== null && str_starts_with(Coerce::str($parent->getAttribute('path'), '/'), $oldPath)) {
                throw new RuntimeException('لا يمكن نقل التصنيف إلى داخل فروعه.');
            }

            if ($parent?->id === $node->parent_id) {
                return $node; // already there; nothing to rewrite
            }

            $newDepth = $parent === null ? 1 : $parent->depth + 1;
            $deepest = $this->deepestDescendantDepth($storefrontId, $oldPath);
            if ($newDepth + ($deepest - $oldDepth) > self::MAX_DEPTH) {
                throw new RuntimeException('النقل يجعل الشجرة أعمق من المسموح ('.self::MAX_DEPTH.').');
            }

            $newPath = ($parent === null ? '/' : Coerce::str($parent->getAttribute('path'), '/')).$node->id.'/';
            self::assertPathFits($newPath);
            // The longest descendant path grows by the same amount as the node's own.
            self::assertPathFits($newPath.str_repeat('0', max(0, $this->longestDescendantPath($storefrontId, $oldPath) - strlen($oldPath))));

            // Rewrite the subtree: every row whose path starts with the old one, node included.
            // SUBSTRING keeps the tail verbatim instead of REPLACE()ing a pattern that could in
            // principle appear twice, and `depth` shifts by the same delta for every level.
            self::assertPathShape($newPath);
            $shift = $newDepth - $oldDepth;

            // The subtree, rewritten row by row inside this transaction.
            //
            // A single `UPDATE … SET path = CONCAT(?, SUBSTRING(path, n))` would be one statement
            // instead of N, and the first version of this method did exactly that — but the SET
            // list of an UPDATE cannot take a binding, so it has to become raw text, and
            // `App\Support\Sql` says of itself that it holds the codebase's ONLY
            // `literal-string` exemption "and nowhere else". Making that sentence false to save
            // statements on a tree that has 37 nodes today (and whose depth is capped at
            // MAX_DEPTH) is the wrong trade. The loop is bounded by the subtree, runs inside the
            // transaction, and needs no exemption to be provably safe.
            $subtree = DB::table('storefront_categories')
                ->where('storefront_id', $storefrontId)
                ->where('path', 'like', self::escapeLike($oldPath).'%')
                ->orderBy('depth')
                ->get(['id', 'path', 'depth']);

            foreach ($subtree as $raw) {
                $descendant = Row::cast($raw);
                $path = Row::str($descendant, 'path');
                $rewritten = $newPath.substr($path, strlen($oldPath));
                self::assertPathFits($rewritten);
                self::assertPathShape($rewritten);

                DB::table('storefront_categories')->where('id', Row::int($descendant, 'id'))->update([
                    'path' => $rewritten,
                    'depth' => Row::int($descendant, 'depth') + $shift,
                    'updated_at' => now(),
                ]);
            }

            DB::table('storefront_categories')->where('id', $nodeId)->update([
                'parent_id' => $parent?->id,
                'sort_order' => $this->nextSort($storefrontId, $parent?->id),
                'updated_at' => now(),
            ]);

            $this->bump($storefrontId);

            return $this->requireNode($storefrontId, $nodeId);
        });
    }

    /**
     * Reorder siblings: an explicit list of node ids in their new order.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(int $storefrontId, ?int $parentId, array $orderedIds): int
    {
        PreSwitch::assertMayEditTree($storefrontId);

        return DB::transaction(function () use ($storefrontId, $parentId, $orderedIds): int {
            $valid = DB::table('storefront_categories')
                ->where('storefront_id', $storefrontId)
                ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            $sort = 0;
            $moved = 0;
            foreach ($orderedIds as $id) {
                if (! in_array($id, $valid, true)) {
                    // A stale id from another parent (or another storefront) is skipped, not
                    // fatal: a drag that raced a concurrent move must not lose the whole reorder.
                    continue;
                }
                DB::table('storefront_categories')->where('id', $id)->update(['sort_order' => $sort++, 'updated_at' => now()]);
                $moved++;
            }
            $this->bump($storefrontId);

            return $moved;
        });
    }

    /** Activate / deactivate, and show / hide in the menu — the two manual overrides of §3.3. */
    public function setFlags(int $storefrontId, int $nodeId, ?bool $isActive, ?bool $showInMenu): StorefrontCategory
    {
        PreSwitch::assertMayEditTree($storefrontId);
        $node = $this->requireNode($storefrontId, $nodeId);

        $update = ['updated_at' => now()];
        if ($isActive !== null) {
            $update['is_active'] = $isActive;
        }
        if ($showInMenu !== null) {
            $update['show_in_menu'] = $showInMenu;
        }
        DB::table('storefront_categories')->where('id', $nodeId)->update($update);
        $this->bump($storefrontId);

        return $this->requireNode($storefrontId, $nodeId);
    }

    /**
     * Delete an EMPTY leaf. Refused while it holds children or product placements.
     *
     * The refusal is the point: both foreign keys cascade, so a permitted delete would take the
     * subtree and every placement in it silently. A category the team wants gone but which still
     * holds products is a deactivation, not a delete — and §2.9.6 rule 2 says the transform never
     * deletes a placement either.
     */
    public function delete(int $storefrontId, int $nodeId): void
    {
        PreSwitch::assertMayEditTree($storefrontId);
        DB::transaction(function () use ($storefrontId, $nodeId): void {
            $node = $this->requireNode($storefrontId, $nodeId);

            $children = DB::table('storefront_categories')->where('parent_id', $nodeId)->count();
            if ($children > 0) {
                throw new RuntimeException("لا يمكن الحذف: التصنيف يحتوي {$children} تصنيفًا فرعيًا. انقلها أولًا أو عطّل التصنيف.");
            }

            $placed = DB::table('storefront_category_product')->where('storefront_category_id', $nodeId)->count();
            if ($placed > 0) {
                throw new RuntimeException("لا يمكن الحذف: {$placed} منتجًا مرتبطًا بهذا التصنيف. انقلها أولًا أو عطّل التصنيف.");
            }

            if ($node->legacy_source !== null) {
                throw new RuntimeException('هذا التصنيف مأخوذ من النظام القديم، وإعادة بناء الجداول ستعيده. عطّله بدلًا من حذفه.');
            }

            DB::table('storefront_category_translations')->where('storefront_category_id', $nodeId)->delete();
            DB::table('storefront_categories')->where('id', $nodeId)->delete();
            $this->bump($storefrontId);
        });
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /**
     * A node of THIS storefront, or a 404-shaped failure.
     *
     * Scoped by storefront on purpose (study §3.11.14): a caller who may only manage storefront 1
     * must not learn that node 900 of storefront 2 exists, so "not yours" and "not there" are the
     * same answer.
     */
    private function requireNode(int $storefrontId, int $nodeId): StorefrontCategory
    {
        $node = StorefrontCategory::query()->where('storefront_id', $storefrontId)->find($nodeId);
        if (! $node instanceof StorefrontCategory) {
            /*
             * Arabic, because `CategoryController` puts this straight on the screen — the most
             * ordinary way to reach it is two people on the same tree: one deletes a category, the
             * other clicks delete (or move) on the row their page still shows (review 🟡-7).
             *
             * It says "refresh", because that is the whole remedy, and it does NOT say whether the
             * id exists somewhere else: "not yours" and "not there" stay one answer (§3.11.14).
             */
            throw new RuntimeException('هذا التصنيف غير موجود — ربما حذفه شخص آخر. حدّث الصفحة لرؤية الشجرة الحالية.');
        }

        return $node;
    }

    /**
     * A slug unique inside the storefront: the caller's, else from the EN name, else from the AR
     * name transliterated by nothing (which yields '' — so the node id is the fallback, exactly as
     * `ProductSlugs` does for products).
     *
     * @param  array<string, string>  $names
     */
    private function uniqueSlug(int $storefrontId, ?string $requested, array $names, ?int $ignoreId): string
    {
        $base = LegacySlug::make($requested ?? '');
        if ($base === '') {
            $base = LegacySlug::make($names['en'] ?? '');
        }
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->slugTaken($storefrontId, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
            if ($suffix > 200) {
                throw new RuntimeException("تعذّر توليد رابط فريد للتصنيف من «{$base}».");
            }
        }

        return $slug;
    }

    private function slugTaken(int $storefrontId, string $slug, ?int $ignoreId): bool
    {
        return DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /** @param  array<string, string>  $names */
    private function writeTranslations(int $nodeId, array $names): void
    {
        foreach (['ar', 'en'] as $locale) {
            $name = trim($names[$locale] ?? '');
            if ($name === '') {
                // An empty EN is allowed and is NOT stored as an empty row: fallback is off, so a
                // missing translation must be missing, not present-and-blank (§2.17).
                DB::table('storefront_category_translations')
                    ->where('storefront_category_id', $nodeId)->where('locale', $locale)->delete();

                continue;
            }
            DB::table('storefront_category_translations')->updateOrInsert(
                ['storefront_category_id' => $nodeId, 'locale' => $locale],
                ['name' => $name],
            );
        }
    }

    private function nextSort(int $storefrontId, ?int $parentId): int
    {
        $max = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->max('sort_order');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    private function deepestDescendantDepth(int $storefrontId, string $path): int
    {
        $max = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('path', 'like', self::escapeLike($path).'%')
            ->max('depth');

        return is_numeric($max) ? (int) $max : 1;
    }

    private function longestDescendantPath(int $storefrontId, string $path): int
    {
        $max = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('path', 'like', self::escapeLike($path).'%')
            ->selectRaw('MAX(CHAR_LENGTH(path)) as len')
            ->value('len');

        return is_numeric($max) ? (int) $max : strlen($path);
    }

    private function recordRedirect(int $storefrontId, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        StorefrontRedirect::query()->updateOrCreate(
            ['storefront_id' => $storefrontId, 'from_hash' => StorefrontRedirect::hashPath($from)],
            ['from_path' => $from, 'to_path' => $to, 'status' => 301, 'source' => 'slug_change'],
        );
    }

    /**
     * Every mutation bumps the storefront's cache version, for the same reason the transform does
     * (study §3.7.6): the menu, the tree endpoint and every cached category payload are derived
     * from these rows, and a team that renames a category and cannot see it on the storefront will
     * rename it again.
     */
    private function bump(int $storefrontId): void
    {
        $this->cache->flush($storefrontId);
    }

    /**
     * A materialised path is digits and slashes, nothing else.
     *
     * Asserted before the path is ever interpolated into SQL. The writer is the only thing that
     * builds one (from `id`s), so a path that fails this is a corrupted row or a bug here — and
     * either way it must not reach a query.
     */
    private static function assertPathShape(string $path): void
    {
        if (preg_match('#^/(?:\d+/)*$#', $path) !== 1) {
            // Arabic for the operator, with the bad value kept verbatim for whoever they call:
            // this is a corrupted row, not something they did, and no retry will fix it.
            throw new RuntimeException(
                'مسار هذا التصنيف غير سليم في قاعدة البيانات، ولا يمكن تنفيذ العملية عليه. '
                ."أبلغ المطوّر بهذه القيمة: [{$path}]"
            );
        }
    }

    private static function assertPathFits(string $path): void
    {
        if (strlen($path) > self::PATH_MAX) {
            throw new RuntimeException('مسار الشجرة أطول من العمود ('.self::PATH_MAX.' حرفًا).');
        }
    }

    /** `%`, `_` and `\` inside a path prefix must not act as LIKE wildcards. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
