import { router } from "@inertiajs/react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogTrigger } from "@/components/ui/dialog";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Ltr } from "@/components/ui/bidi";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useT } from "@/lib/i18n";

/*
 * BULK PRICE CHANGE (M1v, 2026-10-30) — from the products list's selection bar.
 *
 * Three steps, and the server decides each one: choose the change, PREVIEW the old → new price of every product
 * (nothing is written; above the cap the server says how many and asks for a narrower filter), then tick the confirm
 * and APPLY — the server plans the same change again and writes it. The browser never sends a price.
 */

type Mode = "increase_amount" | "decrease_amount" | "increase_percent" | "decrease_percent" | "set";
type Target = "selling" | "sale" | "clear_sale";

interface PlanRow {
    id: number;
    title: string;
    sku: string | null;
    wa_code: string | null;
    old_selling: number;
    old_sale: number | null;
    new_selling: number;
    new_sale: number | null;
    change: boolean;
    note: string | null;
}

interface Preview {
    count: number;
    max: number;
    over_cap: boolean;
    message?: string;
    rows: PlanRow[];
    changing: number;
}

interface Props {
    storefrontId: number;
    /** The selection: the ids on screen, or "every product matching" the screen's query string. */
    selection: { ids: number[] } | { matching: true; query: string };
    count: number;
    max: number;
    onDone: () => void;
}

const money = (value: number | null): string =>
    value === null ? "—" : value.toLocaleString("en-US", { maximumFractionDigits: 2 });

