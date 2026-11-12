<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Compat\CompatCategories;
use App\Domain\Import\CategoryMap;
use App\Domain\Import\CategoryMerger;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontRedirect;
use App\Support\Coerce;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 (2026-11-11): every public URL of the old Brand Fashion site → its URL on the new one.
 *
 * Built from the extract (`products.jsonl`, `site.json`) and THIS database, at the moment it runs —
 * so it is run again on switch day, when visibility and slugs are final. Rows are stored as
 * `storefront_redirects` (storefront 2, source `bf_legacy`, 301) and as a CSV for review.
 *
 * ── Paths, not URLs ─────────────────────────────────────────────────────────────────────────
 *
 * A row's `from_path` is the old path WITHOUT its trailing slash; the trailing-slash form, `www` /
 * apex, `http` / `https` and the Arabic `?lang=ar` form are HOST- and REQUEST-level rules (one rule
 * each, applied before the lookup), never four rows per URL. They are listed in the domain plan.
 *
 * ── Status of each row ──────────────────────────────────────────────────────────────────────
 *
 *   • `matched`   — the same thing exists on the new site (the product, the category, the page);
 *   • `same`      — the new URL IS the old one (a kept product slug): no row is needed, only the
 *                   trailing-slash rule;
 *   • `fallback`  — the thing has no page on the new site (a product hidden there, a category that
 *                   is switched off, a collection): sent to the nearest listing, not to a 404;
 *   • `ambiguous` — the old site had two products for one identity (same SKU or name); both URLs
 *                   go to the one product kept;
 *   • `unmatched` — nothing sensible exists; sent to the listing and listed for a decision.
 */
final class LegacyRedirects
{
    public const SOURCE = 'bf_legacy';

    /**
     * The old site's pages → the new site's. Decided by reading both sites' routes (2026-11-11);
     * `null` = no equivalent (listed as unmatched, sent to the listing).
     */
    public const PAGES = [
        'home' => '/',
        'about-us' => '/about-us',
        'contact-us' => '/contact-us',
        'privacy-policy' => '/privacy-policy',
        'terms-and-condition' => '/terms-and-conditions',
        'blog' => '/blogs',
        'shop' => '/listing',
        'brands' => '/listing',
        'new-arrivals' => '/listing?sort=newest',
        'sale' => '/listing?sale=1',
        'best-sellers' => '/listing',
        'search' => '/listing',
        'cart' => '/cart',
        'checkout' => '/checkout',
        'my-account' => '/account',
        'wishlist' => '/account',
        'sample-page' => null,
        'accept-payments' => null,
    ];

    /** The gender taxonomy's slugs → core's gender names (the listing's `gender` filter). */
    public const GENDER_SLUGS = ['man' => 'Men', 'woman' => 'Women', 'unisex' => 'Unisex'];

    private int $storefront = Storefront::BRAND_FASHION_ID;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, string>|null node id => the URL the Brand Fashion app serves it at */
    private ?array $nodeHrefs = null;

    private ?CompatCategories $tree = null;

    public function __construct(private readonly LegacyImporter $identity) {}

