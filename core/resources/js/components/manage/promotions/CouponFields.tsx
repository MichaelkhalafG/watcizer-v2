import { X } from "lucide-react";

import { Combobox } from "@/components/form/Combobox";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useT } from "@/lib/i18n";

/*
 * The customer-facing half of a promotion (M1u, 2026-10-30): the coupon code and its labels, how the money is taken
 * (once per order or per eligible item, a cap, a minimum order, the floor no unit goes below), the usage limits,
 * sale items and stacking — and WHICH items: the ones the discount is taken off, and the ones that must be in the
 * cart ("buy from X, get a discount on Y"). Every item is picked from a searchable list, never typed as an id.
 *
 * Its own component so the long rule form only gains one line; the fields live in the same `useForm` as the rest.
 */

export type ScopeRole = "target" | "qualify";
export type ScopeDimension = "brand" | "category" | "sub_type" | "grade" | "family";

export interface ScopeRow {
    role: ScopeRole;
    dimension: ScopeDimension;
    value_id: number | null;
    value: string | null;
}

export interface ScopeOptions {
    brands: { id: number; label: string }[];
    grades: { id: number; label: string }[];
    families: { value: string; label: string }[];
    categories: { id: number; storefront_id: number; label: string }[];
    sub_types: { id: number; storefront_id: number; label: string }[];
}

export interface CouponData {
    code: string;
    label_en: string;
    label_ar: string;
    apply_per: "order" | "item";
    max_discount: string;
    min_order_amount: string;
    usage_limit: string;
    usage_limit_per_customer: string;
    include_sale_items: boolean;
    stackable: boolean;
    unit_floor: string;
    qualify_min_quantity: string;
    qualify_min_amount: string;
    scopes: ScopeRow[];
}

export interface CouponRule {
    code?: string | null;
    label_en?: string | null;
    label_ar?: string | null;
    apply_per?: string | null;
    max_discount?: string | null;
    min_order_amount?: string | null;
    usage_limit?: number | null;
    usage_limit_per_customer?: number | null;
    include_sale_items?: boolean;
    stackable?: boolean;
    unit_floor?: string | null;
    qualify_min_quantity?: number | null;
    qualify_min_amount?: string | null;
    scopes?: ScopeRow[];
    redemptions?: number;
}

const text = (value: string | number | null | undefined): string =>
    value === null || value === undefined ? "" : String(value);

/** The form's initial coupon fields, from the saved rule (or the defaults of a new one). */
export function couponDefaults(rule: CouponRule | null): CouponData {
    return {
        code: text(rule?.code),
        label_en: text(rule?.label_en),
        label_ar: text(rule?.label_ar),
        apply_per: rule?.apply_per === "item" ? "item" : "order",
        max_discount: text(rule?.max_discount),
        min_order_amount: text(rule?.min_order_amount),
        usage_limit: text(rule?.usage_limit),
        usage_limit_per_customer: text(rule?.usage_limit_per_customer),
        include_sale_items: rule?.include_sale_items ?? true,
        stackable: rule?.stackable ?? false,
        unit_floor: rule?.unit_floor && Number(rule.unit_floor) > 0 ? text(rule.unit_floor) : "",
        qualify_min_quantity: text(rule?.qualify_min_quantity),
        qualify_min_amount: text(rule?.qualify_min_amount),
        scopes: rule?.scopes ?? [],
    };
}

interface Props {
    data: CouponData;
    errors: Partial<Record<string, string>>;
    setField: <K extends keyof CouponData>(key: K, value: CouponData[K]) => void;
    options: ScopeOptions;
    storefronts: number[];
    redemptions?: number;
}

