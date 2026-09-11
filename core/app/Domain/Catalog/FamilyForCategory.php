<?php

namespace App\Domain\Catalog;

use App\Support\Coerce;
use App\Transform\FamilyResolver;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A product's FAMILY, derived from the category it is placed in — the dashboard half of the
 * transform's rule (CLEAN_CORE_STUDY §2.2 / §2.9.2 step 6).
 *
 * ── Why this class exists at all ─────────────────────────────────────────────────────────────
 *
 * The legacy Blade dashboard decided "is this a watch?" by comparing the category against a
 * HARD-CODED list of ids, which broke the moment the team added a category and had to be hotfixed
 * (`fix(legacy-dashboard): family check via category_type parent (was hardcoded ids)`). Wave 4B
 * must not reproduce that, so the derivation here:
 *
 *   • reads `config('transform.family')` — the SAME configuration the transform reads,
 *   • through the SAME class ({@see FamilyResolver}) — not a second implementation of the rule,
 *   • and gets its inputs from the tree's STRUCTURE (a node's root ancestor) plus the node's
 *     NAME, never from an id.
 *
 * So a category the team invents tomorrow resolves by the same rule as one the transform created,
 * and a re-classification can only ever come from editing the config both halves share.
 *
 * ── How a node becomes the resolver's two inputs ─────────────────────────────────────────────
 *
 * The transform resolves a family from the legacy pair (category type, sub type). The clean tree
 * mirrors that pair as depth: a depth-1 node mirrors a `category_type` and its children mirror
 * `sub_types` (M1 `legacy_source`), so:
 *
 *   `categoryTypeEn` = EN name of the node's ROOT ancestor, read off the materialised `path`
 *   `subTypeEn`      = EN name of the node ITSELF (for a root node, the same name)
 *
 * A deeper tree than legacy's two levels — which the dashboard can now build — keeps working:
 * the root is still the family-bearing level and the chosen node is still the specific one.
 * Nothing reads `depth` as an assumption.
 *
 * ── Placement is what decides, and the PRIMARY placement is what decides first ───────────────
 *
 * A product can sit in several categories. The family follows its PRIMARY placement (the one the
 * DB invariant of M1d already guarantees is unique per storefront), falling back to the
 * lowest-id placement when no primary is marked, and finally to the resolver's own default when
 * the product is in no category at all. One rule, stated once, so the form and a later re-run
 * cannot disagree.
 */
final class FamilyForCategory
{
    private readonly FamilyResolver $resolver;

    public function __construct()
    {
        /** @var array<string, mixed> $config */
        $config = config('transform.family', []);
        $this->resolver = new FamilyResolver($config);
    }

    /**
     * The family a product in this category node belongs to — from the NODE, and nothing else.
     *
     * ── Why the specs are not an input here (fixed 2026-09-11, task 4.1) ─────────────────────
     *
     * `FamilyResolver` reads three things in order: the root category name, then the PREFIXES of
     * the `extra_attributes`/`specs` JSON keys, then the node's own name. That middle rule is a
     * LEGACY-DATA heuristic — step 6 needs it to classify imported products whose categories say
     * nothing useful — and it has no business running in the dashboard, where the operator has
     * just told us the category.
     *
     * It was actively wrong. Moving a bag to a PERFUME category while the form still held
     * `bag_type` in its state sent that key in the payload, the prefix rule fired before the
     * node's name was ever considered, and the server stored `family = bag` for a product the
     * operator had just filed under Perfumes — AGENTS §2.21 broken by exactly the mechanism it
     * was written to prevent, a second input deciding what the category decides.
     *
     * So a NODE resolves by structure and name alone, which is also what makes the per-node family
     * map the form ships to the browser exactly equal to what a save will store. The specs
     * heuristic survives in one place only — {@see self::forProduct()} for a product with NO
     * placement at all, where the category has said nothing and the JSON is the only signal.
     */
    public function forNode(int $nodeId): string
    {
        $names = self::namesFor($nodeId);

        return $this->resolver->resolve($names['root_en'], null, $names['node_en']);
    }

