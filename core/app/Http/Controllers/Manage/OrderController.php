<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Preferences;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Inventory\Actor;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderFulfilment;
use App\Domain\Payment\CallbackPolicy;
use App\Domain\Promotions\PromotionDiscounts;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Table\TableExport;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orders — the shop floor (wave 4C, AGENTS §2.7).
 *
 * ── Three abilities, not one ─────────────────────────────────────────────────────────────────
 *
 * `view-orders` reads, `manage-order-fulfilment` moves an order forward, `cancel-orders` cancels
 * and refunds. Data-entry holds the first two and not the third, because a cancellation moves
 * MONEY and returns STOCK. The split is enforced on the ROUTE (the gates) and again here for the
 * per-action buttons the screen renders, so a data-entry operator sees no cancel control AND is
 * refused by the server if they post one anyway — `OrderAuthorizationTest` proves the second by
 * direct HTTP, because a hidden menu is not a permission.
 *
 * ── What the list must not do ────────────────────────────────────────────────────────────────
 *
 * No correlated sub-select in the SELECT list (AGENTS §2.24): the item count and the payment
 * summary are read once for the page's ids through `TableQuery::paginate()`'s prepare callback.
 * At 9 orders that is invisible; the rule exists because it was measured at 7 000 products.
 */
final class OrderController
{
    public function __construct(private readonly OrderFulfilment $fulfilment) {}

    /**
     * The list, with the filters the brief names: storefront, status, date range, provider,
     * method, and a search over order number or phone.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        /** @var array{items: array<int, int>, attempts: array<int, array<string, mixed>>} $extras */
        $extras = ['items' => [], 'attempts' => []];

        /*
         * ── The filter dropdowns, read ONCE ──────────────────────────────────────────────────
         *
         * Each of these lists is needed twice in this method: once as the filter's ALLOW-LIST (a
         * value outside it is discarded by `resolvedFilters()`) and once as the OPTIONS the screen
         * renders. They used to be called in both places, and each call is a separate read — three
         * for a `distinct()` (orders, attempts, configured) and one for the storefronts. Fourteen
         * queries per page load to populate three dropdowns, all of them asking the same question
         * in the same request and getting the same answer.
         *
         * Two of those reads are `SELECT DISTINCT` over `orders` on `paid_via_provider` and
         * `paid_via_method`, and NEITHER column is indexed: at 67 orders that is invisible, at the
         * 7,000 this screen is being built for it is a full scan and a filesort, twice over, for
         * nothing. Hoisting to a local is the whole fix — no cache, no memo, nothing with a
         * lifetime to get wrong, and the values cannot drift between the allow-list and the options
         * because there is now only one of each.
         */
        $storefronts = self::storefrontOptions();
        $providers = self::distinct('paid_via_provider');
        $methods = self::distinct('paid_via_method');

        $table = TableQuery::for($request)
            ->sortable(['o.created_at', 'o.total_price_for_order', 'o.status', 'o.order_number'], default: 'o.created_at', direction: 'desc')
            // Every filter this screen offers is declared, so it gets a whitelist, a place in the
            // query string and a seat in the reset button — and the ones that are not a plain
            // `where(column, value)` are declared VIRTUAL and applied by `applyFilters()`.
            ->filterable([
                'storefront_id' => array_map(fn (array $o): string => $o['value'], $storefronts),
                'status' => OrderFulfilment::STATUSES,
                'provider' => array_map(fn (array $o): string => $o['value'], $providers),
                'method' => array_map(fn (array $o): string => $o['value'], $methods),
                // `null` = any scalar. An empty ARRAY would be an allow-list of nothing, and
                // `resolvedFilters()` drops a value that is not in it — so `[]` silently discards
                // every date the operator picks.
                'from' => null,
                'to' => null,
            ])
            ->virtual(['storefront_id', 'status', 'provider', 'method', 'from', 'to'])
            /*
             * ── THE ONE EXPORT THAT CARRIES PERSONAL DATA ──────────────────────────────────
             *
             * `customer` and `phone` are here because the ORDER QUEUE already shows them: the
             * export rule is "what this operator can see", and a fulfilment sheet without a phone
             * number is useless in Egypt, where the courier's whole workflow is a phone call.
             *
             * What is deliberately NOT here, and why:
             *   • the street address, the second phone and the e-mail — they live on the order
             *     DETAIL screen, not the queue, so they are not in this payload to pick from;
             *   • the customer's account id, so an export cannot be joined back to `users`;
             *   • every payment identifier beyond "who took it and how" — no token, no card, no
             *     provider reference. `SettlementExport` is the reconciliation file and it is
             *     behind MANAGE_PAYMENTS, a different ability on purpose.
             *
             * A scoped grant exports only its own storefronts' orders, because `applyScope()`
             * narrowed the QUERY before this ever runs (§2.18) — not because the file filters.
             *
             * FLAGGED FOR THE DEVELOPER (2026-09-14): if a customer phone in a downloadable file
             * is not acceptable, the single change is to drop the two lines below; nothing else
             * moves. See docs/wave4d.
             */
            ->exportable([
                'order_number' => ManageText::t('common.order_number', 'رقم الطلب'),
                'created_at' => ManageText::t('common.date', 'التاريخ'),
                'status_label' => ManageText::t('common.status', 'الحالة'),
                'total' => ManageText::t('common.total', 'الإجمالي'),
                'payment_method' => ManageText::t('orders.payment_method', 'طريقة الدفع'),
                'paid_via_provider' => ManageText::t('orders.payment_provider', 'مزوّد الدفع'),
                // NOT `orders.payment_method`: that column is what the customer CHOSE, this one is
                // what the money actually came through. Same word in English until you read both
                // columns side by side in the file, which is exactly when it matters.
                'paid_via_method' => ManageText::t('orders.payment_channel', 'وسيلة الدفع'),
                'customer' => ManageText::t('common.customer', 'العميل'),
                'phone' => ManageText::t('common.phone', 'الهاتف'),
                'items' => ManageText::t('orders.item_count', 'عدد الأصناف'),
                'storefront' => ManageText::t('common.storefront', 'المتجر'),
                'last_attempt' => [ManageText::t('orders.last_attempt', 'آخر محاولة دفع'), fn (array $row): string => Coerce::str(
                    Coerce::arr($row['last_attempt'] ?? null)['status'] ?? null
                )],
            ], 'orders');

