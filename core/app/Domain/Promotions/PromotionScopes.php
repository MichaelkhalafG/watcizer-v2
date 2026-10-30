<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * WHICH cart lines a rule is about (M1u, 2026-10-30): the `promotion_rule_scopes` rows of a rule, and the catalogue
 * facts of the lines they are matched against.
 *
 * A scope row is (role, dimension, value): `target` rows say which items the discount is taken OFF, `qualify` rows
 * which items must be in the cart for the rule to apply ("buy from X"). Within one role: AND across dimensions, OR
 * within one — "brand Rolex OR Omega, AND family watch". A role with no rows matches every line.
 *
 * Dimensions: `brand` (catalog_brands.id), `grade` (catalog_grades.id), `family` (catalog_products.family: watch,
 * bag, ...), and `category` / `sub_type` (a `storefront_categories` node of the cart's storefront, matched with its
 * whole subtree — the same reading as the engine's `category_quantity_min` condition). Category and sub-type are
 * two pickers on the screen over the same tree, kept apart so the screen can say which one the operator chose.
 *
 * One instance per evaluation: the line facts are read ONCE (three queries, whatever the number of rules).
 */
final class PromotionScopes
{
    public const ROLE_TARGET = 'target';

    public const ROLE_QUALIFY = 'qualify';

    /** @var list<string> */
    public const ROLES = [self::ROLE_TARGET, self::ROLE_QUALIFY];

    /** @var list<string> */
    public const DIMENSIONS = ['brand', 'category', 'sub_type', 'grade', 'family'];

    /** @var array<int, array{brand: int|null, grade: int|null, family: string|null, on_sale: bool, paths: list<string>}>|null */
    private ?array $facts = null;

    /** @var array<int, string> node id => path */
    private array $nodePaths = [];

    public function __construct(private readonly CartSnapshot $cart) {}

    /**
     * The rule's scope rows, grouped: role => dimension => list of values (ints, or strings for `family`).
     *
     * @return array<string, array<string, list<int|string>>>
     */
    public static function of(int $ruleId): array
    {
        $out = [];
        foreach (DB::table('promotion_rule_scopes')->where('promotion_rule_id', $ruleId)->orderBy('id')
            ->get(['role', 'dimension', 'value_id', 'value']) as $raw) {
            $row = Row::cast($raw);
            $role = Row::str($row, 'role');
            $dimension = Row::str($row, 'dimension');
            if (! in_array($role, self::ROLES, true) || ! in_array($dimension, self::DIMENSIONS, true)) {
                continue;                       // an unknown row matches nothing rather than everything
            }
            $value = $dimension === 'family' ? Row::nstr($row, 'value') : Row::nint($row, 'value_id');
            if ($value === null || $value === '' || $value === 0) {
                continue;
            }
            $out[$role][$dimension][] = $value;
        }

        return $out;
    }

    /**
     * Does cart line `$index` fall inside `$scope` (one role's dimensions)? An empty scope matches every product line;
     * an offer line (no product) matches nothing.
     *
     * @param  array<string, list<int|string>>  $scope
     */
    public function lineMatches(int $index, array $scope): bool
    {
        $line = $this->cart->lines[$index] ?? null;
        if ($line === null || $line->productId === null) {
            return false;
        }
        if ($scope === []) {
            return true;
        }
        $facts = $this->facts()[$line->productId] ?? null;
        if ($facts === null) {
            return false;
        }

        foreach ($scope as $dimension => $values) {
            $hit = match ($dimension) {
                'brand' => $facts['brand'] !== null && in_array($facts['brand'], $values, true),
                'grade' => $facts['grade'] !== null && in_array($facts['grade'], $values, true),
                'family' => $facts['family'] !== null && in_array($facts['family'], $values, true),
                'category', 'sub_type' => $this->inAnyNode($facts['paths'], $values),
                default => false,
            };
            if (! $hit) {
                return false;                   // AND across dimensions
            }
        }

        return true;
    }

    /** Is the product on line `$index` on sale on this storefront (a valid sale price, 0 < sale < price)? */
    public function onSale(int $index): bool
    {
        $line = $this->cart->lines[$index] ?? null;

        return $line !== null && $line->productId !== null && ($this->facts()[$line->productId]['on_sale'] ?? false);
    }

    /**
     * @param  list<string>  $productPaths
     * @param  list<int|string>  $nodeIds
     */
    private function inAnyNode(array $productPaths, array $nodeIds): bool
    {
        foreach ($nodeIds as $nodeId) {
            $path = $this->nodePath((int) $nodeId);
            if ($path === null) {
                continue;
            }
            foreach ($productPaths as $productPath) {
                if (str_starts_with($productPath, $path)) {
                    return true;                // the node itself or anything below it
                }
            }
        }

        return false;
    }

    private function nodePath(int $nodeId): ?string
    {
        if (! array_key_exists($nodeId, $this->nodePaths)) {
            $path = DB::table('storefront_categories')->where('id', $nodeId)
                ->where('storefront_id', $this->cart->storefrontId)->value('path');
            $this->nodePaths[$nodeId] = is_string($path) ? $path : '';
        }

        return $this->nodePaths[$nodeId] === '' ? null : $this->nodePaths[$nodeId];
    }

    /** @return array<int, array{brand: int|null, grade: int|null, family: string|null, on_sale: bool, paths: list<string>}> */
    private function facts(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }
        $ids = $this->cart->productIds();
        $this->facts = [];
        if ($ids === []) {
            return $this->facts;
        }

        foreach (DB::table('catalog_products as p')
            ->leftJoin('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $this->cart->storefrontId);
            })
            ->whereIn('p.id', $ids)
            ->get(['p.id', 'p.brand_id', 'p.grade_id', 'p.family', 'sp.effective_price', 'sp.effective_sale_price']) as $raw) {
            $row = Row::cast($raw);
            $price = (float) (Row::nmoney($row, 'effective_price') ?? '0');
            $sale = (float) (Row::nmoney($row, 'effective_sale_price') ?? '0');
            $this->facts[Row::int($row, 'id')] = [
                'brand' => Row::nint($row, 'brand_id'),
                'grade' => Row::nint($row, 'grade_id'),
                'family' => Row::nstr($row, 'family'),
                // the valid-sale rule every price in the shop follows (catalogPrice)
                'on_sale' => $sale > 0.0 && $sale < $price,
                'paths' => [],
            ];
        }

        foreach (DB::table('storefront_category_product as scp')
            ->join('storefront_categories as node', 'node.id', '=', 'scp.storefront_category_id')
            ->where('scp.storefront_id', $this->cart->storefrontId)
            ->whereIn('scp.product_id', $ids)
            ->get(['scp.product_id', 'node.path']) as $raw) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            if (isset($this->facts[$productId])) {
                $this->facts[$productId]['paths'][] = Row::str($row, 'path');
            }
        }

        return $this->facts;
    }

    /**
     * The quantity and the goods value of the lines inside `$scope` — the "buy from X" test.
     *
     * @param  array<string, list<int|string>>  $scope
     * @return array{quantity: int, amount: float}
     */
    public function totalsWithin(array $scope): array
    {
        $quantity = 0;
        $amount = 0.0;
        foreach ($this->cart->lines as $index => $line) {
            if ($this->lineMatches($index, $scope)) {
                $quantity += $line->quantity;
                $amount += $line->lineTotal();
            }
        }

        return ['quantity' => $quantity, 'amount' => round($amount, 2)];
    }

    /** @return list<int> */
    public static function intValues(mixed $values): array
    {
        return Coerce::intList(is_array($values) ? $values : []);
    }
}
