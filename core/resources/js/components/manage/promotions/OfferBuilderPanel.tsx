import { X } from "lucide-react";
import { useMemo, useState, type ReactNode } from "react";

import { Combobox } from "@/components/form/Combobox";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input, Select } from "@/components/ui/input";
import { Ltr } from "@/components/ui/bidi";
import { Label } from "@/components/ui/label";
import type { ScopeOptions, ScopeRow } from "@/components/manage/promotions/CouponFields";
import { TEMPLATES, modelOf, sentence, warnings, type FormLike, type FormPatch, type NameOf } from "@/lib/offerBuilder";
import { useLocale, useT } from "@/lib/i18n";

/*
 * THE OFFER BUILDER (Michael's approval of 2026-11-19): five short steps over the SAME form the detailed cards below
 * edit — what, on what, the condition, the limits, where it shows — a template list on top, and the offer said as one
 * plain sentence in Arabic and English under the steps, with the checks. Nothing here saves on its own: the page's
 * Save sends the one form to the one writer, and the order is priced by the one engine again at checkout.
 */

export interface BuilderData extends FormLike {
    name: string;
    label_en: string;
    label_ar: string;
    is_active: boolean;
}

type Kind = "percent_discount" | "fixed_discount" | "free_shipping" | "free_product";

interface Props {
    data: BuilderData;
    /** Several fields at once (a template), or one. */
    patch: (patch: FormPatch) => void;
    options: ScopeOptions;
    storefronts: number[];
    productLabels: Record<string, string>;
    onProductLabel: (id: number, title: string) => void;
    /** The product search box (the page's own), given a pick handler. */
    productPicker: (onPick: (id: number, title: string) => void) => ReactNode;
    /** Only a new offer chooses the wheel; a saved one keeps what it is. */
    isNew: boolean;
    onWheel: boolean;
    /** Names of other running offers on the same items, from the last test cart. */
    overlaps: string[];
    /** Opens the detailed settings (a gift is chosen there). */
    openDetails: () => void;
}