        $query = DB::table('orders as o')
            ->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->select([
                'o.id', 'o.order_number', 'o.status', 'o.total_price_for_order', 'o.payment_method',
                'o.paid_via_provider', 'o.paid_via_method', 'o.guest_name', 'o.guest_phone',
                'o.storefront_id', 'o.created_at', 's.name as storefront_name',
            ]);

        // 🟠-3: a SCOPED grant sees only its own storefronts' orders, constrained in the QUERY.
        // AGENTS §2.18 promises a scoped grant cannot read another storefront's data; before this
        // the promise held for every catalogue screen and not for this one, because the queue is
        // deliberately cross-storefront and nothing narrowed it. The UI stays one queue — a scoped
        // operator simply has a smaller one.
        self::applyScope($query);

        self::applyFilters($query, $table->resolvedFilters(), $request);

        $prepare = function (array $rows) use (&$extras): void {
            $extras = self::pageExtras(Coerce::objectList($rows));
        };

        $map = function (object $raw) use (&$extras): array {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $status = Row::str($row, 'status');

            return [
                'id' => $id,
                'order_number' => Row::str($row, 'order_number'),
                'status' => $status,
                'status_label' => OrderFulfilment::label($status),
                'total' => Row::str($row, 'total_price_for_order'),
                'payment_method' => Row::nstr($row, 'payment_method'),
                // Who took the money, from the attempt that succeeded (§3.9.6). A plain
                // column, NOT an append-only record: `CallbackPolicy` is what stops a
                // second success from overwriting it.
                'paid_via_provider' => Row::nstr($row, 'paid_via_provider'),
                'paid_via_method' => Row::nstr($row, 'paid_via_method'),
                'customer' => Row::nstr($row, 'guest_name') ?? '—',
                'phone' => Row::nstr($row, 'guest_phone'),
                'storefront' => Row::nstr($row, 'storefront_name'),
                'storefront_id' => Row::nint($row, 'storefront_id'),
                'created_at' => Row::nstr($row, 'created_at'),
                'items' => Coerce::int($extras['items'][$id] ?? null),
                // The last attempt's outcome, so a "paid but pending" order is visible
                // from the list rather than only from the detail.
                'last_attempt' => $extras['attempts'][$id] ?? null,
                'url' => route('manage.orders.show', ['order' => $id]),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map, $prepare);
        }

        /*
         * Opening the queue discharges the badge's promise — "there is something here you have not
         * looked at" — so the stamp moves now, for THIS operator only.
         *
         * After the render data is built, and not on the export path: a CSV download is not looking
         * at the queue, and clearing somebody's badge because they exported a file would hide the
         * orders they were about to work.
         */
        $actor = $request->user();
        if ($actor instanceof User) {
            Preferences::markOrdersSeen($actor);
        }

