import { router } from '@inertiajs/react';
import { Lock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ConfirmAction } from '@/components/manage/ConfirmAction';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { PreSwitchState } from '@/types';

export interface VariantRow {
    id: number;
    sku: string | null;
    label: string;
    color_id: number | null;
    size_id: number | null;
    price_delta: string;
    stock_express: number;
    stock_market: number;
    is_active: boolean;
    sort: number;
    order_lines: number;
    /**
     * Ledger movements on this row. Non-zero means it can never be deleted, only deactivated:
     * `inventory_movements.variant_id` is ON DELETE SET NULL, so a delete would RE-LEVEL the
     * history onto the product and break the reconciliation (see VariantWriter).
     */
    movements: number;
    may_delete: boolean;
    delete_blocked_reason: string | null;
}

export interface VariantState {
    has_variants: boolean;
    may_convert: boolean;
    reason: string;
    write_switch_completed: boolean;
    legacy_backed: boolean;
}

type Draft = {
    label: string;
    sku: string;
    color_id: string;
    size_id: string;
    price_delta: string;
    stock_express: string;
    stock_market: string;
    is_active: boolean;
};

const EMPTY: Draft = { label: '', sku: '', color_id: '', size_id: '', price_delta: '0', stock_express: '0', stock_market: '0', is_active: true };

/**
 * The variants panel (scope item 3) — inside the product form, bounded by wave 3.5's rules.
 *
 * Each row posts on its own, and that is not a style choice: **a quantity is a ledger event.**
 * Folding stock into the product's own save would let a failed title validation roll back a
 * movement, or a re-submitted form replay one. `InventoryService::set()` writes the movement, and
 * the product form never owns a number of units.
 *
 * ── The four refusals this panel has to SHOW, not hide ───────────────────────────────────────
 *
 *  1. **Conversion before the write-switch.** A live, legacy-backed product may not gain its
 *     first variant until core is the only writer of a stock column (AGENTS §3). The "add" form is
 *     replaced by the reason, and the server refuses again on POST — hiding a control is never the
 *     control.
 *  2. **Delete with an order line.** `order_items.variant_id` points at the row; deleting it would
 *     orphan a sold line and make a cancellation unable to return its units.
 *  3. **Delete with units left.** The ledger's sum per variant must equal the column, so a row
 *     holding stock is zeroed through the field (which records the movement) first.
 *  4. **Delete with a ledger HISTORY.** `inventory_movements.variant_id` is ON DELETE SET NULL, so
 *     deleting such a row does not erase its movements — it strips their LEVEL and turns them into
 *     product-level rows on a product that has variants, which the reconciliation then reports
 *     forever. Those rows are deactivated, never deleted.
 *
 * Each disabled button carries its own reason, and the server answers with the same sentence.
 *
 * `is_active` is a plain toggle here, and it is the one control on the screen whose side effect is
 * invisible: flipping it moves no units and writes no ledger row, but it does change whether the
 * PRODUCT counts as in stock (`in_stock` means "some ACTIVE variant has stock"). The server calls
 * `recomputeInStock()` on every change for exactly that reason.
 */
