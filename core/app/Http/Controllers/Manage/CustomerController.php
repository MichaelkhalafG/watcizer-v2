<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Roles;
use App\Domain\Customers\Customers;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers (wave 4D) — the people who buy, as opposed to the people who log in to the dashboard.
 *
 * ── Which ability opens it, and why no new one was invented ─────────────────────────────────
 *
 * `VIEW_ORDERS`. Everything on this screen is already on the order screens: the queue shows a
 * customer's name and phone, the detail shows their address. This screen only groups those facts
 * by person instead of by order, so a new ability would be a new thing to grant, review and get
 * wrong for no new access. Data-entry holds `VIEW_ORDERS`, which is correct — they are the people
 * who answer the telephone.
 *
 * A SCOPED grant sees the customers of its own storefronts (§2.18), enforced in the QUERY, exactly
 * as the order queue does it.
 *
 * ── Read-only, and 404 rather than 403 ──────────────────────────────────────────────────────
 *
 * There is no store, update or destroy here, and there is no route for one. A customer key is
 * guessable (`u:41`), so a customer outside the grant's scope answers 404 — a 403 would confirm
 * that the person exists (§3.11.14).
 */
final class CustomerController
{
    public function index(Request $request): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(
                ['c.last_order_at', 'c.orders_count', 'c.spent', 'c.joined_at', 'c.name'],
                default: 'c.last_order_at',
                direction: 'desc',
                // This result set is a UNION with no `id`; `ckey` is what makes a row unique, and
                // without saying so the default tiebreaker is a 1054 on a column that is not there.
                tiebreaker: 'c.ckey',
            )
            // Phone and e-mail are the two things a caller reads out; the name is included because
            // somebody will type it anyway and an empty result would look like a broken search.
            ->searchable(['c.phone', 'c.email', 'c.name'])
            ->filterable([
                'kind' => ['registered', 'guest'],
                'has_orders' => ['0', '1'],
                'storefront_id' => null,
            ])
            // All three are predicates over a derived table, not columns of a real one.
            ->virtual(['kind', 'has_orders', 'storefront_id'])
            /*
             * ── What the file carries, and what it deliberately does not ───────────────────
             *
             * It carries what the SCREEN carries: name, phone, e-mail, counts, totals. That is
             * personal data and it is the point — this is the list the team works from.
             *
             * It never carries a credential, and not because a rule strips one: `Customers` never
             * names `password`, `remember_token` or `email_verified_at` in a select list, so no
             * such value exists in the row this file is built from. It also never carries the
             * street address, which lives on the detail screen behind a second click.
             */
            /*
             * The column headings are the SCREEN's own words: every one of these keys is already
             * rendered by `Customers/Index.tsx` or `Customers/Show.tsx`, so the file and the table
             * cannot disagree about what a column is called.
             */
            ->exportable([
                'name' => ManageText::t('common.name', 'الاسم'),
                'phone' => ManageText::t('common.phone', 'الهاتف'),
                'email' => ManageText::t('common.email', 'البريد'),
                'kind_label' => ManageText::t('common.type', 'النوع'),
                'orders_count' => ManageText::t('customers.show_orders_count', 'عدد الطلبات'),
                'ordered' => ManageText::t('customers.ordered_total', 'إجمالي ما طلبه'),
                'spent' => ManageText::t('customers.delivered_total', 'إجمالي ما استلمه'),
                'last_order_at' => ManageText::t('customers.last_order', 'آخر طلب'),
                'joined_at' => ManageText::t('customers.first_seen', 'أول ظهور'),
                'storefronts' => [ManageText::t('common.storefronts', 'المتاجر'), fn (array $row): string => implode(' | ', array_map(
                    static fn (mixed $name): string => Coerce::str($name),
                    Coerce::arr($row['storefronts'] ?? null),
                ))],
            ], 'customers');

        $scope = self::scope();
        $query = Customers::query($scope);
        $filters = $table->resolvedFilters();

        $kind = Coerce::nstr($filters['kind'] ?? null);
        if ($kind !== null) {
            $query->where('c.kind', $kind);
        }

        $hasOrders = Coerce::nstr($filters['has_orders'] ?? null);
        if ($hasOrders !== null) {
            $query->where('c.orders_count', $hasOrders === '1' ? '>' : '=', 0);
        }

        $storefrontId = Coerce::nint($filters['storefront_id'] ?? null);
        if ($storefrontId !== null) {
            // `storefronts` is the comma list `GROUP_CONCAT` produced, so membership is FIND_IN_SET
            // — the one honest way to ask "did this person ever buy here".
            $query->whereRaw('FIND_IN_SET(?, c.storefronts)', [$storefrontId]);
        }

        $names = self::storefrontNames();

