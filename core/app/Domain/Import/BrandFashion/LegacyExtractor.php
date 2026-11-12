<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Import\CategoryMap;
use App\Support\Coerce;
use App\Transform\Row;
use Generator;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * Reads the OLD Brand Fashion WordPress/WooCommerce database and yields every PUBLISHED product,
 * cleaned, in core's vocabulary (2026-11-11). Read-only by construction: it only ever SELECTs, and
 * the command opens its connection in a read-only transaction.
 *
 * ── Why a file in between, instead of importing straight from WordPress ────────────────────────
 *
 * The WordPress database lives on the old site's server; the core database lives on Hostinger. The
 * extract runs wherever a copy of the WordPress dump is restored (the developer's machine), writes
 * ONE JSON-lines file holding products only — no order, no customer, no user — and the import reads
 * that file on whichever host it runs on. The file is also the review copy: every cleaned value the
 * import will write is in it, beside the legacy id and URL it came from.
 *
 * ── Everything is read in bulk ──────────────────────────────────────────────────────────────────
 *
 * 7,782 products × ~150 meta rows would be a million round trips one product at a time. Products
 * are read in chunks of {@see CHUNK}; meta, terms, variations and attachment files are each one
 * query per chunk.
 */
final class LegacyExtractor
{
    private const CHUNK = 400;

    /** Below this a regular price is not a price (measured minimum of real prices: 50 EGP). */
    public const PRICE_MIN = 20.0;

    /** Above this it is a typo, not a watch (measured maximum: the Seiko Astron at 214,380). */
    public const PRICE_MAX = 2_000_000.0;

    /** The scalar meta the extract reads; specs are matched by pattern on top of these. */
    private const META_KEYS = [
        '_sku', '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to',
        '_stock', '_manage_stock', '_stock_status', '_thumbnail_id', '_product_image_gallery',
        'ar_title', 'ar_description', 'product_specs_title',
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
    ];

    private const TAXONOMIES = ['product_cat', 'brand', 'gender', 'product_visibility', 'product_type'];

    /** @var array<string, int> what happened, by name — the report's numbers */
    private array $counts = [];

    /** @var array<string, array<string, int>> label/value tallies for the report */
    private array $tallies = [];

    private string $sitename = 'Brand Fashion';

    private string $separator = '-';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $prefix,
        private readonly string $siteUrl,
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new RuntimeException("Not a table prefix: [{$prefix}]");
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        ksort($this->counts);

        return $this->counts;
    }

    /** @return array<string, array<string, int>> */
    public function tallies(): array
    {
        $out = [];
        foreach ($this->tallies as $name => $values) {
            arsort($values);
            $out[$name] = $values;
        }

        return $out;
    }

    /**
     * Every published product, one cleaned record at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function products(): Generator
    {
        $this->readSiteOptions();

        $ids = [];
        foreach (self::objects($this->db->select("SELECT ID FROM {$this->t('posts')} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID")) as $row) {
            $ids[] = Row::int($row, 'ID');
        }

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $posts = $this->postsById($chunk);
            $meta = $this->meta($chunk);
            $terms = $this->terms($chunk);
            $variations = $this->variations($chunk);

            $attachmentIds = [];
            foreach ($chunk as $id) {
                foreach ($this->imageIds($meta[$id] ?? []) as $a) {
                    $attachmentIds[$a] = true;
                }
            }
            foreach ($variations as $list) {
                foreach ($list as $v) {
                    $thumb = (int) ($v['meta']['_thumbnail_id'] ?? 0);
                    if ($thumb > 0) {
                        $attachmentIds[$thumb] = true;
                    }
                }
            }
            $files = $this->attachments(array_keys($attachmentIds));

            foreach ($chunk as $id) {
                if (! isset($posts[$id])) {
                    continue;
                }
                $this->count('products_read');

                yield $this->record($posts[$id], $meta[$id] ?? [], $terms[$id] ?? [], $variations[$id] ?? [], $files);
            }
        }
    }

    /**
     * Everything else the old site exposed at a URL, for the redirect map and the SEO carry-over:
     * product categories (with their full path, as WooCommerce builds `/product-category/a/b/`),
     * brand / gender / collection / tag terms, published pages and posts, post categories — each
     * with its Rank Math title, description and focus keyword where one was typed.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function site(): array
    {
        $this->readSiteOptions();
        $out = [];

        $terms = [];
        foreach (self::objects($this->db->select(
            "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.count FROM {$this->t('terms')} t
             JOIN {$this->t('term_taxonomy')} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ('product_cat', 'brand', 'gender', 'collection', 'product_tag', 'category') ORDER BY t.term_id",
        )) as $row) {
            $r = $row;
            $terms[Row::int($r, 'term_id')] = $r;
        }
        $termMeta = [];
        if ($terms !== []) {
            $ids = array_keys($terms);
            $in = self::placeholders($ids);
            foreach (self::objects($this->db->select(
                "SELECT term_id, meta_key, meta_value FROM {$this->t('termmeta')} WHERE term_id IN ({$in})
                 AND meta_key IN ('rank_math_title', 'rank_math_description', 'rank_math_focus_keyword')",
                $ids,
            )) as $row) {
                $r = $row;
                $termMeta[Row::int($r, 'term_id')][Row::str($r, 'meta_key')] = LegacyText::line(Row::nstr($r, 'meta_value'));
            }
        }
        foreach ($terms as $id => $r) {
            $taxonomy = Row::str($r, 'taxonomy');
            $path = [Row::str($r, 'slug')];
            $parent = Row::int($r, 'parent');
            for ($guard = 0; $parent > 0 && isset($terms[$parent]) && $guard < 10; $guard++) {
                array_unshift($path, Row::str($terms[$parent], 'slug'));
                $parent = Row::int($terms[$parent], 'parent');
            }
            $out['terms'][] = [
                'term_id' => $id,
                'taxonomy' => $taxonomy,
                'name' => LegacyText::line(Row::str($r, 'name')),
                'slug' => Row::str($r, 'slug'),
                'path' => implode('/', $path),
                'count' => Row::int($r, 'count'),
                'seo' => [
                    'title' => $termMeta[$id]['rank_math_title'] ?? null,
                    'description' => $termMeta[$id]['rank_math_description'] ?? null,
                    'focus_keyword' => $termMeta[$id]['rank_math_focus_keyword'] ?? null,
                ],
            ];
        }

        foreach (self::objects($this->db->select(
            "SELECT p.ID, p.post_type, p.post_name, p.post_title, p.post_date, p.post_content,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = 'ar_title' LIMIT 1) AS ar_title,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = 'ar_description' LIMIT 1) AS ar_body,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id' LIMIT 1) AS thumb,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = 'rank_math_title' LIMIT 1) AS rm_title,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = 'rank_math_description' LIMIT 1) AS rm_description,
                (SELECT meta_value FROM {$this->t('postmeta')} m WHERE m.post_id = p.ID AND m.meta_key = 'rank_math_focus_keyword' LIMIT 1) AS rm_keyword
             FROM {$this->t('posts')} p WHERE p.post_type IN ('page', 'post') AND p.post_status = 'publish' ORDER BY p.post_type, p.ID",
        )) as $row) {
            $r = $row;
            $type = Row::str($r, 'post_type');
            $entry = [
                'id' => Row::int($r, 'ID'),
                'slug' => rawurldecode(Row::str($r, 'post_name')),
                'title' => LegacyText::line(Row::str($r, 'post_title')),
                'published_at' => Row::str($r, 'post_date'),
                'seo' => [
                    'title' => LegacyText::line(Row::nstr($r, 'rm_title')) ?: null,
                    'description' => LegacyText::line(Row::nstr($r, 'rm_description')) ?: null,
                    'focus_keyword' => LegacyText::line(Row::nstr($r, 'rm_keyword')) ?: null,
                ],
            ];
            if ($type === 'post') {
                // The article itself, for the blog migration (D-13): both bodies as the old site stored
                // them (HTML — `LegacyMarkdown` converts), the Arabic title, and every upload ORIGINAL the
                // article shows (its featured image and the images in either body).
                $thumb = Coerce::int(Row::nstr($r, 'thumb'));
                $files = $thumb > 0 ? $this->attachments([$thumb]) : [];
                $entry += [
                    'title_ar' => LegacyText::title(Row::nstr($r, 'ar_title')),
                    'body_en_html' => Row::str($r, 'post_content'),
                    'body_ar_html' => Row::nstr($r, 'ar_body') ?? '',
                    'cover_file' => $files[$thumb]['file'] ?? null,
                    'image_files' => array_values(array_unique(array_merge(
                        LegacyMarkdown::uploadFiles(Row::str($r, 'post_content')),
                        LegacyMarkdown::uploadFiles(Row::nstr($r, 'ar_body') ?? ''),
                    ))),
                ];
            }
            $out[$type.'s'][] = $entry;
        }

        // Attachment pages: Rank Math 301s them to their parent (attachment_redirect_urls = on),
        // so they were never pages in Google's index — counted, not mapped one by one.
        $att = $this->db->selectOne("SELECT COUNT(*) AS n, SUM(post_parent > 0) AS with_parent FROM {$this->t('posts')} WHERE post_type = 'attachment'");
        $out['attachments'] = [is_object($att) ? ['total' => Row::int(Row::cast($att), 'n'), 'with_parent' => Coerce::int(Row::cast($att)->with_parent ?? 0)] : []];

        return $out;
    }

    /**
     * One product, cleaned.
     *
     * @param  array<string, string>  $meta
     * @param  array<string, list<array{name: string, slug: string}>>  $terms
     * @param  list<array{id: int, title: string, meta: array<string, string>}>  $variations
     * @param  array<int, array{file: string, alt: string}>  $files
     * @return array<string, mixed>
     */
    private function record(stdClass $post, array $meta, array $terms, array $variations, array $files): array
    {
        $flags = [];
        $id = Row::int($post, 'ID');
        $rawTitle = Row::str($post, 'post_title');
        $rawSlug = Row::str($post, 'post_name');

        $titleEn = LegacyText::title($rawTitle);
        $titleAr = LegacyText::title($meta['ar_title'] ?? '');
        if ($rawTitle !== $titleEn && LegacyText::line($rawTitle) !== $titleEn) {
            $flags[] = 'title_cleaned';
        }
        if (preg_match('/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $rawTitle) === 1) {
            $this->count('title_entities_decoded');
        }
        if ($titleAr === '') {
            $flags[] = 'no_arabic_title';
        }
        $wasCopy = LegacyText::wasCopy($rawTitle, $rawSlug);
        if ($wasCopy) {
            $flags[] = 'was_copy';
        }

        // ── descriptions ─────────────────────────────────────────────────────────────────────
        $contentEn = Row::str($post, 'post_content');
        $longEn = LegacyText::paragraphs($contentEn);
        $longAr = LegacyText::paragraphs($meta['ar_description'] ?? '');
        if (LegacyText::hadMarkup($contentEn) || LegacyText::hadMarkup($meta['ar_description'] ?? '')) {
            $flags[] = 'html_stripped';
        }
        $shortEn = LegacyText::paragraphs(Row::str($post, 'post_excerpt'));
        $derived = [];
        if ($shortEn === '' && $longEn !== '') {
            $shortEn = LegacyText::shortFrom($longEn);
            $derived[] = 'en';
        }
        $shortAr = '';
        if ($longAr !== '') {
            $shortAr = LegacyText::shortFrom($longAr);
            $derived[] = 'ar';
        }
        if ($longEn === '') {
            $flags[] = 'no_english_description';
        }
        if ($longAr === '') {
            $flags[] = 'no_arabic_description';
        }

        // ── price ────────────────────────────────────────────────────────────────────────────
        $type = $terms['product_type'][0]['slug'] ?? 'simple';
        [$regular, $sale, $priceIssue, $variants] = $this->prices($meta, $type, $variations, $files, $flags);

        // ── stock ────────────────────────────────────────────────────────────────────────────
        $managed = ($meta['_manage_stock'] ?? 'no') === 'yes';
        $quantity = $managed && is_numeric($meta['_stock'] ?? null) ? (int) $meta['_stock'] : null;
        $status = $meta['_stock_status'] ?? 'instock';
        if (! $managed && $status === 'instock' && $variants === []) {
            $flags[] = 'in_stock_without_count';
        }

        // ── taxonomy ─────────────────────────────────────────────────────────────────────────
        $categories = [];
        $paths = [];
        $unmapped = [];
        $genders = [];
        $materials = [];
        foreach ($terms['product_cat'] ?? [] as $term) {
            $name = LegacyText::line($term['name']);
            $categories[] = $name;
            $leaf = CategoryMap::leaf($name);
            if ($leaf === null) {
                $unmapped[] = $name;

                continue;
            }
            if ($leaf['kind'] === CategoryMap::GENDER) {
                $genders[$leaf['to']] = true;
            } elseif ($leaf['kind'] === CategoryMap::NODE) {
                $paths[$leaf['to']] = true;
                if (isset($leaf['material'])) {
                    $materials[$leaf['material']] = true;
                }
            }
        }
        foreach ($terms['gender'] ?? [] as $term) {
            $g = LegacySpecs::gender($term['name']);
            if ($g !== null) {
                $genders[$g] = true;
            }
        }
        $brand = isset($terms['brand'][0]) ? LegacyText::line($terms['brand'][0]['name']) : null;
        if (count($terms['brand'] ?? []) > 1) {
            $flags[] = 'several_brands';
        }

        $visibility = array_column($terms['product_visibility'] ?? [], 'slug');
        $catalogVisible = ! in_array('exclude-from-catalog', $visibility, true);
        if (! $catalogVisible) {
            $flags[] = 'hidden_on_old_site';
        }

        // ── specs ────────────────────────────────────────────────────────────────────────────
        [$mapped, $unmappedSpecs] = $this->specs($meta);
        foreach ($mapped as $spec) {
            if ($spec['kind'] === LegacySpecs::GENDER) {
                $g = LegacySpecs::gender($spec['value']);
                if ($g !== null) {
                    $genders[$g] = true;
                }
            }
        }

        // ── images: the ORIGINAL of each attachment, featured first ──────────────────────────
        $images = [];
        $missingImages = 0;
        foreach ($this->imageIds($meta) as $attachmentId) {
            if (! isset($files[$attachmentId])) {
                $missingImages++;

                continue;
            }
            $images[$files[$attachmentId]['file']] = [
                'attachment_id' => $attachmentId,
                'file' => $files[$attachmentId]['file'],
                'alt' => LegacyText::line($files[$attachmentId]['alt']),
            ];
        }
        foreach ($variants as $variant) {
            $file = $variant['image'] ?? null;
            if (is_string($file) && ! isset($images[$file])) {
                $images[$file] = ['attachment_id' => 0, 'file' => $file, 'alt' => ''];
                $this->count('variant_images_added_to_gallery');
            }
        }
        if ($missingImages > 0) {
            $flags[] = 'image_record_missing';
        }
        if ($images === []) {
            $flags[] = 'no_image';
        }

        // ── SEO ──────────────────────────────────────────────────────────────────────────────
        $seoTitle = $this->seoTitle($meta['rank_math_title'] ?? '', $titleEn);
        $seoDescription = LegacyText::line($meta['rank_math_description'] ?? '');
        if (str_contains($seoDescription, '%')) {
            $this->count('seo_description_with_variables');
            $seoDescription = '';
        }

        foreach ($flags as $flag) {
            $this->count('flag_'.$flag);
        }

        $legacySlug = rawurldecode($rawSlug);

        return [
            'legacy_id' => $id,
            'legacy_slug' => $legacySlug,
            'legacy_url' => rtrim($this->siteUrl, '/').'/product/'.$rawSlug.'/',
            'slug' => LegacyText::slug($rawSlug),
            'was_copy' => $wasCopy,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'short_en' => $shortEn,
            'long_en' => $longEn,
            'short_ar' => $shortAr,
            'long_ar' => $longAr,
            'short_derived' => $derived,
            'sku' => LegacyText::line($meta['_sku'] ?? '') === '' ? null : LegacyText::line($meta['_sku'] ?? ''),
            'type' => $type,
            'regular' => $regular,
            'sale' => $sale,
            'price_issue' => $priceIssue,
            'stock' => ['managed' => $managed, 'quantity' => $quantity, 'status' => $status],
            'categories' => $categories,
            'category_paths' => array_keys($paths),
            'unmapped_categories' => $unmapped,
            'genders' => array_keys($genders),
            'materials' => array_keys($materials),
            'brand' => $brand,
            'catalog_visible' => $catalogVisible,
            'featured' => in_array('featured', $visibility, true),
            'variants' => $variants,
            'images' => array_values($images),
            'specs' => $mapped,
            'specs_unmapped' => $unmappedSpecs,
            'seo' => [
                'title' => $seoTitle,
                'description' => $seoDescription === '' ? null : $seoDescription,
                'focus_keyword' => LegacyText::line($meta['rank_math_focus_keyword'] ?? '') === '' ? null : LegacyText::line($meta['rank_math_focus_keyword'] ?? ''),
            ],
            'published_at' => Row::str($post, 'post_date'),
            'flags' => $flags,
        ];
    }

    /**
     * Price rules: a regular price must be a real number in range; a sale counts only when
     * `0 < sale < regular` (core's price contract) and its schedule, if any, is still running. A
     * variable product is priced from its variations: the lowest regular price is the product's,
     * each variation carries its difference; a sale survives only if every variation has the same
     * regular and sale (otherwise it is dropped and flagged — core has no per-variant sale).
     *
     * @param  array<string, string>  $meta
     * @param  list<array{id: int, title: string, meta: array<string, string>}>  $variations
     * @param  array<int, array{file: string, alt: string}>  $files
     * @param  list<string>  $flags
     * @return array{0: float|null, 1: float|null, 2: string|null, 3: list<array<string, mixed>>}
     */
    private function prices(array $meta, string $type, array $variations, array $files, array &$flags): array
    {
        $variants = [];
        if ($type === 'variable') {
            $regulars = [];
            $sales = [];
            foreach ($variations as $v) {
                $vm = $v['meta'];
                $reg = self::money($vm['_regular_price'] ?? null);
                $sale = self::money($vm['_sale_price'] ?? null);
                [$size, $colour, $label] = $this->variantAttributes($vm, $v['title']);
                $thumb = (int) ($vm['_thumbnail_id'] ?? 0);
                $variants[] = [
                    'legacy_id' => $v['id'],
                    'label' => $label,
                    'size' => $size,
                    'colour' => $colour,
                    'regular' => $reg,
                    'sale' => $sale,
                    'sku' => LegacyText::line($vm['_sku'] ?? '') === '' ? null : LegacyText::line($vm['_sku'] ?? ''),
                    'managed' => ($vm['_manage_stock'] ?? 'no') === 'yes',
                    'quantity' => is_numeric($vm['_stock'] ?? null) ? (int) $vm['_stock'] : null,
                    'status' => $vm['_stock_status'] ?? 'instock',
                    'image' => $thumb > 0 && isset($files[$thumb]) ? $files[$thumb]['file'] : null,
                ];
                if ($reg !== null && $reg > 0) {
                    $regulars[] = $reg;
                    $sales[] = $sale;
                }
                $this->count('variations_read');
            }

            if ($regulars === []) {
                $flags[] = 'no_price';

                return [null, null, 'no_price', $variants];
            }
            $regular = min($regulars);
            $sameRegular = count(array_unique(array_map('strval', $regulars))) === 1;
            $sameSale = count(array_unique(array_map(fn (?float $s): string => (string) $s, $sales))) === 1;
            $sale = $sameRegular && $sameSale ? $sales[0] : null;
            if (! ($sameRegular && $sameSale) && array_filter($sales) !== []) {
                $flags[] = 'variant_sale_dropped';
            }
            foreach ($variants as $i => $variant) {
                $variants[$i]['price_delta'] = is_float($variant['regular']) ? round($variant['regular'] - $regular, 2) : 0.0;
            }
        } else {
            $regular = self::money($meta['_regular_price'] ?? null);
            $sale = self::money($meta['_sale_price'] ?? null);
        }

        if ($regular === null || $regular <= 0) {
            $flags[] = 'no_price';

            return [null, null, 'no_price', $variants];
        }
        if ($regular < self::PRICE_MIN) {
            $flags[] = 'price_too_low';

            return [$regular, null, 'price_too_low', $variants];
        }
        if ($regular > self::PRICE_MAX) {
            $flags[] = 'price_too_high';

            return [$regular, null, 'price_too_high', $variants];
        }

        if ($sale !== null) {
            $to = $meta['_sale_price_dates_to'] ?? '';
            $from = $meta['_sale_price_dates_from'] ?? '';
            if (is_numeric($to) && (int) $to < time()) {
                $flags[] = 'sale_schedule_ended';
                $sale = null;
            } elseif (is_numeric($from) && (int) $from > time()) {
                $flags[] = 'sale_schedule_future';
                $sale = null;
            } elseif ($sale <= 0) {
                $sale = null;
            } elseif ($sale >= $regular) {
                $flags[] = 'sale_not_below_regular';
                $sale = null;
            }
        }

        return [$regular, $sale, null, $variants];
    }

    /**
     * A variation's size and colour from its `attribute_*` meta, named by the attribute term's
     * NAME (`pa_shoes-size` = `40` → "40"), plus a readable label.
     *
     * @param  array<string, string>  $meta
     * @return array{0: string|null, 1: string|null, 2: string}
     */
    private function variantAttributes(array $meta, string $title): array
    {
        $size = null;
        $colour = null;
        foreach ($meta as $key => $value) {
            if (! str_starts_with($key, 'attribute_') || trim($value) === '') {
                continue;
            }
            $attribute = substr($key, strlen('attribute_'));
            $name = str_starts_with($attribute, 'pa_') ? $this->termName($attribute, $value) : LegacyText::line($value);
            if (str_contains($attribute, 'color') || str_contains($attribute, 'colour')) {
                $colour = $name;
            } else {
                $size = $name;
            }
        }
        $label = trim(implode(' / ', array_filter([$size, $colour])));
        if ($label === '') {
            $label = LegacyText::title($title);
            $this->count('variation_without_attribute');
        }

        return [$size, $colour, mb_substr($label, 0, 100)];
    }

    /** @var array<string, string> taxonomy|slug => name */
    private array $termNames = [];

    private function termName(string $taxonomy, string $slug): string
    {
        $key = $taxonomy.'|'.$slug;
        if (! isset($this->termNames[$key])) {
            $row = $this->db->selectOne(
                "SELECT t.name FROM {$this->t('terms')} t JOIN {$this->t('term_taxonomy')} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = ? AND t.slug = ? LIMIT 1",
                [$taxonomy, $slug],
            );
            $this->termNames[$key] = is_object($row) ? LegacyText::line(Row::str(Row::cast($row), 'name')) : LegacyText::line($slug);
        }

        return $this->termNames[$key];
    }

    /**
     * The spec rows, split into what core has a field for and what it has not.
     *
     * @param  array<string, string>  $meta
     * @return array{0: list<array{kind: string, target: string, list: string, label: string, value: string, value_ar: string}>, 1: list<array{label: string, value: string}>}
     */
    private function specs(array $meta): array
    {
        $mapped = [];
        $unmapped = [];
        for ($i = 0; $i <= 40; $i++) {
            $label = LegacyText::line($meta["product_specs_{$i}_text"] ?? '');
            $value = LegacyText::line($meta["product_specs_{$i}_content"] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }
            // ACF field keys pasted as labels/values (`field_62d5e19580c76`) are noise, not specs.
            if (preg_match('/^field_[0-9a-f]{10,}$/', $label) === 1 || preg_match('/^field_[0-9a-f]{10,}$/', $value) === 1) {
                $this->count('spec_acf_key_noise');

                continue;
            }
            $norm = LegacySpecs::normaliseLabel($label);
            $rule = LegacySpecs::forLabel($label);
            if ($rule === null) {
                $unmapped[] = ['label' => $label, 'value' => $value];
                $this->tally('spec_label_unmapped', $norm);

                continue;
            }
            $mapped[] = [
                'kind' => $rule[0], 'target' => $rule[1], 'list' => $rule[2], 'label' => $norm,
                'value' => $value, 'value_ar' => LegacyText::line($meta["product_specs_{$i}_content_ar"] ?? ''),
            ];
            $this->tally('spec_label_mapped', $norm);
        }

        return [$mapped, $unmapped];
    }

    /**
     * Rank Math's title, only where it says more than the default `%title% %sep% %sitename%`:
     * `%title%`/`%sep%`/`%sitename%` expanded, a trailing " - Brand Fashion" removed (the storefront
     * appends its own name), and anything with another `%variable%` left out and counted.
     */
    private function seoTitle(string $raw, string $title): ?string
    {
        $raw = LegacyText::line($raw);
        if ($raw === '') {
            return null;
        }
        $text = str_replace(['%title%', '%sep%', '%sitename%'], [$title, $this->separator, $this->sitename], $raw);
        if (preg_match('/%[a-z_]+%/i', $text) === 1) {
            $this->count('seo_title_with_variables');

            return null;
        }
        $tail = ' '.$this->separator.' '.$this->sitename;
        if (str_ends_with($text, $tail)) {
            $text = trim(substr($text, 0, -strlen($tail)));
        }
        if ($text === '' || mb_strtolower($text) === mb_strtolower($title)) {
            $this->count('seo_title_same_as_default');

            return null;
        }
        $this->count('seo_title_kept');

        return mb_substr($text, 0, 255);
    }

    /**
     * @param  array<string, string>  $meta
     * @return list<int> featured first, then the gallery
     */
    private function imageIds(array $meta): array
    {
        $ids = [];
        $thumb = (int) ($meta['_thumbnail_id'] ?? 0);
        if ($thumb > 0) {
            $ids[$thumb] = true;
        }
        foreach (explode(',', $meta['_product_image_gallery'] ?? '') as $part) {
            $a = (int) trim($part);
            if ($a > 0) {
                $ids[$a] = true;
            }
        }

        return array_keys($ids);
    }

    private static function money(?string $value): ?float
    {
        $v = trim((string) $value);
        if ($v === '' || ! is_numeric($v)) {
            return null;
        }

        return round((float) $v, 2);
    }

    // ── bulk reads ───────────────────────────────────────────────────────────────────────────

    private function readSiteOptions(): void
    {
        $name = $this->db->selectOne("SELECT option_value FROM {$this->t('options')} WHERE option_name = 'blogname'");
        if (is_object($name)) {
            $this->sitename = LegacyText::line(Row::str(Row::cast($name), 'option_value'));
        }
        $titles = $this->db->selectOne("SELECT option_value FROM {$this->t('options')} WHERE option_name = 'rank-math-options-titles'");
        if (is_object($titles)) {
            $options = @unserialize(Row::str(Row::cast($titles), 'option_value'), ['allowed_classes' => false]);
            if (is_array($options) && is_string($options['title_separator'] ?? null) && $options['title_separator'] !== '') {
                $this->separator = $options['title_separator'];
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    private function postsById(array $ids): array
    {
        $out = [];
        $in = self::placeholders($ids);
        foreach (self::objects($this->db->select("SELECT ID, post_title, post_name, post_content, post_excerpt, post_date FROM {$this->t('posts')} WHERE ID IN ({$in})", $ids)) as $row) {
            $r = $row;
            $out[Row::int($r, 'ID')] = $r;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function meta(array $ids): array
    {
        $in = self::placeholders($ids);
        $keys = self::placeholders(self::META_KEYS);
        $out = [];
        $rows = self::objects($this->db->select(
            "SELECT post_id, meta_key, meta_value FROM {$this->t('postmeta')} WHERE post_id IN ({$in})
             AND (meta_key IN ({$keys}) OR meta_key REGEXP '^product_specs_[0-9]+_(text|content)(_ar)?$')",
            array_merge($ids, self::META_KEYS),
        ));
        foreach ($rows as $row) {
            $r = $row;
            $postId = Row::int($r, 'post_id');
            $key = Row::str($r, 'meta_key');
            // The first value wins, as get_post_meta(…, true) does.
            if (! isset($out[$postId][$key])) {
                $out[$postId][$key] = Row::nstr($r, 'meta_value') ?? '';
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, list<array{name: string, slug: string}>>>
     */
    private function terms(array $ids): array
    {
        $in = self::placeholders($ids);
        $tax = self::placeholders(self::TAXONOMIES);
        $out = [];
        $rows = self::objects($this->db->select(
            "SELECT r.object_id, tt.taxonomy, t.name, t.slug FROM {$this->t('term_relationships')} r
             JOIN {$this->t('term_taxonomy')} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
             JOIN {$this->t('terms')} t ON t.term_id = tt.term_id
             WHERE r.object_id IN ({$in}) AND tt.taxonomy IN ({$tax}) ORDER BY r.object_id, tt.taxonomy, t.term_id",
            array_merge($ids, self::TAXONOMIES),
        ));
        foreach ($rows as $row) {
            $r = $row;
            $out[Row::int($r, 'object_id')][Row::str($r, 'taxonomy')][] = ['name' => Row::str($r, 'name'), 'slug' => Row::str($r, 'slug')];
        }

        return $out;
    }

    /**
     * @param  list<int>  $parentIds
     * @return array<int, list<array{id: int, title: string, meta: array<string, string>}>>
     */
    private function variations(array $parentIds): array
    {
        $in = self::placeholders($parentIds);
        $posts = self::objects($this->db->select(
            "SELECT ID, post_parent, post_title FROM {$this->t('posts')} WHERE post_type = 'product_variation' AND post_status = 'publish' AND post_parent IN ({$in}) ORDER BY post_parent, menu_order, ID",
            $parentIds,
        ));
        if ($posts === []) {
            return [];
        }
        $ids = [];
        foreach ($posts as $p) {
            $ids[] = Row::int($p, 'ID');
        }
        $meta = [];
        $vin = self::placeholders($ids);
        foreach (self::objects($this->db->select(
            "SELECT post_id, meta_key, meta_value FROM {$this->t('postmeta')} WHERE post_id IN ({$vin})
             AND (meta_key IN ('_sku','_regular_price','_sale_price','_stock','_manage_stock','_stock_status','_thumbnail_id') OR meta_key LIKE 'attribute\\_%')",
            $ids,
        )) as $row) {
            $r = $row;
            $meta[Row::int($r, 'post_id')][Row::str($r, 'meta_key')] ??= Row::nstr($r, 'meta_value') ?? '';
        }
        $out = [];
        foreach ($posts as $p) {
            $r = $p;
            $id = Row::int($r, 'ID');
            $out[Row::int($r, 'post_parent')][] = ['id' => $id, 'title' => Row::str($r, 'post_title'), 'meta' => $meta[$id] ?? []];
        }

        return $out;
    }

    /**
     * The ORIGINAL file of each attachment — `_wp_attached_file`, relative to `wp-content/uploads`
     * — never one of WordPress's size variants (`-300x300`); core's pipeline makes its own.
     *
     * @param  list<int>  $ids
     * @return array<int, array{file: string, alt: string}>
     */
    private function attachments(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = self::placeholders($ids);
        $out = [];
        foreach (self::objects($this->db->select(
            "SELECT post_id, meta_key, meta_value FROM {$this->t('postmeta')} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file', '_wp_attachment_image_alt')",
            $ids,
        )) as $row) {
            $r = $row;
            $id = Row::int($r, 'post_id');
            $value = Row::nstr($r, 'meta_value') ?? '';
            if (Row::str($r, 'meta_key') === '_wp_attached_file') {
                $file = ltrim(str_replace('\\', '/', $value), '/');
                // A path that climbs out of uploads/ is never followed — not even read.
                if ($file === '' || str_contains($file, '..') || preg_match('/^[a-z]+:/i', $file) === 1) {
                    $this->count('image_path_refused');

                    continue;
                }
                $out[$id]['file'] = $file;
                $out[$id]['alt'] ??= '';
            } else {
                $out[$id]['alt'] = $value;
            }
        }

        $files = [];
        foreach ($out as $id => $entry) {
            if (isset($entry['file'])) {
                $files[$id] = ['file' => $entry['file'], 'alt' => $entry['alt']];
            }
        }

        return $files;
    }

    /**
     * A raw select's rows, as objects (`select()` is typed `array`: it can be told to return arrays).
     *
     * @param  array<mixed>  $rows
     * @return list<stdClass>
     */
    private static function objects(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = Row::cast($row);
            }
        }

        return $out;
    }

    private function t(string $table): string
    {
        return '`'.$this->prefix.$table.'`';
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    private function count(string $name, int $by = 1): void
    {
        $this->counts[$name] = ($this->counts[$name] ?? 0) + $by;
    }

    private function tally(string $name, string $value): void
    {
        $this->tallies[$name][$value] = ($this->tallies[$name][$value] ?? 0) + 1;
    }
}
