<?php

namespace App\Domain\Catalog;

use App\Support\ArabicSearch;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * "Find me this product" — one implementation, for every screen that asks the question.
 *
 * ── Why this is a class and not a private method ────────────────────────────────────────────
 *
 * It was a private method on `ProductController`, and the stock screen could not reach it. So the
 * stock screen searched `wa_code` and `sku` with a plain `LIKE` — while every row on it is headed
 * by an Arabic product name. Typing `هوغو` into a list of rows reading *ساعة هوغو بوس للرجال*
 * returned **لا توجد منتجات** (D-8, browser walkthrough 2026-09-18). The operator reads a name off
 * the screen, types it, and is told the product does not exist.
 *
 * Two screens asking the same question in two ways is the defect. There is now one answer, and
 * anything that lists products calls it.
 *
 * ── What it does ────────────────────────────────────────────────────────────────────────────
 *
 * FULLTEXT over `catalog_product_search` for terms of three characters or more, `LIKE` on the
 * titles under that.
 *
 * The threshold is not a preference — `innodb_ft_min_token_size` is 3 on this server and cannot be
 * changed on the shared host, so a shorter term matches NOTHING through the index and would return
 * an empty list while looking like it worked.
 *
 * `wa_code` and `sku` are always searched with `LIKE`: they are codes, and somebody typing half of
 * one expects a prefix match, which a word-based index cannot give.
 */
final class ProductSearch
{
    /**
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query
     * @param  string  $arAlias  the join alias holding the ARABIC translation row
     * @param  string  $enAlias  …and the English one
     */
    public static function apply(
        EloquentBuilder|Builder $query,
        string $term,
        string $arAlias = 'ar',
        string $enAlias = 'en',
    ): void {
        $escaped = self::escapeLike($term);

        $query->where(function (Builder $inner) use ($term, $escaped, $arAlias, $enAlias): void {
            $inner->where('p.wa_code', 'like', '%'.$escaped.'%')
                ->orWhere('p.sku', 'like', '%'.$escaped.'%');

            if (mb_strlen($term) >= 3) {
                /*
                 * ── FULLTEXT **OR** a substring match (A-UX-2, 2026-09-17) ───────────────────
                 *
                 * The FULLTEXT index is word-PREFIX, so `اسود` finds the 429 products whose title
                 * has it as a word and misses the 79 spelled `الأسود` — the same word carrying the
                 * definite article, which Arabic attaches to the front of the noun. Typing the bare
                 * word is what an operator does, so the gap is a daily one, not an edge case.
                 *
                 * **Always ORed, never "only when FULLTEXT found nothing".** That was the shape the
                 * review suggested and it would not fix this: `اسود` returns 429 rows, so a
                 * fallback conditional on an empty result never fires and the 79 stay invisible.
                 *
                 * COST, measured on the real 15,426-row index: the LIKE is a full scan at a flat
                 * ~19 ms whatever the term, taking the search sub-query from ~5 ms to ~26 ms and the
                 * page from ~64 ms to ~85 ms. That is the same band as the 2-character LIKE fallback
                 * below, which this system already accepts at 78-82 ms, and half of `missing_data`
                 * at 174 ms.
                 *
                 * It scales LINEARLY with the catalogue, which is the thing to watch: 19 ms at 7,713
                 * products would be ~120 ms at 50,000. If the catalogue reaches that, the cheaper
                 * shape is to emit the article-stripped form as an extra TOKEN at index time, which
                 * moves the cost to the write and keeps the query on the index — a bigger change,
                 * and not worth it for a table this size today.
                 *
                 * The needle is the NORMALISED term, because `body` now holds normalised Arabic.
                 */
                $needle = '%'.self::escapeLike(ArabicSearch::normalise($term)).'%';

                $inner->orWhereExists(function (Builder $sub) use ($term, $needle): void {
                    $sub->from('catalog_product_search as s')
                        ->whereColumn('s.product_id', 'p.id')
                        ->where(function (Builder $match) use ($term, $needle): void {
                            $match->whereRaw('MATCH(s.body) AGAINST (? IN BOOLEAN MODE)', [self::booleanTerm($term)])
                                ->orWhere('s.body', 'like', $needle);
                        })
                        ->selectRaw('1');
                });

                return;
            }

            // Under three characters the index is blind, so fall back to the titles directly.
            $inner->orWhere($arAlias.'.title', 'like', '%'.$escaped.'%')
                ->orWhere($enAlias.'.title', 'like', '%'.$escaped.'%');
        });
    }

    /** Escape the three characters `LIKE` treats as syntax. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * A BOOLEAN MODE term built from the caller's words, with every operator character stripped.
     *
     * The user's string never reaches the parser as syntax: `+`, `-`, `*`, `"`, `(`, `)`, `~`, `<`,
     * `>` and `@` all mean something to MariaDB's boolean parser, and a stray `@distance` or an
     * unbalanced quote is a syntax error rather than a search.
     */
    public static function booleanTerm(string $term): string
    {
        /*
         * NORMALISED FIRST (A-UX-1, 2026-09-17), and first for two reasons.
         *
         * The obvious one: the index now holds folded Arabic, so a term that is not folded asks for
         * a spelling the index no longer contains — the same bug as before with the sides swapped.
         *
         * The less obvious one: folding STRIPS characters (tatweel, diacritics), so it changes
         * length. Doing it after the three-character filter below would measure the word the
         * operator typed rather than the word that reaches MariaDB, and `سـاعة` — five characters of
         * which one is decoration — would be judged on the wrong count.
         */
        $term = ArabicSearch::normalise($term);

        $clean = (string) preg_replace('/[+\-*~<>()"@]+/u', ' ', $term);
        $words = array_values(array_filter(preg_split('/\s+/u', $clean) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));

        if ($words === []) {
            // Every word was too short for the index; match nothing rather than everything.
            return '"'.'zzz-no-such-token'.'"';
        }

        return implode(' ', array_map(fn (string $w): string => '+'.$w.'*', $words));
    }
}
