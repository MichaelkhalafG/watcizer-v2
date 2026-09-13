<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Support\Coerce;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Inventory — stock, the ledger, low stock, and the reconciliation panel (wave 4C).
 *
 * ── One door, and this screen is not it ──────────────────────────────────────────────────────
 *
 * Every quantity this screen changes goes through `InventoryService` (AGENTS §3, wave 3). The
 * controller computes NOTHING about stock: it validates a form, names a `StockTarget`, and calls
 * `set()` or `adjust()`. That is why data-entry may use it — the role decides who may ask, the
 * service decides what is allowed, and the ledger records what happened either way. `StockWriteGuard`
 * is armed outside production and would refuse any statement from here that named a stock column.
 *
 * ── The ledger is READ-ONLY for everybody ───────────────────────────────────────────────────
 *
 * There is no route on this controller that edits or deletes a movement, and there will not be: a
 * ledger you can edit is a ledger that cannot reconcile anything. A mistake is corrected by
 * POSTING THE OPPOSITE MOVEMENT, which is what an accountant would do and what the reasons list is
 * shaped for (`adjustment` with a note). `InventoryAuthorizationTest` asserts the absence of such a
 * route rather than trusting this paragraph.
 *
 * ── The reconciliation panel runs the COMMAND ───────────────────────────────────────────────
 *
 * `inventory:verify` holds the four invariants (ledger = column, variant sum = product aggregate,
 * `in_stock` derived, no product-level movement on a variant product). The panel runs that command
 * and shows its report. It does NOT re-implement the checks: a second implementation would be a
 * second answer, and the first time they disagreed nobody would know which to believe.
 */