        return Inertia::render('Manage/Orders/Index', [
            'table' => $table->paginate($query, $map, $prepare),
            'filters' => [
                'storefronts' => $storefronts,
                'statuses' => array_map(
                    fn (string $s): array => ['value' => $s, 'label' => OrderFulfilment::label($s)],
                    OrderFulfilment::STATUSES,
                ),
                'providers' => $providers,
                'methods' => $methods,
            ],
            'abilities' => [
                'fulfil' => Gate::allows(Role::MANAGE_ORDER_FULFILMENT),
                'cancel' => Gate::allows(Role::CANCEL_ORDERS),
                // The settlement export is payment reconciliation, not order work: its route sits
                // behind `manage-payments`, so the button must ask for that ability and not guess
                // from a neighbouring one.
                'settle' => Gate::allows(Role::MANAGE_PAYMENTS),
            ],
        ]);
    }

    /**
     * One order: its lines with variants, the addresses, every payment attempt, the ledger
     * movements THIS order caused, and what the screen may offer next.
     */
    public function show(Request $request, int $order): Response
    {
        $row = DB::table('orders as o')
            ->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->where('o.id', $order)
            ->first([
                'o.id', 'o.order_number', 'o.status', 'o.total_price_for_order', 'o.payment_method',
                'o.paid_via_provider', 'o.paid_via_method', 'o.note', 'o.guest_name', 'o.guest_email',
                'o.guest_phone', 'o.user_id', 'o.address_id', 'o.storefront_id', 'o.created_at',
                'o.updated_at', 's.name as storefront_name',
            ]);

        // 404, never 403: a 403 would confirm the order exists (§3.11.14).
        abort_if(! is_object($row), 404);
        $orderRow = Row::cast($row);

        // …and the same 404 for an order OUTSIDE the acting grant's storefronts (🟠-3).
        self::requireInScope($order);
        $status = Row::str($orderRow, 'status');

        return Inertia::render('Manage/Orders/Show', [
            'order' => [
                'id' => Row::int($orderRow, 'id'),
                'order_number' => Row::str($orderRow, 'order_number'),
                'status' => $status,
                'status_label' => OrderFulfilment::label($status),
                'total' => Row::str($orderRow, 'total_price_for_order'),
                'payment_method' => Row::nstr($orderRow, 'payment_method'),
                'paid_via_provider' => Row::nstr($orderRow, 'paid_via_provider'),
                'paid_via_method' => Row::nstr($orderRow, 'paid_via_method'),
                'note' => Row::nstr($orderRow, 'note'),
                'customer' => [
                    'name' => Row::nstr($orderRow, 'guest_name'),
                    'email' => Row::nstr($orderRow, 'guest_email'),
                    'phone' => Row::nstr($orderRow, 'guest_phone'),
                    'user_id' => Row::nint($orderRow, 'user_id'),
                ],
                'storefront' => Row::nstr($orderRow, 'storefront_name'),
                'storefront_id' => Row::nint($orderRow, 'storefront_id'),
                'created_at' => Row::nstr($orderRow, 'created_at'),
                'updated_at' => Row::nstr($orderRow, 'updated_at'),
            ],
            'items' => self::items($order),
            /*
             * Why this order's total is lower than its lines (M1r).
             *
             * NULL for the overwhelming majority of orders, which is the honest shape. When it is
             * set, the screen owes the operator an explanation rather than a number: a total that
             * does not match the lines, with nothing saying why, is the thing somebody telephones
             * about.
             */
            'discount' => PromotionDiscounts::forOrder($order),
            'address' => self::address(Row::nint($orderRow, 'address_id')),
            'attempts' => self::attempts($order),
            // The ledger rows this order caused — the reservation AND any release. This is what
            // makes "cancel returned the stock" visible to a human instead of asserted in a test.
            'movements' => self::movements($order),
            /*
             * What this order e-mailed, to whom, and whether it got there (prerequisite (a)).
             *
             * On the ORDER screen because "did the customer get told?" is asked about ONE order,
             * on the telephone, while somebody waits. The global queue view is
             * `php artisan mail:drain --report`, which exits non-zero while anything is failed —
             * the same split as findings: per-order here, a loud list there.
             */
            'notifications' => OrderMailer::forOrder($order),
            'options' => OrderFulfilment::options($status),
            // The callbacks that need a human (🔴-1). On the ORDER screen because that is where
            // someone can actually judge one; `php artisan payments:findings` is the queue view,
            // because a finding that only lives on one order's page is one nobody goes looking for.
            'findings' => self::findings($order),
            'abilities' => [
                'fulfil' => Gate::allows(Role::MANAGE_ORDER_FULFILMENT),
                'cancel' => Gate::allows(Role::CANCEL_ORDERS),
                // Clearing a money finding is payment work, not shop-floor work.
                'resolve_findings' => Gate::allows(Role::MANAGE_PAYMENTS),
                // The settlement export is payment reconciliation, not order work: its route sits
                // behind `manage-payments`, so the button must ask for that ability and not guess
                // from a neighbouring one.
                'settle' => Gate::allows(Role::MANAGE_PAYMENTS),
            ],
        ]);
    }

    /**
     * Clear a payment finding: who, when, and why — never a silent delete.
     *
     * The row stays. "Resolved" is a decision someone made and signed, and deleting it would erase
     * the only evidence that a refund or a decline-after-payment was ever looked at.
     */
    public function resolveFinding(Request $request, int $order, int $finding): RedirectResponse
    {
        self::requireInScope($order);

        $data = Coerce::arr($request->validate([
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]));

        $row = DB::table('payment_reconciliation_findings')
            ->where('id', $finding)->where('order_id', $order)->first(['id', 'resolved_at']);
        abort_if(! is_object($row), 404);

        if (Row::nstr(Row::cast($row), 'resolved_at') !== null) {
            throw ValidationException::withMessages([
                'note' => ManageText::t('orders.finding_already_resolved', 'هذه المطابقة مُصفّاة بالفعل.'),
            ]);
        }

        DB::table('payment_reconciliation_findings')->where('id', $finding)->update([
            'resolved_at' => now(),
            'resolved_by' => Coerce::nint($request->user()?->getAuthIdentifier()),
            'note' => Coerce::str($data['note']),
        ]);

        return back()->with('status', ManageText::t(
            'orders.finding_resolved',
            'تم تصفية المطابقة مع تسجيل السبب.',
        ));
    }

    /** Move an order forward — the `manage-order-fulfilment` ability's whole purpose. */
    public function advance(Request $request, int $order): RedirectResponse
    {
        // Before anything else: an order outside the acting grant's storefronts does not exist for
        // this session, at a write verb exactly as at a read (🟠-3). Scoping the list and the
        // detail while leaving the verbs open would have been a half fix — and was, until a test
        // drove a PUT at another storefront's order and got a redirect instead of a 404.
        self::requireInScope($order);

        $data = Coerce::arr($request->validate([
            'status' => ['required', 'string', Rule::in(OrderFulfilment::STATUSES)],
        ]));

        try {
            $this->fulfilment->advance($order, Coerce::str($data['status']), self::actor($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', ManageText::t('orders.status_updated', 'تم تحديث حالة الطلب.'));
    }

    /**
     * Cancel an order and return its stock. Admin only, by the route gate AND by this check.
     *
     * The stock return is `InventoryService::releaseOrder()` inside the domain service — there is
     * no direct column write anywhere on this path (D-21).
     */
    public function cancel(Request $request, int $order): RedirectResponse
    {
        self::requireInScope($order);

        $data = Coerce::arr($request->validate(['note' => ['nullable', 'string', 'max:255']]));

        try {
            $result = $this->fulfilment->cancel($order, self::actor($request), Coerce::nstr($data['note'] ?? null));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['cancel' => $e->getMessage()]);
        }

        /*
         * Somebody else cancelled it a moment ago (🔵). A STATUS, not an error: the order is
         * cancelled, which is what this operator wanted, and refusing them for a thing that
         * succeeded would send them back to look at a screen that already agrees with them.
         */
        if ($result['already_cancelled']) {
            return back()->with('status', ManageText::t('orders.already_cancelled', 'هذا الطلب ملغى بالفعل.'));
        }

        // `:count`, not `{$…}` interpolation: the number has to be able to move inside the sentence
        // when the sentence is English, and a placeholder is the only thing that lets it.
        return back()->with('status', $result['released']
            ? ManageText::t(
                'orders.cancelled_with_release',
                'تم إلغاء الطلب وإرجاع المخزون (:count حركة في السجل).',
                ['count' => $result['movements']],
            )
            : ManageText::t(
                'orders.cancelled_already_released',
                'تم إلغاء الطلب. المخزون كان قد أُرجع سابقًا، فلم تُسجَّل حركة جديدة.',
            ));
    }

    /**
     * The settlement CSV (§3.9.6, developer decision 2026-09-11): one row per payment ATTEMPT, so
     * a failed attempt and its successful retry both appear.
     *
     * Exactly seven columns and nothing richer — no grouping, no totals, no per-provider sheet —
     * until whoever does the reconciliation asks. The earlier grouped-report proposal was a guess
     * about what finance opens in the morning and was withdrawn in favour of raw rows they can
     * pivot themselves.
     */
    public function settlement(Request $request): StreamedResponse
    {
        $from = Coerce::nstr($request->input('from'));
        $to = Coerce::nstr($request->input('to'));

        $query = DB::table('payment_statuses as ps')
            ->leftJoin('orders as o', 'o.id', '=', 'ps.order_id')
            ->select([
                'ps.created_at', 'o.order_number', 'ps.provider', 'ps.method',
                'ps.pay_transaction_id', 'ps.amount_cents', 'ps.success', 'ps.outcome',
            ])
            /*
             * One correlated sub-select, deliberately — and it is the exception AGENTS §2.24's rule
             * allows for: this query is `chunk()`ed for a streamed download, not a paginated screen,
             * so there is no page of ids to prepare extras for. It reads a tiny index range per row
             * and only for orders that HAVE reward lines.
             */
            ->selectRaw('(SELECT GROUP_CONCAT(DISTINCT oi.promotion_rule_id ORDER BY oi.promotion_rule_id) FROM order_items oi WHERE oi.order_id = o.id AND oi.is_reward = 1) AS reward_rules')
            /*
             * The MONEY half of the same question (M1r). `reward_rules` above names the promotions
             * that gave an ITEM away; these two name the one that took money OFF, and by how much.
             *
             * A LEFT JOIN rather than a third sub-select: `promotion_order_discounts` carries a
             * UNIQUE on `order_id`, so it cannot multiply the payment-attempt rows this query is
             * built from — which is exactly the reason the reward column had to be a sub-select.
             */
            ->leftJoin('promotion_order_discounts as pod', 'pod.order_id', '=', 'o.id')
            ->addSelect(['pod.amount as discount_amount', 'pod.promotion_rule_id as discount_rule'])
            ->orderBy('ps.created_at')->orderBy('ps.id');

        if ($from !== null && $from !== '') {
            $query->where('ps.created_at', '>=', $from.' 00:00:00');
        }
        if ($to !== null && $to !== '') {
            $query->where('ps.created_at', '<=', $to.' 23:59:59');
        }

        /*
         * ── The SAME scope the list applies (🟠-1, 2026-09-17) ────────────────────────────────
         *
         * This export carried no storefront scope at all. The route is gated on `manage-payments`,
         * but that ability can be granted SCOPED — and a scoped holder was downloading every
         * storefront's payment history: transaction ids, amounts and order numbers for a shop they
         * cannot open a single order of.
         *
         * The screen had `applyScope()` from the day the scope existed; the export is the same
         * question asked in a different verb and simply never got it. It is applied here through
         * the same helper rather than a second copy of the rule, because two copies of an
         * authorisation rule agree only by coincidence — which is the finding that produced
         * `Navigation`'s admin short-circuit fix as well.
         */
        self::applyScope($query);

        $storefrontId = Coerce::nint($request->input('storefront_id'));
        if ($storefrontId !== null) {
            /*
             * …and a NAMED storefront outside the grant is a 404, not a silently empty file.
             *
             * `applyScope()` alone would already return nothing for such a request, but "no rows"
             * and "not yours" read identically to the caller, and a finance operator handed an
             * empty CSV concludes the day had no takings. 404 rather than 403 for the reason
             * §3.11.14 gives everywhere else: a 403 confirms the storefront exists.
             */
            $scope = self::storefrontScope();
            abort_if($scope !== null && ! in_array($storefrontId, $scope, true), 404);

            $query->where('o.storefront_id', $storefrontId);
        }

        /*
         * `rewards` since wave 4D (study §3.16.5): which promotion, if any, gave something away on
         * this order. Finance opens this file to reconcile takings, and a sale that carried a gift
         * is a different sale — without the column the only way to know is to join `order_items`
         * by hand, which nobody does.
         *
         * Empty for the overwhelming majority of rows, which is the honest shape: most orders
         * carry no promotion.
         */
        /*
         * ── The headings are TRANSLATED now (🟡-5, 2026-09-17) ────────────────────────────────
         *
         * They used to be the raw column keys — `order_number`, `transaction_id` — in every locale,
         * on the one file an Egyptian accountant opens every morning. Every other export in the
         * dashboard names its columns in the operator's language; this one shipped its database
         * identifiers and nobody noticed because the developers reading it could read them.
         *
         * The keys are unchanged, so nothing that reads the file by key moves. Only the first row
         * does — which is what a person reads.
         */
        $columns = [
            ['key' => 'date', 'label' => ManageText::t('common.date', 'التاريخ'), 'value' => null],
            ['key' => 'order_number', 'label' => ManageText::t('common.order_number', 'رقم الطلب'), 'value' => null],
            ['key' => 'provider', 'label' => ManageText::t('orders.payment_provider', 'مزوّد الدفع'), 'value' => null],
            ['key' => 'method', 'label' => ManageText::t('orders.payment_method', 'طريقة الدفع'), 'value' => null],
            ['key' => 'transaction_id', 'label' => ManageText::t('orders.transaction_id', 'رقم العملية'), 'value' => null],
            ['key' => 'amount', 'label' => ManageText::t('orders.amount', 'المبلغ'), 'value' => null],
            ['key' => 'status', 'label' => ManageText::t('common.status', 'الحالة'), 'value' => null],
            ['key' => 'rewards', 'label' => ManageText::t('orders.settlement_rewards', 'عروض الهدايا'), 'value' => null],
            /*
             * `discount` is the column finance needs most of the three. The `amount` above is what
             * the PROVIDER took; when a promotion reduced the order, that figure is already the
             * discounted one — so without this column a reconciled day's takings look simply lower
             * than the catalogue says, with no line explaining it.
             */
            ['key' => 'discount', 'label' => ManageText::t('orders.discount', 'الخصم'), 'value' => null],
            ['key' => 'discount_rule', 'label' => ManageText::t('orders.settlement_discount_rule', 'رقم عرض الخصم'), 'value' => null],
        ];

        /*
         * Chunked through a generator: a settlement export must not hold a year of attempts in
         * memory, and `TableExport` writes what it yields a row at a time.
         *
         * @return iterable<int, array<string, mixed>>
         */
        $rows = (function () use ($query): iterable {
            foreach ($query->orderBy('ps.id')->cursor() as $raw) {
                $row = Row::cast($raw);
                $minor = Row::nint($row, 'amount_cents');

                yield [
                    'date' => Row::nstr($row, 'created_at'),
                    'order_number' => Row::nstr($row, 'order_number') ?? '',
                    'provider' => Row::nstr($row, 'provider') ?? '',
                    'method' => Row::nstr($row, 'method') ?? '',
                    'transaction_id' => Row::nstr($row, 'pay_transaction_id') ?? '',
                    'amount' => $minor === null ? '' : number_format($minor / 100, 2, '.', ''),
                    /*
                     * The real outcome, not `success`/`failed` (🟡-4). Paymob sends a refund as
                     * `success=true` with `is_refunded=true`, so this column used to call a refund
                     * a sale — and a finance sheet that adds a refund to the day's takings is a
                     * reconciliation error found months after the fact. Rows written before the
                     * `outcome` column existed fall back to what they DO claim rather than to an
                     * invented distinction.
                     */
                    'status' => CallbackPolicy::outcomeOf(Row::nstr($row, 'outcome'), Row::nstr($row, 'success')),
                    // The promotion(s) this order's reward lines were granted by, or ''.
                    'rewards' => Row::nstr($row, 'reward_rules') ?? '',
                    // What a money reward took off this order, and which rule took it. Empty for
                    // every order that carried no discount, which is nearly all of them.
                    'discount' => Row::nmoney($row, 'discount_amount') ?? '',
                    'discount_rule' => ($ruleId = Row::nint($row, 'discount_rule')) === null ? '' : (string) $ruleId,
                ];
            }
        })();

        /*
         * Through the SHARED writer since wave 4D. It was already streamed and already carried the
         * BOM; what it gained is the row count in the filename and — the one that matters here —
         * the leading-character defusing. An order number or a provider reference beginning `=` or
         * `-` is a cell Excel EXECUTES, and this is the file finance opens every morning.
         */
        return TableExport::respond('settlement', $query->count(), $columns, $rows);
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, string|null>  $filters */
    /**
     * The storefront ids the acting grant may see, or NULL for an unscoped grant (see anything).
     *
     * An unscoped administrator's behaviour is unchanged: this returns NULL and no clause is added.
     *
     * @return list<int>|null
     */
    private static function storefrontScope(): ?array
    {
        $user = request()->user();
        if ($user === null) {
            return [];
        }

        $scope = app(Roles::class)->storefrontScope($user);

        /*
         * `storefrontScope()` answers NULL for "every storefront" (an unscoped grant) and a LIST
         * for a scoped one. An empty list means grants that name no storefront at all — which is
         * "see nothing", not "see everything". Mapping `[]` to unscoped, as the first version of
         * this helper did, inverts the control: the account with the least access would get the
         * most. The route's gate already refuses such an account, so this is a belt behind a brace,
         * and it is the belt that has to be the right way round.
         */
        if ($scope === null) {
            return null;
        }

        $out = [];
        foreach ($scope as $id) {
            $out[] = Coerce::int($id);
        }

        return $out;
    }

    /**
     * 404 unless this order is inside the acting grant's storefronts.
     *
     * Used by every path that names an order id — the detail, both fulfilment verbs and the finding
     * resolver — because the scope has to hold wherever the id can be typed, not only where the
     * list happens to render it.
     *
     * A 404 and never a 403: an order number is guessable, and a 403 would confirm the guess
     * (§3.11.14). An order that does not exist and an order that is not yours answer identically.
     */
    private static function requireInScope(int $orderId): void
    {
        $scope = self::storefrontScope();
        if ($scope === null) {
            return;             // unscoped grant: unchanged behaviour
        }

        $storefrontId = DB::table('orders')->where('id', $orderId)->value('storefront_id');
        $storefrontId = is_numeric($storefrontId) ? (int) $storefrontId : null;

        abort_if($storefrontId === null || ! in_array($storefrontId, $scope, true), 404);
    }

    /** Narrow a query to the acting grant's storefronts, if it has any. */
    private static function applyScope(Builder $query): void
    {
        $scope = self::storefrontScope();
        if ($scope === null) {
            return;
        }

        /*
         * A scoped grant sees its own storefronts. `orders.storefront_id` has been written since
         * 2026-09-13 and the pre-column rows were backfilled to the primary storefront, so there is
         * no NULL to make an exception for — and deliberately none is made: a NULL row appearing
         * later would be invisible to a scoped operator rather than silently visible to everyone.
         */
        // `[0]` for an empty scope: no order carries storefront 0, so the query returns nothing —
        // which is what "grants that name no storefront" must mean.
        $query->whereIn('o.storefront_id', $scope === [] ? [0] : $scope);
    }

    /** @param  array<string, string|null>  $filters */
    private static function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $storefrontId = Coerce::nint($filters['storefront_id'] ?? null);
        if ($storefrontId !== null) {
            $query->where('o.storefront_id', $storefrontId);
        }

        $status = Coerce::nstr($filters['status'] ?? null);
        if ($status !== null && in_array($status, OrderFulfilment::STATUSES, true)) {
            $query->where('o.status', $status);
        }

        $from = Coerce::nstr($filters['from'] ?? null);
        if ($from !== null && $from !== '') {
            $query->where('o.created_at', '>=', $from.' 00:00:00');
        }
        $to = Coerce::nstr($filters['to'] ?? null);
        if ($to !== null && $to !== '') {
            $query->where('o.created_at', '<=', $to.' 23:59:59');
        }

        $provider = Coerce::nstr($filters['provider'] ?? null);
        if ($provider !== null && $provider !== '') {
            // An order is matched by who TOOK the money, and also by an attempt that named the
            // provider — a failed card attempt is exactly what someone filtering by provider is
            // looking for, and it never reaches `orders.paid_via_provider`.
            $query->where(function (Builder $outer) use ($provider): void {
                $outer->where('o.paid_via_provider', $provider)
                    ->orWhereExists(function (Builder $sub) use ($provider): void {
                        $sub->from('payment_statuses as psf')
                            ->whereColumn('psf.order_id', 'o.id')
                            ->where('psf.provider', $provider)
                            ->selectRaw('1');
                    });
            });
        }

        $method = Coerce::nstr($filters['method'] ?? null);
        if ($method !== null && $method !== '') {
            $query->where(function (Builder $outer) use ($method): void {
                $outer->where('o.paid_via_method', $method)->orWhere('o.payment_method', $method);
            });
        }

        $term = trim(Coerce::str($request->input('q')));
        if ($term !== '') {
            // Order number or phone — the two things a customer says on the telephone.
            $query->where(function (Builder $outer) use ($term): void {
                $outer->where('o.order_number', 'like', $term.'%')
                    ->orWhere('o.guest_phone', 'like', '%'.$term.'%');
            });
        }
    }

    /**
     * Per-page extras, read once for the page's ids (AGENTS §2.24).
     *
     * @param  list<object>  $rows
     * @return array{items: array<int, int>, attempts: array<int, array<string, mixed>>}
     */
    private static function pageExtras(array $rows): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return ['items' => [], 'attempts' => []];
        }

        $items = [];
        foreach (
            // `line_count`, not `lines`: LINES is a reserved word in MariaDB and the unquoted
            // alias made this whole list answer a 1064 the moment a page held an order with
            // items. Caught by `Wave4CAuthorizationTest`, which opens the list against the real
            // database rather than an empty one.
            DB::table('order_items')->whereIn('order_id', $ids)
                ->groupBy('order_id')->get(['order_id', DB::raw('COUNT(*) as line_count')]) as $raw
        ) {
            $row = Row::cast($raw);
            $items[Row::int($row, 'order_id')] = Row::int($row, 'line_count');
        }

        // The LAST attempt per order: one ordered read, first row per order wins.
        $attempts = [];
        foreach (
            DB::table('payment_statuses')->whereIn('order_id', $ids)
                ->orderBy('order_id')->orderByDesc('id')
                ->get(['order_id', 'provider', 'method', 'success']) as $raw
        ) {
            $row = Row::cast($raw);
            $orderId = Row::int($row, 'order_id');
            if (! array_key_exists($orderId, $attempts)) {
                $attempts[$orderId] = [
                    'provider' => Row::nstr($row, 'provider'),
                    'method' => Row::nstr($row, 'method'),
                    'success' => Row::nstr($row, 'success') === 'true',
                ];
            }
        }

        return ['items' => $items, 'attempts' => $attempts];
    }

    /**
     * The lines, with the VARIANT named — a size/colour the warehouse has to pick.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('order_items as oi')
                ->leftJoin('catalog_products as p', 'p.id', '=', 'oi.product_id')
                ->leftJoin('catalog_product_translations as pt', function (JoinClause $join): void {
                    $join->on('pt.product_id', '=', 'oi.product_id')->where('pt.locale', '=', 'ar');
                })
                ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'oi.variant_id')
                ->where('oi.order_id', $orderId)
                ->orderBy('oi.id')
                ->get([
                    'oi.id', 'oi.product_id', 'oi.variant_id', 'oi.offer_id', 'oi.quantity',
                    'oi.piece_price', 'oi.total_price', 'oi.type_stock', 'oi.color_band', 'oi.color_dial',
                    'p.wa_code', 'pt.title as title_ar', 'v.label as variant_label', 'v.sku as variant_sku',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $out[] = [
                'id' => Row::int($row, 'id'),
                'product_id' => Row::nint($row, 'product_id'),
                'wa_code' => Row::nstr($row, 'wa_code'),
                'title' => Row::nstr($row, 'title_ar'),
                'variant_id' => Row::nint($row, 'variant_id'),
                'variant' => Row::nstr($row, 'variant_label'),
                'variant_sku' => Row::nstr($row, 'variant_sku'),
                'offer_id' => Row::nint($row, 'offer_id'),
                'quantity' => Row::int($row, 'quantity'),
                'piece_price' => Row::str($row, 'piece_price'),
                'total_price' => Row::str($row, 'total_price'),
                'bucket' => Row::nstr($row, 'type_stock'),
                'color_band' => Row::nstr($row, 'color_band'),
                'color_dial' => Row::nstr($row, 'color_dial'),
            ];
        }

        return $out;
    }

    /**
     * Every payment finding for this order, open ones first.
     *
     * @return list<array<string, mixed>>
     */
    private static function findings(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('payment_reconciliation_findings as f')
                ->leftJoin('users as u', 'u.id', '=', 'f.resolved_by')
                ->where('f.order_id', $orderId)
                ->orderByRaw('f.resolved_at IS NOT NULL')
                ->orderByDesc('f.id')
                ->get([
                    'f.id', 'f.kind', 'f.outcome', 'f.order_status', 'f.amount_cents', 'f.detail',
                    'f.created_at', 'f.resolved_at', 'f.note', 'u.email as resolved_by_email',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $minor = Row::nint($row, 'amount_cents');
            $out[] = [
                'id' => Row::int($row, 'id'),
                'kind' => Row::str($row, 'kind'),
                'outcome' => Row::nstr($row, 'outcome'),
                'order_status' => Row::nstr($row, 'order_status'),
                'amount' => $minor === null ? null : number_format($minor / 100, 2, '.', ''),
                'created_at' => Row::nstr($row, 'created_at'),
                'resolved_at' => Row::nstr($row, 'resolved_at'),
                'resolved_by' => Row::nstr($row, 'resolved_by_email'),
                'note' => Row::nstr($row, 'note'),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function address(?int $addressId): ?array
    {
        if ($addressId === null) {
            return null;
        }
        // The city NAME lives in `shipping_city_translations`, not on the city row — measured,
        // not assumed: `shipping_cities` carries an id and a cost and nothing else.
        $row = DB::table('addresses as a')
            ->leftJoin('shipping_city_translations as ct', function (JoinClause $join): void {
                $join->on('ct.shipping_city_id', '=', 'a.shipping_city_id')->where('ct.locale', '=', 'ar');
            })
            ->where('a.id', $addressId)
            ->first([
                'a.id', 'a.address_line', 'a.phone_number_one', 'a.phone_number_two',
                'a.shipping_city_id', 'ct.city_name',
            ]);

        if (! is_object($row)) {
            return null;
        }
        $address = Row::cast($row);

        return [
            'id' => Row::int($address, 'id'),
            'line' => Row::nstr($address, 'address_line'),
            'phone' => Row::nstr($address, 'phone_number_one'),
            'phone_alt' => Row::nstr($address, 'phone_number_two'),
            'city' => Row::nstr($address, 'city_name'),
        ];
    }

    /**
     * Every payment ATTEMPT, newest first — provider, method, transaction id and amount, so a
     * human can find the row in that merchant's portal (§3.9.6).
     *
     * @return list<array<string, mixed>>
     */
    private static function attempts(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('payment_statuses as ps')
                ->leftJoin('storefront_payment_methods as m', 'm.id', '=', 'ps.storefront_payment_method_id')
                ->where('ps.order_id', $orderId)
                ->orderByDesc('ps.id')
                ->get([
                    'ps.id', 'ps.provider', 'ps.method', 'ps.pay_transaction_id', 'ps.pay_order_id',
                    'ps.amount_cents', 'ps.success', 'ps.created_at', 'm.integration_id',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $minor = Row::nint($row, 'amount_cents');
            $out[] = [
                'id' => Row::int($row, 'id'),
                'provider' => Row::nstr($row, 'provider'),
                'method' => Row::nstr($row, 'method'),
                'transaction_id' => Row::nstr($row, 'pay_transaction_id'),
                'provider_order_id' => Row::nstr($row, 'pay_order_id'),
                'amount' => $minor === null ? null : number_format($minor / 100, 2, '.', ''),
                'success' => Row::nstr($row, 'success') === 'true',
                // NOT a credential: Paymob puts the integration id in the signed payload, and it is
                // what the admin needs to match a row in the portal.
                'integration_id' => Row::nstr($row, 'integration_id'),
                'created_at' => Row::nstr($row, 'created_at'),
            ];
        }

        return $out;
    }

    /**
     * The ledger rows this order caused. Read-only, always: nobody edits the ledger (wave 3).
     *
     * @return list<array<string, mixed>>
     */
    private static function movements(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('inventory_movements as im')
                ->leftJoin('catalog_products as p', 'p.id', '=', 'im.product_id')
                ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'im.variant_id')
                // `orders`, plural: that is the string `Reference::order()`/`orderLine()` write
                // (wave 3). The singular guess matched nothing, so this panel rendered an empty
                // table for an order whose stock HAD moved — the exact opposite of the proof it
                // exists to give.
                ->where('im.reference_type', 'orders')->where('im.reference_id', $orderId)
                ->orderBy('im.id')
                ->get([
                    'im.id', 'im.product_id', 'im.variant_id', 'im.bucket', 'im.quantity_delta',
                    'im.quantity_after', 'im.reason', 'im.actor_type', 'im.actor_id', 'im.note',
                    'im.created_at', 'p.wa_code', 'v.label as variant_label',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $out[] = [
                'id' => Row::int($row, 'id'),
                'product_id' => Row::nint($row, 'product_id'),
                'wa_code' => Row::nstr($row, 'wa_code'),
                'variant' => Row::nstr($row, 'variant_label'),
                'bucket' => Row::str($row, 'bucket'),
                'delta' => Row::int($row, 'quantity_delta'),
                'after' => Row::int($row, 'quantity_after'),
                'reason' => Row::str($row, 'reason'),
                'actor' => Row::nstr($row, 'actor_type'),
                'note' => Row::nstr($row, 'note'),
                'created_at' => Row::nstr($row, 'created_at'),
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $query = DB::table('storefronts')->orderBy('id');

        /*
         * A scoped grant is not offered a storefront it cannot see. The QUERY already refuses those
         * rows (`applyScope()`), so offering the option was only ever a filter that returned an
         * empty list — a control that looks broken rather than absent. It also leaked one fact a
         * scoped operator has no business learning from this screen: that the other storefront
         * exists, and its name.
         */
        $scope = self::storefrontScope();
        if ($scope !== null) {
            $query->whereIn('id', $scope === [] ? [0] : $scope);
        }

        $out = [];
        foreach ($query->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['value' => (string) Row::int($row, 'id'), 'label' => Row::nstr($row, 'name') ?? ''];
        }

        return $out;
    }

    /**
     * The provider/method values a filter may offer — from `orders.paid_via_*` **and** from the
     * payment ATTEMPTS.
     *
     * Reading only `orders.paid_via_*` was wrong twice over. That column is written once, by the
     * attempt that SUCCEEDS, so a provider whose payments have only ever failed appeared nowhere —
     * and that is precisely the row an operator filtering by provider is hunting for. Worse, the
     * same list is the filter's ALLOW-LIST: `TableQuery::resolvedFilters()` drops a value that is
     * not in it, so before the first successful payment through the new path the list was EMPTY and
     * every provider filter was silently discarded. Measured, not theorised — a test asked for
     * `filters[provider]=paymob` with a failed attempt on record and got the unfiltered list back.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function distinct(string $column): array
    {
        $values = [];
        foreach (
            DB::table('orders')->whereNotNull($column)->distinct()->orderBy($column)->pluck($column) as $value
        ) {
            if (is_string($value) && $value !== '') {
                $values[$value] = true;
            }
        }

        // `paid_via_provider` → `payment_statuses.provider`, `paid_via_method` → `.method`.
        $attemptColumn = $column === 'paid_via_provider' ? 'provider' : 'method';
        foreach (
            DB::table('payment_statuses')->whereNotNull($attemptColumn)
                ->distinct()->orderBy($attemptColumn)->pluck($attemptColumn) as $value
        ) {
            if (is_string($value) && $value !== '') {
                $values[$value] = true;
            }
        }

        /*
         * …and what is CONFIGURED (🟠-2). Both sources above are HISTORY, and a freshly migrated
         * production database has none of it: no order has been paid through the new path and no
         * attempt has been recorded, so the allow-list was empty — and `resolvedFilters()` drops a
         * value that is not in its allow-list, which silently disabled the provider and method
         * filters on exactly the database where they are first used.
         */
        $configured = $column === 'paid_via_provider'
            ? DB::table('storefront_payment_providers')->distinct()->orderBy('provider')->pluck('provider')
            : DB::table('storefront_payment_methods')->distinct()->orderBy('method')->pluck('method');

        foreach ($configured as $value) {
            if (is_string($value) && $value !== '') {
                $values[$value] = true;
            }
        }

        $out = [];
        foreach (array_keys($values) as $value) {
            $out[] = ['value' => $value, 'label' => $value];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

        return $out;
    }

    private static function actor(Request $request): Actor
    {
        $user = $request->user();

        return Actor::user($user === null ? null : Coerce::int($user->getAuthIdentifier()));
    }
}
