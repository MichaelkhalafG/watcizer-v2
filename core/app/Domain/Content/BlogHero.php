<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Catalog\SearchExposure;
use App\Storefront\ProductCards;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * AN ARTICLE'S HERO PICTURE (Michael, 2026-11-17). WRITES core_blogs (cover_path, hero_*) and
 * core_blog_translations.cover_alt — nothing else; `blog:heroes` runs it with --dry-run first.
 *
 * The picture is a photo from our own catalogue, chosen ONCE and stored, so it never changes on a rebuild:
 *  - a product of the article's storefront that a shopper can see and buy now (visible, active, in stock);
 *  - never one the search-exposure rules keep back (`hidden` or `restricted`), never a brand whose products are all
 *    kept back, never a restricted brand (Rolex) nor one that names no maker (Generic) — {@see SearchExposure};
 *  - of the article's brand when it names one, else of its family (watch, bag, …), else any — and among those, the
 *    one whose title shares the most words with the article's address, whose photo is the largest, not already the
 *    hero of another article of the shop; then the dearest, then the lowest id (the same answer every time).
 * Its alt text is the product's own title, in each language.
 *
 * The rule never replaces the TEAM's choice (`hero_source = dashboard`: an uploaded cover, or a product they named in
 * the form). It re-picks an automatic hero only when the picture is gone or its product has since been kept back from
 * search (or removed) — a product that merely sold out keeps its photo where it is.
 */
final class BlogHero
{
    public const AUTO = 'auto';

    public const DASHBOARD = 'dashboard';

    /** The core_blogs columns reason() and choose() read. */
    public const COLUMNS = ['id', 'storefront_id', 'slug', 'brand_id', 'family', 'cover_path', 'hero_image_id', 'hero_source'];

    /** Words of an article address that say nothing about the product. */
    private const STOP = ['what', 'when', 'where', 'which', 'with', 'your', 'from', 'that', 'this', 'their', 'them', 'they', 'than', 'into', 'over', 'best', 'guide', 'buying', 'egypt', 'prices', 'price', 'tell', 'original', 'choose', 'choosing', 'how-to', 'make', 'live', 'longer', 'tips', 'top', 'worth', 'time', 'ideas', 'vs', 'the', 'and', 'for', 'buy', 'explained', 'difference', 'whats', 'service', 'warranty', 'options', 'functional', 'stylish', 'trends', 'during', 'take', 'care', 'online', 'order', 'check', 'before', 'professional', 'shopping', 'worth', 'store', 'work', '2024', '2025', '2026', 'gift', 'gifts'];

    /**
     * Whether an article needs the rule to (re)pick its hero, and why ('' = it does not).
     */
    public function reason(stdClass $blog): string
    {
        $source = Row::nstr($blog, 'hero_source');
        $imageId = Row::nint($blog, 'hero_image_id');
        $cover = Row::nstr($blog, 'cover_path');
        if ($source === self::DASHBOARD) {
            // the team's choice stays; only a named product photo that no longer exists is replaced
            return $imageId !== null && ! DB::table('catalog_product_images')->where('id', $imageId)->exists() ? 'the chosen photo is gone' : '';
        }
        if ($imageId === null) {
            return $cover === null || $cover === '' ? 'no picture' : 'not chosen by the rule yet';
        }
        $img = DB::table('catalog_product_images')->where('id', $imageId)->first(['product_id', 'path']);
        if ($img === null) {
            return 'the photo is gone';
        }
        $p = DB::table('catalog_products as p')->where('p.id', Row::int(Row::cast($img), 'product_id'))
            ->first(['p.id', 'p.deleted_at', 'p.brand_id', 'p.search_class', 'p.search_override']);
        if ($p === null || Row::nstr(Row::cast($p), 'deleted_at') !== null) {
            return 'its product was removed';
        }
        if (SearchExposure::effective(Row::cast($p)) !== null) {
            return 'its product is now kept from search';
        }
        if (in_array(Row::int(Row::cast($p), 'brand_id'), $this->leftBrands(Row::int($blog, 'storefront_id')), true)) {
            return 'its brand is now kept from search';
        }

        return '';
    }