export function OfferBuilderPanel({ data, patch, options, storefronts, productLabels, onProductLabel, productPicker, isNew, onWheel, overlaps, openDetails }: Props) {
    const t = useT();
    const lang = useLocale() === "en" ? "en" : "ar";
    const [pending, setPending] = useState<string | null>(null);
    // "a code" is a choice before the code is typed, so it is kept here rather than read back from an empty field
    const [mode, setMode] = useState<"auto" | "code">(data.code.trim() !== "" ? "code" : "auto");

    // every level: a category the team means (Wallets) can sit under another (depth 2), and the engine matches it the same
    const categories = [...options.categories, ...options.sub_types].filter((c) => storefronts.includes(c.storefront_id));
    const anyCategory = (id: number | null) => options.categories.find((c) => c.id === id) ?? options.sub_types.find((c) => c.id === id);
    const nameOf: NameOf = (dimension, id, value) => {
        const pick = (row: { label: string; ar?: string | null; en?: string | null } | undefined) =>
            row ? { en: row.en ?? row.label, ar: row.ar ?? row.label } : null;
        if (dimension === "category" || dimension === "sub_type") return pick(anyCategory(id));
        if (dimension === "brand") return pick(options.brands.find((b) => b.id === id));
        if (dimension === "grade") return pick(options.grades.find((g) => g.id === id));
        if (dimension === "product") {
            const title = productLabels[String(id)] ?? `#${id}`;
            return { en: title, ar: title };
        }
        const family = options.families.find((f) => f.value === value);
        return family ? { en: family.label, ar: family.label } : null;
    };

    const reward = data.rewards[0];
    const kind: Kind = (["percent_discount", "fixed_discount", "free_shipping", "free_product"].includes(reward?.type ?? "") ? reward?.type : "percent_discount") as Kind;
    const giftTitle = (reward as { product?: { title?: string } | null } | undefined)?.product?.title ?? null;
    // a saved wheel offer is the wheel's whatever the form says: each spin's code carries its own validity, so no end date
    const model = useMemo(() => {
        const m = modelOf(data, nameOf, giftTitle ? { en: giftTitle, ar: giftTitle } : null);
        return onWheel ? { ...m, where: "wheel" as const, endsAt: null, code: null } : m;
    }, [data, productLabels, options, giftTitle, onWheel]); // eslint-disable-line react-hooks/exhaustive-deps
    const checks = [...warnings(model, lang), ...overlaps.map((name) => t("promotions.builder_overlap", "يتقاطع مع عرض يعمل الآن على نفس المنتجات: :name", { name }))];

    const filled = data.rewards.some((r) => (r.amount ?? "") !== "" || r.product_id !== null) || data.scopes.length > 0;
    const applyTemplate = (key: string) => {
        const template = TEMPLATES.find((tpl) => tpl.key === key);
        if (!template) return;
        const categoryBySlug = (slug: string) => categories.find((c) => c.slug === slug)?.id ?? null;
        patch(template.patch({ categoryBySlug }));
        setPending(null);
    };

    const setReward = (next: Partial<{ type: string; amount: string | null }>) => {
        const first = { type: kind, amount: reward?.amount ?? null, product_id: reward?.product_id ?? null, quantity: reward?.quantity ?? 1, ...next };
        if (first.type !== "free_product") first.product_id = null;
        if (first.type === "free_shipping" || first.type === "free_product") first.amount = null;
        patch({ rewards: [first, ...data.rewards.slice(1)] });
        if (first.type === "free_product") openDetails();
    };

    const scopesOf = (role: ScopeRow["role"]) => data.scopes.map((scope, index) => ({ scope, index })).filter(({ scope }) => scope.role === role);
    const addScope = (role: ScopeRow["role"], dimension: string, id: number | null) => {
        if (id === null || data.scopes.some((s) => s.role === role && s.dimension === dimension && s.value_id === id)) return;
        patch({ scopes: [...data.scopes, { role, dimension, value_id: id, value: null }] });
    };
    const removeScope = (index: number) => patch({ scopes: data.scopes.filter((_, i) => i !== index) });

    const items = model.items;
    const setItems = (mode: string) => {
        if (mode === "per_qualifier") patch({ apply_per: "paired", max_units: "" });
        else if (mode === "cheapest") patch({ apply_per: "cheapest", max_units: "" });
        else if (mode === "fixed") patch({ apply_per: "item", max_units: data.max_units === "" || data.max_units === null ? "1" : String(data.max_units) });
        else patch({ apply_per: data.apply_per === "paired" || data.apply_per === "cheapest" ? "item" : data.apply_per, max_units: "" });
    };

    const firstOrder = data.conditions.some((c) => c.type === "first_order");
    const toggleFirstOrder = (on: boolean) =>
        patch({ conditions: on ? [...data.conditions, { type: "first_order", amount: null, quantity: null }] : data.conditions.filter((c) => c.type !== "first_order") });

    const where = data.for_wheel || onWheel ? "wheel" : mode;

    const chips = (role: ScopeRow["role"], empty: string) => {
        const rows = scopesOf(role);
        return rows.length === 0 ? (
            <p className="text-xs text-muted-foreground">{empty}</p>
        ) : (
            <ul className="flex flex-wrap gap-2">
                {rows.map(({ scope, index }) => (
                    <li key={index} className="flex items-center gap-1 rounded-full border bg-muted/40 py-1 ps-3 pe-1 text-xs">
                        {nameOf(scope.dimension, scope.value_id, scope.value)?.[lang] ?? scope.dimension}
                        <Button type="button" variant="ghost" size="sm" className="h-6 w-6 p-0" aria-label={t("common.remove", "إزالة")} onClick={() => removeScope(index)}>
                            <X className="h-3 w-3" />
                        </Button>
                    </li>
                ))}
            </ul>
        );
    };
    const categoryChoices = categories.map((c) => ({ value: String(c.id), label: c.label }));
    const brandChoices = options.brands.map((b) => ({ value: String(b.id), label: b.label }));

    const step = (n: number, title: string, hint: string, body: ReactNode) => (
        <section className="space-y-3 rounded-lg border p-3">
            <div className="flex items-baseline gap-2">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">{n}</span>
                <div>
                    <h3 className="text-sm font-semibold">{title}</h3>
                    <p className="text-xs text-muted-foreground">{hint}</p>
                </div>
            </div>
            {body}
        </section>
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t("promotions.builder_title", "صمّم العرض")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* the templates: each fills the steps; every field stays editable */}
                <div className="space-y-2">
                    <Label>{t("promotions.builder_templates", "ابدأ من نموذج جاهز")}</Label>
                    <div className="flex flex-wrap gap-2">
                        {TEMPLATES.map((tpl) => (
                            <Button key={tpl.key} type="button" variant="outline" size="sm" title={tpl.hint[lang]} onClick={() => (filled ? setPending(tpl.key) : applyTemplate(tpl.key))}>
                                {t(`promotions.template_${tpl.key}`, tpl.label.ar)}
                            </Button>
                        ))}
                    </div>
                    {/* asked only when it would replace what was filled in (the Hot Deals lesson) — inline, never a dialog */}
                    {pending !== null ? (
                        <div className="flex flex-wrap items-center gap-2 rounded-md border border-amber-300/60 bg-amber-50/60 p-2 text-sm dark:border-amber-900/50 dark:bg-amber-950/20" role="alert">
                            {t("promotions.builder_template_replace", "النموذج يستبدل ما كتبته في الخطوات. أكمل؟")}
                            <Button type="button" size="sm" onClick={() => applyTemplate(pending)}>{t("promotions.builder_template_apply", "استخدم النموذج")}</Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => setPending(null)}>{t("common.cancel", "إلغاء")}</Button>
                        </div>
                    ) : null}
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    {step(1, t("promotions.step_what", "ما العرض؟"), t("promotions.step_what_hint", "نسبة أو مبلغ أو هدية أو توصيل مجاني."), (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Select aria-label={t("promotions.step_what", "ما العرض؟")} value={kind} onChange={(e) => setReward({ type: e.target.value })}>
                                <option value="percent_discount">{t("promotions.kind_percent", "خصم بنسبة %")}</option>
                                <option value="fixed_discount">{t("promotions.kind_amount", "خصم بمبلغ (ج.م)")}</option>
                                <option value="free_product">{t("promotions.kind_gift", "هدية مجانية")}</option>
                                <option value="free_shipping">{t("promotions.kind_shipping", "توصيل مجاني")}</option>
                            </Select>
                            {kind === "percent_discount" || kind === "fixed_discount" ? (
                                <Input type="number" inputMode="decimal" aria-label={t("promotions.kind_value", "القيمة")} placeholder={kind === "percent_discount" ? "30" : "300"}
                                    value={reward?.amount ?? ""} onChange={(e) => setReward({ amount: e.target.value })} />
                            ) : kind === "free_product" ? (
                                <Button type="button" variant="outline" onClick={openDetails}>{giftTitle ?? t("promotions.kind_gift_pick", "اختر الهدية في الإعدادات التفصيلية")}</Button>
                            ) : null}
                        </div>
                    ))}

                    {step(2, t("promotions.step_on", "على أي منتجات؟"), t("promotions.step_on_hint", "تصنيف أو ماركة أو منتجات بعينها. لا شيء = السلة كلها."), (
                        <div className="space-y-2">
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Combobox label={t("promotions.scope_category", "التصنيف")} value={null} options={categoryChoices} placeholder={t("promotions.scope_add", "أضف…")}
                                    onChange={(v) => addScope("target", "category", v === null ? null : Number(v))} />
                                <Combobox label={t("promotions.scope_brand", "الماركة")} value={null} options={brandChoices} placeholder={t("promotions.scope_add", "أضف…")}
                                    onChange={(v) => addScope("target", "brand", v === null ? null : Number(v))} />
                            </div>
                            {productPicker((id, title) => {
                                onProductLabel(id, title);
                                addScope("target", "product", id);
                            })}
                            {chips("target", t("promotions.scope_whole_cart", "السلة كلها"))}
                        </div>
                    ))}

                    {step(3, t("promotions.step_condition", "بشرط؟ (اختياري)"), t("promotions.step_condition_hint", "منتج آخر في السلة، أو حد أدنى للسلة، أو أول طلب."), (
                        <div className="space-y-2">
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Combobox label={t("promotions.condition_item_category", "مع منتج من تصنيف")} value={null} options={categoryChoices} placeholder={t("promotions.scope_add", "أضف…")}
                                    onChange={(v) => addScope("qualify", "category", v === null ? null : Number(v))} />
                                <Combobox label={t("promotions.condition_item_brand", "مع منتج من ماركة")} value={null} options={brandChoices} placeholder={t("promotions.scope_add", "أضف…")}
                                    onChange={(v) => addScope("qualify", "brand", v === null ? null : Number(v))} />
                            </div>
                            {chips("qualify", t("promotions.scope_none", "بدون شرط"))}
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="builder-min-total">{t("promotions.min_order", "أقل قيمة للطلب (ج.م)")}</Label>
                                    <Input id="builder-min-total" type="number" inputMode="decimal" value={data.min_order_amount} onChange={(e) => patch({ min_order_amount: e.target.value })} />
                                </div>
                                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                    <Checkbox checked={firstOrder} onCheckedChange={(c) => toggleFirstOrder(c === true)} />
                                    {t("promotions.condition_first_order", "أول طلب فقط (بالحساب وبرقم الهاتف)")}
                                </label>
                            </div>
                        </div>
                    ))}

                    {step(4, t("promotions.step_limits", "الحدود"), t("promotions.step_limits_hint", "كم قطعة، وأقصى خصم، ومتى ينتهي، وكم مرة."), (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="space-y-1 sm:col-span-2">
                                <Label htmlFor="builder-items">{t("promotions.items_mode", "على كم قطعة؟")}</Label>
                                <div className="flex flex-wrap gap-2">
                                    <Select id="builder-items" value={items} onChange={(e) => setItems(e.target.value)}>
                                        <option value="all">{t("promotions.items_all", "كل القطع المشمولة")}</option>
                                        <option value="per_qualifier">{t("promotions.items_per_qualifier", "قطعة واحدة مع كل قطعة من الشرط")}</option>
                                        <option value="fixed">{t("promotions.items_fixed", "عدد ثابت لكل طلب")}</option>
                                        <option value="cheapest">{t("promotions.items_cheapest", "أرخص قطعة فقط")}</option>
                                    </Select>
                                    {items === "fixed" ? (
                                        <Input className="w-24" type="number" min={1} aria-label={t("promotions.max_units", "أقصى عدد قطع")} value={String(data.max_units ?? "")} onChange={(e) => patch({ max_units: e.target.value })} />
                                    ) : null}
                                </div>
                                <p className="text-xs text-muted-foreground">{t("promotions.items_hint", "الأغلى أولًا دائمًا، إلا «أرخص قطعة».")}</p>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="builder-cap">{t("promotions.max_discount", "أقصى خصم (ج.م)")}</Label>
                                <Input id="builder-cap" type="number" inputMode="decimal" value={data.max_discount} onChange={(e) => patch({ max_discount: e.target.value })} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="builder-uses">{t("promotions.usage_limit", "عدد مرات الاستخدام الكلي")}</Label>
                                <Input id="builder-uses" type="number" value={data.usage_limit} onChange={(e) => patch({ usage_limit: e.target.value })} />
                            </div>
                            {where !== "wheel" ? (
                                <div className="space-y-1 sm:col-span-2">
                                    <Label htmlFor="builder-ends">{t("common.ends", "ينتهي")}</Label>
                                    <Input id="builder-ends" type="datetime-local" value={data.ends_at} onChange={(e) => patch({ ends_at: e.target.value })} />
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>

                {step(5, t("promotions.step_where", "أين يظهر؟"), t("promotions.step_where_hint", "تلقائيًا في السلة وصفحة المنتج، أو بكود، أو جائزة في العجلة."), (
                    <div className="flex flex-wrap items-center gap-2">
                        <Select aria-label={t("promotions.step_where", "أين يظهر؟")} value={where} disabled={!isNew && onWheel}
                            onChange={(e) => {
                                const v = e.target.value;
                                if (v !== "wheel") setMode(v === "code" ? "code" : "auto");
                                patch({ for_wheel: v === "wheel", code: v === "code" ? data.code : "" });
                            }}>
                            <option value="auto">{t("promotions.where_auto", "تلقائيًا")}</option>
                            <option value="code">{t("promotions.where_code", "بكود خصم")}</option>
                            {isNew || onWheel ? <option value="wheel">{t("promotions.where_wheel", "جائزة في عجلة الحظ")}</option> : null}
                        </Select>
                        {where === "code" ? (
                            <Input className="w-48" dir="ltr" aria-label={t("promotions.code", "كود الخصم")} placeholder="WALLET30" value={data.code} onChange={(e) => patch({ code: e.target.value.toUpperCase() })} />
                        ) : null}
                        {where === "wheel" ? (
                            <p className="text-xs text-muted-foreground">{t("promotions.where_wheel_hint", "يبقى متوقفًا هنا وبلا كود: كل لفة تنسخه بكودها ومدتها. اختره في صفحة العجلة.")}</p>
                        ) : null}
                    </div>
                ))}

                {/* the offer said plainly, in both languages, as the team will read it */}
                <div className="grid gap-2 rounded-lg border bg-muted/30 p-3 md:grid-cols-2" aria-live="polite">
                    <p className="text-sm leading-relaxed" lang="ar"><bdi dir="rtl">{sentence(model, "ar")}</bdi></p>
                    <p className="text-sm leading-relaxed" lang="en"><Ltr>{sentence(model, "en")}</Ltr></p>
                </div>

                {checks.length > 0 ? (
                    <ul className="space-y-1" role="status">
                        {checks.map((check) => (
                            <li key={check} className="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-300">
                                <Badge variant="neutral">{t("promotions.builder_check", "تنبيه")}</Badge>
                                {check}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </CardContent>
        </Card>
    );
}