    /**
     * The family and the reason for MANY nodes, in two queries — what the product form ships to
     * the browser so that changing the category swaps the spec block with no round trip.
     *
     * The browser LOOKS THIS UP; it does not recompute anything. A TypeScript mirror of the rule
     * was the obvious alternative and is the reason the bug existed in the first place: the form's
     * "shallow mirror" never actually mirrored, and two implementations of one rule drift by
     * definition. One rule, resolved once, on the server.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, array{family: string, reason: string}>
     */
    public function explainMany(array $nodeIds): array
    {
        $ids = array_values(array_unique(array_filter($nodeIds, fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        // One query for the paths — the root is read off the materialised `path`, never walked.
        $roots = [];
        foreach (DB::table('storefront_categories')->whereIn('id', $ids)->get(['id', 'path']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $path = Row::nstr($row, 'path') ?? '';
            $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $s): bool => $s !== '' && ctype_digit($s)));
            // A malformed path THROWS in `namesFor()` because a single node with a broken path is
            // a bug worth stopping for. Here the same node would poison a whole dropdown, so it is
            // skipped and simply carries no family — the save still refuses it loudly.
            if ($segments !== []) {
                $roots[$id] = (int) $segments[0];
            }
        }

        // One query for every EN name involved — the nodes and their roots together.
        $names = self::englishNames(array_values(array_unique(array_merge(array_keys($roots), array_values($roots)))));

        $out = [];
        foreach ($roots as $id => $rootId) {
            $nodeEn = $names[$id] ?? '';
            $rootEn = $names[$rootId] ?? '';
            $out[$id] = [
                'family' => $this->resolver->resolve($rootEn, null, $nodeEn),
                'reason' => self::reasonFor($nodeEn, $rootEn),
            ];
        }

        return $out;
    }

    /** The derivation in words, for a screen that must justify the block it is showing. */
    private static function reasonFor(string $nodeEn, string $rootEn): string
    {
        if ($rootEn === '') {
            return 'لا يوجد اسم إنجليزي للتصنيف الجذر، فالعائلة هي الافتراضية.';
        }

        return mb_strtolower($rootEn) === mb_strtolower($nodeEn)
            ? "التصنيف الجذر «{$rootEn}»"
            : "التصنيف «{$nodeEn}» تحت الجذر «{$rootEn}»";
    }

    /**
     * The family for a product, from its placements. Null node = no placement anywhere, which
     * resolves to the configured default rather than guessing.
     *
     * `$specs` is used ONLY in the no-placement case, and that is deliberate: with no category the
     * JSON's key prefixes are the only signal there is (it is how the transform classifies legacy
     * rows). The moment a category exists, the category decides — {@see self::forNode()}.
     *
     * @param  array<string, mixed>|null  $specs
     */
    public function forProduct(int $productId, ?array $specs = null): string
    {
        $node = self::primaryNodeFor($productId);

        return $node === null
            ? $this->resolver->resolve('', $specs === null || $specs === [] ? null : (json_encode($specs) ?: null), '')
            : $this->forNode($node);
    }

    /**
     * Which node governs a product's family: the PRIMARY STOREFRONT's primary placement.
     *
     * ── Why one storefront has to win (2026-09-11, two storefronts) ──────────────────────────
     *
     * `catalog_products.family` is ONE column on a SHARED product, while placement is per
     * storefront. So a product filed under Watches on Watchizer and under Bags on Brand Fashion
     * can only have one family, and something has to decide which. The answer is the primary
     * storefront: its tree is the one legacy feeds, its categories are the ones the transform
     * derived the family from in the first place, and a rule that followed "whichever storefront
     * you edited last" would make the spec block change under the team's hands for no visible
     * reason.
     *
     * `ORDER BY is_primary DESC, storefront_id ASC, id ASC` — the middle clause is the fix. It
     * used to order by the pivot row id alone, which happened to put Watchizer first only because
     * the transform inserts its rows first; for a product created in the dashboard the answer
     * depended on insert order. The product form says which storefront decides, so the team is
     * never guessing.
     */
    public static function primaryNodeFor(int $productId): ?int
    {
        $value = DB::table('storefront_category_product')
            ->where('product_id', $productId)
            ->orderByDesc('is_primary')
            ->orderBy('storefront_id')
            ->orderBy('id')
            ->value('storefront_category_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The two names the resolver needs: the node's own EN name, and its ROOT ancestor's.
     *
     * The root is read off the materialised `path` ("/1/29/") rather than by walking `parent_id`
     * in a loop — one query, and it is the same string the read layer trusts for the visibility
     * rule. A node whose path is malformed is an error worth shouting about: silently treating it
     * as a root is how a bag becomes a watch.
     *
     * @return array{node_en: string, root_en: string, root_id: int}
     */
    public static function namesFor(int $nodeId): array
    {
        $node = DB::table('storefront_categories')->where('id', $nodeId)->first(['id', 'path']);
        if ($node === null) {
            throw new RuntimeException("Category node {$nodeId} does not exist.");
        }

        $path = Row::nstr(Row::cast($node), 'path') ?? '';
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $s): bool => $s !== '' && ctype_digit($s)));
        if ($segments === []) {
            throw new RuntimeException("Category node {$nodeId} has no materialised path; the tree writer is the only thing allowed to set it.");
        }

        $rootId = (int) $segments[0];
        $names = self::englishNames([$nodeId, $rootId]);

        return [
            'node_en' => $names[$nodeId] ?? '',
            'root_en' => $names[$rootId] ?? '',
            'root_id' => $rootId,
        ];
    }

