<?php

declare(strict_types=1);

namespace App\Compat;

/**
 * The suggestion rails, computed on the server (C-1 stage 4). Rules DECIDED by the developer on
 * 2026-09-29 after measuring the catalogue — they replace the browser's two rules, which suggested
 * more of what the shopper was already buying (four more watches next to a watch).
 *
 * ── Add-ons: "Complete the look" (cart) and "Pairs well with" (product page, first) ──────────────
 * Suggestions that ADD to the order instead of competing with it. A candidate must be in stock, not
 * already in the cart, and of a DIFFERENT family (watch / bag / wallet / fashion / …) from every
 * anchor. Two tiers, no third:
 *   1. same brand AND same gender (unisex matches either) — a Tommy men's watch → Tommy men's bags;
 *   2. same gender, any brand — the fallback for watch-only brands (Rolex, Patek, …).
 * A women's bag is never offered to a men's-watch shopper: a short rail beats a wrong one. Within a
 * tier: cheaper than the anchor first (an add-on usually costs less), closest price first, then the
 * pricier ones closest first; newest breaks ties. A cart anchors on its most expensive line.
 *
 * ── Similar styles (product page, second) ───────────────────────────────────────────────────────
 * Alternatives for a shopper still choosing: the storefront's old product-page score (brand +3,
 * sub-type +3, category +1, gender +2, movement +2, case +1, band material +1, dial colour +1, price
 * band +2/+1, grade +1; score ≥ 2; ties broken randomly after same-brand; topped up from the category
 * when fewer than 4), restricted to the SAME family, with the decided fixes:
 *   - out of stock never suggested;
 *   - a missing value never counts as a match (the old pre-filter let "both missing" through);
 *   - prices are what the shopper pays (`CompatCart::catalogPrice`), not `sale || list`;
 *   - nothing already in the cart;
 *   - the random tie-break is a UNIFORM shuffle — a deliberate deviation from the browser's biased
 *     `sort(() => Math.random() - 0.5)`: the same intent, done properly.
 * `CatalogRelatedTest` compares this score with a frozen copy of the old JavaScript rule on every
 * candidate both keep, and checks every candidate only the old rule kept is one of the fixes above.
 *
 * @phpstan-import-type Entry from CompatListing
 */
final class CompatRelated
{
    public const LIMIT = 12;

    public const ADDON_LIMIT = 8;

    public function __construct(private readonly CompatListing $listing) {}

    /**
     * @param  list<int>  $anchorIds  the product (product page) or every cart line's product (cart)
     * @param  list<int>  $excludeIds  never suggested (the cart)
     * @return list<int>
     */
    public function addOns(array $anchorIds, array $excludeIds = [], int $limit = self::ADDON_LIMIT): array
    {
        return self::rankAddOns($this->listing->entries(), $anchorIds, $excludeIds, $limit);
    }

    /**
     * @param  list<int>  $excludeIds  never suggested (the cart)
     * @return list<int>
     */
    public function similar(int $productId, array $excludeIds = [], int $limit = self::LIMIT): array
    {
        return self::rankSimilar($this->listing->entries(), $productId, $excludeIds, $limit);
    }

    /**
     * @param  list<Entry>  $entries
     * @param  list<int>  $anchorIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public static function rankAddOns(array $entries, array $anchorIds, array $excludeIds, int $limit = self::ADDON_LIMIT): array
    {
        $byId = [];
        foreach ($entries as $e) {
            $byId[$e['id']] = $e;
        }
        $anchor = null;
        $families = [];
        foreach ($anchorIds as $id) {
            $a = $byId[$id] ?? null;
            if ($a === null) {
                continue;
            }
            if ($a['family'] !== null) {
                $families[$a['family']] = true;
            }
            if ($anchor === null || $a['price'] > $anchor['price']) {   // the most expensive line; first wins a tie
                $anchor = $a;
            }
        }
        if ($anchor === null) {
            return [];
        }
        $exclude = array_flip([...$anchorIds, ...$excludeIds]);

        $price = $anchor['price'];
        // Each candidate with its sort key: cheaper-or-equal first, then pricier; closest price first
        // within each; newest breaks ties.
        /** @var array{1: list<array{key: array{int, float, int}, id: int}>, 2: list<array{key: array{int, float, int}, id: int}>} $tiers */
        $tiers = [1 => [], 2 => []];
        foreach ($entries as $e) {
            if (! $e['inStock'] || isset($exclude[$e['id']]) || $e['family'] === null || isset($families[$e['family']])) {
                continue;
            }
            if (! self::sameGender($anchor['genders'], $e['genders'])) {
                continue;                                     // no tier 3
            }
            $tier = $anchor['brands'] !== null && $e['brands'] === $anchor['brands'] ? 1 : 2;
            $tiers[$tier][] = ['key' => [(int) ($e['price'] > $price), abs($e['price'] - $price), -$e['created']], 'id' => $e['id']];
        }
        $byKey = fn (array $a, array $b): int => $a['key'] <=> $b['key'];
        usort($tiers[1], $byKey);
        usort($tiers[2], $byKey);

