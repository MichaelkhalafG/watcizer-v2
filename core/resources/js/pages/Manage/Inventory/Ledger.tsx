import { Link } from '@inertiajs/react';

import { DataTable, type Column } from '@/components/table/DataTable';
import { DateRangeFilter } from '@/components/table/DateRangeFilter';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { bucketLabel, referenceLabel } from '@/lib/labels';
import type { TablePayload } from '@/types';
import { Ltr, Name } from '@/components/ui/bidi';

/**
 * The movement ledger (wave 4C) — READ-ONLY, for everybody including an administrator.
 *
 * There is no edit control on this page and no route behind one. A ledger you can edit reconciles
 * nothing: the whole value of `inventory:verify` is that the sum of the movements must equal the
 * column, and an edited history makes that equality meaningless. A mistake is corrected by posting
 * the OPPOSITE movement with a note — which is what the `adjustment` reason exists for, and what
 * an accountant would do with a wrong entry.
 *
 * `InventoryAuthorizationTest` asserts the absence of a write route rather than trusting this
 * paragraph: the screen not having a button is not the control.
 */

interface LedgerRow {
    id: number;
    product_id: number | null;
    wa_code: string | null;
    variant: string | null;
    bucket: string;
    delta: number;
    after: number;
    reason: string;
    reference: string | null;
    reference_id: number | null;
    actor: string | null;
    actor_id: number | null;
    storefront_id: number | null;
    note: string | null;
    external_ref: string | null;
    created_at: string | null;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    table: TablePayload<LedgerRow>;
    filters: { reasons: Option[]; buckets: Option[]; references: Option[] };
}

type Translate = ReturnType<typeof useT>;

/**
 * The ledger reasons in Arabic — the same vocabulary the other inventory screens use.
 *
 * A function of `t` rather than a constant map: a hook cannot run at module level, and these have
 * to be translated. `adjustment` keeps its own key because the ledger names it a stock-COUNT
 * correction, which is a longer phrase than the order screen's, and one key cannot hold both.
 */
const reasonLabels = (t: Translate): Record<string, string> => ({
    order: t('common.reason_order', 'حجز لطلب'),
    order_cancel: t('common.reason_order_cancel', 'إرجاع بعد إلغاء'),
    payment_failed: t('common.reason_payment_failed', 'إرجاع بعد فشل دفع'),
    restock: t('common.reason_restock', 'توريد'),
    manual: t('common.reason_manual', 'تعديل يدوي'),
    import: t('common.reason_import', 'استيراد'),
    adjustment: t('inventory.ledger_reason_adjustment', 'تسوية جرد'),
    erp_sync: t('common.reason_erp_sync', 'مزامنة ERP'),
    transform: t('common.reason_transform', 'بناء أولي'),
    // Units given away by a promotion. It was the one reason of the ten with no word, so the
    // filter listed `promotion_reward` among nine Arabic phrases — and the ledger row said it too.
    promotion_reward: t('common.reason_promotion_reward', 'هدية عرض ترويجي'),
});

/** A release is the movement that proves a cancellation gave the stock back. */
const RELEASE_REASONS = ['order_cancel', 'payment_failed'];

const bucketLabels = (t: Translate): Record<string, string> => ({
    express: t('common.stock_express', 'إكسبريس'),
    market: t('common.stock_market', 'ماركت'),
});

