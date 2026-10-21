<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Roles;
use App\Domain\Analytics\DashboardMetrics;
use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\NewOrders;
use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /manage — the dashboard home.
 *
 * ── What this page is for, and what it refuses to be ────────────────────────────────────────
 *
 * It is the screen somebody opens in the morning to decide what to do first. So the test every number
 * here had to pass is: **what would you DO after reading this?** A number nobody acts on was removed,
 * however true it was. The page answers five questions, in the order they get asked:
 *
 *   1. Is today normal?            → today against the same weekday, averaged over the last four weeks
 *   2. How is the month going?     → 30 days of orders and money, as a chart, with the cancellation rate
 *   3. What is waiting for me?     → open orders by status, and the age of the oldest one
 *   4. What should I buy more of?  → what sold, and what sold and is now OUT OF STOCK
 *   5. What is wrong with the shop? → the per-storefront catalogue gaps, which were already here
 *
 * ── What was dropped, and why ───────────────────────────────────────────────────────────────
 *
 *   • `products` and `active_products` (catalogue-wide totals) — the per-storefront table below answers
 *     the question those two were standing in for, and answers it per shop, which is the useful form.
 *   • `orders_total` (all-time) — a lifetime counter nobody acts on.
 *   • `variants` and `threshold_products` — plumbing counts; "how many variant rows exist" is not a
 *     decision anybody makes on this screen.
 *   • the express/market unit sums — a total with no decision attached. Out-of-stock and low-stock, which
 *     DO have one, are kept.
 *
 * `orders_unseen` is kept exactly as it was, from `NewOrders::countFor()`, because the sidebar badge reads
 * the same source: two numbers that disagreed would be worse than either alone. It follows the operator's
 * GRANT and not this page's storefront switch, for the same reason — it must agree with the badge.
 *
 * ── The two shops ───────────────────────────────────────────────────────────────────────────
 *
 * A switch, defaulting to ALL the shops the operator may see. The catalogue is shared, so catalogue numbers
 * are catalogue-wide whatever the switch says and are labelled that way; the MONEY is not shared, so every
 * sales figure follows the switch. An operator with a scoped grant never sees another shop's money and is
 * offered no option that would show it — the switch is built from their own scope, not from the storefront
 * table. Combined was chosen as the default because the owner runs both shops and asks "how is the business
 * doing" before "how is Brand Fashion doing"; one click narrows it.
 *
 * ── Cost ────────────────────────────────────────────────────────────────────────────────────
 *
 * Budget: 20 queries and 250 ms. See {@see DashboardMetrics} for what each query is bounded by and what it
 * measured at 250,000 orders. Nothing on this page is cached.
 */
final class HomeController
{
    public function __construct(
        private readonly NewOrders $newOrders,
        private readonly DashboardMetrics $metrics,
        private readonly Roles $roles,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $grant = $user instanceof User ? $this->roles->storefrontScope($user) : [];
        // ONE read of `storefronts`: the switch's options and the catalogue table below are both built from
        // it, and the page's query budget is tight enough that a second identical SELECT is worth removing.
        $shops = Storefront::query()->orderBy('id')->get(['id', 'code', 'name', 'is_active', 'currency']);
        $options = $this->options($shops, $grant);
        $selected = $this->selected($request, $options);
        $scope = $selected === null ? $grant : [$selected];
        // one aggregation over the window answers both "what sold" and "what sold and has run out"
        $sold = $this->metrics->sold($scope);

        return Inertia::render('Manage/Home', [
            'scope' => [
                'selected' => $selected,
                'options' => $options,
                // The currency every money figure on the page is in. Both shops are EGP today; if a future
                // storefront is not, the page says so rather than adding two currencies into one total.
                'currency' => $this->currency($options, $selected),
                'mixed_currency' => $this->mixedCurrency($options, $selected),
                'window_days' => DashboardMetrics::WINDOW_DAYS,
            ],
            'sales' => $this->metrics->sales($scope),
            'queue' => $this->metrics->queue($scope),
            'payments' => $this->metrics->payments($scope),
            'top_products' => $sold['top'],
            'restock' => $sold['restock'],
            'unseen' => $this->newOrders->countFor($user),
            'inventory' => $this->inventory(),
            'storefronts' => $this->storefronts($shops),
        ]);
    }

    /**
     * The shops this operator may look at. An unscoped grant gets every active storefront; a scoped one gets
     * exactly its own, and nothing else is offered — the switch cannot be used to widen a grant.
     *
     * @param  Collection<int, Storefront>  $shops
     * @param  list<int>|null  $grant
     * @return list<array{id: int, code: string, name: string, currency: string}>
     */
    private function options(Collection $shops, ?array $grant): array
    {
        $out = [];
        foreach ($shops as $storefront) {
            if ($grant !== null && ! in_array((int) $storefront->id, $grant, true)) {
                continue;
            }
            $out[] = [
                'id' => (int) $storefront->id,
                'code' => (string) $storefront->code,
                'name' => (string) $storefront->name,
                'currency' => (string) $storefront->currency,
            ];
        }

        return $out;
    }

