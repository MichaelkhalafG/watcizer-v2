<?php

namespace App\Compat\Diff;

/**
 * The sanctioned-deviations table of the compat layer (CLEAN_CORE_STUDY §3.7.3): every finding
 * the differ produces is either absorbed by exactly one rule here or is a failure.
 *
 * A rule matches by case name (one or more `|`-separated fnmatch patterns), normalised JSON path
 * (indices as `[*]`, `**` = any prefix, `*` within a segment) and finding kind. Two optional
 * predicates narrow it further, and both exist so a rule can absorb a field's VOLATILITY without
 * absorbing its CORRECTNESS:
 *
 *  - `ci`   the two values must be equal case-insensitively.
 *  - `both` both values must satisfy a named shape (positive_int, timestamp, uuid, guest_token,
 *           order_number). A rule carrying `both` never absorbs a null-vs-value or a
 *           malformed-vs-well-formed difference; those stay UNEXPLAINED.
 *  - `direction` the difference must run one specific way (compat_lower_int, compat_newer), so
 *           absorbing a deviation never absorbs its mirror image — which would be a bug.
 *
 * @phpstan-type Rule array{id: string, cases: string, paths: list<string>, kinds: list<string>, why: string, ci?: bool, both?: string, direction?: string}
 */
final class DeviationRules
{
    /** @return list<array{id: string, cases: string, paths: list<string>, kinds: list<string>, why: string, ci?: bool, both?: string, direction?: string}> */
    public static function all(): array
    {
        return [
            [
                'id' => 'D-01', 'cases' => 'all_product*', 'paths' => ['$[*].percentage_discount'], 'kinds' => ['value', 'type'],
                'why' => 'Stored `percentage_discount` dropped (study §2.2, A-22): compat derives round((selling-sale)/selling*100) as a 2-decimal string ("21.00"); the storefront only tests `> 0`. 330 of 341 legacy values differ from the derivation (329 rounded differently, 1 NULL — flag F-03).',
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
                'id' => 'D-08', 'cases' => 'gone:*', 'paths' => ['status', 'body', 'header:content-type'], 'kinds' => ['value', 'type'],
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
            [
                'id' => 'D-13', 'cases' => '*:ar', 'paths' => ['$[*].product_title', '$[*].model_name', '$[*].country', '$[*].stone', '$[*].long_description', '$[*].short_description', '$[*].feature[*].feature_name', '$[*].gender[*].gender_name', '$[*].dial_color[*].color_name', '$[*].band_color[*].color_name', '$.tables.*[*].*_name', '$.tables.*[*].description', '$[*].city_name'], 'kinds' => ['value', 'type'],
                'why' => 'The appended current-locale attributes are pinned to EN on compat (`compat.pinned_locale`, decision 2026-09-08 on review 🟠-2): the legacy host negotiates a locale per request but serves `catalog/meta`, `all_product` and `show_shipping_city` from locale-blind caches, i.e. whichever locale warmed them (EN in practice: the SSR prefetch sends no Accept-Language). A legacy host whose cache was warmed by an Arabic browser answers Arabic here for up to an hour; compat never does. The storefront reads `translations[]`, never these attributes; pinning EN also keeps `all_product` 687 KB (32 %) smaller than its Arabic rendering against the §5.5 budget.',
            ],
            [
                'id' => 'D-14', 'cases' => '*:404:no-accept', 'paths' => ['header:content-type', 'body'], 'kinds' => ['value'],
                'why' => 'Without a JSON Accept header the legacy host renders its Blade error page (text/html) for a 404; compat answers `application/json` for every /api path (Laravel `shouldRenderJsonWhen`). Only the status is contract; the storefront (axios) always sends `application/json, text/plain, */*` and gets JSON from both.',
            ],
            [
                'id' => 'D-14', 'cases' => '*:404:any-accept', 'paths' => ['header:content-type', 'body'], 'kinds' => ['value'],
                'why' => 'Same as above for a native-fetch `Accept: */*` (review 🟠-3a).',
            ],
            [
                'id' => 'D-14', 'cases' => '*:404:unrouted', 'paths' => ['header:content-type', 'body'], 'kinds' => ['value'],
                'why' => 'A path that matches NO legacy route (an unknown /api path, or a by-name segment the legacy route pattern rejects, e.g. one holding a backslash) gets the legacy HTML 404 page even when JSON is accepted (route-level miss, verified live); compat answers `{"message":"Not Found"}` with the same 404 status.',
            ],
            [
                'id' => 'D-15', 'cases' => 'product:*', 'paths' => ['$.message'], 'kinds' => ['value'],
                'why' => 'Compat 404 bodies are generic (`{"message":"Not Found"}`, review 🟡-11); the legacy body echoes its model class and the raw id (`No query results for model [App\\Models\\Product] 999999`). The storefront reads the status only (serverCatalog.js catches and returns null).',
            ],
            // -- wave 3: the five fields a stateful sequence CANNOT match, and nothing else --
            // Each host runs the cart script under its own guest token, so it builds its own
            // rows. These rules absorb only the identity of those rows; every price, quantity,
            // total, warning and message is still compared byte for byte, and each absorption is
            // counted in the report so an over-broad rule would show up as a spike.
            [
                'id' => 'D-16', 'cases' => 'cart:*|checkout:*|address:*', 'paths' => ['$.id', '$.address_id', '$.cart_id', '$.cart_item[*].id', '$.cart_item[*].cart_id', '$.warnings[*].item_id', '$.cart.id', '$.cart.cart_item[*].id', '$.cart.cart_item[*].cart_id', '$.cart.warnings[*].item_id'], 'kinds' => ['value'], 'both' => 'positive_int',
                'why' => 'Row identity. The two hosts insert into the SHARED carts/cart_items/addresses tables under different guest tokens, so their auto-increment ids differ by construction. Both sides must still be a positive integer; the id linkage INSIDE each payload is asserted by tests/Feature/Compat/CartOwnershipTest.php, not by the differ.',
            ],
            [
                'id' => 'D-17', 'cases' => 'cart:*|checkout:*|address:*', 'paths' => ['**created_at', '**updated_at', '**expires_at'], 'kinds' => ['value'], 'both' => 'timestamp',
                'why' => 'Row timestamps: the two hosts write their rows milliseconds apart. Both sides must still parse as a timestamp of the same shape - ISO-8601 with microseconds for created_at/updated_at, the raw column string for expires_at, which the legacy model does not cast.',
            ],
            [
                'id' => 'D-18', 'cases' => 'cart:*|checkout:*', 'paths' => ['$.guest_token', '$.cart.guest_token', 'header:x-guest-token'], 'kinds' => ['value'], 'both' => 'guest_token',
                'why' => 'The per-host guest token itself. Both sides must echo a 36-character token; that each host echoes the one it was SENT is proven by the sequence continuing to resolve the same cart across its later steps.',
            ],
            [
                'id' => 'D-19', 'cases' => '*', 'paths' => ['$.ref'], 'kinds' => ['value'], 'both' => 'uuid',
                'why' => 'The legacy 500 body carries Str::uuid() as a correlation id, generated fresh per response, so it can never match. Both sides must emit a UUID. AddToCart reaches this branch on a VALIDATION failure, because it catches \\Exception - see CartCompatController.',
            ],
            [
                'id' => 'D-20', 'cases' => 'checkout:*', 'paths' => ['$.order_number'], 'kinds' => ['value'], 'both' => 'order_number',
                'why' => 'The order number is MAX(CAST(order_number AS UNSIGNED))+1 over the shared orders table, so the second host to place an order necessarily takes the next one. Both sides must be a zero-padded 6-digit string.',
            ],
            [
                'id' => 'D-22', 'cases' => 'all_product*', 'paths' => ['$[*].stock', '$[*].market_stock'], 'kinds' => ['value'], 'direction' => 'compat_lower_int',
                'why' => 'A sale the compat layer has already applied and the legacy host has not yet noticed. `StockChanged` bumps the storefront cache version, so core re-renders `all_product` the moment stock moves; the legacy endpoint caches its payload for 600 s and has no invalidation at all, so it keeps serving the pre-sale number for up to ten minutes. Directional ON PURPOSE: it absorbs compat being LOWER (core saw the sale first) and never compat being HIGHER, which would mean core failed to decrement. The real guard on stock equality is the transform reconciliation `catalog_products[stock mirror]`, which compares every row, not a cached page.',
            ],
            [
                'id' => 'D-22', 'cases' => 'all_product*|sitemap*', 'paths' => ['$[*].updated_at', 'url:/product/*'], 'kinds' => ['value'], 'direction' => 'compat_newer',
                'why' => 'The `updated_at` (and the sitemap `lastmod` derived from it) of a product whose stock the harness just moved: the ledger touches `updated_at` exactly as the legacy decrement did, and core re-renders while the legacy cache still holds the old page. Directional: compat may be NEWER, never older.',
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
            if (! self::caseMatches($rule['cases'], $caseName)) {
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
                    $both = $rule['both'] ?? null;
                    if ($both !== null && ! (self::shaped($both, $finding['legacy']) && self::shaped($both, $finding['compat']))) {
                        continue;
                    }
                    $direction = $rule['direction'] ?? null;
                    if ($direction !== null && ! self::directed($direction, $finding['legacy'], $finding['compat'])) {
                        continue;
                    }

                    return $rule['id'];
                }
            }
        }

        return null;
    }

    /** One or more `|`-separated fnmatch patterns; the rule fires when any of them matches. */
    public static function caseMatches(string $patterns, string $caseName): bool
    {
        foreach (explode('|', $patterns) as $pattern) {
            if (fnmatch($pattern, $caseName, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the difference run the ONE way the rule allows?
     *
     * A directional rule is how a deviation can be absorbed without the reverse deviation being
     * absorbed with it: `compat_lower_int` says core may hold LESS stock than a stale legacy
     * cache (it saw the sale first) and says nothing about core holding more, which would be a
     * failed decrement and stays UNEXPLAINED.
     */
    public static function directed(string $direction, mixed $legacy, mixed $compat): bool
    {
        return match ($direction) {
            'compat_lower_int' => is_int($legacy) && is_int($compat) && $compat < $legacy && $compat >= 0,
            'compat_newer' => is_string($legacy) && is_string($compat) && self::instant($compat) !== null
                && self::instant($legacy) !== null && self::instant($compat) > self::instant($legacy),
            default => false,
        };
    }

    /** The first ISO-8601 instant in a string (a bare value, or one inside a `<lastmod>` element). */
    private static function instant(string $value): ?int
    {
        if (preg_match('/\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})/', $value, $m) !== 1) {
            return null;
        }
        $time = strtotime($m[0]);

        return $time === false ? null : $time;
    }

    /**
     * Is a value of the shape a `both` predicate names? Deliberately strict: an absorbed
     * difference must still be a WELL-FORMED value of the right type on BOTH sides, so a rule
     * can never hide a null, an empty string or a wrong type.
     */
    public static function shaped(string $shape, mixed $value): bool
    {
        return match ($shape) {
            'positive_int' => is_int($value) && $value > 0,
            'timestamp' => is_string($value) && (
                preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$/', $value) === 1
                || preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $value) === 1
            ),
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'guest_token' => is_string($value) && strlen($value) === 36,
            'order_number' => is_string($value) && preg_match('/^\\d{6,}$/', $value) === 1,
            default => false,
        };
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