export default function InventoryLedger({ table, filters }: Props) {
    const t = useT();

    const REASON_LABEL = reasonLabels(t);
    const BUCKET_LABEL = bucketLabels(t);

    const columns: Array<Column<LedgerRow>> = [
        {
            key: 'im.created_at',
            header: t('common.date', 'التاريخ'),
            sortable: true,
            cell: (row) => (
                <span className="text-xs" dir="ltr">
                    {row.created_at ?? '—'}
                </span>
            ),
        },
        {
            key: 'product',
            header: t('common.product', 'المنتج'),
            sortable: false,
            cell: (row) => (
                <div className="space-y-0.5">
                    <div className="text-xs font-medium">
                        <Ltr>{row.wa_code ?? `#${row.product_id ?? '—'}`}</Ltr>
                    </div>
                    {row.variant !== null ? <div className="text-xs text-muted-foreground">{row.variant}</div> : null}
                </div>
            ),
        },
        {
            key: 'im.bucket',
            header: t('inventory.bucket', 'المخزن'),
            sortable: false,
            cell: (row) => <span className="text-sm">{BUCKET_LABEL[row.bucket] ?? row.bucket}</span>,
        },
        {
            key: 'im.quantity_delta',
            header: t('inventory.ledger_delta', 'التغيير'),
            sortable: true,
            cell: (row) => (
                <span
                    className={row.delta > 0 ? 'font-medium text-emerald-700 dark:text-emerald-400' : 'font-medium text-destructive'}
                    dir="ltr"
                >
                    {row.delta > 0 ? `+${row.delta}` : row.delta}
                </span>
            ),
        },
        {
            key: 'after',
            header: t('inventory.ledger_balance_after', 'الرصيد بعدها'),
            sortable: false,
            cell: (row) => (
                <span dir="ltr">{row.after}</span>
            ),
        },
        {
            key: 'reason',
            header: t('common.reason', 'السبب'),
            sortable: false,
            cell: (row) => (
                <div className="space-y-0.5">
                    <Badge variant={RELEASE_REASONS.includes(row.reason) ? 'success' : 'outline'}>
                        {REASON_LABEL[row.reason] ?? row.reason}
                    </Badge>
                    {row.note !== null ? <div className="text-xs text-muted-foreground">{row.note}</div> : null}
                </div>
            ),
        },
        {
            key: 'reference',
            header: t('inventory.ledger_source', 'المصدر'),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    {row.reference === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : row.reference === 'orders' && row.reference_id !== null ? (
                        // The one link on the page: a movement caused by an order leads to that
                        // order, which is the question a surprising number actually raises.
                        <Link href={`/manage/orders/${row.reference_id}`} className="text-brand-strong hover:underline">
                            {/* Item 10: "order #41" was half English, half number. */}
                            {t('inventory.reference_order_number', 'طلب #:id', { id: row.reference_id })}
                        </Link>
                    ) : (
                        <span>
                            {referenceLabel(t, row.reference)}
                            {row.reference_id !== null ? ` #${row.reference_id}` : ''}
                        </span>
                    )}
                    {row.external_ref !== null ? (
                        <div className="text-muted-foreground">
                            <Ltr>{row.external_ref}</Ltr>
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'actor',
            header: t('inventory.ledger_actor', 'مَن'),
            sortable: false,
            hideOnMobile: true,
            // The server resolves this to a NAME (D-11). The id is no longer appended: `Michael
            // #5` tells the reader nothing the name did not, and the raw `user #5` it replaced is
            // exactly what this column was reported for. The id is still in the CSV export, where
            // a machine reads it.
            cell: (row) => (
                <span className="text-xs text-muted-foreground">
                    <Name>{row.actor ?? '—'}</Name>
                </span>
            ),
        },
    ];

    return (
        <ManageLayout
            title={t('inventory.ledger_title', 'سجل حركات المخزون')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.inventory', 'المخزون'), href: '/manage/inventory' },
                { label: t('inventory.ledger_crumb', 'السجل') },
            ]}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/manage/inventory/reconciliation">{t('common.reconciliation', 'فحص المطابقة')}</Link>
                </Button>
            }
        >
            <div className="space-y-4">
                <Alert tone="info" title={t('inventory.ledger_read_only_title', 'السجل للقراءة فقط')}>
                    {t(
                        'inventory.ledger_read_only_body',
                        'لا توجد شاشة ولا مسار يعدّل سطرًا هنا أو يحذفه. الخطأ يُصحَّح بتسجيل الحركة المضادة مع ملاحظة — وهكذا يظل مجموع الحركات مساويًا للرصيد، وهو أساس فحص المطابقة.',
                    )}
                </Alert>

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.id}
                    emptyTitle={t('inventory.ledger_empty_title', 'لا توجد حركات')}
                    emptyDescription={t('inventory.ledger_empty_description', 'جرِّب تعديل التصفية أو نطاق التاريخ.')}
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('common.reason', 'السبب')}
                                value={current.reason ?? ''}
                                onChange={(event) => setFilter('reason', event.target.value || null)}
                            >
                                <option value="">{t('inventory.ledger_all_reasons', 'كل الأسباب')}</option>
                                {filters.reasons.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {REASON_LABEL[option.value] ?? option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('inventory.bucket', 'المخزن')}
                                value={current.bucket ?? ''}
                                onChange={(event) => setFilter('bucket', event.target.value || null)}
                            >
                                {/* Item 10: the server sends these options as the stored value in
                                    BOTH fields, so the dropdown read `express` while the table column
                                    beside it read «إكسبريس». Translated here, where the words already
                                    live, rather than by teaching the filter builder about language.

                                    …and then translating the VALUE broke the option that has no
                                    value (D-9). The "any" row is the one option whose label the
                                    server writes out in full — `['value' => '', 'label' => 'كل
                                    المخازن']` — and `bucketLabel('')` fell through to
                                    `bucket ?? '—'`, which does not fire for `''` because `''` is
                                    not `null`. The dropdown rendered as an empty box. An option
                                    with no value is not a token to translate; it is a label to
                                    print. */}
                                {filters.buckets.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.value === ''
                                            ? option.label
                                            : bucketLabel(t, option.value)}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('inventory.ledger_source', 'المصدر')}
                                value={current.reference_type ?? ''}
                                onChange={(event) => setFilter('reference_type', event.target.value || null)}
                            >
                                {/* Same rule as the bucket filter above: `''` means "any", and its
                                    label is the server's sentence, not a token. */}
                                {filters.references.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.value === ''
                                            ? option.label
                                            : referenceLabel(t, option.value)}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                type="number"
                                aria-label={t('common.product_number', 'رقم المنتج')}
                                className="w-[9rem]"
                                dir="ltr"
                                placeholder={t('common.product_number', 'رقم المنتج')}
                                value={current.product_id ?? ''}
                                onChange={(event) => setFilter('product_id', event.target.value || null)}
                            />
                            <DateRangeFilter
                                from={current.from ?? null}
                                to={current.to ?? null}
                                onChange={(from, to) => {
                                    setFilter('from', from);
                                    setFilter('to', to);
                                }}
                            />
                        </>
                    )}
                />
            </div>
        </ManageLayout>
    );
}