        return array_slice(array_map(fn (array $c): int => $c['id'], [...$tiers[1], ...$tiers[2]]), 0, $limit);
    }

    /**
     * @param  list<Entry>  $entries
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public static function rankSimilar(array $entries, int $productId, array $excludeIds = [], int $limit = self::LIMIT): array
    {
        $base = self::find($entries, $productId);
        if ($base === null) {
            return [];
        }
        $scores = self::productScores($entries, $base, $excludeIds);
        $scored = [];
        foreach ($entries as $e) {
            if (isset($scores[$e['id']]) && $scores[$e['id']] >= 2) {
                $scored[] = $e;
            }
        }
        // A UNIFORM shuffle among equals, then a STABLE sort by score and same-brand (see the class note).
        shuffle($scored);
        usort($scored, function (array $a, array $b) use ($scores, $base): int {
            $s = $scores[$b['id']] <=> $scores[$a['id']];
            if ($s !== 0) {
                return $s;
            }

            return (int) self::same($b['brands'], $base['brands']) <=> (int) self::same($a['brands'], $base['brands']);
        });
        $result = array_slice(array_map(fn (array $e): int => $e['id'], $scored), 0, $limit);

        // New / sparse products: top up from the same family and category, in stock, in catalogue order.
        if (count($result) < 4) {
            $have = array_flip([...$result, ...$excludeIds]);
            foreach ($entries as $e) {
                if ($e['id'] !== $base['id'] && ! isset($have[$e['id']]) && self::eligibleSimilar($e, $base)
                    && self::same($e['categories'], $base['categories'])) {
                    $result[] = $e['id'];
                }
            }
            $result = array_slice($result, 0, $limit);
        }

        return $result;
    }

    /**
     * Every eligible candidate's "Similar styles" score — the parity test's view.
     *
     * @param  list<Entry>  $entries
     * @param  Entry  $base
     * @param  list<int>  $excludeIds
     * @return array<int, int> product id => score
     */
    public static function productScores(array $entries, array $base, array $excludeIds = []): array
    {
        $exclude = array_flip($excludeIds);
        $baseDials = array_flip(array_filter($base['dialColors'], fn (int $id): bool => $id !== 0));
        $current = $base['price'];
        $out = [];
        foreach ($entries as $p) {
            if ($p['id'] === $base['id'] || isset($exclude[$p['id']]) || ! self::eligibleSimilar($p, $base)) {
                continue;
            }
            // Shares at least one core attribute — a missing value never counts (fix 2).
            if (! (self::same($p['brands'], $base['brands']) || self::same($p['subTypes'], $base['subTypes']) || self::same($p['categories'], $base['categories']))) {
                continue;
            }
            $score = 0;
            $score += self::same($p['brands'], $base['brands']) ? 3 : 0;
            $score += self::same($p['subTypes'], $base['subTypes']) ? 3 : 0;
            $score += self::same($p['categories'], $base['categories']) ? 1 : 0;
            $score += array_intersect($base['genders'], $p['genders']) !== [] ? 2 : 0;
            $score += self::same($p['movements'], $base['movements']) ? 2 : 0;
            $score += self::same($p['shapes'], $base['shapes']) ? 1 : 0;
            $score += self::same($p['materials'], $base['materials']) ? 1 : 0;
            if ($baseDials !== [] && array_filter($p['dialColors'], fn (int $id): bool => isset($baseDials[$id])) !== []) {
                $score += 1;
            }
            if ($current > 0 && $p['price'] > 0) {               // what the shopper pays (fix 3)
                $ratio = $p['price'] / $current;
                $score += ($ratio >= 0.7 && $ratio <= 1.3) ? 2 : (($ratio >= 0.5 && $ratio <= 1.5) ? 1 : 0);
            }
            $score += self::same($p['grades'], $base['grades']) ? 1 : 0;
            $out[$p['id']] = $score;
        }

        return $out;
    }

    /**
     * A "Similar styles" candidate: in stock (fix 1) and of the same family — a missing family never matches.
     *
     * @param  Entry  $p
     * @param  Entry  $base
     */
    private static function eligibleSimilar(array $p, array $base): bool
    {
        return $p['inStock'] && $base['family'] !== null && $p['family'] === $base['family'];
    }

    /** Equal AND present: a missing (null or 0) id never matches (fix 2). */
    private static function same(?int $a, ?int $b): bool
    {
        return $a !== null && $a !== 0 && $a === $b;
    }

    /**
     * Genders overlap, or either side is unisex. No gender on the anchor matches only unisex.
     *
     * @param  list<string>  $anchor
     * @param  list<string>  $candidate
     */
    private static function sameGender(array $anchor, array $candidate): bool
    {
        return array_intersect($anchor, $candidate) !== [] || in_array('Unisex', $anchor, true) || in_array('Unisex', $candidate, true);
    }

    /**
     * @param  list<Entry>  $entries
     * @return Entry|null
     */
    private static function find(array $entries, int $id): ?array
    {
        foreach ($entries as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }
}
