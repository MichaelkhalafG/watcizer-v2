import { router } from '@inertiajs/react';
import { Boxes, MoveRight, PackagePlus, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/** The rows the bar can preview — what it needs from a stock row and nothing more. */
export interface BulkRow {
    id: number;
    wa_code: string;
    express: number;
    market: number;
    threshold: number;
}

interface Props {
    rows: BulkRow[];
    selected: Array<string | number>;
    clear: () => void;
    reasons: Array<{ value: string; label: string }>;
}

/** How many before→after lines the preview shows before it summarises the rest. */
const PREVIEW_ROWS = 5;

/**
 * Bulk stock work on the selected rows (6.3, 2026-09-20).
 *
 * ── The two stock modes are two BUTTONS, and that is a safety decision ───────────────────────
 *
 * "Count to" and "Receive" are separate actions with separate forms, not one form with a mode
 * dropdown. The failure that shape prevents:
 *
 *     an operator means *"a shipment of 5 arrived"* and gets *"set the shelf to 5"*
 *
 * on a product holding 80 — which writes off 75 units, and the ledger records it as a deliberate
 * correction, because that is precisely what it was told. Nothing looks wrong afterwards: the
 * number is plausible, the movement is signed and dated, and the only trace is a stock figure
 * that is quietly false until somebody counts the shelf again.
 *
 * A dropdown puts those two one keystroke apart. Two buttons put them two decisions apart — and
 * the preview below shows the RESULT on real rows before either will submit, so the difference is
 * visible in numbers rather than only in wording.
 *
 * ── Why the words are the warehouse's and not the database's ────────────────────────────────
 *
 * «جرد» is what the team calls counting the shelf; «توريد» is what they call a delivery arriving.
 * `set` and `adjust` are what the service calls them, and those stay in the payload where the
 * service reads them. The operator is choosing between two jobs they do, not two API modes.
 */
export function StockBulkBar({ rows, selected, clear, reasons }: Props) {
    const t = useT();

    type Action = 'count_to' | 'receive' | 'set_threshold' | 'move_bucket';

    const [action, setAction] = useState<Action | null>(null);
    const [quantity, setQuantity] = useState('');
    const [bucket, setBucket] = useState<'express' | 'market'>('express');
    const [toBucket, setToBucket] = useState<'express' | 'market'>('market');
    const [reason, setReason] = useState(reasons[0]?.value ?? 'adjustment');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const ids = selected.map((id) => Number(id));
    const chosen = rows.filter((row) => ids.includes(row.id));
    const amount = quantity === '' ? null : Number(quantity);

    /** What this row's bucket holds today, for the preview. */
    const current = (row: BulkRow) => (bucket === 'express' ? row.express : row.market);

    /**
     * The number the row WILL hold. Computed here so the preview cannot disagree with the button
     * beside it — the same reason the low-stock badge is now selected from the filter's own
     * expression rather than re-derived.
     */
    const resulting = (row: BulkRow): number | null => {
        if (amount === null) {
            return null;
        }
        if (action === 'count_to') {
            return Math.max(0, amount);
        }
        if (action === 'receive') {
            return Math.max(0, current(row) + amount);
        }
        if (action === 'move_bucket') {
            return Math.max(0, current(row) - Math.abs(amount));
        }
        return null;
    };

    const submit = () => {
        if (action === null || amount === null) {
            return;
        }
        setBusy(true);
        router.post(
            '/manage/inventory/bulk',
            {
                action,
                ids,
                quantity: amount,
                bucket,
                to_bucket: action === 'move_bucket' ? toBucket : null,
                reason: action === 'set_threshold' ? null : reason,
                note: note === '' ? null : note,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAction(null);
                    setQuantity('');
                    setNote('');
                    clear();
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const count = ids.length;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">
                    {t('inventory.bulk_selected', ':count محددًا').replace(':count', String(count))}
                </span>

                {/* Four actions, four buttons. The two that move stock are deliberately not
                    one control with a mode: see the docblock. */}
                <Button
                    variant={action === 'count_to' ? 'default' : 'outline'}
                    onClick={() => setAction(action === 'count_to' ? null : 'count_to')}
                >
                    <ClipboardCheck className="me-1.5 h-4 w-4" aria-hidden="true" />
                    {t('inventory.bulk_count_to', 'جرد: عيّن الكمية')}
                </Button>
                <Button
                    variant={action === 'receive' ? 'default' : 'outline'}
                    onClick={() => setAction(action === 'receive' ? null : 'receive')}
                >
                    <PackagePlus className="me-1.5 h-4 w-4" aria-hidden="true" />
                    {t('inventory.bulk_receive', 'توريد: أضف أو اخصم')}
                </Button>
                <Button
                    variant={action === 'move_bucket' ? 'default' : 'outline'}
                    onClick={() => setAction(action === 'move_bucket' ? null : 'move_bucket')}
                >
                    <MoveRight className="me-1.5 h-4 w-4" aria-hidden="true" />
                    {t('inventory.bulk_move', 'نقل بين المخازن')}
                </Button>
                <Button
                    variant={action === 'set_threshold' ? 'default' : 'outline'}
                    onClick={() => setAction(action === 'set_threshold' ? null : 'set_threshold')}
                >
                    <Boxes className="me-1.5 h-4 w-4" aria-hidden="true" />
                    {t('inventory.bulk_threshold', 'حد التنبيه')}
                </Button>
            </div>

            {action === null ? null : (
                <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
                    {/* One sentence saying what this action DOES, in the words of the job. The
                        two stock actions read differently on purpose. */}
                    <p className="text-sm">
                        {action === 'count_to'
                            ? t(
                                  'inventory.bulk_count_to_hint',
                                  'عددت الرف: الكمية الجديدة تحل محل الموجود. استخدمه بعد الجرد.',
                              )
                            : action === 'receive'
                              ? t(
                                    'inventory.bulk_receive_hint',
                                    'وصلت شحنة: الكمية تُضاف إلى الموجود. اكتب رقمًا سالبًا للخصم.',
                                )
                              : action === 'move_bucket'
                                ? t(
                                      'inventory.bulk_move_hint',
                                      'نقل كمية من مخزن إلى الآخر. الإجمالي لا يتغيّر، والسجل يقيّد حركتين.',
                                  )
                                : t(
                                      'inventory.bulk_threshold_hint',
                                      'حد التنبيه فقط — ليست حركة مخزون، ولا يُقيَّد في السجل.',
                                  )}
                    </p>

                    <div className="flex flex-wrap items-end gap-3">
                        {action !== 'set_threshold' ? (
                            <label className="space-y-1 text-sm">
                                <span>
                                    {action === 'move_bucket'
                                        ? t('inventory.bulk_from', 'من مخزن')
                                        : t('inventory.bucket', 'المخزن')}
                                </span>
                                <Select
                                    id="bulk-bucket"
                                    value={bucket}
                                    onChange={(event) =>
                                        setBucket(event.target.value === 'market' ? 'market' : 'express')
                                    }
                                >
                                    <option value="express">{t('common.stock_express', 'إكسبريس')}</option>
                                    <option value="market">{t('common.stock_market', 'ماركت')}</option>
                                </Select>
                            </label>
                        ) : null}

                        {action === 'move_bucket' ? (
                            <label className="space-y-1 text-sm">
                                <span>{t('inventory.bulk_to', 'إلى مخزن')}</span>
                                <Select
                                    id="bulk-to-bucket"
                                    value={toBucket}
                                    onChange={(event) =>
                                        setToBucket(event.target.value === 'express' ? 'express' : 'market')
                                    }
                                >
                                    <option value="express">{t('common.stock_express', 'إكسبريس')}</option>
                                    <option value="market">{t('common.stock_market', 'ماركت')}</option>
                                </Select>
                            </label>
                        ) : null}

                        <label className="space-y-1 text-sm">
                            <span>
                                {action === 'count_to'
                                    ? t('inventory.bulk_counted', 'الكمية المعدودة')
                                    : action === 'receive'
                                      ? t('inventory.bulk_delta', 'الوارد (أو سالب للخصم)')
                                      : action === 'move_bucket'
                                        ? t('inventory.bulk_move_qty', 'الكمية المنقولة')
                                        : t('inventory.threshold', 'حد التنبيه')}
                            </span>
                            <Input
                                id="bulk-quantity"
                                type="number"
                                dir="ltr"
                                className="w-36"
                                value={quantity}
                                onChange={(event) => setQuantity(event.target.value)}
                            />
                        </label>

                        {action !== 'set_threshold' ? (
                            <label className="space-y-1 text-sm">
                                <span>{t('inventory.reason', 'السبب')}</span>
                                <Select id="bulk-reason" value={reason} onChange={(event) => setReason(event.target.value)}>
                                    {reasons.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                        ) : null}

                        {action !== 'set_threshold' ? (
                            <label className="space-y-1 text-sm">
                                <span>{t('inventory.note', 'ملاحظة')}</span>
                                <Input
                                    id="bulk-note"
                                    className="w-52"
                                    value={note}
                                    onChange={(event) => setNote(event.target.value)}
                                />
                            </label>
                        ) : null}
                    </div>

                    {/* THE PREVIEW — the result on real rows, before anything is written. This is
                        what makes "count to 5" and "receive 5" visibly different rather than only
                        differently worded. */}
                    {amount === null || action === 'set_threshold' ? null : (
                        <div className="rounded-md border bg-background p-3 text-sm">
                            <div className="mb-1.5 text-xs font-medium text-muted-foreground">
                                {t('inventory.bulk_preview', 'النتيجة قبل التنفيذ')}
                            </div>
                            <ul className="space-y-0.5">
                                {chosen.slice(0, PREVIEW_ROWS).map((row) => (
                                    <li key={row.id} className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono text-xs" dir="ltr">
                                            {row.wa_code}
                                        </span>
                                        <span className="tabular-nums" dir="ltr">
                                            {current(row)} → {resulting(row)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {chosen.length > PREVIEW_ROWS ? (
                                <div className="mt-1.5 text-xs text-muted-foreground">
                                    {t('inventory.bulk_preview_more', '…و :count منتجًا آخر').replace(
                                        ':count',
                                        String(chosen.length - PREVIEW_ROWS),
                                    )}
                                </div>
                            ) : null}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <Button onClick={submit} disabled={busy || amount === null || count === 0}>
                            {busy
                                ? t('common.saving', 'جارٍ الحفظ…')
                                : t('inventory.bulk_apply', 'نفّذ على المحدد')}
                        </Button>
                        <Button variant="outline" onClick={() => setAction(null)}>
                            {t('common.cancel', 'إلغاء')}
                        </Button>
                        {/* A product with sizes is adjusted per size and will be skipped, by name —
                            said here so it is not a surprise in the result message. */}
                        <span className="text-xs text-muted-foreground">
                            {t(
                                'inventory.bulk_variants_note',
                                'المنتجات ذات المقاسات تُترك ويُذكر كودها، لأن مخزونها يُدار لكل مقاس.',
                            )}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