    /**
     * The whole map. Nothing is written.
     *
     * @return list<array{from: string, to: string, status: string, kind: string, note: string}>
     */
    public function build(string $productsFile, string $siteFile, ?string $imageMapFile, ?string $assetBase): array
    {
        $this->identity->prepare(null, $productsFile);
        $rows = [];

        // ── products ──
        $slugs = [];
        // Visible = what the product page shows: the placement visible AND the product active and not
        // deleted (a product switched off on the dashboard keeps its placement flag - 2026-11-11: product
        // 71, matched by SKU, answered 404 in the hop test).
        foreach (DB::table('storefront_product as sp')
            ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $this->storefront)
            ->get(['sp.product_id', 'sp.slug', 'sp.is_visible', 'cp.is_active', 'cp.deleted_at']) as $sp) {
            $r = Row::cast($sp);
            $slugs[Row::int($r, 'product_id')] = [
                'slug' => Row::str($r, 'slug'),
                'visible' => Row::bool($r, 'is_visible') && Row::bool($r, 'is_active') && Row::nstr($r, 'deleted_at') === null,
            ];
        }
        $handle = fopen($productsFile, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $record = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
                $from = '/product/'.Coerce::str($record['legacy_slug'] ?? null);
                $plan = $this->identity->plan($record);
                $productId = $plan['existing'];
                $status = match ($plan['action']) {
                    'already', 'match' => 'matched',
                    'skip' => $plan['reason'] === 'duplicate_in_source' ? 'ambiguous' : 'unmatched',
                    default => 'unmatched',
                };
                $rows[] = $this->productRow($from, $productId, $slugs, $status, $plan['reason']);
            }
            fclose($handle);
        }

        // ── terms, pages, posts ──
        $site = Coerce::arr(json_decode((string) file_get_contents($siteFile), true, 512, JSON_THROW_ON_ERROR));
        $brandSlugs = $this->brandSlugs();
        foreach (Coerce::arr($site['terms'] ?? null) as $t) {
            $term = Coerce::arr($t);
            $taxonomy = Coerce::str($term['taxonomy'] ?? null);
            $path = Coerce::str($term['path'] ?? null);
            $slug = Coerce::str($term['slug'] ?? null);
            $name = Coerce::str($term['name'] ?? null);
            $rows[] = match ($taxonomy) {
                'product_cat' => $this->categoryRow('/product-category/'.$path, $name),
                'brand' => isset($brandSlugs[LegacyImporter::canonicalBrandKey($name)])
                    ? $this->row('/brand-category/'.$slug, '/brand/'.$brandSlugs[LegacyImporter::canonicalBrandKey($name)], 'matched', 'brand', '')
                    : $this->row('/brand-category/'.$slug, '/listing', 'unmatched', 'brand', 'no such brand on Brand Fashion: '.$name),
                'gender' => isset(self::GENDER_SLUGS[$slug])
                    ? $this->row('/gender-category/'.$slug, '/listing?gender='.self::GENDER_SLUGS[$slug], 'matched', 'gender', '')
                    : $this->row('/gender-category/'.$slug, '/listing', 'unmatched', 'gender', $name),
                'collection' => $this->row('/collection/'.$slug, '/listing', 'unmatched', 'collection', 'no collections on the new site: '.$name),
                'product_tag' => $this->row('/product-tag/'.$slug, '/listing', 'unmatched', 'tag', 'no tags on the new site: '.$name),
                'category' => $this->row('/category/'.$slug, '/blogs', 'fallback', 'post category', 'blog category → the blog list (collides with /category/<slug> on the new site: must be served BEFORE that route)'),
                default => $this->row('/'.$slug, '/listing', 'unmatched', $taxonomy, ''),
            };
        }
        foreach (Coerce::arr($site['pages'] ?? null) as $p) {
            $slug = Coerce::str(Coerce::arr($p)['slug'] ?? null);
            $to = self::PAGES[$slug] ?? null;
            $rows[] = array_key_exists($slug, self::PAGES) && $to !== null
                ? $this->row('/'.$slug, $to, 'matched', 'page', '')
                : $this->row('/'.$slug, '/', 'unmatched', 'page', 'no equivalent page');
        }
        $blogs = [];
        foreach (DB::table('core_blogs')->where('storefront_id', $this->storefront)->whereNotNull('published_at')->pluck('slug') as $b) {
            $blogs[Coerce::str($b)] = true;
        }
        foreach (Coerce::arr($site['posts'] ?? null) as $p) {
            $post = Coerce::arr($p);
            $slug = Coerce::str($post['slug'] ?? null);
            // The article's slug on Brand Fashion (bf:legacy-blogs, D-13) — the same rule, computed here.
            $new = LegacyBlogs::newSlug($slug, Coerce::str($post['title'] ?? null));
            $rows[] = isset($blogs[$new])
                ? $this->row('/'.$slug, '/blog/'.$new, 'matched', 'post', $new === $slug ? '' : 'migrated under a new slug')
                : $this->row('/'.$slug, '/blogs', 'fallback', 'post', 'not published on Brand Fashion yet (a draft until its Arabic is written) — re-run after publishing');
        }

        // ── images: the ORIGINAL's URL → the master core made from it ──
        // The import's own map AND the image pass's shard maps beside it (bf:legacy-images, Block D, 2026-11-12): an
        // image the shards made is REUSED by the import and never written to the import's own map. One row per original.
        $maps = $imageMapFile === null || $assetBase === null ? [] : array_values(array_filter(
            [$imageMapFile, ...(glob(dirname($imageMapFile).'/image-map.shard-*.jsonl') ?: [])], 'is_file'));
        $seen = [];
        foreach ($maps as $map) {
            foreach (file($map, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = Coerce::arr(json_decode($line, true));
                $stored = Coerce::arr($entry['stored'] ?? null);
                $source = Coerce::str($entry['source'] ?? null);
                if ($source === '' || isset($seen[$source])) {
                    continue;
                }
                $seen[$source] = true;
                $rows[] = $this->row('/wp-content/uploads/'.$source,
                    rtrim(Coerce::str($assetBase), '/').'/Uploads_Images/'.Coerce::str($stored['folder'] ?? null).'/'.Coerce::str($stored['file'] ?? null),
                    'matched', 'image', '');
            }
        }

        return $rows;
    }

    /**
     * Write the rows that redirect (not `same`) as `storefront_redirects`; an existing row for the
     * same path is updated. Returns [written, kept unchanged].
     *
     * @param  list<array{from: string, to: string, status: string, kind: string, note: string}>  $rows
     * @return array{written: int, skipped_same: int}
     */
    public function store(array $rows): array
    {
        $written = 0;
        $same = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$written, &$same): void {
                foreach ($chunk as $row) {
                    if ($row['status'] === 'same') {
                        $same++;

                        continue;
                    }
                    StorefrontRedirect::query()->updateOrCreate(
                        ['storefront_id' => $this->storefront, 'from_hash' => StorefrontRedirect::hashPath($row['from'])],
                        ['from_path' => $row['from'], 'to_path' => $row['to'], 'status' => 301, 'source' => self::SOURCE],
                    );
                    $written++;
                }
            });
        }

        return ['written' => $written, 'skipped_same' => $same];
    }

    /**
     * @param  array<int, array{slug: string, visible: bool}>  $slugs
     * @return array{from: string, to: string, status: string, kind: string, note: string}
     */
    private function productRow(string $from, ?int $productId, array $slugs, string $status, string $reason): array
    {
        if ($productId === null || ! isset($slugs[$productId])) {
            return $this->row($from, '/listing', 'unmatched', 'product', $reason === '' ? 'not on Brand Fashion' : $reason);
        }
        $placement = $slugs[$productId];
        if (! $placement['visible']) {
            $category = $this->primaryCategory($productId);

            return $this->row($from, $category ?? '/listing', 'fallback', 'product', 'product '.$productId.' is hidden or switched off on Brand Fashion — re-run on switch day');
        }
        $to = '/product/'.$placement['slug'];

        return $this->row($from, $to, $to === $from && $status === 'matched' ? 'same' : $status, 'product', $reason);
    }

    /** @return array{from: string, to: string, status: string, kind: string, note: string} */
    private function categoryRow(string $from, string $leafName): array
    {
        $leaf = CategoryMap::leaf($leafName);
        if ($leaf === null) {
            return $this->row($from, '/listing', 'unmatched', 'category', 'not in CategoryMap: '.$leafName);
        }
        if ($leaf['kind'] === CategoryMap::GENDER) {
            return $this->row($from, '/listing?gender='.$leaf['to'], 'matched', 'category', 'a gender on the new site');
        }
        if ($leaf['kind'] !== CategoryMap::NODE) {
            return $this->row($from, '/listing', 'unmatched', 'category', 'mapped to no category: '.$leafName);
        }
        $node = CategoryMerger::find($this->storefront, $leaf['to']);
        if ($node === null) {
            return $this->row($from, '/listing', 'unmatched', 'category', 'no node '.$leaf['to'].' on Brand Fashion');
        }
        $href = $this->nodeHref($node);
        if ($href === null) {
            return $this->row($from, '/listing', 'fallback', 'category', 'node '.$leaf['to'].' and its parents have no page on Brand Fashion (switched off, out of the menu, or no visible product)');
        }
        if (! $href['exact']) {
            return $this->row($from, $href['to'], 'fallback', 'category', 'node '.$leaf['to'].' has no page on Brand Fashion: its nearest parent with one');
        }

        return $this->row($from, $href['to'], 'matched', 'category', '');
    }

    /**
     * The URL the Brand Fashion app serves a category node at, or its nearest ancestor's - the app's
     * own rule, read from its code (2026-11-11): a node has a page only when the catalogue answer lists
     * it (CompatCategories: active, in the menu, holding a visible product); depth 1 is
     * `/category/<slug>`, depth 2 `/subtypes/<slug>`, deeper nodes have none; the slug is the ENGLISH
     * NAME slugged (`toSlug`, src/utils/slugs.js - never the node's `slug` column), the legacy id when
     * the name slugs to nothing, and a slug two nodes share resolves to the first (by legacy id).
     *
     * @return array{to: string, exact: bool}|null
     */
    private function nodeHref(int $nodeId): ?array
    {
        $tree = $this->tree ??= new CompatCategories($this->storefront);
        if ($this->nodeHrefs === null) {
            $hrefs = [];
            foreach (['/category/' => $tree->categoryTypes(), '/subtypes/' => $tree->subTypes()] as $base => $list) {
                $taken = [];
                foreach ($list as $node) {
                    $slug = self::appSlug($tree->name($node, 'en') ?? $tree->name($node, 'ar') ?? '');
                    if ($slug === '') {
                        $slug = (string) CompatCategories::legacyIdOf($node);
                    }
                    if (isset($taken[$slug])) {
                        continue;
                    }
                    $taken[$slug] = true;
                    $hrefs[Val::int($node, 'id')] = $base.$slug;
                }
            }
            $this->nodeHrefs = $hrefs;
        }
        $node = $tree->nodes()[$nodeId] ?? null;
        if ($node === null) {
            return null;
        }
        $chain = array_reverse(array_map('intval', array_filter(explode('/', trim(Val::str($node, 'path'), '/')), 'ctype_digit')));
        foreach ($chain as $id) {
            if (isset($this->nodeHrefs[$id])) {
                return ['to' => $this->nodeHrefs[$id], 'exact' => $id === $nodeId];
            }
        }

        return null;
    }

    /** The Brand Fashion app's `toSlug` (src/utils/slugs.js), rule for rule. */
    public static function appSlug(string $name): string
    {
        $s = trim(mb_strtolower($name));
        $s = (string) preg_replace("/['\u{2019}`]/u", '', $s);
        $s = str_replace('&', '', $s);
        $s = (string) preg_replace('/[^a-z0-9\s-]/u', '', $s);
        $s = (string) preg_replace('/\s+/u', '-', $s);
        $s = (string) preg_replace('/-+/', '-', $s);

        return trim($s, '-');
    }

    private function primaryCategory(int $productId): ?string
    {
        $id = Coerce::nint(DB::table('storefront_category_product')
            ->where('storefront_id', $this->storefront)->where('product_id', $productId)
            ->where('is_primary', 1)->value('storefront_category_id'));

        return $id === null ? null : ($this->nodeHref($id)['to'] ?? null);
    }

    /** @return array<string, string> brand key => core brand slug */
    private function brandSlugs(): array
    {
        $out = [];
        foreach (DB::table('catalog_brands as b')
            ->leftJoin('catalog_brand_translations as t', fn (JoinClause $j) => $j->on('t.brand_id', '=', 'b.id')->where('t.locale', 'en'))
            ->get(['b.slug', 't.name']) as $b) {
            $r = Row::cast($b);
            foreach ([Row::nstr($r, 'name') ?? '', Row::str($r, 'slug')] as $name) {
                $key = LegacyImporter::brandKeyOf($name);
                if ($key !== '') {
                    $out[$key] ??= Row::str($r, 'slug');
                }
            }
        }

        return $out;
    }

    /** @return array{from: string, to: string, status: string, kind: string, note: string} */
    private function row(string $from, string $to, string $status, string $kind, string $note): array
    {
        $from = rtrim($from, '/');
        $this->counts[$kind.':'.$status] = ($this->counts[$kind.':'.$status] ?? 0) + 1;

        return ['from' => $from === '' ? '/' : $from, 'to' => $to, 'status' => $status, 'kind' => $kind, 'note' => $note];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        ksort($this->counts);

        return $this->counts;
    }
}
