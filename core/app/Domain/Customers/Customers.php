<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Support\Coerce;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The people who BUY — read-only, and read-only by construction (wave 4D).
 *
 * ── Why this is a different screen from "Users" ──────────────────────────────────────────────
 *
 * `/manage/users` is about who may open the dashboard: it grants roles and touches
 * `core_user_roles`, a table core owns. This one is about customers, and it reads `users`,
 * `orders` and `addresses` — all three LEGACY, all three shared with the live storefront. The two
 * were deliberately never merged, because a screen that can do both is a screen where somebody
 * eventually grants a role by clicking the wrong row.
 *
 * ── Read-only is not a policy here, it is the architecture ──────────────────────────────────
 *
 * AGENTS §3 forbids core writing a legacy table at all, and the `legacy` connection is held in
 * `tx_read_only = 1` for exactly this reason — a write would be refused by the SERVER, not by a
 * missing button. So this class has no sibling that writes, no update method, and no route behind
 * it that is not a GET.
 *
 * ── What a "customer" IS in this schema, and why the query has two halves ───────────────────
 *
 * Half of the shop's orders were never placed by an account. `orders` carries `guest_name`,
 * `guest_email`, `guest_phone` and `guest_token` for exactly that, and a guest who ordered three
 * times is three rows with no row anywhere that says "this is one person". So:
 *
 *   • a REGISTERED customer is a `users` row of type `User` — the enum's other two values are the
 *     legacy dashboard's own staff accounts and have no business on a customer list;
 *   • a GUEST customer is the group of orders sharing a phone number, or failing that an e-mail,
 *     or failing that the cart token the storefront issued. Phone first on purpose: in Egypt it is
 *     the field the courier actually uses, it is required at checkout, and it is what the person
 *     on the telephone will read out.
 *
 * The grouping is a heuristic and is LABELLED as one on the screen. It cannot be anything else
 * without a customer table that does not exist.
 *
 * ── No credential column is selected. Anywhere. ─────────────────────────────────────────────
 *
 * `password`, `remember_token` and `email_verified_at` are never named in a select list here, so
 * they cannot reach a prop, a CSV or a log — the same structural argument the CSV export relies
 * on, one level further down.
 */
final class Customers
{
    /** Order statuses that count as money actually taken. */
    public const EARNED = ['delivered', 'completed'];

    /**
     * The list query: registered accounts and guest groups, in one set.
     *
     * @param  list<int>|null  $storefrontScope  null = every storefront (an unscoped grant)
     */
    public static function query(?array $storefrontScope): Builder
    {
        $registered = DB::connection('legacy')
            ->table('users as u')
            ->leftJoinSub(self::ordersByUser($storefrontScope), 'oa', 'oa.user_id', '=', 'u.id')
            // The enum's `Admin`/`SuperAdmin` are the legacy dashboard's own staff, not customers.
            ->where('u.type', 'User')
            ->select([
                DB::raw("CONCAT('u:', u.id) as ckey"),
                DB::raw("'registered' as kind"),
                'u.id as user_id',
                DB::raw('NULL as guest_token'),
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') as name"),
                'u.email',
                'u.phone_number as phone',
                'u.created_at as joined_at',
                DB::raw('COALESCE(oa.orders_count, 0) as orders_count'),
                DB::raw('COALESCE(oa.spent, 0) as spent'),
                DB::raw('COALESCE(oa.ordered, 0) as ordered'),
                'oa.last_order_at',
                'oa.storefronts',
            ]);

        /*
         * A scoped grant sees the customers of ITS storefronts (§2.18). For a registered account
         * that means "has at least one order in scope" — an account with no order anywhere is
         * nobody's customer in particular, and showing it to one storefront's operator and not the
         * other would be arbitrary, so it is shown to unscoped grants only.
         */
        if ($storefrontScope !== null) {
            $registered->whereNotNull('oa.user_id');
        }

        return DB::connection('legacy')
            ->query()
            ->fromSub(
                $registered->unionAll(self::guests($storefrontScope)),
                'c'
            );
    }