    /**
     * The picture the rule gives this article now, or null when the shop has no product to show.
     *
     * @return array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string}|null
     */
    public function choose(stdClass $blog): ?array
    {
        $sf = Row::int($blog, 'storefront_id');
        $brandId = Row::nint($blog, 'brand_id');
        $family = Row::nstr($blog, 'family');
        $words = self::words(Row::str($blog, 'slug'));
        // an article that names no family takes the family of the kind of product its address names most (an article on
        // sunglasses: fashion) — so a sold-out kind falls back to its own family, never to a watch with "shape" in its name
        if (($family === null || $family === '') && $words !== []) {
            $family = $this->kindFamily($sf, $words);
        }
        $taken = array_values(array_unique(array_merge(
            DB::table('core_blogs')->where('storefront_id', $sf)->where('id', '<>', Row::int($blog, 'id'))
                ->whereNotNull('hero_image_id')->pluck('hero_image_id')->map(fn (mixed $v): int => Coerce::int($v))->all(),
            $this->claimed[$sf] ?? [],
        )));

        // the narrowest set that has anything: brand and family, brand, family, the whole shop
        $tries = array_values(array_unique([
            [$brandId, $family], [$brandId, null], [null, $family], [null, null],
        ], SORT_REGULAR));
        foreach ($tries as [$b, $f]) {
            $pick = $this->best($sf, $b, $f, $words, $taken);
            if ($pick !== null) {
                return $pick;
            }
        }

        return null;
    }

    /**
     * Store the rule's picture for one article (when it needs one, or always with $force — never over the team's).
     * Returns what was stored, or null when nothing changed.
     *
     * @return array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string, reason: string}|null
     */
    public function ensure(int $blogId, bool $force = false, bool $write = true): ?array
    {
        $raw = DB::table('core_blogs')->where('id', $blogId)->first(self::COLUMNS);
        if ($raw === null) {
            return null;
        }
        $blog = Row::cast($raw);
        $reason = $this->reason($blog);
        if ($reason === '' && ! ($force && Row::nstr($blog, 'hero_source') !== self::DASHBOARD)) {
            return null;
        }
        $pick = $this->choose($blog);
        if ($pick === null || $pick['image_id'] === Row::nint($blog, 'hero_image_id')) {
            return null;
        }
        $this->claimed[Row::int($blog, 'storefront_id')][] = $pick['image_id'];
        if ($write) {
            $this->save($blogId, $pick, self::AUTO);
        }

        return $pick + ['reason' => $reason === '' ? 'picked again (--force)' : $reason];
    }

    /**
     * The team's choice from the dashboard: the cover photo of the product with that code (its wa_code, its import key,
     * or its id), on the article's storefront. A string is the refusal: not-found, kept-from-search (the rules keep it
     * back — the same rule as the automatic pick), not-on-shop (not visible there), no-photo.
     *
     * @return array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string}|string
     */
    public function productPick(int $sf, string $code): array|string
    {
        $code = trim($code);
        if ($code === '' || $sf === 0) {
            return 'not-found';
        }
        $p = DB::table('catalog_products')->whereNull('deleted_at')
            ->where(fn (Builder $q) => $q->where('wa_code', $code)->orWhere('import_ref', $code)->orWhere('id', ctype_digit($code) ? (int) $code : 0))
            ->first(['id', 'brand_id', 'search_class', 'search_override']);
        if ($p === null) {
            return 'not-found';
        }
        $p = Row::cast($p);
        if (SearchExposure::effective($p) !== null || in_array(Row::int($p, 'brand_id'), $this->leftBrands($sf), true)) {
            return 'kept-from-search';
        }
        $visible = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $sf)
            ->where('p.id', Row::int($p, 'id'))->exists();
        if (! $visible) {
            return 'not-on-shop';
        }
        $pick = $this->describe([Row::int($p, 'id')])[Row::int($p, 'id')] ?? null;
        if ($pick === null) {
            return 'no-photo';
        }
        unset($pick['width']);