export function BulkPriceDialog({ storefrontId, selection, count, max, onDone }: Props) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<Mode>("increase_percent");
    const [target, setTarget] = useState<Target>("selling");
    const [value, setValue] = useState("");
    const [rounding, setRounding] = useState("0");
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const payload = () => ({
        ...("matching" in selection ? { scope: "matching", query: selection.query } : { ids: selection.ids }),
        mode,
        target,
        value: target === "clear_sale" ? null : value === "" ? null : Number(value),
        rounding: Number(rounding),
    });

    // any change to the request makes the old preview a lie: it is dropped and must be run again
    const reset = () => {
        setPreview(null);
        setConfirmed(false);
        setError(null);
    };

    const runPreview = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(`/manage/storefronts/${storefrontId}/products/bulk-price/preview`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "",
                },
                credentials: "same-origin",
                body: JSON.stringify(payload()),
            });
            if (!response.ok) {
                setError(t("products.bulk_price_check_fields", "راجع نوع التغيير والقيمة ثم أعد المعاينة."));
                setPreview(null);
                return;
            }
            setPreview((await response.json()) as Preview);
        } catch {
            setError(t("products.bulk_price_preview_failed", "تعذّرت المعاينة. حاول مرة أخرى."));
        } finally {
            setBusy(false);
        }
    };

    const apply = () => {
        setBusy(true);
        router.post(
            `/manage/storefronts/${storefrontId}/products/bulk-price`,
            { ...payload(), confirm: "1" },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    reset();
                    onDone();
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                onFinish: () => setBusy(false),
            },
        );
    };

    const overCapBeforePreview = count > max;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    reset();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    {t("products.bulk_price_open", "تغيير الأسعار…")}
                </Button>
            </DialogTrigger>
            <DialogContent
                className="max-w-3xl"
                title={t("products.bulk_price_title", "تغيير أسعار المنتجات المحددة")}
                description={t(
                    "products.bulk_price_description",
                    "الأسعار مشتركة بين المتاجر: التغيير يظهر على كل متجر يعرض المنتج. عاين قبل التطبيق.",
                )}
            >
                <div className="space-y-4">
                    {overCapBeforePreview ? (
                        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                            {t(
                                "products.bulk_price_over_cap_hint",
                                "المحدد :count منتجًا، والحد الأقصى :max في المرة الواحدة. ضيّق التصفية أولًا.",
                                { count, max },
                            )}
                        </p>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-4">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="bp-mode">{t("products.bulk_price_mode", "التغيير")}</Label>
                            <Select
                                id="bp-mode"
                                value={mode}
                                disabled={target === "clear_sale"}
                                onChange={(event) => {
                                    setMode(event.target.value as Mode);
                                    reset();
                                }}
                            >
                                <option value="increase_percent">{t("products.bulk_price_increase_percent", "زيادة بنسبة %")}</option>
                                <option value="decrease_percent">{t("products.bulk_price_decrease_percent", "تخفيض بنسبة %")}</option>
                                <option value="increase_amount">{t("products.bulk_price_increase_amount", "زيادة بمبلغ (ج.م)")}</option>
                                <option value="decrease_amount">{t("products.bulk_price_decrease_amount", "تخفيض بمبلغ (ج.م)")}</option>
                                <option value="set">{t("products.bulk_price_set", "سعر ثابت جديد (ج.م)")}</option>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="bp-value">{t("products.bulk_price_value", "القيمة")}</Label>
                            <Input
                                id="bp-value"
                                type="number"
                                min={0}
                                step="0.01"
                                dir="ltr"
                                disabled={target === "clear_sale"}
                                value={value}
                                onChange={(event) => {
                                    setValue(event.target.value);
                                    reset();
                                }}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="bp-rounding">{t("products.bulk_price_rounding", "التقريب")}</Label>
                            <Select
                                id="bp-rounding"
                                value={rounding}
                                disabled={target === "clear_sale"}
                                onChange={(event) => {
                                    setRounding(event.target.value);
                                    reset();
                                }}
                            >
                                <option value="0">{t("products.bulk_price_round_none", "بدون تقريب")}</option>
                                <option value="5">{t("products.bulk_price_round_to", "لأقرب :step ج.م", { step: 5 })}</option>
                                <option value="10">{t("products.bulk_price_round_to", "لأقرب :step ج.م", { step: 10 })}</option>
                                <option value="50">{t("products.bulk_price_round_to", "لأقرب :step ج.م", { step: 50 })}</option>
                                <option value="100">{t("products.bulk_price_round_to", "لأقرب :step ج.م", { step: 100 })}</option>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="bp-target">{t("products.bulk_price_target", "على أي سعر")}</Label>
                        <Select
                            id="bp-target"
                            value={target}
                            onChange={(event) => {
                                setTarget(event.target.value as Target);
                                reset();
                            }}
                        >
                            <option value="selling">{t("products.bulk_price_target_selling", "السعر الأصلي")}</option>
                            <option value="sale">{t("products.bulk_price_target_sale", "سعر التخفيض (يبدأ من السعر الأصلي إن لم يوجد تخفيض)")}</option>
                            <option value="clear_sale">{t("products.bulk_price_target_clear", "مسح سعر التخفيض")}</option>
                        </Select>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" disabled={busy || (target !== "clear_sale" && value === "")} onClick={runPreview}>
                            {busy && !preview ? t("products.bulk_price_previewing", "جارٍ المعاينة…") : t("products.bulk_price_preview", "معاينة")}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            {t("products.bulk_price_selected", "المحدد: :count منتجًا", { count })}
                        </span>
                    </div>

                    {error ? (
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                    ) : null}

                    {preview?.over_cap ? (
                        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                            {preview.message}
                        </p>
                    ) : null}

                    {preview && !preview.over_cap ? (
                        <>
                            <p className="text-sm">
                                {t("products.bulk_price_summary", "سيتغيّر :changing من :count منتجًا.", {
                                    changing: preview.changing,
                                    count: preview.rows.length,
                                })}
                            </p>
                            <div className="max-h-72 overflow-auto rounded-md border">
                                <Table className="text-sm">
                                    <TableHeader className="sticky top-0 bg-muted/60">
                                        <TableRow>
                                            <TableHead className="p-2">{t("common.name", "الاسم")}</TableHead>
                                            <TableHead className="p-2">{t("products.sku", "رقم الموديل (SKU)")}</TableHead>
                                            <TableHead className="p-2" align="end">{t("products.bulk_price_old", "الحالي")}</TableHead>
                                            <TableHead className="p-2" align="end">{t("products.bulk_price_new", "الجديد")}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {preview.rows.map((row) => (
                                            <TableRow key={row.id} className={row.change ? undefined : "text-muted-foreground"}>
                                                <TableCell className="p-2">
                                                    {row.title}
                                                    {row.note ? <span className="block text-xs text-destructive">{row.note}</span> : null}
                                                </TableCell>
                                                <TableCell className="p-2">
                                                    <Ltr>{row.sku ?? row.wa_code ?? "—"}</Ltr>
                                                </TableCell>
                                                <TableCell className="p-2" align="end">
                                                    <Ltr>
                                                        {money(row.old_selling)}
                                                        {row.old_sale !== null ? ` / ${money(row.old_sale)}` : ""}
                                                    </Ltr>
                                                </TableCell>
                                                <TableCell className="p-2 font-medium" align="end">
                                                    <Ltr>
                                                        {money(row.new_selling)}
                                                        {row.new_sale !== null ? ` / ${money(row.new_sale)}` : ""}
                                                    </Ltr>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t("products.bulk_price_columns_hint", "السعر الأصلي / سعر التخفيض.")}
                            </p>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={confirmed} onCheckedChange={(checked) => setConfirmed(checked === true)} />
                                {t("products.bulk_price_confirm", "راجعت الجدول، وأريد تطبيق هذه الأسعار.")}
                            </label>
                            <Button type="button" disabled={!confirmed || busy || preview.changing === 0} onClick={apply}>
                                {t("products.bulk_price_apply", "طبّق على :count منتجًا", { count: preview.changing })}
                            </Button>
                        </>
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