    /**
     * Which shop the switch is on: null for "all of them". A storefront the operator may not see is read as
     * null rather than refused — a bookmarked URL from a wider grant should show what this person is allowed
     * to see, not a 403 on the home page.
     *
     * @param  list<array{id: int, code: string, name: string, currency: string}>  $options
     */
    private function selected(Request $request, array $options): ?int
    {
        $asked = Coerce::nint($request->query('storefront'));
        if ($asked === null) {
            return null;
        }
        foreach ($options as $option) {
            if ($option['id'] === $asked) {
                return $asked;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int, code: string, name: string, currency: string}>  $options
     */
    private function currency(array $options, ?int $selected): string
    {
        foreach ($options as $option) {
            if ($selected !== null && $option['id'] === $selected) {
                return $option['currency'];
            }
        }

        return $options[0]['currency'] ?? 'EGP';
    }

    /**
     * True when "all shops" would add up money in more than one currency — in which case the page prints the
     * totals per shop instead of inventing a converted number.
     *
     * @param  list<array{id: int, code: string, name: string, currency: string}>  $options
     */
    private function mixedCurrency(array $options, ?int $selected): bool
    {
        if ($selected !== null || count($options) < 2) {
            return false;
        }

        return count(array_unique(array_column($options, 'currency'))) > 1;
    }

    /**
     * The catalogue AS EACH SITE SEES IT — unchanged from the first version of this screen.
     *
     * The catalogue is SHARED (AGENTS §2.4), so "how many products are there" has one answer and is not the
     * question the team asks in the morning. They ask "how many are live on Brand Fashion, and what is
     * stopping the rest". Every number is one indexed query per storefront. `not_added` is separated from
     * `hidden` on purpose: a product with no `storefront_product` row was never offered to that site, while a
     * hidden one was and somebody decided against it. They lead to different actions.
     *
     * @param  Collection<int, Storefront>  $shops
     * @return list<array{id: int, code: string, name: string, is_active: bool, visible: int, hidden: int, not_added: int, unplaced: int, no_arabic: int}>
     */
    private function storefronts(Collection $shops): array
    {
        $live = DB::table('catalog_products')->whereNull('deleted_at')->count();

        $out = [];
        foreach ($shops as $storefront) {
            $id = (int) $storefront->id;
            $rows = DB::table('storefront_product as sp')
                ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
                ->where('sp.storefront_id', $id)
                ->whereNull('p.deleted_at');

            $visible = (clone $rows)->where('sp.is_visible', 1)->count();
            $present = (clone $rows)->count();

            $out[] = [
                'id' => $id,
                'code' => (string) $storefront->code,
                'name' => (string) $storefront->name,
                'is_active' => (bool) $storefront->is_active,
                'visible' => $visible,
                'hidden' => $present - $visible,
                'not_added' => $live - $present,
                // Placed nowhere on THIS storefront: present in the catalogue and reachable from
                // no page of this site.
                'unplaced' => (clone $rows)
                    ->whereNotExists(function (Builder $sub) use ($id): void {
                        $sub->from('storefront_category_product as scp')
                            ->whereColumn('scp.product_id', 'sp.product_id')
                            ->where('scp.storefront_id', $id)
                            ->selectRaw('1');
                    })
                    ->count(),
                // Visible today and missing its Arabic title — which the dashboard now refuses to
                // create, so a non-zero number here is legacy data and worth seeing.
                'no_arabic' => (clone $rows)
                    ->where('sp.is_visible', 1)
                    ->whereNotExists(function (Builder $sub): void {
                        $sub->from('catalog_product_translations as t')
                            ->whereColumn('t.product_id', 'sp.product_id')
                            ->where('t.locale', 'ar')
                            ->whereRaw("TRIM(COALESCE(t.title, '')) <> ''")
                            ->selectRaw('1');
                    })
                    ->count(),
            ];
        }

        return $out;
    }

    /**
     * Stock health — the two numbers that lead to an action, kept from the first version.
     *
     * Catalogue-wide, because stock is: one product, one shelf, however many shops sell it. The page labels
     * them so, and they do not move when the storefront switch does.
     *
     * @return array{out_of_stock: int, low_stock: int}
     */
    private function inventory(): array
    {
        return [
            'out_of_stock' => InventoryService::outOfStockProducts()->count(),
            'low_stock' => InventoryService::lowStockProducts()->count(),
        ];
    }

}