    /**
     * One row per registered customer: their order totals, already narrowed to the grant's scope.
     *
     * A real sub-query rather than SQL text. `App\Support\Sql` is the one place in this
     * application allowed to compose a statement out of parts — it earns that with a column
     * whitelist — and a reader has no business becoming a second one.
     *
     * @param  list<int>|null  $storefrontScope
     */
    private static function ordersByUser(?array $storefrontScope): Builder
    {
        $query = DB::connection('legacy')
            ->table('orders as o')
            ->whereNotNull('o.user_id')
            ->groupBy('o.user_id')
            ->select([
                'o.user_id',
                DB::raw('COUNT(*) as orders_count'),
                self::spent(),
                self::ordered(),
                DB::raw('MAX(o.created_at) as last_order_at'),
                DB::raw('GROUP_CONCAT(DISTINCT o.storefront_id ORDER BY o.storefront_id) as storefronts'),
            ]);

        if ($storefrontScope !== null) {
            $query->whereIn('o.storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }

        return $query;
    }

    /**
     * One row per GUEST group — phone, else e-mail, else the storefront's cart token.
     *
     * The key is computed in a SUB-QUERY and grouped as a plain column. Grouping directly by the
     * `COALESCE(...)` expression is rejected by MariaDB's ONLY_FULL_GROUP_BY — it does not match
     * that expression against the same expression in the select list, and names the first column
     * inside it as ungrouped. This shape has nothing to match.
     *
     * @param  list<int>|null  $storefrontScope
     */
    private static function guests(?array $storefrontScope): Builder
    {
        $orders = DB::connection('legacy')
            ->table('orders as o')
            ->whereNull('o.user_id')
            ->whereNotNull(DB::raw(self::GUEST_KEY))
            ->select([
                DB::raw(self::GUEST_KEY.' as gkey'),
                'o.guest_token', 'o.guest_name', 'o.guest_email', 'o.guest_phone',
                'o.created_at', 'o.status', 'o.total_price_for_order', 'o.storefront_id',
            ]);

        if ($storefrontScope !== null) {
            $orders->whereIn('o.storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }

        return DB::connection('legacy')
            ->query()
            ->fromSub($orders, 'g')
            ->groupBy('g.gkey')
            ->select([
                DB::raw("CONCAT('g:', g.gkey) as ckey"),
                DB::raw("'guest' as kind"),
                DB::raw('NULL as user_id'),
                DB::raw('MAX(g.guest_token) as guest_token'),
                DB::raw('MAX(g.guest_name) as name'),
                DB::raw('MAX(g.guest_email) as email'),
                DB::raw('MAX(g.guest_phone) as phone'),
                // A guest "joined" when they first bought: there is no account to have a birthday.
                DB::raw('MIN(g.created_at) as joined_at'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw(
                    "COALESCE(SUM(CASE WHEN g.status IN ('delivered', 'completed') "
                    .'THEN g.total_price_for_order ELSE 0 END), 0) as spent'
                ),
                DB::raw(
                    "COALESCE(SUM(CASE WHEN g.status <> 'cancelled' "
                    .'THEN g.total_price_for_order ELSE 0 END), 0) as ordered'
                ),
                DB::raw('MAX(g.created_at) as last_order_at'),
                DB::raw('GROUP_CONCAT(DISTINCT g.storefront_id ORDER BY g.storefront_id) as storefronts'),
            ]);
    }

    /** The expression that decides which orders are the SAME guest. Phone, e-mail, then token. */
    private const GUEST_KEY = "COALESCE(NULLIF(o.guest_phone, ''), NULLIF(o.guest_email, ''), NULLIF(o.guest_token, ''))";

    /**
     * `SUM(total) FILTERED TO MONEY ACTUALLY TAKEN` — delivered and completed, nothing else.
     *
     * A pending order is not a purchase and a cancelled one is not either; a "total spent" that
     * counted them would be the number somebody quotes at a customer before refunding them.
     */
    private static function spent(): ExpressionContract
    {
        // The two status literals are written out here and nowhere else, so nothing that varies at
        // run time reaches the statement.
        return DB::raw(
            "COALESCE(SUM(CASE WHEN o.status IN ('delivered', 'completed') "
            .'THEN o.total_price_for_order ELSE 0 END), 0) as spent'
        );
    }

    /**
     * `SUM(total) OF EVERYTHING NOT CANCELLED` — what this customer has ORDERED (D-23).
     *
     * ── Why a second number rather than a looser first one ──────────────────────────────────
     *
     * `/manage/customers` showed `عدد الطلبات 5` and `إجمالي المشتريات 0.00` on the same card,
     * with five orders of 3,190 listed underneath. The query was right and the docblock above says
     * why — a pending order is not a purchase and a cancelled one is not either. But **no order in
     * this database has ever reached `delivered` or `completed`**, so the column is 0.00 for every
     * row, and an unqualified "total purchases" beside an order count reads as broken data.
     *
     * Loosening `spent()` to fix the appearance would be the wrong repair: it would make the
     * number that means "money actually taken" stop meaning that, and that is the number somebody
     * quotes at a customer before refunding them.
     *
     * So both are shown. `ordered` is what they have committed to, `spent` is what has actually
     * completed, and the gap between them is itself worth seeing — it is the shop's open exposure.
     */
    private static function ordered(): ExpressionContract
    {
        return DB::raw(
            "COALESCE(SUM(CASE WHEN o.status <> 'cancelled' "
            .'THEN o.total_price_for_order ELSE 0 END), 0) as ordered'
        );
    }

    /**
     * ONE customer's orders, newest first — the detail a telephone call needs.
     *
     * @param  list<int>|null  $storefrontScope
     * @return list<array<string, mixed>>
     */
    public static function orders(string $ckey, ?array $storefrontScope): array
    {
        $query = DB::connection('legacy')
            ->table('orders as o')
            ->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->orderByDesc('o.created_at')
            ->limit(200)
            ->select([
                'o.id', 'o.order_number', 'o.status', 'o.total_price_for_order', 'o.payment_method',
                'o.paid_via_provider', 'o.created_at', 'o.storefront_id', 'o.address_id',
                's.name as storefront_name',
            ]);

        self::identify($query, $ckey);

        if ($storefrontScope !== null) {
            $query->whereIn('o.storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $out[] = self::fields($row);
        }

        return $out;
    }

    /**
     * The addresses this customer has used.
     *
     * Reached through the ORDERS, never by listing `addresses` for a token: an operator who can
     * see the order can see where it went, and nothing else is offered.
     *
     * @param  list<int>|null  $storefrontScope
     * @return list<array<string, mixed>>
     */
    public static function addresses(string $ckey, ?array $storefrontScope): array
    {
        $orders = DB::connection('legacy')->table('orders as o')->whereNotNull('o.address_id');
        self::identify($orders, $ckey);
        if ($storefrontScope !== null) {
            $orders->whereIn('o.storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }

        $ids = [];
        foreach ($orders->distinct()->pluck('o.address_id') as $id) {
            $value = Coerce::nint($id);
            if ($value !== null) {
                $ids[] = $value;
            }
        }
        if ($ids === []) {
            return [];
        }

        // `shipping_cities` carries only the COST; the name is in its translations table, one
        // row per locale — measured on the schema, not assumed from the column list.
        $rows = DB::connection('legacy')
            ->table('addresses as a')
            ->leftJoin('shipping_city_translations as ct', function (JoinClause $join): void {
                $join->on('ct.shipping_city_id', '=', 'a.shipping_city_id')->where('ct.locale', '=', 'ar');
            })
            ->whereIn('a.id', $ids)
            ->orderByDesc('a.updated_at')
            ->get(['a.id', 'a.address_line', 'a.phone_number_one', 'a.phone_number_two', 'a.updated_at', 'ct.city_name']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::fields($row);
        }

        return $out;
    }

    /**
     * Is this a customer the acting grant may open at all?
     *
     * @param  list<int>|null  $storefrontScope
     */
    public static function exists(string $ckey, ?array $storefrontScope): bool
    {
        $query = DB::connection('legacy')->table('orders as o');
        self::identify($query, $ckey);
        if ($storefrontScope !== null) {
            $query->whereIn('o.storefront_id', $storefrontScope === [] ? [0] : $storefrontScope);
        }
        if ($query->exists()) {
            return true;
        }

        // A registered account with no orders exists for an unscoped grant only — same rule the
        // list applies, so the detail screen cannot show what the list would not.
        $userId = self::userIdOf($ckey);

        return $storefrontScope === null
            && $userId !== null
            && DB::connection('legacy')->table('users')->where('id', $userId)->where('type', 'User')->exists();
    }

    /** Narrow a query on `orders as o` to this one customer. */
    private static function identify(Builder $query, string $ckey): void
    {
        $userId = self::userIdOf($ckey);
        if ($userId !== null) {
            $query->where('o.user_id', $userId);

            return;
        }

        $value = str_starts_with($ckey, 'g:') ? substr($ckey, 2) : '';
        if ($value === '') {
            // An unparseable key matches nothing rather than everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull('o.user_id')->where(DB::raw(self::GUEST_KEY), $value);
    }

    /**
     * A result row as a keyed array. `(array) $stdClass` is exactly this at run time; saying so
     * once here is what lets every caller be typed instead of each of them re-stating it.
     *
     * @return array<string, mixed>
     */
    private static function fields(object $row): array
    {
        $out = [];
        foreach ((array) $row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function userIdOf(string $ckey): ?int
    {
        return str_starts_with($ckey, 'u:') ? Coerce::nint(substr($ckey, 2)) : null;
    }

    /**
     * The identity columns of one customer, for the detail header.
     *
     * @param  list<int>|null  $storefrontScope
     * @return array<string, mixed>|null
     */
    public static function find(string $ckey, ?array $storefrontScope): ?array
    {
        $row = self::query($storefrontScope)->where('c.ckey', $ckey)->first();

        return $row === null ? null : self::fields($row);
    }
}