export function VariantsPanel({
    productId,
    rows,
    state,
    preSwitch,
    colors,
    sizes,
    error,
}: {
    productId: number | null;
    rows: VariantRow[];
    state: VariantState;
    /** The broader pre-switch block: no variant ROW may be created at all before the switch. */
    preSwitch: PreSwitchState;
    colors: Array<{ value: string; label: string }>;
    sizes: Array<{ value: string; label: string }>;
    error?: string | null;
}) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [edits, setEdits] = useState<Record<number, Partial<VariantRow>>>({});

    const base = productId === null ? '' : `/manage/products/${productId}/variants`;

    const submitNew = () => {
        router.post(
            base,
            {
                label: draft.label,
                sku: draft.sku === '' ? null : draft.sku,
                color_id: draft.color_id === '' ? null : draft.color_id,
                size_id: draft.size_id === '' ? null : draft.size_id,
                price_delta: draft.price_delta === '' ? 0 : draft.price_delta,
                is_active: draft.is_active,
                stock_express: draft.stock_express === '' ? null : draft.stock_express,
                stock_market: draft.stock_market === '' ? null : draft.stock_market,
            },
            { preserveScroll: true, onSuccess: () => setDraft(EMPTY) },
        );
    };

    const saveRow = (row: VariantRow) => {
        const patch = edits[row.id] ?? {};
        const merged = { ...row, ...patch };
        router.put(
            `${base}/${row.id}`,
            {
                label: merged.label,
                sku: merged.sku,
                color_id: merged.color_id,
                size_id: merged.size_id,
                price_delta: merged.price_delta,
                is_active: merged.is_active,
                sort: merged.sort,
                stock_express: merged.stock_express,
                stock_market: merged.stock_market,
            },
            { preserveScroll: true, onSuccess: () => setEdits((current) => ({ ...current, [row.id]: {} })) },
        );
    };

    const patch = (id: number, key: keyof VariantRow, value: string | number | boolean) =>
        setEdits((current) => ({ ...current, [id]: { ...(current[id] ?? {}), [key]: value } }));

    const valueOf = (row: VariantRow, key: keyof VariantRow) => {
        const patched = edits[row.id]?.[key];

        return patched === undefined ? row[key] : patched;
    };

    const dirty = (id: number) => Object.keys(edits[id] ?? {}).length > 0;

    return (
        <Card>
            <CardHeader className="gap-2">
                <CardTitle>المقاسات والألوان (المخزون لكل صف)</CardTitle>
                <p className="text-xs text-muted-foreground">
                    كل كمية تُسجَّل في دفتر الحركات عبر <code dir="ltr">InventoryService</code> — لا يُكتب عمود مخزون مباشرة من أي شاشة. المنتج الذي له
                    صفوف هنا يصبح مخزونه محسوبًا من الصفوف، وحالة «متوفر» تعني أن صفًا <strong>مفعّلًا</strong> به كمية.
                </p>
            </CardHeader>

            <CardContent className="space-y-4">
                {error ? (
                    <Alert tone="error" title="تعذّر تنفيذ العملية">
                        {error}
                    </Alert>
                ) : null}

                {productId === null ? (
                    <Alert tone="info" title="احفظ المنتج أولًا">
                        تُضاف المقاسات والألوان بعد حفظ المنتج، لأن كل صف يحمل مخزونه الخاص في دفتر الحركات.
                    </Alert>
                ) : null}

                {rows.length > 0 ? (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>الاسم</TableHead>
                                    <TableHead className="hidden sm:table-cell">SKU</TableHead>
                                    <TableHead>اللون</TableHead>
                                    <TableHead>المقاس</TableHead>
                                    <TableHead>فرق السعر</TableHead>
                                    <TableHead>Express</TableHead>
                                    <TableHead>Market</TableHead>
                                    <TableHead>مفعّل</TableHead>
                                    <TableHead className="text-end">إجراءات</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <Input
                                                aria-label={`اسم الصف ${row.id}`}
                                                className="min-w-[8rem]"
                                                value={String(valueOf(row, 'label') ?? '')}
                                                onChange={(event) => patch(row.id, 'label', event.target.value)}
                                            />
                                            {row.order_lines > 0 ? (
                                                <Badge variant="outline" className="mt-1">
                                                    {row.order_lines} سطر طلب
                                                </Badge>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <Input
                                                dir="ltr"
                                                aria-label={`SKU للصف ${row.id}`}
                                                className="min-w-[7rem]"
                                                value={String(valueOf(row, 'sku') ?? '')}
                                                onChange={(event) => patch(row.id, 'sku', event.target.value)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                aria-label={`لون الصف ${row.id}`}
                                                className="min-w-[7rem]"
                                                value={String(valueOf(row, 'color_id') ?? '')}
                                                onChange={(event) => patch(row.id, 'color_id', event.target.value)}
                                            >
                                                <option value="">—</option>
                                                {colors.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                aria-label={`مقاس الصف ${row.id}`}
                                                className="min-w-[7rem]"
                                                value={String(valueOf(row, 'size_id') ?? '')}
                                                onChange={(event) => patch(row.id, 'size_id', event.target.value)}
                                            >
                                                <option value="">—</option>
                                                {sizes.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                type="number"
                                                step="0.01"
                                                aria-label={`فرق سعر الصف ${row.id}`}
                                                className="w-24"
                                                value={String(valueOf(row, 'price_delta') ?? '0')}
                                                onChange={(event) => patch(row.id, 'price_delta', event.target.value)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                type="number"
                                                min={0}
                                                aria-label={`كمية Express للصف ${row.id}`}
                                                className="w-20"
                                                value={String(valueOf(row, 'stock_express') ?? 0)}
                                                onChange={(event) => patch(row.id, 'stock_express', event.target.value)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                type="number"
                                                min={0}
                                                aria-label={`كمية Market للصف ${row.id}`}
                                                className="w-20"
                                                value={String(valueOf(row, 'stock_market') ?? 0)}
                                                onChange={(event) => patch(row.id, 'stock_market', event.target.value)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Switch
                                                aria-label={`تفعيل الصف ${row.id}`}
                                                checked={valueOf(row, 'is_active') === true}
                                                onCheckedChange={(checked) => patch(row.id, 'is_active', checked)}
                                            />
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button type="button" size="sm" variant={dirty(row.id) ? 'default' : 'outline'} disabled={!dirty(row.id)} onClick={() => saveRow(row)}>
                                                    حفظ
                                                </Button>
                                                <ConfirmAction
                                                    title="حذف صف المقاس/اللون"
                                                    consequence={
                                                        <>
                                                            <p>
                                                                هذا الصف بلا أي تاريخ: لا طلبات تشير إليه، ولا وحدات في المخزون، ولا حركات في سجل
                                                                المخزون. لذلك يمكن حذفه نهائيًا.
                                                            </p>
                                                            <p className="mt-2">
                                                                الصف الذي له تاريخ لا يُحذف أبدًا — يُعطَّل — لأن الحذف لا يمسح حركات مخزونه بل ينقلها إلى
                                                                المنتج نفسه ويفسد أرقامه للأبد.
                                                            </p>
                                                        </>
                                                    }
                                                    confirmLabel="احذف الصف"
                                                    disabled={!row.may_delete}
                                                    onConfirm={() => router.delete(`${base}/${row.id}`, { preserveScroll: true })}
                                                    trigger={
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="ghost"
                                                            className="text-destructive"
                                                            disabled={!row.may_delete}
                                                            title={row.delete_blocked_reason ?? 'حذف الصف'}
                                                            aria-label={
                                                                row.delete_blocked_reason === null
                                                                    ? `حذف الصف ${row.id}`
                                                                    : `لا يمكن حذف الصف ${row.id}: ${row.delete_blocked_reason}`
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            </div>
                                            {row.delete_blocked_reason === null ? null : (
                                                <p className="mt-1 text-[11px] text-muted-foreground">{row.delete_blocked_reason}</p>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                ) : null}

                {/* The conversion gate. When it refuses, the form is REPLACED by the reason — an
                    enabled control the server would reject is a worse experience than no control,
                    and the reason is the only thing that tells the team what to do instead. */}
                {productId === null ? null : preSwitch.blocked ? (
                    <Alert tone="warning" title="إضافة صفوف موقوفة قبل ليلة التحويل">
                        <p className="flex items-start gap-2">
                            <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>{preSwitch.message}</span>
                        </p>
                    </Alert>
                ) : state.may_convert ? (
                    <div className="space-y-3 rounded-lg border border-dashed p-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Input aria-label="اسم الصف الجديد" placeholder="الاسم (مثال: أسود / 42مم)" value={draft.label} onChange={(event) => setDraft({ ...draft, label: event.target.value })} />
                            <Input dir="ltr" aria-label="SKU" placeholder="SKU" value={draft.sku} onChange={(event) => setDraft({ ...draft, sku: event.target.value })} />
                            <Select aria-label="اللون" value={draft.color_id} onChange={(event) => setDraft({ ...draft, color_id: event.target.value })}>
                                <option value="">لون —</option>
                                {colors.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select aria-label="المقاس" value={draft.size_id} onChange={(event) => setDraft({ ...draft, size_id: event.target.value })}>
                                <option value="">مقاس —</option>
                                {sizes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Input dir="ltr" type="number" step="0.01" aria-label="فرق السعر" placeholder="فرق السعر" value={draft.price_delta} onChange={(event) => setDraft({ ...draft, price_delta: event.target.value })} />
                            <Input dir="ltr" type="number" min={0} aria-label="كمية Express" placeholder="Express" value={draft.stock_express} onChange={(event) => setDraft({ ...draft, stock_express: event.target.value })} />
                            <Input dir="ltr" type="number" min={0} aria-label="كمية Market" placeholder="Market" value={draft.stock_market} onChange={(event) => setDraft({ ...draft, stock_market: event.target.value })} />
                            <Button type="button" className="gap-1.5" disabled={draft.label.trim() === ''} onClick={submitNew}>
                                <Plus className="h-4 w-4" />
                                أضف صفًا
                            </Button>
                        </div>
                        {state.has_variants ? null : (
                            <p className="text-xs text-muted-foreground">
                                إضافة أول صف تُحوِّل المنتج ليُدار مخزونه بالمقاسات: أعمدة المنتج تصبح مجموعًا محسوبًا لصفوفه. {state.reason}
                            </p>
                        )}
                    </div>
                ) : (
                    <Alert tone="warning" title="التحويل إلى مقاسات/ألوان ممنوع الآن">
                        <p className="flex items-start gap-2">
                            <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>{state.reason}</span>
                        </p>
                    </Alert>
                )}
            </CardContent>
        </Card>
    );
}
