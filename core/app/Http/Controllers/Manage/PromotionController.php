<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Compat\CompatCart;
use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionOutcome;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionScopes;
use App\Domain\Promotions\PromotionState;
use App\Domain\Promotions\PromotionWriter;
use App\Domain\Wheel\WheelPrizes;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Promotions — authoring (wave 4D, study §3.16.6).
 *
 * ── Admin only, and the screen says the two things the operator has to know ──────────────────
 *
 * `manage-promotions` is not in data-entry's abilities: a promotion moves money and gives away
 * stock. The route gate enforces it and `PromotionAuthorizationTest` drives the endpoints
 * directly, because a hidden menu is not a permission.
 *
 * Two statements the screens must make, both the developer's, both because the operator is the
 * weakest link here:
 *
 *  1. **Rules do NOT stack.** Written on the authoring page itself, not in a help article. Nobody
 *     may publish three promotions expecting three discounts.
 *  2. **The preview shows BOTH outcomes** — what the customer pays AND what stock leaves the shop.
 *     A promotion is a stock decision, and the screen makes that visible before saving.
 *
 * ── The list answers "is this doing anything?" without opening anything ──────────────────────
 *
 * `PromotionState::of()` per row: running / scheduled / expired / inactive / no-storefront /
 * no-stock / not-visible, with the skip counter beside it. Same principle as the catalogue's four
 * at-a-glance states (task 4.3) — `is_active` is what the admin typed, not what the shop is doing.
 *
 * ── ⚠ KNOWN GAP, LEFT OPEN ON PURPOSE: NO STOREFRONT SCOPE CHECK (2026-09-23) ─────────────────
 *
 * Nothing in this controller asks WHICH storefront the operator may act on. `storefront_id` comes
 * from the request body and is used as given, so an operator holding a grant SCOPED to one
 * storefront could create or edit a discount on another — money, on a shop they were never given.
 *
 * It is not reachable today, and the reason is a BUSINESS decision, not code: every operator is
 * either an unscoped admin or data-entry; Watchizer and Brand Fashion are run by one team and nobody
 * is split by storefront (developer, 2026-09-23). Scoped grants are a capability the system HAS and
 * the business does not use.
 *
 * **It becomes real the day anybody issues a scoped grant.** Before that day, give this controller
 * the same treatment the security audit's Finding 1 gave the grant screen and the storefront
 * settings: check `Roles::scopeForAbility($actor, Role::MANAGE_PROMOTIONS)` against every
 * `storefront_id` it reads, writes or lists. The same warning sits on `UserRoleController::store()`,
 * which is where a scoped grant would be issued.
 */
final class PromotionController
{
    public function __construct(
        private readonly PromotionWriter $writer,
        private readonly PromotionEngine $engine,
    ) {}

    /** The rule list, each row carrying what it is actually doing. */
    public function index(Request $request): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(['r.priority', 'r.name', 'r.starts_at', 'r.ends_at'], default: 'r.priority', direction: 'desc')
            ->filterable([
                'is_active' => ['0', '1'],
                'storefront_id' => null,
                // the offer builder's list (2026-11-19): running, starting later, over, switched off, kept for the wheel
                'status' => ['active', 'scheduled', 'ended', 'off', 'wheel'],
            ])
            /*
             * BOTH filters are VIRTUAL — declared here so the whitelist, the URL and the reset
             * button know them, applied below by this controller.
             *
             * `storefront_id` has to be: it is not a column on `promotion_rules` but a row in the
             * pivot, so `apply()`'s automatic `where('storefront_id', 2)` was a 1054 the moment the
             * filter was used — the same trap wave 4B's `flag` filter fell into, and the reason
             * `virtual()` exists at all. `is_active` is a real column, but this controller writes
             * it as `r.is_active`, and declaring one of the pair virtual and not the other is how
             * the next reader learns the wrong rule.
             */
            ->virtual(['is_active', 'storefront_id', 'status'])
            // M1x: the coupon code too — the word a customer reads out on the phone
            ->searchable(['r.name', 'r.code'])
            /*
             * `label` and `summary` rather than `is_active` alone, for the same reason the SCREEN
             * shows them: `is_active` is what somebody typed, and a rule can be switched on with a
             * window that closed. A file that said only "مفعّل" would carry the misunderstanding
             * out of the dashboard and into a spreadsheet nobody can question.
             */
            ->exportable([
                // The column headings the SCREEN already says, off the same keys — a second
                // "Priority" in the English file is two strings waiting to disagree.
                'name' => ManageText::t('common.name', 'الاسم'),
                'label' => ManageText::t('common.status', 'الحالة'),
                'priority' => ManageText::t('promotions.list_priority', 'الأولوية'),
                'starts_at' => ManageText::t('common.starts', 'يبدأ'),
                'ends_at' => ManageText::t('common.ends', 'ينتهي'),
                'is_active' => ManageText::t('common.active', 'مفعّل'),
                'summary' => [ManageText::t('promotions.summary', 'ماذا تفعل'), fn (array $row): string => implode(' | ', array_map(
                    static fn (mixed $line): string => Coerce::str($line),
                    Coerce::arr($row['summary'] ?? null),
                ))],
            ], 'promotions');

        $query = DB::table('promotion_rules as r')
            ->select(['r.id', 'r.name', 'r.priority', 'r.is_active', 'r.starts_at', 'r.ends_at', 'r.updated_at', 'r.issuer'])
            /*
             * The wheel's prize rules are NOT listed here (2026-11-08). They are made by the wheel, one per set
             * of prize terms, reached only through a spin's code, and the team edits the prizes on the wheel's
             * own page — a row here would invite an edit the engine does not expect. They are still ordinary
             * rules to the engine and to the edit screen by id. The list also stays at its query budget: every
             * row costs its summary's reads, and a wheel with twelve prizes would add twelve rows nobody asked for.
             */
            // the offers made for the wheel (2026-11-19) are listed too: they are the team's, edited here
            ->where(fn (Builder $q) => $q->whereNull('r.issuer')->orWhere('r.issuer', WheelPrizes::TEMPLATE_ISSUER));

        $filters = $table->resolvedFilters();

        $active = Coerce::nstr($filters['is_active'] ?? null);
        if ($active !== null) {
            $query->where('r.is_active', $active === '1' ? 1 : 0);
        }

        $stamp = now()->format('Y-m-d H:i:s');
        match (Coerce::nstr($filters['status'] ?? null)) {
            'active' => $query->whereNull('r.issuer')->where('r.is_active', 1)->where('r.starts_at', '<=', $stamp)->where('r.ends_at', '>=', $stamp),
            'scheduled' => $query->whereNull('r.issuer')->where('r.is_active', 1)->where('r.starts_at', '>', $stamp),
            'ended' => $query->whereNull('r.issuer')->where('r.ends_at', '<', $stamp),
            'off' => $query->whereNull('r.issuer')->where('r.is_active', 0)->where(fn (Builder $q) => $q->whereNull('r.ends_at')->orWhere('r.ends_at', '>=', $stamp)),
            'wheel' => $query->where('r.issuer', WheelPrizes::TEMPLATE_ISSUER),
            default => null,
        };

