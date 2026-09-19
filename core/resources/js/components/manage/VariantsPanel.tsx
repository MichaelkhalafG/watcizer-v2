import { router } from "@inertiajs/react";
import { Lock, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { SelectField, TextField } from "@/components/form/TextField";
import { Input, Select } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import type { PreSwitchState } from "@/types";
import { Num } from "@/components/ui/bidi";
import { bucketLabel } from "@/lib/labels";
import { useT } from "@/lib/i18n";

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

const EMPTY: Draft = {
    label: "",
    sku: "",
    color_id: "",
    size_id: "",
    price_delta: "0",
    stock_express: "0",
    stock_market: "0",
    is_active: true,
};

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
/**
 * What adding the first row does — SHOWN, not described (item 2, 2026-09-19).
 *
 * "The product's stock moves down to the rows" is a sentence you can only parse if you already
 * know what it means. The picture below says it in one glance: one box with a number becomes two
 * boxes with their own numbers, and the first box goes quiet.
 *
 * Rendered only while the product has no rows, because that is the only moment this is a decision
 * anybody is making. After the first row exists, the reader needs one line and not a lesson.
 */
function FirstRowExplainer() {
    const t = useT();

    return (
        <div className="rounded-lg border bg-muted/30 p-4">
            <p className="text-sm font-medium">
                {t('variants.what_changes', 'ماذا يتغيّر لو أضفت أول سطر؟')}
            </p>

            {/* The before/after. Two small boxes and an arrow — no vocabulary at all. */}
            <div className="mt-3 flex flex-wrap items-center gap-3 text-xs">
                <div className="rounded-md border bg-background px-3 py-2">
                    <p className="text-muted-foreground">{t('variants.before', 'الآن')}</p>
                    <p className="mt-1 font-medium">{t('variants.the_product', 'المنتج')}</p>
                    <p className="text-muted-foreground">
                        {t('variants.quantity_is', 'الكمية: ')}
                        <Num>12</Num>
                    </p>
                </div>

                <span aria-hidden="true" className="text-lg text-muted-foreground rtl:rotate-180">
                    →
                </span>

                <div className="rounded-md border bg-background px-3 py-2">
                    <p className="text-muted-foreground">{t('variants.after', 'بعد إضافة سطرين')}</p>
                    <p className="mt-1 font-medium">
                        {t('variants.example_black', 'سوار أسود')} —{' '}
                        <Num>7</Num>
                    </p>
                    <p className="font-medium">
                        {t('variants.example_brown', 'سوار بني')} — <Num>5</Num>
                    </p>
                </div>
            </div>

            <ul className="mt-3 space-y-1.5 text-sm text-muted-foreground">
                <li>{t('variants.change_stock', 'الكمية تصبح لكل سطر على حدة، ومجموعها هو كمية المنتج.')}</li>
                <li>{t('variants.change_locked', 'خانتا الكمية في قسم السعر تُقفَلان، لأن الرقم لم يعد للمنتج بل للسطور.')}</li>
                <li>{t('variants.change_in_stock', '«متوفر» تعني أن سطرًا واحدًا على الأقل مُشغَّلًا وبه كمية.')}</li>
                <li>{t('variants.change_undo', 'غيّرت رأيك؟ احذف السطور وترجع الكمية إلى المنتج. سطر بِيع منه لا يُحذف — أوقِفه بدل حذفه.')}</li>
            </ul>
        </div>
    );
}

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
    const t = useT();
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [edits, setEdits] = useState<Record<number, Partial<VariantRow>>>({});

    const base =
        productId === null ? "" : `/manage/products/${productId}/variants`;

    const submitNew = () => {
        router.post(
            base,
            {
                label: draft.label,
                sku: draft.sku === "" ? null : draft.sku,
                color_id: draft.color_id === "" ? null : draft.color_id,
                size_id: draft.size_id === "" ? null : draft.size_id,
                price_delta: draft.price_delta === "" ? 0 : draft.price_delta,
                is_active: draft.is_active,
                stock_express:
                    draft.stock_express === "" ? null : draft.stock_express,
                stock_market:
                    draft.stock_market === "" ? null : draft.stock_market,
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
            {
                preserveScroll: true,
                onSuccess: () =>
                    setEdits((current) => ({ ...current, [row.id]: {} })),
            },
        );
    };

    const patch = (
        id: number,
        key: keyof VariantRow,
        value: string | number | boolean,
    ) =>
        setEdits((current) => ({
            ...current,
            [id]: { ...(current[id] ?? {}), [key]: value },
        }));

    const valueOf = (row: VariantRow, key: keyof VariantRow) => {
        const patched = edits[row.id]?.[key];

        return patched === undefined ? row[key] : patched;
    };

    const dirty = (id: number) => Object.keys(edits[id] ?? {}).length > 0;

    return (
        <Card>
            {/* ── Rewritten for somebody who has never heard the word "variant" ───────────────
                   (item 2, second browser pass, 2026-09-19)

                The old header was one 200-character sentence containing "دفتر الحركات", "عمود
                مخزون" and a conditional definition of «متوفر». The developer read it twice and
                could not follow it, and said the data-entry team has no chance. They are right:
                it was written by somebody who already knew the answer.

                What replaced it, in order of what the reader needs:

                  1. what a row IS — with an example, because "variant" is not a word anybody uses
                     about a watch;
                  2. what happens to the product's own quantity when the first row appears — SHOWN
                     as a before/after rather than described, because "stock moves down a level"
                     is a sentence you can only understand if you already understand it;
                  3. why the quantity boxes on the price tab stop accepting input;
                  4. how to undo it.

                Points 2-4 only render while there are NO rows yet, which is the only moment they
                are a decision. Once the rows exist the reader needs one line, not a lesson. */}
            <CardHeader className="gap-2">
                <CardTitle>{t("variants.tab", "المقاسات والألوان")}</CardTitle>
                <p className="text-sm text-muted-foreground">
                    {t(
                        "variants.lead",
                        "لو المنتج يأتي بأكثر من مقاس أو لون، اكتب لكل واحد سطرًا هنا. مثال: ساعة بسوار أسود وأخرى بسوار بني — سطران.",
                    )}
                </p>
            </CardHeader>

            <CardContent className="space-y-4">
                {error ? (
                    <Alert
                        tone="error"
                        title={t("common.action_failed", "تعذّر تنفيذ العملية")}
                    >
                        {error}
                    </Alert>
                ) : null}

                {productId === null ? (
                    <Alert
                        tone="info"
                        title={t(
                            "variants.save_product_first_title",
                            "احفظ المنتج أولًا",
                        )}
                    >
                        {t(
                            "variants.save_product_first_body",
                            "احفظ المنتج، ثم ارجع إلى هنا لإضافة مقاساته وألوانه.",
                        )}
                    </Alert>
                ) : null}

                {/* The whole decision, once, at the only moment it is a decision. */}
                {productId !== null && rows.length === 0 ? <FirstRowExplainer /> : null}

                {rows.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "variants.now_per_row",
                            "كمية هذا المنتج صارت مجموع كميات السطور تحت. عدّل الرقم في سطره، وكل تغيير يُسجَّل باسمك.",
                        )}
                    </p>
                ) : null}

                {rows.length > 0 ? (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t("common.name", "الاسم")}
                                    </TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        SKU
                                    </TableHead>
                                    <TableHead>
                                        {t("variants.colour", "اللون")}
                                    </TableHead>
                                    <TableHead>
                                        {t("variants.size", "المقاس")}
                                    </TableHead>
                                    <TableHead>
                                        {t("variants.price_delta", "فرق السعر")}
                                    </TableHead>
                                    {/* Item 10: the bucket NAMES, not the stored tokens.
                                        The same two words the ledger has always used. */}
                                    <TableHead>{bucketLabel(t, 'express')}</TableHead>
                                    <TableHead>{bucketLabel(t, 'market')}</TableHead>
                                    <TableHead>
                                        {t("common.active", "مفعّل")}
                                    </TableHead>
                                    <TableHead className="text-end">
                                        {t("common.actions", "إجراءات")}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <Input
                                                aria-label={t(
                                                    "variants.row_name_aria",
                                                    "اسم الصف :id",
                                                    { id: row.id },
                                                )}
                                                className="min-w-[8rem]"
                                                value={String(
                                                    valueOf(row, "label") ?? "",
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "label",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            {row.order_lines > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="mt-1"
                                                >
                                                    {t(
                                                        "variants.order_lines_count",
                                                        ":count سطر طلب",
                                                        {
                                                            count: row.order_lines,
                                                        },
                                                    )}
                                                </Badge>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <Input
                                                dir="ltr"
                                                aria-label={t(
                                                    "variants.row_sku_aria",
                                                    "SKU للصف :id",
                                                    { id: row.id },
                                                )}
                                                className="min-w-[7rem]"
                                                value={String(
                                                    valueOf(row, "sku") ?? "",
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "sku",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                aria-label={t(
                                                    "variants.row_colour_aria",
                                                    "لون الصف :id",
                                                    { id: row.id },
                                                )}
                                                className="min-w-[7rem]"
                                                value={String(
                                                    valueOf(row, "color_id") ??
                                                        "",
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "color_id",
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">—</option>
                                                {colors.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                aria-label={t(
                                                    "variants.row_size_aria",
                                                    "مقاس الصف :id",
                                                    { id: row.id },
                                                )}
                                                className="min-w-[7rem]"
                                                value={String(
                                                    valueOf(row, "size_id") ??
                                                        "",
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "size_id",
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">—</option>
                                                {sizes.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
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
                                                aria-label={t(
                                                    "variants.row_price_delta_aria",
                                                    "فرق سعر الصف :id",
                                                    { id: row.id },
                                                )}
                                                className="w-24"
                                                value={String(
                                                    valueOf(
                                                        row,
                                                        "price_delta",
                                                    ) ?? "0",
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "price_delta",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                type="number"
                                                min={0}
                                                aria-label={t(
                                                    "variants.row_express_aria",
                                                    "كمية Express للصف :id",
                                                    { id: row.id },
                                                )}
                                                className="w-20"
                                                value={String(
                                                    valueOf(
                                                        row,
                                                        "stock_express",
                                                    ) ?? 0,
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "stock_express",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                dir="ltr"
                                                type="number"
                                                min={0}
                                                aria-label={t(
                                                    "variants.row_market_aria",
                                                    "كمية Market للصف :id",
                                                    { id: row.id },
                                                )}
                                                className="w-20"
                                                value={String(
                                                    valueOf(
                                                        row,
                                                        "stock_market",
                                                    ) ?? 0,
                                                )}
                                                onChange={(event) =>
                                                    patch(
                                                        row.id,
                                                        "stock_market",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Switch
                                                aria-label={t(
                                                    "variants.row_active_aria",
                                                    "تفعيل الصف :id",
                                                    { id: row.id },
                                                )}
                                                checked={
                                                    valueOf(
                                                        row,
                                                        "is_active",
                                                    ) === true
                                                }
                                                onCheckedChange={(checked) =>
                                                    patch(
                                                        row.id,
                                                        "is_active",
                                                        checked,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        dirty(row.id)
                                                            ? "default"
                                                            : "outline"
                                                    }
                                                    disabled={!dirty(row.id)}
                                                    onClick={() => saveRow(row)}
                                                >
                                                    {t("common.save", "حفظ")}
                                                </Button>
                                                <ConfirmAction
                                                    title={t(
                                                        "variants.delete_title",
                                                        "حذف صف المقاس/اللون",
                                                    )}
                                                    consequence={
                                                        <>
                                                            {/* Two facts, each in one line. The
                                                                second used to explain HOW the
                                                                data would be corrupted, which is
                                                                our problem, not the reader's —
                                                                theirs is "can I press this, and
                                                                what do I do instead". */}
                                                            <p>
                                                                {t(
                                                                    "variants.delete_consequence_clean",
                                                                    "هذا السطر لم يُبَع منه شيء ولا يحمل أي كمية، فيمكن حذفه.",
                                                                )}
                                                            </p>
                                                            <p className="mt-2">
                                                                {t(
                                                                    "variants.delete_consequence_history",
                                                                    "السطر الذي بِيع منه أو تحرّكت كميته لا يُحذف أبدًا. أوقِفه: يختفي من المتجر وتتوقف كميته عن الحساب، ويبقى تاريخه سليمًا.",
                                                                )}
                                                            </p>
                                                        </>
                                                    }
                                                    confirmLabel={t(
                                                        "variants.delete_confirm",
                                                        "احذف الصف",
                                                    )}
                                                    disabled={!row.may_delete}
                                                    onConfirm={() =>
                                                        router.delete(
                                                            `${base}/${row.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    trigger={
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="ghost"
                                                            className="text-destructive"
                                                            disabled={
                                                                !row.may_delete
                                                            }
                                                            title={
                                                                row.delete_blocked_reason ??
                                                                t(
                                                                    "variants.delete_row",
                                                                    "حذف الصف",
                                                                )
                                                            }
                                                            aria-label={
                                                                row.delete_blocked_reason ===
                                                                null
                                                                    ? t(
                                                                          "variants.delete_row_aria",
                                                                          "حذف الصف :id",
                                                                          {
                                                                              id: row.id,
                                                                          },
                                                                      )
                                                                    : t(
                                                                          "variants.delete_row_blocked_aria",
                                                                          "لا يمكن حذف الصف :id: :reason",
                                                                          {
                                                                              id: row.id,
                                                                              reason: row.delete_blocked_reason,
                                                                          },
                                                                      )
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            </div>
                                            {row.delete_blocked_reason ===
                                            null ? null : (
                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                    {row.delete_blocked_reason}
                                                </p>
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
                {/* Item 5: a caveat renders in the same place as the refusal did, because the
                    operator needs the same fact either way — only the verb changes. */}
                {productId === null ? null : preSwitch.blocked ||
                  preSwitch.caveat !== null ? (
                    <Alert
                        tone="warning"
                        title={t(
                            "variants.add_blocked_pre_switch",
                            "إضافة الصفوف موقوفة حاليًا",
                        )}
                    >
                        <p className="flex items-start gap-2">
                            <Lock
                                className="mt-0.5 h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                {preSwitch.blocked
                                    ? preSwitch.message
                                    : preSwitch.caveat}
                            </span>
                        </p>
                    </Alert>
                ) : state.may_convert ? (
                    <div className="space-y-3 rounded-lg border border-dashed p-4">
                        {/* ── Every box here says what it is (item 3, 2026-10-05) ───────────

                            Reported by the developer: *"I looked at the row and could not tell
                            what the three numeric boxes are. The four fields above them have
                            labels; the three zeros below have nothing."*

                            They were right, and the cause is worth stating because it is a whole
                            class of bug rather than one screen: every control here was labelled
                            with a PLACEHOLDER. A placeholder is not a label — it is the text a box
                            shows **while it is empty**, and these three start at `0`, so their
                            placeholders were never once visible to anybody. The four above them
                            start empty, which is the only reason they looked labelled.

                            Now they carry real `<label>` elements through the same `Field` wrapper
                            the product form uses, plus a line saying what the number MEANS —
                            because «فرق السعر» names the box without answering the question
                            somebody is actually asking, which is what happens if they leave it
                            alone. */}
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <TextField
                                label={t("variants.new_row_name", "اسم الصف")}
                                required
                                hint={t(
                                    "variants.new_row_name_hint",
                                    "ما يراه العميل ويختار به، مثل «أسود» أو «42 مم».",
                                )}
                                value={draft.label}
                                onChange={(value) =>
                                    setDraft({ ...draft, label: value })
                                }
                            />
                            <TextField
                                label={t("variants.new_row_sku", "كود الصف (SKU)")}
                                dir="ltr"
                                hint={t(
                                    "variants.new_row_sku_hint",
                                    "اختياري، وكودنا نحن لا كود المورّد. لو كتبته فلا يتكرر بين الصفوف.",
                                )}
                                value={draft.sku}
                                onChange={(value) =>
                                    setDraft({ ...draft, sku: value })
                                }
                            />
                            <SelectField
                                label={t("variants.colour", "اللون")}
                                placeholder={t("variants.colour_none", "لون —")}
                                hint={t(
                                    "variants.new_row_colour_hint",
                                    "اتركه فارغًا لو هذا الصف لا يختلف باللون.",
                                )}
                                options={colors}
                                value={draft.color_id}
                                onChange={(value) =>
                                    setDraft({ ...draft, color_id: value })
                                }
                            />
                            <SelectField
                                label={t("variants.size", "المقاس")}
                                placeholder={t("variants.size_none", "مقاس —")}
                                hint={t(
                                    "variants.new_row_size_hint",
                                    "اتركه فارغًا لو هذا الصف لا يختلف بالمقاس.",
                                )}
                                options={sizes}
                                value={draft.size_id}
                                onChange={(value) =>
                                    setDraft({ ...draft, size_id: value })
                                }
                            />
                            <TextField
                                label={t("variants.price_delta", "فرق السعر")}
                                dir="ltr"
                                type="number"
                                step="0.01"
                                hint={t(
                                    "variants.price_delta_hint",
                                    "يُضاف إلى سعر المنتج لهذا الصف وحده — واكتب رقمًا سالبًا ليُخصم. اتركه صفرًا ليُباع بسعر المنتج نفسه.",
                                )}
                                value={draft.price_delta}
                                onChange={(value) =>
                                    setDraft({ ...draft, price_delta: value })
                                }
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <TextField
                                    label={t(
                                        "variants.express_quantity",
                                        "كمية Express",
                                    )}
                                    dir="ltr"
                                    type="number"
                                    min={0}
                                    hint={t(
                                        "variants.express_quantity_hint",
                                        "الكمية الجاهزة للشحن السريع من هذا الصف.",
                                    )}
                                    value={draft.stock_express}
                                    onChange={(value) =>
                                        setDraft({
                                            ...draft,
                                            stock_express: value,
                                        })
                                    }
                                />
                                <TextField
                                    label={t(
                                        "variants.market_quantity",
                                        "كمية Market",
                                    )}
                                    dir="ltr"
                                    type="number"
                                    min={0}
                                    hint={t(
                                        "variants.market_quantity_hint",
                                        "الكمية الموجودة في المعرض من هذا الصف.",
                                    )}
                                    value={draft.stock_market}
                                    onChange={(value) =>
                                        setDraft({
                                            ...draft,
                                            stock_market: value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                className="gap-1.5"
                                disabled={draft.label.trim() === ""}
                                onClick={submitNew}
                            >
                                <Plus className="h-4 w-4" />
                                {t("variants.add_row", "أضف صفًا")}
                            </Button>
                        </div>
                        {state.has_variants ? null : (
                            <p className="text-xs text-muted-foreground">
                                {/* The explainer above already SHOWS what changes; this line is
                                    the reminder at the moment of the click, plus whatever the
                                    server has to say about this particular product. */}
                                {t(
                                    "variants.first_row_converts",
                                    "هذا أول سطر: بعده تصبح الكمية لكل سطر على حدة.",
                                )}{" "}
                                {state.reason}
                            </p>
                        )}
                    </div>
                ) : (
                    <Alert
                        tone="warning"
                        title={t(
                            "variants.conversion_blocked_title",
                            "التحويل إلى مقاسات/ألوان ممنوع الآن",
                        )}
                    >
                        <p className="flex items-start gap-2">
                            <Lock
                                className="mt-0.5 h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{state.reason}</span>
                        </p>
                    </Alert>
                )}
            </CardContent>
        </Card>
    );
}
