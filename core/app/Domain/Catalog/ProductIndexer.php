<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * `catalog_product_search` maintained from the CLEAN tables — the "future ProductIndexer" the
 * transform's step 21 has been naming in its own docblock since wave 1.
 *
 * Step 21 builds the index from LEGACY names so that on switch night the index matches what the
 * legacy storefront searches today. The moment the dashboard can edit a title, a brand or a
 * category, that stops being enough: an edited product would keep answering searches by its old
 * words, and a product created here would answer none at all. So every write path that can change
 * a searchable word calls this.
 *
 * Same body composition as step 21, deliberately — title ∥ brand ∥ model_name ∥ model_number ∥
 * search_keywords ∥ category names, per locale — so a re-index of an untouched product produces
 * the byte-identical row and a transform re-run finds nothing to change. That property is what
 * makes it safe to call this after every edit: it is a rebuild, not a second opinion.
 *
 * The index is word-based InnoDB FULLTEXT (`cps_body_ft`): MariaDB has no ngram parser and
 * `innodb_ft_min_token_size = 3` is fixed (AGENTS §2.3), so a term under three characters never
 * matches through it and every caller needs the `LIKE` fallback. That is the storefront's rule
 * (study §5.1 L6 / R2-16) and the dashboard list follows it.
 */
final class ProductIndexer
{
    /** Locales the index carries. Both, always: fallback is off and search must work in each. */
    public const LOCALES = ['ar', 'en'];

    /**
     * Rebuild the index rows for one product. Safe to call repeatedly.
     */
    public function reindex(int $productId): void
    {
        $product = DB::table('catalog_products')
            ->where('id', $productId)
            ->first(['id', 'brand_id', 'model_number', 'search_keywords']);

        if ($product === null) {
            DB::table('catalog_product_search')->where('product_id', $productId)->delete();

            return;
        }

        $translations = $this->productTranslations($productId);
        $brandNames = $this->brandNames(is_numeric($product->brand_id) ? (int) $product->brand_id : 0);
        $categoryNames = $this->categoryNames($productId);

        foreach (self::LOCALES as $locale) {
            $other = $locale === 'en' ? 'ar' : 'en';
            $translation = $translations[$locale] ?? $translations[$other] ?? ['title' => 'Product '.$productId, 'model_name' => ''];

            $parts = [
                $translation['title'],
                $brandNames[$locale] ?? '',
                $translation['model_name'],
                is_string($product->model_number) ? trim($product->model_number) : '',
                is_string($product->search_keywords) ? trim($product->search_keywords) : '',
                ...($categoryNames[$locale] ?? []),
            ];

            $body = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, fn (string $s): bool => $s !== ''))));

            DB::table('catalog_product_search')->updateOrInsert(
                ['product_id' => $productId, 'locale' => $locale],
                ['body' => $body],
            );
        }
    }

    /**
     * @return array<string, array{title: string, model_name: string}>
     */
    private function productTranslations(int $productId): array
    {
        $rows = DB::table('catalog_product_translations')
            ->where('product_id', $productId)
            ->whereIn('locale', self::LOCALES)
            ->get(['locale', 'title', 'model_name']);

        $out = [];
        foreach ($rows as $row) {
            $locale = is_string($row->locale) ? $row->locale : '';
            $out[$locale] = [
                'title' => is_string($row->title) ? trim($row->title) : '',
                'model_name' => is_string($row->model_name) ? trim($row->model_name) : '',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function brandNames(int $brandId): array
    {
        if ($brandId === 0) {
            return [];
        }

        $rows = DB::table('catalog_brand_translations')
            ->where('brand_id', $brandId)
            ->whereIn('locale', self::LOCALES)
            ->get(['locale', 'name']);

        $out = [];
        foreach ($rows as $row) {
            $out[is_string($row->locale) ? $row->locale : ''] = is_string($row->name) ? trim($row->name) : '';
        }

        return $out;
    }

    /**
     * The names of every category the product sits in, per locale.
     *
     * Step 21 uses the legacy (category type, sub type) pair; the clean equivalent is the set of
     * nodes the product is placed in, which is the same two names for a transformed product and
     * the right answer for a product placed by hand in a deeper tree.
     *
     * @return array<string, list<string>>
     */
    private function categoryNames(int $productId): array
    {
        $rows = DB::table('storefront_category_product as scp')
            ->join('storefront_category_translations as t', 't.storefront_category_id', '=', 'scp.storefront_category_id')
            ->where('scp.product_id', $productId)
            ->whereIn('t.locale', self::LOCALES)
            ->orderBy('scp.storefront_category_id')
            ->get(['t.locale', 't.name']);

        $out = [];
        foreach ($rows as $row) {
            $locale = is_string($row->locale) ? $row->locale : '';
            $name = is_string($row->name) ? trim($row->name) : '';
            if ($name === '' || in_array($name, $out[$locale] ?? [], true)) {
                continue;
            }
            $out[$locale][] = $name;
        }

        return $out;
    }
}