        return $pick;
    }

    /** Whether the hero columns exist (the blog_hero migration has run): code that writes them asks first. */
    public static function ready(): bool
    {
        return self::$ready ??= DB::getSchemaBuilder()->hasColumn('core_blogs', 'hero_source');
    }

    private static ?bool $ready = null;

    /**
     * Store a picture as the article's hero (its path, its image row, who chose it, its alt text in both languages).
     *
     * @param  array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string}  $pick
     */
    public function save(int $blogId, array $pick, string $source): void
    {
        DB::transaction(function () use ($blogId, $pick, $source): void {
            DB::table('core_blogs')->where('id', $blogId)->update([
                'cover_path' => $pick['path'],
                'hero_image_id' => $pick['image_id'],
                'hero_source' => $source,
                'hero_picked_at' => now(),
                'updated_at' => now(),
            ]);
            foreach (['en', 'ar'] as $locale) {
                DB::table('core_blog_translations')->where('blog_id', $blogId)->where('locale', $locale)
                    ->update(['cover_alt' => mb_substr($pick['alt'][$locale], 0, 255)]);
            }
        });
    }

    /**
     * @param  list<string>  $words
     * @param  list<int>  $taken
     * @return array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string}|null
     */
    private function best(int $sf, ?int $brandId, ?string $family, array $words, array $taken): ?array
    {
        $q = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $sf)
            ->where('p.in_stock', 1)
            ->whereRaw(SearchExposure::effectiveSql('p').' IS NULL')
            ->whereNotIn('p.brand_id', $this->leftBrands($sf));
        if ($brandId !== null) {
            $q->where('p.brand_id', $brandId);
        }
        if ($family !== null && $family !== '') {
            $q->where('p.family', $family);
        }
        // the products whose English title names a word of the article's address first (an article on sunglasses shows
        // sunglasses), then the rest; each set the dearest first, at most 400
        $ids = [];
        if ($words !== []) {
            $named = (clone $q)->join('catalog_product_translations as pt', fn (JoinClause $j) => $j->on('pt.product_id', '=', 'p.id')->where('pt.locale', '=', 'en'))
                ->where(function (Builder $w) use ($words): void {
                    foreach ($words as $word) {
                        $w->orWhere('pt.title', 'like', '%'.$word.'%');
                    }
                });
            $ids = array_values($named->orderByDesc('p.selling_price')->orderBy('p.id')->limit(400)->pluck('p.id')->map(fn (mixed $v): int => Coerce::int($v))->all());
        }
        if ($ids === []) {
            $ids = array_values($q->orderByDesc('p.selling_price')->orderBy('p.id')->limit(400)->pluck('p.id')->map(fn (mixed $v): int => Coerce::int($v))->all());
        }
        if ($ids === []) {
            return null;
        }
        $cands = $this->describe($ids);
        if ($cands === []) {
            return null;
        }
        $rank = array_flip($ids); // dearest first, then the lowest id
        $bestScore = null;
        $first = null;
        foreach ($cands as $c) {
            $score = [
                in_array($c['image_id'], $taken, true) ? 1 : 0,          // not another article's hero
                preg_match('/\s\+\s|\bgift\b|\bset\b|\bbundle\b/iu', $c['title']) === 1 ? 1 : 0, // one product, not a bundle
                -self::shared($words, $c['title']),                      // the most words of the article's address
                $c['width'] >= 600 ? 0 : 1,                              // a photo big enough for the hero
                $rank[$c['product_id']] ?? PHP_INT_MAX,                  // the dearest
            ];
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $first = $c;
            }
        }
        unset($first['width']);

        return $first;
    }

    /**
     * Each product's own cover photo (the gallery's cover, else its first picture) with its titles.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{image_id: int, product_id: int, path: string, alt: array{en: string, ar: string}, title: string, width: int}>
     */
    private function describe(array $productIds): array
    {
        $images = [];
        foreach (DB::table('catalog_product_images')->whereIn('product_id', $productIds)->whereNull('variant_id')
            ->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->get(['id', 'product_id', 'path', 'width']) as $raw) {
            $r = Row::cast($raw);
            $pid = Row::int($r, 'product_id');
            if (! isset($images[$pid]) && Row::str($r, 'path') !== '') {
                $images[$pid] = $r;
            }
        }
        $titles = [];
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', array_keys($images))->whereIn('locale', ['en', 'ar'])
            ->get(['product_id', 'locale', 'title']) as $raw) {
            $r = Row::cast($raw);
            $titles[Row::int($r, 'product_id')][Row::str($r, 'locale')] = trim(Row::nstr($r, 'title') ?? '');
        }
        $out = [];
        foreach ($images as $pid => $r) {
            $en = $titles[$pid]['en'] ?? '';
            $ar = $titles[$pid]['ar'] ?? '';
            $out[$pid] = [
                'image_id' => Row::int($r, 'id'),
                'product_id' => $pid,
                'path' => ltrim((string) preg_replace('#^/?Uploads_Images/#', '', Row::str($r, 'path')), '/'),
                'alt' => ['en' => $en !== '' ? $en : $ar, 'ar' => $ar !== '' ? $ar : $en],
                'title' => $en,
                'width' => Row::nint($r, 'width') ?? 0,
            ];
        }

        return $out;
    }

    /** @var array<int, list<int>> */
    private array $left = [];

    /** @var array<int, list<int>> images chosen in THIS process, per storefront (a dry run writes nothing) */
    private array $claimed = [];

    /**
     * The brands no hero may come from on this storefront: every brand whose products are all kept from search, every
     * brand whose open products are only a matched Rolex, the restricted brands (Rolex) and the brands that name no
     * maker (Generic).
     *
     * @return list<int>
     */
    private function leftBrands(int $sf): array
    {
        if (isset($this->left[$sf])) {
            return $this->left[$sf];
        }
        $exposure = SearchExposure::brands($sf);
        $slugs = array_merge(
            config()->array('catalog.search_exposure.restricted_brands', []),
            config()->array('catalog.original_watches.exclude_brands', []),
        );
        $named = DB::table('catalog_brands')->whereIn('slug', $slugs)->pluck('id')->map(fn (mixed $v): int => Coerce::int($v))->all();

        return $this->left[$sf] = array_values(array_unique(array_merge($exposure['hidden_brand_ids'], $exposure['restricted_brand_ids'], $named)));
    }

    /** @return list<string> the words of an article address that may name a product's kind or line */
    private static function words(string $slug): array
    {
        $out = [];
        foreach (explode('-', mb_strtolower($slug)) as $w) {
            // "men" is a word of three letters that matters (a men's style guide shows a men's piece)
            if ((mb_strlen($w) >= 4 || $w === 'men') && ! in_array($w, self::STOP, true)) {
                $out[] = self::stem($w);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The family of the word of the address that names the most visible products of the shop, in stock or not
     * ("sunglasses" → fashion), or null when no word names twenty of them.
     *
     * @param  list<string>  $words
     */
    private function kindFamily(int $sf, array $words): ?string
    {
        $best = null;
        $bestN = 19;
        foreach ($words as $word) {
            $rows = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $sf)
                ->join('catalog_product_translations as pt', fn (JoinClause $j) => $j->on('pt.product_id', '=', 'p.id')->where('pt.locale', '=', 'en'))
                ->where('pt.title', 'like', '%'.$word.'%')
                ->groupBy('p.family')->selectRaw('p.family, COUNT(*) AS n')->orderByDesc('n')->limit(1)->get();
            $top = $rows->first();
            if ($top !== null && Row::int(Row::cast($top), 'n') > $bestN) {
                $bestN = Row::int(Row::cast($top), 'n');
                $best = Row::nstr(Row::cast($top), 'family');
            }
        }

        return $best;
    }

    /** One word, singular: watches → watch, sunglasses → sunglass, bags → bag; "glass" and "swiss" stay. */
    private static function stem(string $w): string
    {
        if (preg_match('/(ch|sh|x|ss)es$/', $w) === 1) {
            return substr($w, 0, -2);
        }

        return preg_match('/[^s]s$/', $w) === 1 ? substr($w, 0, -1) : $w;
    }

    /**
     * How many of the article's words a product title carries, each as a whole word or its plural ("men" never
     * matches "women").
     *
     * @param  list<string>  $words
     */
    private static function shared(array $words, string $title): int
    {
        $t = mb_strtolower($title);
        $n = 0;
        foreach ($words as $w) {
            if ($w !== '' && preg_match('/\b'.preg_quote($w, '/').'(e?s)?\b/u', $t) === 1) {
                $n++;
            }
        }

        return $n;
    }
}