        $storefrontId = Coerce::nint($filters['storefront_id'] ?? null);
        if ($storefrontId !== null) {
            $query->whereExists(function (Builder $sub) use ($storefrontId): void {
                $sub->from('promotion_rule_storefront as rs')
                    ->whereColumn('rs.promotion_rule_id', 'r.id')
                    ->where('rs.storefront_id', $storefrontId)
                    ->selectRaw('1');
            });
        }

        $map = function (object $raw): array {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');

            return [
                'id' => $id,
                'name' => Row::str($row, 'name'),
                'priority' => Row::int($row, 'priority'),
                'is_active' => Row::bool($row, 'is_active'),
                'starts_at' => Row::nstr($row, 'starts_at'),
                'ends_at' => Row::nstr($row, 'ends_at'),
                'summary' => self::summary($id),
                // THE at-a-glance cell: state, label, tone, per-storefront visibility, skips.
                ...PromotionState::of($row),
                // kept for the wheel (2026-11-19): its state is the wheel's, not a window's
                'on_wheel' => Row::nstr($row, 'issuer') === WheelPrizes::TEMPLATE_ISSUER,
                'edit_url' => route('manage.promotions.edit', ['promotion' => $id]),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Promotions/Index', [
            'table' => $table->paginate($query, $map),
            'storefronts' => self::storefronts(),
            /*
             * The no-stacking statement, shipped as a prop so the list and the form say the same
             * sentence. An admin who publishes three promotions expecting three discounts has been
             * failed by the screen, not by themselves.
             */
            'stacking_notice' => self::stackingNotice(),
        ]);
    }

    /**
     * The sentence both screens show. One string, one place.
     *
     * A static METHOD rather than the `public const` it used to be: a constant is folded at compile
     * time and cannot ask the translator anything, so the one statement this screen exists to make
     * would have stayed Arabic for an English operator whatever the seam said. The three call sites
     * below and `PromotionScreenTest` are all that read it.
     */
    public static function stackingNotice(): string
    {
        return ManageText::t('promotions.stacking_notice', 'العروض لا تتجمع إلا إذا عُلِّمت كلها «تتجمّع»: تحصل السلة على عرض واحد — صاحب الأولوية الأعلى، وعند تساوي الأولوية صاحب الخصم الأكبر. كود الخصم لا يُطبَّق إلا إذا كتبه العميل في السلة.');
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $promotion): Response
    {
        return $this->form($promotion);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate(self::rules()));
        $forWheel = Coerce::bool($data['for_wheel'] ?? false);
        if ($forWheel) {
            // an offer for the wheel is copied at each spin: it never runs by itself (switched off, no code)
            $data = ['is_active' => false, 'code' => null] + $data;
            $data['is_active'] = false;
            $data['code'] = null;
        }
        $id = $this->writer->save($data, null, self::actorId($request));
        if ($forWheel) {
            DB::table('promotion_rules')->where('id', $id)->update(['issuer' => WheelPrizes::TEMPLATE_ISSUER]);
        }

        // A promotion gives away stock and money, so who created it is an audit question.
        ActivityLog::record('promotion_rules', $id, ActivityLog::CREATED,
            label: Coerce::nstr($data['name'] ?? null));

        return redirect()
            ->route('manage.promotions.edit', ['promotion' => $id])
            ->with('status', ManageText::t('promotions.created', 'تم إنشاء القاعدة.'));
    }

    public function update(Request $request, int $promotion): RedirectResponse
    {
        self::requireRule($promotion);
        $data = Coerce::arr($request->validate(self::rules()));
        if (DB::table('promotion_rules')->where('id', $promotion)->value('issuer') === WheelPrizes::TEMPLATE_ISSUER) {
            // a wheel offer stays one: switched off and without a code (the spin's own rule carries the code)
            $data['is_active'] = false;
            $data['code'] = null;
        }

        /*
         * The rule's own columns before and after. Conditions and rewards live in child tables and
         * are not diffed here: a reward list is a set, not a field, and rendering "it changed" for
         * a reordered array would be noise. The row records WHO touched the rule and WHEN, which is
         * what makes the child tables' own timestamps interpretable.
         */
        $before = ActivityLog::fields(DB::table('promotion_rules')->where('id', $promotion)
            ->first(['name', 'code', 'priority', 'is_active', 'starts_at', 'ends_at', 'apply_per', 'max_discount', 'min_order_amount', 'usage_limit', 'usage_limit_per_customer', 'include_sale_items', 'stackable', 'unit_floor']));

        $this->writer->save($data, $promotion, self::actorId($request));

        $after = ActivityLog::fields(DB::table('promotion_rules')->where('id', $promotion)
            ->first(['name', 'code', 'priority', 'is_active', 'starts_at', 'ends_at', 'apply_per', 'max_discount', 'min_order_amount', 'usage_limit', 'usage_limit_per_customer', 'include_sale_items', 'stackable', 'unit_floor']));

        ActivityLog::record('promotion_rules', $promotion, ActivityLog::UPDATED, $before, $after,
            label: Coerce::nstr($data['name'] ?? null));

        return back()->with('status', ManageText::t('promotions.saved', 'تم حفظ القاعدة.'));
    }

    public function destroy(int $promotion): RedirectResponse
    {
        self::requireRule($promotion);
        $name = DB::table('promotion_rules')->where('id', $promotion)->value('name');
        $result = $this->writer->delete($promotion);

        if ($result['deleted']) {
            ActivityLog::record('promotion_rules', $promotion, ActivityLog::DELETED,
                label: is_string($name) ? $name : null);
        }

        if (! $result['deleted']) {
            return back()->withErrors(['delete' => $result['reason']]);
        }

        return redirect()->route('manage.promotions.index')->with('status', $result['reason']);
    }

    /**
     * PRODUCT SEARCH for the sample cart and the reward picker.
     *
     * Search, never a typed id (developer requirement 2026-09-13). An admin who has to look a
     * product id up in another tab will eventually type the wrong one, and the wrong one here
     * means the shop gives away the wrong thing.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim(Coerce::str($request->input('q')));
        $storefrontId = Coerce::int($request->input('storefront_id'), 1);

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->leftJoin('catalog_product_translations as t', function (JoinClause $join): void {
                $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where(function (Builder $outer) use ($term): void {
                $outer->where('t.title', 'like', '%'.$term.'%')
                    ->orWhere('p.wa_code', 'like', $term.'%')
                    // `model_number` was a third column here and is the same value as `p.sku`
                    // since the merge (item 4), so searching it as well would only cost a scan.
                    ->orWhere('p.sku', 'like', $term.'%');
            })
            ->orderBy('p.id')
            ->limit(20)
            /*
             * `select()` FIRST, then `selectRaw()`, then a bare `get()`. A column list passed to
             * `get([...])` is SILENTLY IGNORED once the select list has been set, so the
             * sub-select would have been the whole projection and the first accessor throws.
             *
             * Second time this trap has been sprung in this codebase (the first was
             * `OrderEmailData::items()`), which is why it is written down rather than just fixed.
             */
            ->select(['p.id', 'p.wa_code', 'p.stock_express', 'p.stock_market', 'sp.is_visible',
                'sp.effective_price', 'sp.effective_sale_price', 't.title'])
            ->selectRaw('(SELECT ci.path FROM catalog_product_images ci WHERE ci.product_id = p.id AND ci.is_cover = 1 ORDER BY ci.sort, ci.id LIMIT 1) AS cover')
            ->get();

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $cover = Row::nstr($row, 'cover');
            $out[] = [
                'id' => Row::int($row, 'id'),
                'title' => Row::nstr($row, 'title') ?? ('#'.Row::int($row, 'id')),
                'wa_code' => Row::nstr($row, 'wa_code'),
                'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
                'stock_express' => Row::int($row, 'stock_express'),
                'stock_market' => Row::int($row, 'stock_market'),
                // Said in the picker, so a reward that cannot be given is visible before saving.
                'is_visible' => Row::bool($row, 'is_visible'),
                'cover' => $cover === null ? null : ImageUrl::src($cover),
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * THE SAMPLE-CART PREVIEW — both outcomes, before saving.
     *
     * The developer's requirement, and the reason this endpoint exists at all: *"a promotion is a
     * stock decision, and the screen should make that visible before saving."* So the answer has
     * two halves and neither is optional:
     *
     *  - **what the customer pays** — the subtotal, the shipping, the total, and the fact that the
     *     total does not move (the gift is free, and that is why it does not 422 the checkout);
     *  - **what LEAVES THE SHOP** — every unit, by product, with the stock that remains after. An
     *     admin approving "buy 2, get 1 free" is approving a third unit off the shelf per order,
     *     and this is where that is said out loud.
     *
     * It evaluates the UNSAVED rule: the draft is written, evaluated and rolled back inside one
     * transaction, so the preview reflects exactly what the admin is about to publish rather than
     * what is already stored. Nothing survives.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = Coerce::arr($request->validate([
            'storefront_id' => ['required', 'integer'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'paymob', 'whatsapp'])],
            'lines' => ['array'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'conditions' => ['array'],
            'conditions.*.type' => ['required', 'string', Rule::in(PromotionRules::CONDITIONS)],
            'rewards' => ['array'],
            'rewards.*.type' => ['required', 'string', Rule::in(PromotionRules::REWARDS)],
            // Not `required`: a money reward carries no quantity, and demanding one would make the
            // preview refuse a draft the save would accept.
            'rewards.*.quantity' => ['nullable', 'integer', 'min:1'],
            // The preview previews the rule the admin is about to save, so it must keep the same
            // parameters the save keeps — otherwise it would answer about a different rule.
            // Since 2026-11-19 the WHOLE offer (the builder's test cart): its scopes, pairing, cap, maximum, minimum,
            // code — and a phone, for "first order only".
            'code' => ['nullable', 'string', 'max:40'],
            'apply_per' => ['nullable', 'string', Rule::in(PromotionMoney::PER)],
            'max_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'max_units' => ['nullable', 'integer', 'min:1', 'max:999'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'include_sale_items' => ['nullable', 'boolean'],
            'unit_floor' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'qualify_min_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'qualify_min_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'scopes' => ['array', 'max:200'],
            'scopes.*.role' => ['required', 'string', Rule::in(PromotionScopes::ROLES)],
            'scopes.*.dimension' => ['required', 'string', Rule::in(PromotionScopes::DIMENSIONS)],
            'scopes.*.value_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.value' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:20'],
            // the code test (2026-11-24): SKUs pasted one per line ("SKU" or "SKU x2"), and an account's e-mail for the
            // first-order rule and a code bound to an address
            'skus' => ['nullable', 'string', 'max:2000'],
            'account' => ['nullable', 'string', 'email', 'max:191'],
            'rule_id' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ] + self::parameterRules()));

        $storefrontId = Coerce::int($data['storefront_id'] ?? null, 1);
        // the code test's pasted SKUs join the picked lines; what this shop does not sell is named back, never guessed
        [$skuLines, $unknownSkus] = self::skuLines(Coerce::str($data['skus'] ?? ''), $storefrontId);
        $data['lines'] = array_merge(Coerce::arr($data['lines'] ?? null), array_map(static fn (array $l): array => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $skuLines));
        if ($data['lines'] === []) {
            return response()->json(['ok' => false, 'errors' => ['lines' => [ManageText::t('promotions.test_needs_product', 'أضف منتجًا واحدًا على الأقل إلى السلة التجريبية، بالبحث أو بلصق كود SKU.')]], 'unknown_skus' => $unknownSkus], 422);
        }
        $account = Coerce::nstr($data['account'] ?? null);
        $account = $account === null ? null : mb_strtolower(trim($account));
        $accountId = $account === null ? null : Coerce::nint(DB::table('users')->whereRaw('LOWER(email) = ?', [$account])->value('id'));
        $data['account'] = $account;
        $data['account_user_id'] = $accountId;
        // a coded offer is previewed as the shopper meets it: with its code typed — a draft code of its own, so it never
        // collides with the saved offer's
        $typed = trim(Coerce::str($data['code'] ?? '')) === '' ? null : 'PREVIEW'.strtoupper(bin2hex(random_bytes(4)));
        $data['code'] = $typed;
        $snapshot = $this->snapshotFrom($data, $storefrontId, $typed);
        $warnings = self::overlaps($data, $storefrontId, Coerce::nint($data['rule_id'] ?? null));

        /*
         * The draft is saved, evaluated and ROLLED BACK, all inside one transaction. A preview that
         * evaluated the STORED rule would answer about yesterday's version — which is the one thing
         * a preview must not do. Nothing survives this method.
         */
        /*
         * ── Unwind to the level we FOUND, not to zero (2026-10-05) ──────────────────
         *
         * This method used to `rollBack()` in the catch AND again in a `finally` guarded by
         * `transactionLevel() > 0`. In production that guard is right — after the catch's rollback
         * the level is 0 and the second one is skipped. Whenever a transaction is ALREADY OPEN
         * around this call it is wrong twice over: the catch unwinds this method's savepoint, and
         * then the `finally` sees the CALLER's transaction still open and rolls that back too.
         *
         * The whole test suite runs inside exactly such a transaction, so a refused preview drops
         * the suite out of it. Measured with a scratch probe rather than assumed: the level really
         * does reach 0, and anything that SAME test writes afterwards survives the run
         * permanently. A later test is not affected — `DatabaseTransactions` opens a fresh
         * transaction per test — so the damage is bounded to the offending test, and a silent
         * permanent write is bad enough on a database with no test-only copy.
         *
         * Found by asserting `transactionLevel() >= 1` after every test, which is now permanent in
         * `Tests\TestCase::tearDown()`.
         *
         * The same shape would corrupt production the first time anything wraps this call.
         */
        $outer = DB::transactionLevel();

        DB::beginTransaction();

        try {
            /*
             * The draft carries a WINDOW the preview invents, because the form's dates are not part
             * of this request and the writer requires one — "no end date" is a refusal, and rightly
             * so. Inventing a window here previews the rule's CONTENT; the dates the admin typed are
             * validated when they save.
             */
            $draft = $this->writer->save(
                $data + [
                    'name' => 'معاينة', // i18n-exempt: a row VALUE written to promotion_rules.name for the draft, which this transaction rolls back before anything can read it — never rendered.
                    'priority' => 0,
                    'is_active' => true,
                    'storefronts' => [$storefrontId],
                    'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                    'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
                ],
                null,
                null,
            );

            $outcome = $this->engine->evaluate($snapshot);
        } catch (ValidationException $e) {
            /*
             * The draft is not saveable yet — a missing end date, an unbounded reward, a gift that
             * is not visible where the rule runs. The admin gets the SAME refusals the save would
             * give, before they press save, which is most of what a preview is for.
             *
             * No `rollBack()` here: the `finally` below does it, once, for every exit path. Two
             * rollbacks for one `beginTransaction()` is what broke the suite.
             */
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } finally {
            // Down to where we started and no further — never to zero.
            while (DB::transactionLevel() > $outer) {
                DB::rollBack();
            }
        }

        /*
         * Which rule won matters: a draft that loses to an existing higher-priority rule would
         * otherwise preview as "nothing happens" and look like the draft is broken. Rules do not
         * stack, so saying WHICH one applied is the honest answer.
         */
        $winner = match (true) {
            $outcome->ruleId === null => null,
            $outcome->ruleId === $draft => 'draft',
            default => 'other_rule',
        };

        return response()->json([
            'ok' => true,
            'applies' => $outcome->applies(),
            'winner' => $winner,
            // What the customer pays…
            'customer' => self::customerOutcome($snapshot, $outcome),
            // …and what leaves the shop. Both halves, always (developer requirement).
            'stock' => self::stockOutcome($outcome),
            'skipped' => self::skippedNames($outcome),
            'notice' => self::stackingNotice(),
            // the coupon's own answer when the offer has a code (what the shopper would read)
            'coupon' => $outcome->coupon === null ? null : ['status' => $outcome->coupon['status'], 'messages' => $outcome->coupon['messages']],
            // other running offers on the same items (2026-11-19): a warning, never a refusal
            'overlaps' => $warnings,
            // what came off each test-cart line (index = the line's position in the request), for the builder's test cart
            'line_discounts' => array_map(static fn (array $line): array => ['index' => $line['index'], 'amount' => round($line['amount'], 2)], $outcome->lines),
            // the code test: what the pasted SKUs became, what this shop does not sell, whether the account was found
            'sku_lines' => $skuLines,
            'unknown_skus' => $unknownSkus,
            'account' => $account === null ? null : ['email' => $account, 'found' => $accountId !== null],
        ]);
    }

    /**
     * Running offers (not the wheel's) whose items overlap this one's, in an overlapping window on a shared shop: names.
     * Two offers "overlap" when either is on the whole cart, or both name the same category, brand or product.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function overlaps(array $data, int $storefrontId, ?int $ruleId): array
    {
        $mine = [];
        foreach (Coerce::arr($data['scopes'] ?? []) as $scope) {
            $scope = Coerce::arr($scope);
            if (Coerce::str($scope['role'] ?? '') === PromotionScopes::ROLE_TARGET) {
                $mine[Coerce::str($scope['dimension'] ?? '')][] = Coerce::str($scope['value_id'] ?? $scope['value'] ?? '');
            }
        }
        $starts = Coerce::nstr($data['starts_at'] ?? null) ?? now()->format('Y-m-d H:i:s');
        $ends = Coerce::nstr($data['ends_at'] ?? null) ?? '2099-12-31 23:59:59';
        $others = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->whereNull('r.issuer')->where('r.is_active', 1)->where('rs.storefront_id', $storefrontId)
            ->where('r.ends_at', '>=', $starts)->where('r.starts_at', '<=', $ends)
            ->when($ruleId !== null, fn ($q) => $q->where('r.id', '!=', $ruleId))
            ->orderBy('r.name')->get(['r.id', 'r.name']);
        $out = [];
        foreach ($others as $raw) {
            $other = Row::cast($raw);
            $theirs = [];
            foreach (DB::table('promotion_rule_scopes')->where('promotion_rule_id', Row::int($other, 'id'))->where('role', PromotionScopes::ROLE_TARGET)->get(['dimension', 'value_id', 'value']) as $s) {
                $s = Row::cast($s);
                $theirs[Row::str($s, 'dimension')][] = (string) (Row::nint($s, 'value_id') ?? Row::nstr($s, 'value') ?? '');
            }
            $hit = $mine === [] || $theirs === [];
            foreach ($mine as $dimension => $values) {
                $hit = $hit || array_intersect($values, $theirs[$dimension] ?? []) !== [];
            }
            if ($hit) {
                $out[] = Row::str($other, 'name');
            }
        }

        return $out;
    }

    // ── one click: pause, start again, duplicate, end now (the offer builder's list, 2026-11-19) ────────────────

    public function pause(Request $request, int $promotion): RedirectResponse
    {
        return $this->switchTo($request, $promotion, false);
    }

    public function resume(Request $request, int $promotion): RedirectResponse
    {
        return $this->switchTo($request, $promotion, true);
    }

    private function switchTo(Request $request, int $promotion, bool $on): RedirectResponse
    {
        $rule = self::requireRule($promotion);
        if (DB::table('promotion_rules')->where('id', $promotion)->value('issuer') !== null) {
            return back()->withErrors(['offer' => ManageText::t('promotions.wheel_offer_no_switch', 'عرض العجلة يعمل من خلال العجلة نفسها، لا يُشغَّل أو يُوقف هنا.')]);
        }
        if ($on && (Row::nstr($rule, 'ends_at') === null || Row::str($rule, 'ends_at') < now()->format('Y-m-d H:i:s'))) {
            return back()->withErrors(['offer' => ManageText::t('promotions.resume_needs_end', 'انتهى هذا العرض. غيّر تاريخ الانتهاء أولًا ثم شغّله.')]);
        }
        if (Row::bool($rule, 'is_active') !== $on) {
            DB::table('promotion_rules')->where('id', $promotion)->update(['is_active' => $on, 'updated_by' => self::actorId($request), 'updated_at' => now()]);
            ActivityLog::record('promotion_rules', $promotion, ActivityLog::UPDATED, ['is_active' => ! $on], ['is_active' => $on], label: Row::str($rule, 'name'));
        }

        return back()->with('status', $on ? ManageText::t('promotions.resumed', 'العرض يعمل الآن.') : ManageText::t('promotions.paused', 'تم إيقاف العرض مؤقتًا.'));
    }

    /** End an offer now: its end date becomes this minute (it stays in the list as ended). */
    public function end(Request $request, int $promotion): RedirectResponse
    {
        $rule = self::requireRule($promotion);
        if (DB::table('promotion_rules')->where('id', $promotion)->value('issuer') !== null) {
            return back()->withErrors(['offer' => ManageText::t('promotions.wheel_offer_no_switch', 'عرض العجلة يعمل من خلال العجلة نفسها، لا يُشغَّل أو يُوقف هنا.')]);
        }
        // a second ago, so the offer reads as ended at once (the running window includes its end second)
        $now = now()->subSecond()->format('Y-m-d H:i:s');
        $starts = Row::nstr($rule, 'starts_at');
        $update = ['ends_at' => $now, 'updated_by' => self::actorId($request), 'updated_at' => now()];
        if ($starts === null || $starts > $now) {
            $update['starts_at'] = $now;   // an offer that had not started ends without ever running
        }
        DB::table('promotion_rules')->where('id', $promotion)->update($update);
        ActivityLog::record('promotion_rules', $promotion, ActivityLog::UPDATED, ['ends_at' => Row::nstr($rule, 'ends_at')], ['ends_at' => $now], label: Row::str($rule, 'name'));

        return back()->with('status', ManageText::t('promotions.ended', 'انتهى العرض الآن.'));
    }

    /** A copy of an offer, switched off and without a code, named "(copy)"; the page opens on it. */
    public function duplicate(Request $request, int $promotion): RedirectResponse
    {
        $rule = self::requireRule($promotion);
        $payload = self::rulePayload($rule);
        $issuer = DB::table('promotion_rules')->where('id', $promotion)->value('issuer');
        $data = [
            'name' => mb_substr(Row::str($rule, 'name'), 0, 110).' '.ManageText::t('promotions.copy_suffix', '(نسخة)'),
            'is_active' => false,
            'code' => null,
            'conditions' => array_map(fn (mixed $c): array => array_intersect_key(Coerce::arr($c), array_flip(['type', 'product_id', 'variant_id', 'storefront_category_id', 'brand_id', 'quantity', 'amount', 'methods'])), Coerce::arr($payload['conditions'])),
            'rewards' => array_map(fn (mixed $r): array => array_intersect_key(Coerce::arr($r), array_flip(['type', 'product_id', 'variant_id', 'quantity', 'amount'])), Coerce::arr($payload['rewards'])),
        ] + array_intersect_key($payload, array_flip(['priority', 'starts_at', 'ends_at', 'label_en', 'label_ar', 'apply_per', 'max_discount', 'min_order_amount',
            'usage_limit', 'usage_limit_per_customer', 'include_sale_items', 'stackable', 'unit_floor', 'qualify_min_quantity', 'qualify_min_amount', 'max_units', 'scopes', 'storefronts']));
        $id = $this->writer->save($data, null, self::actorId($request));
        if ($issuer === WheelPrizes::TEMPLATE_ISSUER) {
            DB::table('promotion_rules')->where('id', $id)->update(['issuer' => WheelPrizes::TEMPLATE_ISSUER]);
        }
        ActivityLog::record('promotion_rules', $id, ActivityLog::CREATED, [], ['copied_from' => $promotion], label: Coerce::str($data['name']));

        return redirect()->route('manage.promotions.edit', ['promotion' => $id])
            ->with('status', ManageText::t('promotions.duplicated', 'تم إنشاء نسخة متوقفة من العرض. راجعها ثم شغّلها.'));
    }

    /**
     * The rules that MATCHED this sample cart and were refused, by name and reason.
     *
     * Shown in the preview because "nothing happened" and "your gift is out of stock" look
     * identical to an admin otherwise, and the second is the one that costs the client.
     *
     * @return list<array<string, mixed>>
     */
    private static function skippedNames(PromotionOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->skipped as $ruleId => $reason) {
            $name = DB::table('promotion_rules')->where('id', $ruleId)->value('name');
            $out[] = [
                'id' => $ruleId,
                'name' => is_scalar($name) ? (string) $name : ('#'.$ruleId),
                'reason' => $reason,
                'label' => PromotionRules::skipLabel($reason),
            ];
        }

        return $out;
    }

    // ── pieces ───────────────────────────────────────────────────────────────────────────────

    private function form(?int $promotion): Response
    {
        $rule = $promotion === null ? null : self::requireRule($promotion);

        return Inertia::render('Manage/Promotions/Form', [
            'rule' => $rule === null ? null : self::rulePayload($rule),
            'storefronts' => self::storefronts(),
            'condition_types' => self::types(PromotionRules::CONDITIONS, PromotionRules::conditionLabel(...)),
            /*
             * Every reward type is offered, with the unavailable ones marked and their reason
             * attached — rather than hidden. An admin who cannot find "percentage discount" will
             * ask whether it exists; one who sees it greyed out with "needs the new storefront,
             * coming after the switch" has their answer.
             */
            'reward_types' => self::rewardTypes(),
            // M1u: what a coupon or a "buy from X" can be about — searchable lists, never typed ids
            'scope_options' => self::scopeOptions(),
            'stacking_notice' => self::stackingNotice(),
            'max_reward_quantity' => PromotionWriter::MAX_REWARD_QUANTITY,
        ]);
    }

    /** @return array<string, mixed> */
    private static function rulePayload(\stdClass $rule): array
    {
        $id = Row::int($rule, 'id');

        $conditions = [];
        foreach (DB::table('promotion_rule_conditions')->where('promotion_rule_id', $id)->orderBy('id')->get() as $raw) {
            $row = Row::cast($raw);
            $conditions[] = [
                'type' => Row::str($row, 'type'),
                'product_id' => Row::nint($row, 'product_id'),
                'variant_id' => Row::nint($row, 'variant_id'),
                'storefront_category_id' => Row::nint($row, 'storefront_category_id'),
                'brand_id' => Row::nint($row, 'brand_id'),
                'quantity' => Row::nint($row, 'quantity'),
                'amount' => Row::nmoney($row, 'amount'),
                'methods' => Row::nstr($row, 'methods'),
            ];
        }

        $full = Row::cast(DB::table('promotion_rules')->where('id', $id)->first() ?? new \stdClass);
        $scopes = [];
        foreach (DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->orderBy('id')->get() as $raw) {
            $row = Row::cast($raw);
            $scopes[] = [
                'role' => Row::str($row, 'role'),
                'dimension' => Row::str($row, 'dimension'),
                'value_id' => Row::nint($row, 'value_id'),
                'value' => Row::nstr($row, 'value'),
            ];
        }

        $rewards = [];
        foreach (DB::table('promotion_rule_rewards')->where('promotion_rule_id', $id)->orderBy('id')->get() as $raw) {
            $row = Row::cast($raw);
            $productId = Row::nint($row, 'product_id');
            $rewards[] = [
                'type' => Row::str($row, 'type'),
                'product_id' => $productId,
                'variant_id' => Row::nint($row, 'variant_id'),
                'quantity' => Row::int($row, 'quantity'),
                'amount' => Row::nmoney($row, 'amount'),
                'product' => $productId === null ? null : self::productBrief($productId),
            ];
        }

        return [
            'id' => $id,
            'name' => Row::str($rule, 'name'),
            'priority' => Row::int($rule, 'priority'),
            'is_active' => Row::bool($rule, 'is_active'),
            'starts_at' => Row::nstr($rule, 'starts_at'),
            'ends_at' => Row::nstr($rule, 'ends_at'),
            'code' => Row::nstr($full, 'code'),
            'label_en' => Row::nstr($full, 'label_en'),
            'label_ar' => Row::nstr($full, 'label_ar'),
            'apply_per' => Row::nstr($full, 'apply_per') ?? PromotionMoney::PER_ORDER,
            'max_discount' => Row::nmoney($full, 'max_discount'),
            'min_order_amount' => Row::nmoney($full, 'min_order_amount'),
            'usage_limit' => Row::nint($full, 'usage_limit'),
            'usage_limit_per_customer' => Row::nint($full, 'usage_limit_per_customer'),
            'include_sale_items' => Row::bool($full, 'include_sale_items'),
            'stackable' => Row::bool($full, 'stackable'),
            'unit_floor' => Row::nmoney($full, 'unit_floor'),
            'qualify_min_quantity' => Row::nint($full, 'qualify_min_quantity'),
            'qualify_min_amount' => Row::nmoney($full, 'qualify_min_amount'),
            'max_units' => property_exists($full, 'max_units') ? Row::nint($full, 'max_units') : null,
            'on_wheel' => Row::nstr($full, 'issuer') === WheelPrizes::TEMPLATE_ISSUER,
            'scopes' => $scopes,
            // the names of chosen products, for the builder's chips and its sentence (other dimensions name themselves from scope_options)
            'product_labels' => self::productLabels($scopes),
            'redemptions' => PromotionEngine::redemptions($id, null),
            'storefronts' => Coerce::intList(
                DB::table('promotion_rule_storefront')->where('promotion_rule_id', $id)->pluck('storefront_id')
            ),
            'conditions' => $conditions,
            'rewards' => $rewards,
            'state' => PromotionState::of($rule),
        ];
    }

    /**
     * @param  array<int, mixed>  $scopes
     * @return array<int, string> product id => its Arabic title (a JSON object on the page)
     */
    private static function productLabels(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            $row = Coerce::arr($scope);
            $id = Coerce::nint($row['value_id'] ?? null);
            if (($row['dimension'] ?? null) === 'product' && $id !== null && ! isset($out[$id])) {
                $out[$id] = Coerce::str(self::productBrief($id)['title'] ?? ('#'.$id));
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function productBrief(int $productId): ?array
    {
        $row = DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as t', function (JoinClause $join): void {
                $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            ->where('p.id', $productId)
            ->first(['p.id', 'p.wa_code', 'p.stock_express', 'p.stock_market', 't.title']);

        if (! is_object($row)) {
            return null;
        }
        $product = Row::cast($row);

        return [
            'id' => Row::int($product, 'id'),
            'title' => Row::nstr($product, 'title') ?? ('#'.$productId),
            'wa_code' => Row::nstr($product, 'wa_code'),
            'stock_express' => Row::int($product, 'stock_express'),
            'stock_market' => Row::int($product, 'stock_market'),
        ];
    }

    /**
     * What the CUSTOMER pays — and the sentence that matters: the total does not move.
     *
     * @return array<string, mixed>
     */
    private static function customerOutcome(CartSnapshot $cart, PromotionOutcome $outcome): array
    {
        $before = round($cart->subtotal + $cart->shippingCost, 2);
        $discount = round($outcome->discount, 2);

        return [
            'subtotal' => round($cart->subtotal, 2),
            'shipping' => round($cart->shippingCost, 2),
            'total_before' => $before,
            'discount' => $discount,
            'total' => round(max(0.0, $before - $discount), 2),
            'free_shipping' => $outcome->freeShipping,
            /*
             * Stated explicitly rather than left to be inferred, and it is no longer always true.
             *
             * A free-ITEM reward leaves the total alone, which is why it never trips `addOrder()`'s
             * total check — the thing an admin most often assumes wrongly. A money reward moves the
             * total by construction, and an admin previewing one needs to see the number change
             * here or they will not believe the shop will charge it.
             */
            'total_unchanged' => $discount <= 0.0,
        ];
    }

    /**
     * What LEAVES THE SHOP — the half a promotions screen usually omits.
     *
     * @return list<array<string, mixed>>
     */
    private static function stockOutcome(PromotionOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->rewards as $reward) {
            $productId = $reward->productId;
            $brief = $productId === null ? null : self::productBrief($productId);
            $bucket = $reward->typeStock === 'Express' ? 'stock_express' : 'stock_market';
            $before = $brief === null ? 0 : Coerce::int($brief[$bucket] ?? null);

            $out[] = [
                'product_id' => $productId,
                'title' => $brief['title'] ?? '—',
                'quantity' => $reward->quantity,
                'bucket' => $reward->typeStock,
                'stock_before' => $before,
                // Per ORDER, so an admin can multiply it by the orders they expect.
                'stock_after' => $before - $reward->quantity,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function snapshotFrom(array $data, int $storefrontId, ?string $code = null): CartSnapshot
    {
        $lines = [];
        $subtotal = 0.0;

        foreach (Coerce::arr($data['lines'] ?? null) as $raw) {
            $line = Coerce::arr($raw);
            $productId = Coerce::int($line['product_id'] ?? null);
            $quantity = max(1, Coerce::int($line['quantity'] ?? null, 1));

            $priced = DB::table('storefront_product')
                ->where('storefront_id', $storefrontId)->where('product_id', $productId)
                ->first(['effective_price', 'effective_sale_price']);

            $price = is_object($priced)
                ? CompatCart::catalogPrice(Row::money(Row::cast($priced), 'effective_price'), Row::nmoney(Row::cast($priced), 'effective_sale_price'))
                : 0.0;

            $lines[] = new CartLine($productId, null, null, $quantity, $price, 'Express');
            $subtotal += $price * $quantity;
        }

        return new CartSnapshot(
            storefrontId: $storefrontId,
            lines: $lines,
            subtotal: round($subtotal, 2),
            // A sample cart has no address, so shipping is zero — and the preview says so rather
            // than inventing a city the admin did not choose.
            shippingCost: 0.0,
            paymentMethod: Coerce::str($data['payment_method'] ?? 'cash'),
            code: $code,
            customerKeys: PromotionDiscounts::customerKeys(Coerce::nint($data['account_user_id'] ?? null), Coerce::nstr($data['account'] ?? null), Coerce::nstr($data['phone'] ?? null)),
        );
    }

    /**
     * The code test's pasted SKUs (2026-11-24): one per line or comma, "SKU", "SKU x2", "SKU*2" or "SKU 2". Each is
     * matched EXACTLY (case aside) on the product's SKU or WhatsApp code, and must be shown on this shop; anything else
     * comes back in the second list with its reason, so the operator reads "not on this shop" instead of a silent gap.
     *
     * @return array{0: list<array{sku: string, product_id: int, title: string, price: float, quantity: int}>, 1: list<array{sku: string, reason: string}>}
     */
    private static function skuLines(string $pasted, int $storefrontId): array
    {
        $found = [];
        $unknown = [];
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        foreach (preg_split('/[\r\n,;]+/u', $pasted) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(\S+?)(?:\s*[x\x{00D7}*]\s*|\s+)(\d{1,2})$/u', $item, $m) === 1) {
                [$sku, $quantity] = [$m[1], max(1, min(99, (int) $m[2]))];
            } else {
                [$sku, $quantity] = [$item, 1];
            }
            if (count($found) + count($unknown) >= 50) {
                $unknown[] = ['sku' => $sku, 'reason' => 'too_many'];

                continue;
            }
            $product = DB::table('catalog_products')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->orWhereRaw('LOWER(wa_code) = ?', [mb_strtolower($sku)]))
                ->orderBy('id')->first(['id']);
            if ($product === null) {
                $unknown[] = ['sku' => $sku, 'reason' => 'not_found'];

                continue;
            }
            $productId = Row::int(Row::cast($product), 'id');
            $placed = DB::table('storefront_product')->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('is_visible', 1)
                ->first(['effective_price', 'effective_sale_price']);
            if ($placed === null) {
                $unknown[] = ['sku' => $sku, 'reason' => 'not_on_shop'];

                continue;
            }
            $titles = DB::table('catalog_product_translations')->where('product_id', $productId)->pluck('title', 'locale')->all();
            $found[] = [
                'sku' => $sku,
                'product_id' => $productId,
                'title' => Coerce::str($titles[$locale] ?? $titles[$locale === 'en' ? 'ar' : 'en'] ?? ''),
                'price' => CompatCart::catalogPrice(Row::money(Row::cast($placed), 'effective_price'), Row::nmoney(Row::cast($placed), 'effective_sale_price')),
                'quantity' => $quantity,
            ];
        }

        return [$found, $unknown];
    }

    /** A one-line description of what a rule does, for the list. */
    private static function summary(int $ruleId): string
    {
        $conditions = DB::table('promotion_rule_conditions')->where('promotion_rule_id', $ruleId)->count();
        $rewards = DB::table('promotion_rule_rewards')->where('promotion_rule_id', $ruleId)->get(['type', 'quantity']);

        $units = 0;
        foreach ($rewards as $raw) {
            $units += Row::int(Row::cast($raw), 'quantity');
        }

        return ManageText::t('promotions.summary_line', ':count شرط · :units قطعة مجانية', [
            'count' => $conditions,
            'units' => $units,
        ]);
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private static function types(array $types, callable $label): array
    {
        $out = [];
        foreach ($types as $type) {
            $out[] = ['value' => $type, 'label' => $label($type)];
        }

        return $out;
    }

    /**
     * Every reward type, and — for the money family — exactly WHICH storefronts can deliver it.
     *
     * Not a single `available` boolean any more. Availability stopped being a property of the
     * platform when it became a per-storefront setting, and a screen that flattened it to one flag
     * would have to answer "available where?" with a guess. The screen gets the storefront ids and
     * says it itself.
     *
     * @return list<array<string, mixed>>
     */
    private static function rewardTypes(): array
    {
        $storefronts = self::storefronts();

        $out = [];
        foreach (PromotionRules::REWARDS as $type) {
            $money = PromotionRules::isMoneyReward($type);

            $liveOn = [];
            $blockedOn = [];
            foreach ($storefronts as $storefront) {
                $id = Coerce::int($storefront['id'] ?? null, 0);
                if (PromotionRules::isRewardAvailableOn($type, $id)) {
                    $liveOn[] = $id;
                } else {
                    $blockedOn[] = $id;
                }
            }

            $out[] = [
                'value' => $type,
                'label' => PromotionRules::rewardLabel($type),
                'money' => $money,
                'live_on' => $liveOn,
                'blocked_on' => $blockedOn,
                // Kept so a screen (or a script) can still ask the one-word question.
                'available' => $liveOn !== [],
                'reason' => $blockedOn === [] ? null : PromotionRules::unavailableReason($type, self::namesOf($blockedOn)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private static function namesOf(array $ids): array
    {
        $names = [];
        foreach (self::storefronts() as $storefront) {
            if (in_array(Coerce::int($storefront['id'] ?? null, 0), $ids, true)) {
                $names[] = Coerce::str($storefront['name'] ?? '');
            }
        }

        return $names;
    }

    /** @return list<array<string, mixed>> */
    private static function storefronts(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->where('is_active', 1)->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = [
                'id' => Row::int($row, 'id'),
                'name' => Row::str($row, 'name'),
                // So the form can mark a storefront checkbox itself, not only the reward row.
                'money_rewards' => PromotionRules::moneyRewardsEnabled(Row::int($row, 'id')),
            ];
        }

        return $out;
    }

    /**
     * The lists a coupon's item scopes are picked from (M1u): brands and grades by name (Arabic first, English
     * beside it), the product families, and each storefront's category tree split into categories (top level) and
     * sub-types (below), because the screen names them separately.
     *
     * @return array{brands: list<array{id: int, label: string}>, grades: list<array{id: int, label: string}>, families: list<array{value: string, label: string}>, categories: list<array{id: int, storefront_id: int, label: string}>, sub_types: list<array{id: int, storefront_id: int, label: string}>}
     */
    private static function scopeOptions(): array
    {
        $named = static function (string $table, string $translations, string $key): array {
            $out = [];
            foreach (DB::table($table.' as b')
                ->leftJoin($translations.' as ar', function (JoinClause $j) use ($key): void {
                    $j->on('ar.'.$key, '=', 'b.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin($translations.' as en', function (JoinClause $j) use ($key): void {
                    $j->on('en.'.$key, '=', 'b.id')->where('en.locale', '=', 'en');
                })
                ->orderBy('en.name')->get(['b.id', 'ar.name as ar_name', 'en.name as en_name']) as $raw) {
                $row = Row::cast($raw);
                $out[] = ['id' => Row::int($row, 'id'), 'label' => LocalisedName::pick(
                    Row::nstr($row, 'ar_name'), Row::nstr($row, 'en_name'), '#'.Row::int($row, 'id'),
                ), 'ar' => Row::nstr($row, 'ar_name') ?? Row::nstr($row, 'en_name'), 'en' => Row::nstr($row, 'en_name') ?? Row::nstr($row, 'ar_name')];
            }

            return $out;
        };

        $nodes = ['categories' => [], 'sub_types' => []];
        foreach (DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $j): void {
                $j->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $j): void {
                $j->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->leftJoin('storefronts as s', 's.id', '=', 'c.storefront_id')
            ->orderBy('c.storefront_id')->orderBy('c.path')
            ->get(['c.id', 'c.storefront_id', 'c.depth', 'c.slug', 'ar.name as ar_name', 'en.name as en_name', 's.name as storefront']) as $raw) {
            $row = Row::cast($raw);
            $label = LocalisedName::pick(Row::nstr($row, 'ar_name'), Row::nstr($row, 'en_name'), Row::str($row, 'slug'))
                .' — '.(Row::nstr($row, 'storefront') ?? '');
            $nodes[Row::int($row, 'depth') <= 1 ? 'categories' : 'sub_types'][] = [
                'id' => Row::int($row, 'id'), 'storefront_id' => Row::int($row, 'storefront_id'), 'label' => $label, 'slug' => Row::str($row, 'slug'),
                // both names, for the builder's sentence in both languages
                'ar' => Row::nstr($row, 'ar_name') ?? Row::nstr($row, 'en_name'), 'en' => Row::nstr($row, 'en_name') ?? Row::nstr($row, 'ar_name'),
            ];
        }

        $families = [];
        foreach (PromotionWriter::families() as $family) {
            $families[] = ['value' => $family, 'label' => match ($family) {
                'watch' => ManageText::t('promotions.family_watch', 'ساعات'),
                'fashion' => ManageText::t('promotions.family_fashion', 'أزياء وإكسسوارات'),
                'bag' => ManageText::t('promotions.family_bag', 'حقائب'),
                'wallet' => ManageText::t('promotions.family_wallet', 'محافظ'),
                'perfume' => ManageText::t('promotions.family_perfume', 'عطور'),
                default => ManageText::t('promotions.family_electronics', 'إلكترونيات'),
            }];
        }

        return [
            'brands' => $named('catalog_brands', 'catalog_brand_translations', 'brand_id'),
            'grades' => $named('catalog_grades', 'catalog_grade_translations', 'grade_id'),
            'families' => $families,
            'categories' => $nodes['categories'],
            'sub_types' => $nodes['sub_types'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            // nullable since M1u: an inactive DRAFT may wait for its dates; PromotionWriter refuses to activate one without
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'code' => ['nullable', 'string', 'max:40'],
            'label_en' => ['nullable', 'string', 'max:160'],
            'label_ar' => ['nullable', 'string', 'max:160'],
            'apply_per' => ['nullable', 'string', Rule::in(PromotionMoney::PER)],
            'max_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'include_sale_items' => ['nullable', 'boolean'],
            'stackable' => ['nullable', 'boolean'],
            'unit_floor' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'qualify_min_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'qualify_min_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            // the offer builder (2026-11-19): a fixed maximum of discounted items per order; an offer kept for the wheel
            'max_units' => ['nullable', 'integer', 'min:1', 'max:999'],
            'for_wheel' => ['nullable', 'boolean'],
            'scopes' => ['array', 'max:200'],
            'scopes.*.role' => ['required', 'string', Rule::in(PromotionScopes::ROLES)],
            'scopes.*.dimension' => ['required', 'string', Rule::in(PromotionScopes::DIMENSIONS)],
            'scopes.*.value_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.value' => ['nullable', 'string', 'max:32'],
            'storefronts' => ['array'],
            'storefronts.*' => ['integer', 'exists:storefronts,id'],
            'conditions' => ['array'],
            'conditions.*.type' => ['required', 'string', Rule::in(PromotionRules::CONDITIONS)],
            'rewards' => ['array'],
            'rewards.*.type' => ['required', 'string', Rule::in(PromotionRules::REWARDS)],
            // Nullable for the money family, which has no quantity. `PromotionWriter` still refuses
            // a free-item reward whose quantity is missing or out of bounds — that check knows the
            // reward's type and this one does not.
            'rewards.*.quantity' => ['nullable', 'integer', 'min:1'],
        ] + self::parameterRules();
    }

    /**
     * The PARAMETERS of a condition and a reward — the amount, the product, the category.
     *
     * ── Why these have to be declared, and what it cost that they were not ───────────────────
     *
     * `$request->validate()` returns only the keys it has a rule FOR. Every parameter below was
     * missing from the list, so each one was silently dropped between the form and the writer:
     * a `free_product` reward saved with `product_id = NULL` (a gift that points at no product),
     * and a `cart_subtotal_min` condition saved with `amount = NULL`, which the engine reads as
     * `subtotal >= 0` — a gift on every cart in the shop. Nothing refused either, because the
     * writer was checking whether the gift was VISIBLE, and a gift that is nothing is not
     * invisible. Found 2026-09-13 by the test that hides a gift and expects the save to refuse.
     *
     * `exists` on each id is deliberate: the writer's own checks answer "is it sellable there",
     * which is a different question from "is it real", and a stale id from a form left open is the
     * likelier of the two mistakes.
     *
     * @return array<string, list<mixed>>
     */
    private static function parameterRules(): array
    {
        return [
            'conditions.*.product_id' => ['nullable', 'integer', 'exists:catalog_products,id'],
            'conditions.*.variant_id' => ['nullable', 'integer', 'exists:catalog_product_variants,id'],
            'conditions.*.storefront_category_id' => ['nullable', 'integer', 'exists:storefront_categories,id'],
            'conditions.*.brand_id' => ['nullable', 'integer', 'exists:catalog_brands,id'],
            'conditions.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'conditions.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'conditions.*.methods' => ['nullable', 'string', 'max:64'],
            'rewards.*.product_id' => ['nullable', 'integer', 'exists:catalog_products,id'],
            'rewards.*.variant_id' => ['nullable', 'integer', 'exists:catalog_product_variants,id'],
            /*
             * The money reward's value: a RATE for `percent_discount`, an AMOUNT for
             * `fixed_discount`. Declared here for the same reason every parameter above is — a key
             * with no rule is dropped by `validate()` before the writer ever sees it, which is how
             * a gift once saved pointing at no product.
             *
             * Bounded loosely here and precisely in the writer: this rule only has to stop a
             * hostile payload, while "a percentage over 100" and "zero is not a discount" are
             * decisions about what a promotion MEANS, and those belong with the other refusals.
             */
            'rewards.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    private static function requireRule(int $promotion): \stdClass
    {
        $row = DB::table('promotion_rules')->where('id', $promotion)
            ->first(['id', 'name', 'priority', 'is_active', 'starts_at', 'ends_at']);

        // 404, never 403: a rule id is guessable and a 403 would confirm the guess (§3.11.14).
        abort_if(! is_object($row), 404);

        return Row::cast($row);
    }

    private static function actorId(Request $request): ?int
    {
        return Coerce::nint($request->user()?->getAuthIdentifier());
    }
}