    /**
     * EN names for a set of nodes, in one query.
     *
     * EN and not AR because the transform's configured lists are English
     * (`watch_category_type_names => ['watches']`, `sub_type_names => ['bags' => 'bag', …]`) —
     * they mirror the legacy `category_types.name` EN rows. A node with no EN translation
     * contributes '' and falls through to the configured default, which is the honest answer:
     * the rule cannot read a name that is not there.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, string>
     */
    public static function englishNames(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $rows = DB::table('storefront_category_translations')
            ->whereIn('storefront_category_id', array_values(array_unique($nodeIds)))
            ->where('locale', 'en')
            ->get(['storefront_category_id', 'name']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'storefront_category_id')] = Row::nstr($row, 'name') ?? '';
        }

        return $out;
    }

    /**
     * What the form needs to explain itself: the family, the reason, and the family the product is
     * SAVED with — so the screen can say "watch specs, because this category sits under Watches"
     * instead of showing a block the team cannot account for, and can warn when the category the
     * operator just chose would move the product to a different block.
     *
     * `saved_family` is the row's stored `family` (null on a create screen). The form compares it
     * with the family of whatever category is selected now; when they differ it names the spec
     * values the save will DISCARD, because they are dropped and that must never be silent.
     *
     * @return array{family: string, node_id: int|null, node_en: string, root_en: string, reason: string, saved_family: string|null}
     */
    public function explain(?int $nodeId, ?string $savedFamily = null): array
    {
        if ($nodeId === null) {
            $config = Coerce::arr(config('transform.family', []));
            $default = Coerce::str($config['default'] ?? null, 'fashion');

            return [
                'family' => $default,
                'node_id' => null,
                'node_en' => '',
                'root_en' => '',
                'reason' => 'لا يوجد تصنيف محدد بعد، فالعائلة هي الافتراضية.',
                'saved_family' => $savedFamily,
            ];
        }

        $names = self::namesFor($nodeId);

        return [
            'family' => $this->forNode($nodeId),
            'node_id' => $nodeId,
            'node_en' => $names['node_en'],
            'root_en' => $names['root_en'],
            'reason' => self::reasonFor($names['node_en'], $names['root_en'])
                .' — القاعدة نفسها التي يستخدمها التحويل (config/transform.php).',
            'saved_family' => $savedFamily,
        ];
    }
}