final class InventoryController
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Stock per product, with its variants, both buckets — and a low-stock view that is a FILTER
     * on this list rather than a separate screen, so the numbers cannot differ between the two.
     */
    public function index(Request $request): Response
    {
        /** @var array<int, list<array<string, mixed>>> $variants */
        $variants = [];

        $table = TableQuery::for($request)
            ->sortable(['p.wa_code', 'p.stock_express', 'p.stock_market', 'p.low_stock_threshold'], default: 'p.wa_code')
            ->searchable(['p.wa_code', 'p.sku'])
            // `view` and `bucket` are predicates, not columns: declared so they are whitelisted
            // and rendered, applied by hand below (the `flag` lesson of wave 4B).
            ->filterable(['view' => ['', 'low'], 'bucket' => ['', 'express', 'market', 'out']])
            ->virtual(['view', 'bucket']);

        $query = DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as t', function (JoinClause $join): void {
                $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            ->whereNull('p.deleted_at')
            ->select([
                'p.id', 'p.wa_code', 'p.sku', 'p.stock_express', 'p.stock_market', 'p.in_stock',
                'p.low_stock_threshold', 'p.family', 't.title as title_ar',
            ]);

        // `resolvedFilters()`, never raw request input — see OrderController for why.
        $filters = $table->resolvedFilters();

        // Low stock: the product's OWN threshold, not a global number — the column exists per
        // product because a watch and a keychain do not run low at the same count.
        if (Coerce::str($filters['view'] ?? null) === 'low') {
            $query->whereRaw('(p.stock_express + p.stock_market) <= p.low_stock_threshold');
        }
        $bucket = Coerce::str($filters['bucket'] ?? null);
        if ($bucket === 'express') {
            $query->where('p.stock_express', '>', 0);
        } elseif ($bucket === 'market') {
            $query->where('p.stock_market', '>', 0);
        } elseif ($bucket === 'out') {
            $query->where('p.stock_express', 0)->where('p.stock_market', 0);
        }

        return Inertia::render('Manage/Inventory/Index', [
            'table' => $table->paginate(
                $query,
                function (object $raw) use (&$variants): array {
                    $row = Row::cast($raw);
                    $id = Row::int($row, 'id');
                    $express = Row::int($row, 'stock_express');
                    $market = Row::int($row, 'stock_market');
                    $threshold = Row::int($row, 'low_stock_threshold');

                    return [
                        'id' => $id,
                        'wa_code' => Row::str($row, 'wa_code'),
                        'sku' => Row::nstr($row, 'sku'),
                        'title' => Row::nstr($row, 'title_ar'),
                        'family' => Row::nstr($row, 'family'),
                        'express' => $express,
                        'market' => $market,
                        'total' => $express + $market,
                        'threshold' => $threshold,
                        'in_stock' => Row::bool($row, 'in_stock'),
                        'is_low' => ($express + $market) <= $threshold,
                        // A product with variants is adjusted PER VARIANT: the aggregate is derived
                        // and the service refuses a product-level movement on it (wave 3.5).
                        'variants' => $variants[$id] ?? [],
                    ];
                },
                function (array $rows) use (&$variants): void {
                    $variants = self::variantsForPage($rows);
                },
            ),
            'filters' => [
                'views' => [
                    ['value' => '', 'label' => 'كل المنتجات'],
                    ['value' => 'low', 'label' => 'مخزون منخفض'],
                ],
                'buckets' => [
                    ['value' => '', 'label' => 'كل المخازن'],
                    ['value' => 'express', 'label' => 'إكسبريس فقط'],
                    ['value' => 'market', 'label' => 'ماركت فقط'],
                    ['value' => 'out', 'label' => 'نفد بالكامل'],
                ],
            ],
            'reasons' => self::adjustmentReasons(),
            'low_count' => self::lowStockCount(),
        ]);
    }

    /**
     * The movement ledger, filterable — the audit trail a human reads when a number surprises them.
     *
     * Read-only by construction: this method has no sibling that writes.
     */
    public function ledger(Request $request): Response
    {
        $table = TableQuery::for($request)
            ->sortable(['im.created_at', 'im.quantity_delta', 'im.id'], default: 'im.created_at', direction: 'desc')
            ->filterable([
                'reason' => InventoryService::REASONS,
                'bucket' => ['', 'express', 'market'],
                // `null` = any scalar; `[]` would be an allow-list of nothing (see OrderController).
                'product_id' => null,
                'storefront_id' => null,
                'reference_type' => null,
                'from' => null,
                'to' => null,
            ])
            ->virtual(['reason', 'bucket', 'product_id', 'storefront_id', 'reference_type', 'from', 'to']);

        $query = DB::table('inventory_movements as im')
            ->leftJoin('catalog_products as p', 'p.id', '=', 'im.product_id')
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'im.variant_id')
            ->select([
                'im.id', 'im.product_id', 'im.variant_id', 'im.bucket', 'im.quantity_delta',
                'im.quantity_after', 'im.reason', 'im.reference_type', 'im.reference_id',
                'im.actor_type', 'im.actor_id', 'im.storefront_id', 'im.note', 'im.external_ref',
                'im.created_at', 'p.wa_code', 'v.label as variant_label',
            ]);

        $filters = $table->resolvedFilters();

        foreach ([
            'reason' => 'im.reason',
            'bucket' => 'im.bucket',
            'product_id' => 'im.product_id',
            'storefront_id' => 'im.storefront_id',
            'reference_type' => 'im.reference_type',
        ] as $input => $column) {
            $value = Coerce::nstr($filters[$input] ?? null);
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
        $from = Coerce::nstr($filters['from'] ?? null);
        if ($from !== null && $from !== '') {
            $query->where('im.created_at', '>=', $from.' 00:00:00');
        }
        $to = Coerce::nstr($filters['to'] ?? null);
        if ($to !== null && $to !== '') {
            $query->where('im.created_at', '<=', $to.' 23:59:59');
        }

        return Inertia::render('Manage/Inventory/Ledger', [
            'table' => $table->paginate($query, function (object $raw): array {
                $row = Row::cast($raw);

                return [
                    'id' => Row::int($row, 'id'),
                    'product_id' => Row::nint($row, 'product_id'),
                    'wa_code' => Row::nstr($row, 'wa_code'),
                    'variant' => Row::nstr($row, 'variant_label'),
                    'bucket' => Row::str($row, 'bucket'),
                    'delta' => Row::int($row, 'quantity_delta'),
                    'after' => Row::int($row, 'quantity_after'),
                    'reason' => Row::str($row, 'reason'),
                    'reference' => Row::nstr($row, 'reference_type'),
                    'reference_id' => Row::nint($row, 'reference_id'),
                    'actor' => Row::nstr($row, 'actor_type'),
                    'actor_id' => Row::nint($row, 'actor_id'),
                    'storefront_id' => Row::nint($row, 'storefront_id'),
                    'note' => Row::nstr($row, 'note'),
                    'external_ref' => Row::nstr($row, 'external_ref'),
                    'created_at' => Row::nstr($row, 'created_at'),
                ];
            }),
            'filters' => [
                'reasons' => array_map(
                    fn (string $r): array => ['value' => $r, 'label' => $r],
                    InventoryService::REASONS,
                ),
                'buckets' => [
                    ['value' => '', 'label' => 'كل المخازن'],
                    ['value' => 'express', 'label' => 'express'],
                    ['value' => 'market', 'label' => 'market'],
                ],
                'references' => self::referenceTypes(),
            ],
        ]);
    }

    /**
     * The reconciliation panel: what `inventory:verify` says, right now, read-only.
     *
     * Running the command is the point. The four invariants live in ONE place and this screen shows
     * that place's answer; re-deriving them here would create a second answer that can disagree
     * with the command the runbook trusts.
     */
    public function reconciliation(Request $request): Response
    {
        $exitCode = Artisan::call('inventory:verify');
        $output = trim(Artisan::output());

        return Inertia::render('Manage/Inventory/Reconciliation', [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            // The command's own words, verbatim, so the screen and the terminal agree.
            'report' => $output === '' ? 'لم يُصدر الفحص أي مخرجات.' : $output,
            'ran_at' => now()->toDateTimeString(),
            'counts' => [
                'products' => DB::table('catalog_products')->whereNull('deleted_at')->count(),
                'variants' => DB::table('catalog_product_variants')->count(),
                'movements' => DB::table('inventory_movements')->count(),
            ],
        ]);
    }

    /**
     * An adjustment — the one write this controller makes, and it makes it through the service.
     *
     * `set` is an absolute count ("the shelf holds 7"), `adjust` a relative one ("three arrived").
     * Both are recorded with a reason and an optional note, and the service refuses:
     * a product-level movement on a product that has variants, a decrement below zero, and a
     * reason outside its own list.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'product_id' => ['required', 'integer', Rule::exists('catalog_products', 'id')->whereNull('deleted_at')],
            'variant_id' => ['nullable', 'integer', Rule::exists('catalog_product_variants', 'id')],
            'bucket' => ['required', 'string', Rule::in(['express', 'market'])],
            'mode' => ['required', 'string', Rule::in(['set', 'adjust'])],
            'quantity' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', Rule::in(self::ADJUSTMENT_REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        $productId = Coerce::int($data['product_id']);
        $variantId = Coerce::nint($data['variant_id'] ?? null);
        $quantity = Coerce::int($data['quantity']);
        $mode = Coerce::str($data['mode']);

        if ($variantId !== null) {
            // A variant must belong to the named product: the service checks it too, and asking
            // here turns a 500 into a field error the operator can act on.
            $belongs = DB::table('catalog_product_variants')
                ->where('id', $variantId)->where('product_id', $productId)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['variant_id' => 'هذا المقاس/اللون لا يتبع هذا المنتج.']);
            }
        }

        if ($mode === 'adjust' && $quantity === 0) {
            throw ValidationException::withMessages(['quantity' => 'التغيير النسبي لا يمكن أن يكون صفرًا. استخدم «تعيين» لضبط رقم مطلق.']);
        }
        if ($mode === 'set' && $quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية المطلقة لا يمكن أن تكون سالبة.']);
        }

        $target = $variantId === null
            ? StockTarget::product($productId)
            : StockTarget::variant($productId, $variantId);

        try {
            $movement = $mode === 'set'
                ? $this->inventory->set(
                    $target,
                    Coerce::str($data['bucket']),
                    $quantity,
                    Coerce::str($data['reason']),
                    actor: self::actor($request),
                    note: Coerce::nstr($data['note'] ?? null),
                )
                : $this->inventory->adjust(
                    $target,
                    Coerce::str($data['bucket']),
                    $quantity,
                    Coerce::str($data['reason']),
                    actor: self::actor($request),
                    note: Coerce::nstr($data['note'] ?? null),
                );
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages([
                'quantity' => 'لا يوجد مخزون كافٍ لهذا الخصم: الكمية المطلوبة أكبر من المتاح.',
            ]);
        } catch (RuntimeException $e) {
            // The service's own refusals are already operator-readable (wave 3.5 wrote them in
            // Arabic where they can reach a screen); anything else is named plainly.
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('status', $movement === null
            ? 'الكمية المطلوبة مطابقة للحالية، فلم تُسجَّل حركة.'
            : 'تم تسجيل الحركة في السجل.');
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /**
     * The reasons an OPERATOR may choose, which is not every reason the service knows.
     *
     * `order`, `order_cancel`, `payment_failed` and `transform` are written by machinery — an
     * operator picking "order" by hand would put a movement in the ledger that no order explains,
     * and the reconciliation would be right to call it a finding.
     *
     * @var list<string>
     */
    private const ADJUSTMENT_REASONS = ['adjustment', 'restock', 'manual', 'import', 'erp_sync'];

    /** @return list<array{value: string, label: string}> */
    private static function adjustmentReasons(): array
    {
        $labels = [
            'adjustment' => 'تسوية جرد',
            'restock' => 'توريد جديد',
            'manual' => 'تعديل يدوي',
            'import' => 'استيراد',
            'erp_sync' => 'مزامنة ERP',
        ];

        $out = [];
        foreach (self::ADJUSTMENT_REASONS as $reason) {
            $out[] = ['value' => $reason, 'label' => $labels[$reason]];
        }

        return $out;
    }

    /**
     * The variants of the page's products, read once for the page (AGENTS §2.24).
     *
     * @param  list<object>  $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private static function variantsForPage(array $rows): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (
            DB::table('catalog_product_variants')
                ->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderBy('sort')->orderBy('id')
                ->get(['id', 'product_id', 'label', 'sku', 'stock_express', 'stock_market', 'is_active']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'product_id')][] = [
                'id' => Row::int($row, 'id'),
                'label' => Row::nstr($row, 'label'),
                'sku' => Row::nstr($row, 'sku'),
                'express' => Row::int($row, 'stock_express'),
                'market' => Row::int($row, 'stock_market'),
                'is_active' => Row::bool($row, 'is_active'),
            ];
        }

        return $out;
    }

    private static function lowStockCount(): int
    {
        return (int) DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->whereRaw('(stock_express + stock_market) <= low_stock_threshold')
            ->count();
    }

    /** @return list<array{value: string, label: string}> */
    private static function referenceTypes(): array
    {
        $out = [['value' => '', 'label' => 'كل المصادر']];
        foreach (
            DB::table('inventory_movements')->whereNotNull('reference_type')
                ->distinct()->orderBy('reference_type')->pluck('reference_type') as $value
        ) {
            if (is_string($value) && $value !== '') {
                $out[] = ['value' => $value, 'label' => $value];
            }
        }

        return $out;
    }

    private static function actor(Request $request): Actor
    {
        $user = $request->user();

        return Actor::user($user === null ? null : Coerce::int($user->getAuthIdentifier()));
    }
}
