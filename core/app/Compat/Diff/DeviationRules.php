<?php

namespace App\Compat\Diff;

/**
 * The sanctioned-deviations table of the compat layer (CLEAN_CORE_STUDY §3.5.1 "documented
 * deltas", §3.3 visibility rule, wave 1 X-01, and the reality findings of 2026-09-07). Every
 * finding the differ produces is either absorbed by exactly one rule here or is a failure.
 *
 * A rule matches by case name (fnmatch), normalised JSON path (fnmatch, indices as `[*]`,
 * `**` = any prefix) and finding kind; `ci` rules additionally require the two values to be
 * equal case-insensitively.
 *
 * @phpstan-type Rule array{id: string, cases: string, paths: list<string>, kinds: list<string>, why: string, ci?: bool}
 */
final class DeviationRules
{
    /** @return list<array{id: string, cases: string, paths: list<string>, kinds: list<string>, why: string, ci?: bool}> */
    public static function all(): array
    {
        return [
            [
                'id' => 'D-01', 'cases' => 'all_product*', 'paths' => ['$[*].percentage_discount'], 'kinds' => ['value', 'type'],
                'why' => 'Stored `percentage_discount` dropped (study §2.2, A-22): compat derives round((selling-sale)/selling*100) as a 2-decimal string ("21.00"); the storefront only tests `> 0`. 329/341 legacy values differ from the derivation (see flag F-03).',
            ],
            [
                'id' => 'D-02', 'cases' => '*', 'paths' => ['**translations[*].id'], 'kinds' => ['value'],
                'why' => 'Translation rows have new ids in the clean tables (upserted by (fk, locale), never by legacy id); the storefront reads translations by `locale`, never by row id.',
            ],
            [
                'id' => 'D-03', 'cases' => '*', 'paths' => ['**translations'], 'kinds' => ['order'],
                'why' => 'The legacy eager load returns translations in whatever order the optimizer picks (PK order or the (fk, locale) index — both observed on the same table across endpoints); compat emits en,ar for lookups and ar,en for product-level rows. Consumers use `.find(t => t.locale === …)`.',
            ],
            [
                'id' => 'D-04', 'cases' => 'meta*', 'paths' => ['$.tables.subTypes[*]', '$.sub_types[*]', '$.tables.categoryTypes[*]'], 'kinds' => ['missing_in_compat'],
                'why' => 'Permanent dynamic visibility rule (study §3.3, decided 2026-09-06): a category node without a visible product is not listed. 20 of 27 legacy sub types hold no product today.',
            ],
            [
                'id' => 'D-04', 'cases' => 'sitemap*', 'paths' => ['url:/subtypes/*', 'url:/category/*'], 'kinds' => ['missing_in_compat'],
                'why' => 'Same visibility rule applied to the sitemap sub-type / category-type URLs (study §3.3 lists the sitemap explicitly).',
            ],
            [
                'id' => 'D-05', 'cases' => 'all_product_image*', 'paths' => ['$[*].is_cover'], 'kinds' => ['value'],
                'why' => 'The legacy gallery `is_cover` flag ("first upload") was dropped by the transform (rehearsal #1 finding X-01, study §2.9.6/1); compat emits false. The storefront reads only product_id + image.',
            ],
            [
                'id' => 'D-06', 'cases' => 'product*', 'paths' => ['$.product.slug', '$.related[*].slug'], 'kinds' => ['value'],
                'why' => 'Legacy `slug` = the free-text `seo_slug` column (dropped, study §2.2); compat emits the storefront slug. Never read: the storefront builds product URLs from the EN title (productUrl.js).',
            ],
            [
                'id' => 'D-07', 'cases' => '*', 'paths' => ['**color_value'], 'kinds' => ['value'], 'ci' => true,
                'why' => 'Colour hex normalised to upper case by the transform (step 4); CSS colours are case-insensitive.',
            ],
            [
                'id' => 'D-08', 'cases' => 'gone:*', 'paths' => ['status', 'body'], 'kinds' => ['value', 'type'],
                'why' => 'Legacy paths the storefront never calls are retired with 410 (study §3.3, last row) instead of being reimplemented on frozen data.',
            ],
            [
                'id' => 'D-09', 'cases' => 'all_product*', 'paths' => ['$[*]'], 'kinds' => ['missing_in_compat'],
                'why' => 'Products hidden by the team after the switch (`storefront_product.is_visible = 0`, `is_active = 0`, soft-deleted) leave the compat catalog; the legacy endpoint has no WHERE at all. None today (A-16 = 0).',
            ],
            [
                'id' => 'D-10', 'cases' => '*', 'paths' => ['$[*].average_rate', '$.product.average_rating', '$.related[*].average_rating', '$.product.ratings_count', '$.related[*].ratings_count'], 'kinds' => ['value', 'type'],
                'why' => 'Ratings are recomputed from `product_ratings` at transform / request time (study §2.2 `rating_avg`); the legacy `average_rate` column is only refreshed by the rating endpoint and can be stale.',
            ],
            [
                'id' => 'D-11', 'cases' => 'all_product*', 'paths' => ['$[*].sale_price_after_discount'], 'kinds' => ['type'],
                'why' => 'A sale price that is not 0 < sale < selling is NULL in the clean core (A-04 delta, study §3.5.1). None today.',
            ],
            [
                'id' => 'D-12', 'cases' => 'all_product*', 'paths' => ['$[*].warranty_years'], 'kinds' => ['type'],
                'why' => 'Non-numeric `warranty_years` becomes NULL (A-07). None today.',
            ],
        ];
    }

    /**
     * @param  array{path: string, kind: string, legacy: mixed, compat: mixed}  $finding
     * @return string|null the rule id that absorbs the finding
     */
    public static function match(string $caseName, array $finding): ?string
    {
        $path = JsonDiff::normalise($finding['path']);
        foreach (self::all() as $rule) {
            if (! fnmatch($rule['cases'], $caseName, FNM_NOESCAPE)) {
                continue;
            }
            if (! in_array($finding['kind'], $rule['kinds'], true)) {
                continue;
            }
            foreach ($rule['paths'] as $pattern) {
                if (self::pathMatches($pattern, $path)) {
                    if (($rule['ci'] ?? false) && ! (is_string($finding['legacy']) && is_string($finding['compat']) && strcasecmp($finding['legacy'], $finding['compat']) === 0)) {
                        continue;
                    }

                    return $rule['id'];
                }
            }
        }

        return null;
    }

    /**
     * Pattern grammar: `[*]` is the literal normalised array index, `**` matches any prefix,
     * a bare `*` matches within one path segment. (fnmatch cannot be used: `[*]` is a character class there.)
     */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\[\*\]', "\0", $regex);
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^.\[\]]*', $regex);
        $regex = str_replace("\0", '\[\*\]', $regex);

        return preg_match('#^'.$regex.'$#u', $path) === 1;
    }
}