export function CouponFields({ data, errors, setField, options, storefronts, redemptions }: Props) {
    const t = useT();

    const field = (key: keyof CouponData, label: string, hint: string, props: { type?: string; dir?: string } = {}) => (
        <div className="space-y-1.5">
            <Label htmlFor={`coupon-${key}`}>{label}</Label>
            <Input
                id={`coupon-${key}`}
                type={props.type ?? "text"}
                dir={props.dir}
                inputMode={props.type === "number" ? "decimal" : undefined}
                value={String(data[key] ?? "")}
                onChange={(event) => setField(key, event.target.value as never)}
            />
            <p className="text-xs text-muted-foreground">{hint}</p>
            {errors[key] ? <p className="text-xs text-destructive">{errors[key]}</p> : null}
        </div>
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t("promotions.coupon_title", "الكود والمنتجات المشمولة")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                <section className="grid gap-4 md:grid-cols-3">
                    {field(
                        "code",
                        t("promotions.code", "كود الخصم"),
                        t("promotions.code_hint", "يكتبه العميل في السلة. اتركه فارغًا لعرض يُطبَّق تلقائيًا."),
                        { dir: "ltr" },
                    )}
                    {field("label_ar", t("promotions.label_ar", "ما يراه العميل (عربي)"), t("promotions.label_hint", "يظهر بجانب سطر الخصم في السلة والدفع."))}
                    {field("label_en", t("promotions.label_en", "ما يراه العميل (إنجليزي)"), t("promotions.label_hint", "يظهر بجانب سطر الخصم في السلة والدفع."), { dir: "ltr" })}
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="coupon-apply_per">{t("promotions.apply_per", "يُطبَّق الخصم")}</Label>
                        <Select
                            id="coupon-apply_per"
                            value={data.apply_per}
                            onChange={(event) => setField("apply_per", event.target.value === "item" ? "item" : "order")}
                        >
                            <option value="order">{t("promotions.apply_per_order", "مرة واحدة على الطلب")}</option>
                            <option value="item">{t("promotions.apply_per_item", "على كل قطعة مشمولة")}</option>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {t("promotions.apply_per_hint", "«على كل قطعة»: خصم ١٬٠٠٠ على ساعتين = ٢٬٠٠٠.")}
                        </p>
                    </div>
                    {field("max_discount", t("promotions.max_discount", "أقصى خصم (ج.م)"), t("promotions.max_discount_hint", "فارغ = بلا حد أقصى."), { type: "number" })}
                    {field(
                        "min_order_amount",
                        t("promotions.min_order", "أقل قيمة للطلب (ج.م)"),
                        t("promotions.min_order_hint", "على قيمة المنتجات قبل الخصم، مثل حد الشحن المجاني."),
                        { type: "number" },
                    )}
                    {field("usage_limit", t("promotions.usage_limit", "عدد مرات الاستخدام الكلي"), t("promotions.usage_hint", "فارغ = بلا حد. الطلب الملغى لا يُحسب."), { type: "number" })}
                    {field(
                        "usage_limit_per_customer",
                        t("promotions.usage_limit_customer", "لكل عميل"),
                        t("promotions.usage_customer_hint", "بالحساب أو البريد أو رقم الهاتف."),
                        { type: "number" },
                    )}
                    {field(
                        "unit_floor",
                        t("promotions.unit_floor", "أقل سعر للقطعة بعد الخصم (ج.م)"),
                        t("promotions.unit_floor_hint", "لا ينزل سعر أي قطعة عن هذا الرقم. فارغ = صفر."),
                        { type: "number" },
                    )}
                </section>

                <section className="flex flex-wrap gap-6">
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.include_sale_items} onCheckedChange={(checked) => setField("include_sale_items", checked === true)} />
                        {t("promotions.include_sale", "يشمل المنتجات المخفّضة بالفعل")}
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.stackable} onCheckedChange={(checked) => setField("stackable", checked === true)} />
                        {t("promotions.stackable", "يتجمّع مع العروض الأخرى القابلة للتجميع")}
                    </label>
                    {redemptions !== undefined ? (
                        <span className="text-sm text-muted-foreground">
                            {t("promotions.redemptions", "استُخدم في :count طلب", { count: redemptions })}
                        </span>
                    ) : null}
                </section>

                <ScopePicker
                    role="target"
                    title={t("promotions.target_title", "يُخصم من")}
                    hint={t("promotions.target_hint", "اختر الماركات أو التصنيفات أو الأنواع أو الفئات أو نوع المنتج. لا شيء = كل المنتجات. داخل النوع الواحد «أو»، وبين الأنواع «و».")}
                    data={data}
                    setField={setField}
                    options={options}
                    storefronts={storefronts}
                    errors={errors}
                />

                <ScopePicker
                    role="qualify"
                    title={t("promotions.qualify_title", "بشرط وجود هذه المنتجات في السلة (اشترِ من… واحصل على خصم)")}
                    hint={t("promotions.qualify_hint", "اتركه فارغًا إذا لم يكن هناك شرط. مثال: ساعة من ماركة معيّنة، فيُخصم من الحقائب.")}
                    data={data}
                    setField={setField}
                    options={options}
                    storefronts={storefronts}
                    errors={errors}
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        {field("qualify_min_quantity", t("promotions.qualify_quantity", "أقل عدد قطع منها"), t("promotions.qualify_quantity_hint", "فارغ = قطعة واحدة."), { type: "number" })}
                        {field("qualify_min_amount", t("promotions.qualify_amount", "أو أقل قيمة منها (ج.م)"), t("promotions.qualify_amount_hint", "فارغ = بلا حد."), { type: "number" })}
                    </div>
                </ScopePicker>
            </CardContent>
        </Card>
    );
}

