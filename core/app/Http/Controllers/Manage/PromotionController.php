<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Compat\CompatCart;
use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionOutcome;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionState;
use App\Domain\Promotions\PromotionWriter;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
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
            ->virtual(['is_active', 'storefront_id'])
            ->searchable(['r.name'])
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
            ->select(['r.id', 'r.name', 'r.priority', 'r.is_active', 'r.starts_at', 'r.ends_at', 'r.updated_at']);

        $filters = $table->resolvedFilters();

        $active = Coerce::nstr($filters['is_active'] ?? null);
        if ($active !== null) {
            $query->where('r.is_active', $active === '1' ? 1 : 0);
        }

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
        return ManageText::t('promotions.stacking_notice', 'العروض لا تتجمع: تُطبَّق قاعدة واحدة فقط على كل سلة — صاحبة الأولوية الأعلى. لو نشرت ثلاثة عروض، العميل يحصل على واحد منها فقط، وليس الثلاثة.');
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
        $id = $this->writer->save($data, null, self::actorId($request));

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

        /*
         * The rule's own columns before and after. Conditions and rewards live in child tables and
         * are not diffed here: a reward list is a set, not a field, and rendering "it changed" for
         * a reordered array would be noise. The row records WHO touched the rule and WHEN, which is
         * what makes the child tables' own timestamps interpretable.
         */
        $before = ActivityLog::fields(DB::table('promotion_rules')->where('id', $promotion)
            ->first(['name', 'priority', 'is_active', 'starts_at', 'ends_at']));

        $this->writer->save($data, $promotion, self::actorId($request));

        $after = ActivityLog::fields(DB::table('promotion_rules')->where('id', $promotion)
            ->first(['name', 'priority', 'is_active', 'starts_at', 'ends_at']));

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
                    ->orWhere('p.sku', 'like', $term.'%')
                    ->orWhere('p.model_number', 'like', $term.'%');
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
        ] + self::parameterRules()));

        $storefrontId = Coerce::int($data['storefront_id'] ?? null, 1);
        $snapshot = $this->snapshotFrom($data, $storefrontId);

        /*
         * The draft is saved, evaluated and ROLLED BACK, all inside one transaction. A preview that
         * evaluated the STORED rule would answer about yesterday's version — which is the one thing
         * a preview must not do. Nothing survives this method.
         */
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
             */
            DB::rollBack();

            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } finally {
            if (DB::transactionLevel() > 0) {
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
        ]);
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
            'storefronts' => Coerce::intList(
                DB::table('promotion_rule_storefront')->where('promotion_rule_id', $id)->pluck('storefront_id')
            ),
            'conditions' => $conditions,
            'rewards' => $rewards,
            'state' => PromotionState::of($rule),
        ];
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
    private function snapshotFrom(array $data, int $storefrontId): CartSnapshot
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
        );
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

    /** @return array<string, list<mixed>> */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
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