        $map = function (object $raw) use ($names): array {
            $row = Row::cast($raw);
            $kind = Row::str($row, 'kind');

            return [
                'ckey' => Row::str($row, 'ckey'),
                'kind' => $kind,
                // The VALUE (`registered`/`guest`) is what the filter and the badge variant compare
                // on and it stays a code; only the label beside it is translated.
                'kind_label' => self::kindLabel($kind),
                'name' => Row::nstr($row, 'name') ?? '—',
                'email' => Row::nstr($row, 'email'),
                'phone' => Row::nstr($row, 'phone'),
                'orders_count' => Row::int($row, 'orders_count'),
                'spent' => Row::str($row, 'spent'),
                'ordered' => Row::str($row, 'ordered'),
                'last_order_at' => Row::nstr($row, 'last_order_at'),
                'joined_at' => Row::nstr($row, 'joined_at'),
                'storefronts' => self::storefrontLabels(Row::nstr($row, 'storefronts'), $names),
                'url' => route('manage.customers.show', ['customer' => Row::str($row, 'ckey')]),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Customers/Index', [
            'table' => $table->paginate($query, $map),
            'storefronts' => self::storefrontOptions(),
            /*
             * The honest caveat, on the screen. There is no customer table in this schema: a guest
             * is a group of orders that share a phone number. The team must be able to read that
             * sentence, because it is what makes "3 orders" possibly mean "two people who share a
             * phone" — and they are the ones who can tell.
             */
            // ONE literal, not two concatenated halves: the fallback has to be the whole sentence
            // the operator would read, and a sentence split across a `.` is a sentence no
            // translator — human or mechanical — can see all of.
            'grouping_notice' => ManageText::t('customers.grouping_notice', 'الضيوف تُجمَّع حسب رقم الهاتف (وإن غاب فالبريد، وإن غاب فرمز السلة). لا يوجد جدول عملاء في قاعدة البيانات، فهذا تجميع مبني على بيانات الطلبات نفسها.'),
            'read_only_notice' => ManageText::t(
                'customers.read_only_notice',
                'بيانات العملاء يملكها المتجر، ولا تُعدَّل من اللوحة.',
            ),
        ]);
    }

    public function show(string $customer): Response
    {
        $scope = self::scope();

        $row = Customers::find($customer, $scope);
        // 404 and not 403: `u:41` is a guessable key, and refusing differently would confirm that
        // the person exists.
        abort_if($row === null || ! Customers::exists($customer, $scope), 404);

        $names = self::storefrontNames();
        $customerRow = Row::cast((object) $row);
        $kind = Row::str($customerRow, 'kind');

        return Inertia::render('Manage/Customers/Show', [
            'customer' => [
                'ckey' => Row::str($customerRow, 'ckey'),
                'kind' => $kind,
                'kind_label' => self::kindLabel($kind),
                'name' => Row::nstr($customerRow, 'name') ?? '—',
                'email' => Row::nstr($customerRow, 'email'),
                'phone' => Row::nstr($customerRow, 'phone'),
                'orders_count' => Row::int($customerRow, 'orders_count'),
                'spent' => Row::str($customerRow, 'spent'),
                'ordered' => Row::str($customerRow, 'ordered'),
                'last_order_at' => Row::nstr($customerRow, 'last_order_at'),
                'joined_at' => Row::nstr($customerRow, 'joined_at'),
                'storefronts' => self::storefrontLabels(Row::nstr($customerRow, 'storefronts'), $names),
            ],
            'orders' => array_map(static function (array $order): array {
                $row = Row::cast((object) $order);

                return [
                    'id' => Row::int($row, 'id'),
                    'order_number' => Row::str($row, 'order_number'),
                    'status' => Row::str($row, 'status'),
                    'total' => Row::str($row, 'total_price_for_order'),
                    'payment_method' => Row::nstr($row, 'payment_method'),
                    'provider' => Row::nstr($row, 'paid_via_provider'),
                    'storefront' => Row::nstr($row, 'storefront_name'),
                    'created_at' => Row::nstr($row, 'created_at'),
                    'url' => route('manage.orders.show', ['order' => Row::int($row, 'id')]),
                ];
            }, Customers::orders($customer, $scope)),
            'addresses' => array_map(static function (array $address): array {
                $row = Row::cast((object) $address);

                return [
                    'id' => Row::int($row, 'id'),
                    'line' => Row::nstr($row, 'address_line'),
                    'city' => Row::nstr($row, 'city_name'),
                    'phone_one' => Row::nstr($row, 'phone_number_one'),
                    'phone_two' => Row::nstr($row, 'phone_number_two'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
            }, Customers::addresses($customer, $scope)),
        ]);
    }

    /**
     * "Registered account" or "Guest" — the label, never the value.
     *
     * One home for it because the list and the detail both render it, and a label that said one
     * thing on the table and another on the profile would be a defect nobody would report.
     */
    private static function kindLabel(string $kind): string
    {
        return $kind === 'registered'
            ? ManageText::t('customers.registered', 'حساب مسجَّل')
            : ManageText::t('common.guest', 'ضيف');
    }

    /**
     * The storefront ids this grant may see, or null for an unscoped grant.
     *
     * @return list<int>|null
     */
    private static function scope(): ?array
    {
        $user = request()->user();
        if ($user === null) {
            return [];
        }

        return app(Roles::class)->storefrontScope($user);
    }

    /**
     * `"1,2"` → the storefronts' names.
     *
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private static function storefrontLabels(?string $ids, array $names): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $ids) as $id) {
            $key = Coerce::nint(trim($id));
            if ($key !== null) {
                $out[] = $names[$key] ?? ('#'.$key);
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function storefrontNames(): array
    {
        $out = [];
        foreach (Storefront::query()->orderBy('id')->get(['id', 'name']) as $storefront) {
            $out[Coerce::int($storefront->getAttribute('id'))] = Coerce::str($storefront->getAttribute('name'));
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (self::storefrontNames() as $id => $name) {
            $out[] = ['value' => (string) $id, 'label' => $name];
        }

        return $out;
    }
}