function ScopePicker({
    role,
    title,
    hint,
    data,
    setField,
    options,
    storefronts,
    errors,
    children,
}: {
    role: ScopeRole;
    title: string;
    hint: string;
    data: CouponData;
    setField: Props["setField"];
    options: ScopeOptions;
    storefronts: number[];
    errors: Props["errors"];
    children?: React.ReactNode;
}) {
    const t = useT();
    const dimensions: { key: ScopeDimension; label: string; choices: { value: string; label: string }[] }[] = [
        { key: "brand", label: t("promotions.scope_brand", "الماركة"), choices: options.brands.map((b) => ({ value: String(b.id), label: b.label })) },
        {
            key: "category",
            label: t("promotions.scope_category", "التصنيف"),
            choices: options.categories.filter((c) => storefronts.includes(c.storefront_id)).map((c) => ({ value: String(c.id), label: c.label })),
        },
        {
            key: "sub_type",
            label: t("promotions.scope_sub_type", "النوع"),
            choices: options.sub_types.filter((c) => storefronts.includes(c.storefront_id)).map((c) => ({ value: String(c.id), label: c.label })),
        },
        { key: "grade", label: t("promotions.scope_grade", "الفئة"), choices: options.grades.map((g) => ({ value: String(g.id), label: g.label })) },
        { key: "family", label: t("promotions.scope_family", "نوع المنتج"), choices: options.families },
    ];

    const rows = data.scopes.map((scope, index) => ({ scope, index })).filter(({ scope }) => scope.role === role);
    const add = (dimension: ScopeDimension, value: string | null) => {
        if (value === null) {
            return;
        }
        const family = dimension === "family";
        const exists = data.scopes.some(
            (s) => s.role === role && s.dimension === dimension && (family ? s.value === value : String(s.value_id) === value),
        );
        if (!exists) {
            setField("scopes", [...data.scopes, { role, dimension, value_id: family ? null : Number(value), value: family ? value : null }]);
        }
    };
    const remove = (index: number) => setField("scopes", data.scopes.filter((_, i) => i !== index));
    const labelOf = (scope: ScopeRow): string => {
        const dimension = dimensions.find((d) => d.key === scope.dimension);
        const key = scope.dimension === "family" ? scope.value : String(scope.value_id);
        return `${dimension?.label ?? scope.dimension}: ${dimension?.choices.find((c) => c.value === key)?.label ?? key ?? ""}`;
    };

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </div>
            <div className="grid gap-3 md:grid-cols-5">
                {dimensions.map((dimension) => (
                    <Combobox
                        key={dimension.key}
                        label={dimension.label}
                        value={null}
                        onChange={(value) => add(dimension.key, value)}
                        options={dimension.choices}
                        placeholder={t("promotions.scope_add", "أضف…")}
                    />
                ))}
            </div>
            {rows.length > 0 ? (
                <ul className="flex flex-wrap gap-2">
                    {rows.map(({ scope, index }) => (
                        <li key={index} className="flex items-center gap-1 rounded-full border bg-muted/40 py-1 ps-3 pe-1 text-xs">
                            {labelOf(scope)}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-6 w-6 p-0"
                                aria-label={t("common.remove", "إزالة")}
                                onClick={() => remove(index)}
                            >
                                <X className="h-3 w-3" />
                            </Button>
                            {errors[`scopes.${index}`] ? <span className="text-destructive">{errors[`scopes.${index}`]}</span> : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-xs text-muted-foreground">
                    {role === "target" ? t("promotions.scope_all", "كل المنتجات") : t("promotions.scope_none", "بدون شرط")}
                </p>
            )}
            {children}
        </section>
    );
}
