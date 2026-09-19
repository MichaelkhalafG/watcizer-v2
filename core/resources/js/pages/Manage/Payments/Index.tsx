import { router, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";

import { SelectField, TextField } from "@/components/form/TextField";
import { Select } from "@/components/ui/input";
import { methodLabel, providerLabel } from "@/lib/labels";
import { SwitchField } from "@/components/form/SwitchField";
import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import ManageLayout from "@/layouts/ManageLayout";
import { useT } from "@/lib/i18n";
import type { SharedProps } from "@/types";
import { Ltr } from "@/components/ui/bidi";

/**
 * Payments — providers, methods and the merged order, for ONE storefront (study §3.9.7).
 *
 * ── The credential fields are WRITE-ONLY, and that is the whole design ──────────────────────
 *
 * No stored secret is ever sent to this page: the model hides the attribute, the controller sends
 * only booleans and key NAMES, and the inputs render empty with "leave blank to keep the current
 * value". There is no reveal control and there will not be one — a dashboard that can show a
 * secret is a dashboard that leaks it through a screenshot, a support session or a browser
 * extension. What the screen says instead is whether each key is SET and when the contract last
 * changed, which is everything a rotation actually needs to be checkable.
 *
 * `integration_id` is NOT a credential — the provider puts it in the signed callback payload and
 * the admin needs it to match a row in the merchant portal — so it is a normal text field.
 *
 * ── The order routes the money ──────────────────────────────────────────────────────────────
 *
 * Two contracts can both offer `card`. The customer sees ONE entry, and the row with the lowest
 * sort is the one that takes the money. So this ordering list is not cosmetic, and every row says
 * plainly whether it currently serves its method or which provider took it instead.
 */

interface MethodRow {
    id: number;
    method: string;
    integration_id: string | null;
    icon: string | null;
    is_enabled: boolean;
    sort: number;
    label: { ar: string; en: string };
}

interface ProviderRow {
    id: number;
    provider: string;
    is_enabled: boolean;
    /** Whether the registry has code for this key — a row can outlive its implementation. */
    implemented: boolean;
    credentials_set: boolean;
    credential_fields: string[];
    credential_keys_present: string[];
    credentials_complete: boolean;
    needs_credentials: boolean;
    methods: MethodRow[];
    updated_at: string | null;
}

interface MergedRow {
    id: number;
    method: string;
    label: string;
    icon: string | null;
    sort: number;
    provider: string;
    provider_id: number;
    is_enabled: boolean;
    provider_enabled: boolean;
    serves: boolean;
    served_by: string | null;
    label_mismatch: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    /** The ACTIVE storefronts this operator's grant reaches (item 15) — scoped, so no option 404s. */
    storefronts: Array<{ value: string; label: string }>;
    providers: ProviderRow[];
    merged: MergedRow[];
    customer_preview: Array<{
        id: number;
        method: string;
        label: string;
        icon: string | null;
        sort: number;
    }>;
    registry: Array<{
        value: string;
        label: string;
        credential_fields: string[];
    }>;
    method_keys: string[];
}

type Translator = ReturnType<typeof useT>;

/**
 * The display name of a method KEY.
 *
 * It is a function of `t` rather than a constant map because a hook cannot run at module level:
 * the translated name has to be asked for inside a component, and every call site here already
 * has a translator in hand. `valU` and `Tamara` are product names and stay as they are written.
 */

/**
 * The credential key names, so a rotation checklist reads like one (Arabic since item 10).
 *
 * They were English — "Secret key", "Public key", "HMAC secret" — on an Arabic screen. A key name
 * IS a technical thing and an administrator recognises it either way, but it sat in a checklist
 * whose every other word was Arabic, and this screen is the one an administrator uses under
 * pressure, at the moment a payment provider has been rotated.
 *
 * A key the provider registry declares and this map has not heard of falls through to the raw name,
 * which is correct: a new credential field is something to notice, not something to hide.
 */
function fieldLabel(t: Translator, field: string): string {
    switch (field) {
        case "secret_key":
            return t("payments.field_secret_key", "المفتاح السرّي");
        case "public_key":
            return t("payments.field_public_key", "المفتاح العام");
        case "hmac_secret":
            return t("payments.field_hmac_secret", "مفتاح التوقيع (HMAC)");
        default:
            return field;
    }
}

export default function PaymentsIndex({
    storefront,
    providers,
    storefronts,
    merged,
    customer_preview,
    registry,
    method_keys,
}: Props) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const [addingProvider, setAddingProvider] = useState(false);
    const [editing, setEditing] = useState<ProviderRow | null>(null);
    const [methodTarget, setMethodTarget] = useState<{
        provider: ProviderRow;
        method: MethodRow | null;
    } | null>(null);

    const base = `/manage/storefronts/${storefront.id}/payments`;

    // ── the ordering list ────────────────────────────────────────────────────────────────────
    // Up/down rather than drag-and-drop: it is keyboard-operable, works on a tablet, and posts the
    // whole id list in its new order — which is the contract the server validates ownership on.
    const move = (index: number, direction: -1 | 1) => {
        const next = [...merged];
        const swapWith = index + direction;
        if (swapWith < 0 || swapWith >= next.length) {
            return;
        }
        const moved = next[swapWith];
        const current = next[index];
        if (moved === undefined || current === undefined) {
            return;
        }
        next[swapWith] = current;
        next[index] = moved;

        router.post(
            `${base}/order`,
            { ids: next.map((row) => row.id) },
            { preserveScroll: true },
        );
    };

    return (
        <ManageLayout
            title={t("payments.title_for", "وسائل الدفع — :name", {
                name: storefront.name,
            })}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                {
                    label: t("common.storefronts", "المتاجر"),
                    href: "/manage/storefronts",
                },
                {
                    label: storefront.name,
                    href: `/manage/storefronts/${storefront.id}/edit`,
                },
                { label: t("payments.title", "وسائل الدفع") },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {/*
                      * The storefront switcher (item 15). Every other per-storefront screen has one
                      * and this did not, so the only route to Brand Fashion's payment settings was
                      * to type its id into the address bar — the sidebar links to whichever
                      * storefront you were last on, and nothing here said another existed.
                      *
                      * Offered only when there IS somewhere else to go: a single-storefront grant
                      * gets a dropdown of one, which is furniture.
                      */}
                    {storefronts.length > 1 ? (
                        <Select
                            aria-label={t("common.storefront", "المتجر")}
                            value={String(storefront.id)}
                            onChange={(event) =>
                                router.get(
                                    `/manage/storefronts/${event.target.value}/payments`,
                                )
                            }
                        >
                            {storefronts.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                    <Button size="sm" onClick={() => setAddingProvider(true)}>
                        {t("payments.add_contract", "أضف عقد مزوّد")}
                    </Button>
                </div>
            }
        >
            <div className="space-y-6">
                <Alert
                    tone="info"
                    title={t(
                        "payments.write_only_title",
                        "المفاتيح تُكتب ولا تُقرأ",
                    )}
                >
                    {t(
                        "payments.write_only_body",
                        "المفاتيح المحفوظة لا تُرسل إلى المتصفح ولا يمكن استرجاعها. الخانة الفارغة تُبقي المحفوظ كما هو؛ أي قيمة تكتبها تستبدله.",
                    )}
                </Alert>

                {errors.ids ? <Alert tone="error">{errors.ids}</Alert> : null}
                {errors.provider ? (
                    <Alert tone="error">{errors.provider}</Alert>
                ) : null}

                {/* ── contracts ──────────────────────────────────────────────────────────── */}
                <div className="space-y-4">
                    {providers.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                {t(
                                    "payments.no_contracts",
                                    "لا يوجد عقد مزوّد لهذا المتجر بعد. «دفع عند الاستلام» و«واتساب» يحتاجان عقدًا بلا مفاتيح عند المزوّد",
                                )}{" "}
                                <strong>{providerLabel(t, "offline")}</strong>.
                            </CardContent>
                        </Card>
                    ) : null}

                    {providers.map((provider) => (
                        <Card key={provider.id}>
                            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                <CardTitle className="flex flex-wrap items-center gap-2">
                                    <span>{providerLabel(t, provider.provider)}</span>
                                    <Badge
                                        variant={
                                            provider.is_enabled
                                                ? "success"
                                                : "neutral"
                                        }
                                    >
                                        {provider.is_enabled
                                            ? t("common.active", "مفعّل")
                                            : t("common.suspended", "موقوف")}
                                    </Badge>
                                    {!provider.implemented ? (
                                        <Badge
                                            variant="destructive"
                                            title={t(
                                                "payments.not_implemented_hint",
                                                "لا يوجد كود لهذا المزوّد في السجل",
                                            )}
                                        >
                                            {t(
                                                "payments.not_implemented",
                                                "غير مُنفَّذ",
                                            )}
                                        </Badge>
                                    ) : null}
                                    {provider.needs_credentials ? (
                                        <Badge
                                            variant={
                                                provider.credentials_complete
                                                    ? "success"
                                                    : "warning"
                                            }
                                        >
                                            {provider.credentials_complete
                                                ? t(
                                                      "payments.keys_set",
                                                      "المفاتيح مضبوطة",
                                                  )
                                                : provider.credentials_set
                                                  ? t(
                                                        "payments.keys_incomplete",
                                                        "مفاتيح ناقصة",
                                                    )
                                                  : t(
                                                        "payments.keys_none",
                                                        "بلا مفاتيح",
                                                    )}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            {t(
                                                "payments.keys_not_needed",
                                                "لا يحتاج مفاتيح",
                                            )}
                                        </Badge>
                                    )}
                                </CardTitle>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(provider)}
                                    >
                                        {t(
                                            "payments.keys_and_activation",
                                            "المفاتيح والتفعيل",
                                        )}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setMethodTarget({
                                                provider,
                                                method: null,
                                            })
                                        }
                                    >
                                        {t("payments.add_method", "أضف طريقة")}
                                    </Button>
                                    <ConfirmAction
                                        title={t(
                                            "payments.delete_contract",
                                            "حذف العقد",
                                        )}
                                        confirmLabel={t(
                                            "payments.delete_contract_confirm",
                                            "احذف العقد وطرقه",
                                        )}
                                        consequence={
                                            <p>
                                                {t(
                                                    "payments.delete_contract_consequence_before",
                                                    "سيُحذف عقد",
                                                )}{" "}
                                                <span dir="ltr">
                                                    {providerLabel(t, provider.provider)}
                                                </span>{" "}
                                                {t(
                                                    "payments.delete_contract_consequence_after",
                                                    "مع :count طريقة دفع تحته، وستتوقف هذه الطرق عن الظهور للعملاء فورًا. محاولات الدفع المسجّلة لا تُحذف — تبقى في سجل المحاولات مع اسم المزوّد.",
                                                    {
                                                        count: provider.methods
                                                            .length,
                                                    },
                                                )}
                                            </p>
                                        }
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                {t("common.delete", "حذف")}
                                            </Button>
                                        }
                                        onConfirm={() =>
                                            router.delete(
                                                `${base}/providers/${provider.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {provider.needs_credentials ? (
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        {provider.credential_fields.map(
                                            (field) => (
                                                <Badge
                                                    key={field}
                                                    variant={
                                                        provider.credential_keys_present.includes(
                                                            field,
                                                        )
                                                            ? "success"
                                                            : "warning"
                                                    }
                                                >
                                                    {fieldLabel(t, field)}
                                                    {provider.credential_keys_present.includes(
                                                        field,
                                                    )
                                                        ? " ✓"
                                                        : " —"}
                                                </Badge>
                                            ),
                                        )}
                                        {provider.updated_at !== null ? (
                                            <span
                                                className="text-muted-foreground"
                                                dir="ltr"
                                            >
                                                {t("common.updated_at", "آخر تعديل")} {provider.updated_at}
                                            </span>
                                        ) : null}
                                    </div>
                                ) : null}

                                {provider.methods.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            "payments.no_methods_under_contract",
                                            "لا توجد طرق دفع تحت هذا العقد.",
                                        )}
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {provider.methods.map((method) => (
                                            <div
                                                key={method.id}
                                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                                            >
                                                <div className="space-y-0.5">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                        {method.label.ar ||
                                                            methodLabel(
                                                                t,
                                                                method.method,
                                                            )}
                                                        <Badge
                                                            variant="outline"
                                                            className="font-mono"
                                                        >
                                                            <span dir="ltr">
                                                                {methodLabel(t, method.method)}
                                                            </span>
                                                        </Badge>
                                                        {!method.is_enabled ? (
                                                            <Badge variant="neutral">
                                                                {t(
                                                                    "payments.method_suspended",
                                                                    "موقوفة",
                                                                )}
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        <Ltr>
                                                            {t("common.sort", "الترتيب")} {method.sort}
                                                            {method.integration_id !==
                                                            null
                                                                ? ` · ${t("payments.integration_id", "رقم العملية لدى المزوّد")}: ${method.integration_id}`
                                                                : ""}
                                                            {method.label.en !==
                                                            ""
                                                                ? ` · ${method.label.en}`
                                                                : ""}
                                                        </Ltr>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setMethodTarget({
                                                                provider,
                                                                method,
                                                            })
                                                        }
                                                    >
                                                        {t(
                                                            "common.edit",
                                                            "تعديل",
                                                        )}
                                                    </Button>
                                                    <ConfirmAction
                                                        title={t(
                                                            "payments.delete_method",
                                                            "حذف طريقة الدفع",
                                                        )}
                                                        confirmLabel={t(
                                                            "payments.delete_method_confirm",
                                                            "احذف الطريقة",
                                                        )}
                                                        consequence={
                                                            <p>
                                                                {t(
                                                                    "payments.delete_method_consequence",
                                                                    "ستتوقف «:label» عن الظهور للعملاء في هذا المتجر. إن كانت طريقة أخرى بنفس المفتاح تحت عقد آخر، فستصبح هي المستقبِلة للأموال.",
                                                                    {
                                                                        label:
                                                                            method
                                                                                .label
                                                                                .ar ||
                                                                            method.method,
                                                                    },
                                                                )}
                                                            </p>
                                                        }
                                                        trigger={
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                {t(
                                                                    "common.delete",
                                                                    "حذف",
                                                                )}
                                                            </Button>
                                                        }
                                                        onConfirm={() =>
                                                            router.delete(
                                                                `${base}/methods/${method.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* ── the merged order ───────────────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t("payments.merged_order", "الترتيب الموحَّد")}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                "payments.merged_order_help",
                                "ترتيب واحد يعبر العقود. عند تكرار نفس المفتاح تحت عقدين، الأعلى في هذه القائمة هو الذي يستقبل الأموال — فالترتيب هنا قرار توجيه لا قرار شكل.",
                            )}
                        </p>

                        {merged.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    "payments.no_methods",
                                    "لا توجد طرق دفع بعد.",
                                )}
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {merged.map((row, index) => (
                                    <div
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span
                                                className="w-6 text-center text-xs text-muted-foreground"
                                                dir="ltr"
                                            >
                                                {index + 1}
                                            </span>
                                            <span className="text-sm font-medium">
                                                {row.label ||
                                                    methodLabel(t, row.method)}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className="font-mono"
                                            >
                                                <span dir="ltr">
                                                    {methodLabel(t, row.method)}
                                                </span>
                                            </Badge>
                                            <Badge variant="neutral">
                                                <span dir="ltr">
                                                    {providerLabel(t, row.provider)}
                                                </span>
                                            </Badge>

                                            {/* Who takes the money for this key. Never inferred from
                                                position by the reader — the server says it. */}
                                            {row.serves ? (
                                                <Badge variant="success">
                                                    {t(
                                                        "payments.takes_the_money",
                                                        "تستقبل الأموال",
                                                    )}
                                                </Badge>
                                            ) : row.served_by !== null ? (
                                                <Badge
                                                    variant="warning"
                                                    title={t(
                                                        "payments.covered_hint",
                                                        "مفتاح مكرَّر: عقد آخر يستقبل الأموال",
                                                    )}
                                                >
                                                    {t(
                                                        "payments.covered_by",
                                                        "مغطّاة بـ",
                                                    )}{" "}
                                                    <span dir="ltr">
                                                        {providerLabel(t, row.served_by)}
                                                    </span>
                                                </Badge>
                                            ) : (
                                                <Badge variant="neutral">
                                                    {!row.is_enabled ||
                                                    !row.provider_enabled
                                                        ? t(
                                                              "payments.method_suspended",
                                                              "موقوفة",
                                                          )
                                                        : t(
                                                              "payments.does_not_take_money",
                                                              "لا تستقبل",
                                                          )}
                                                </Badge>
                                            )}

                                            {row.label_mismatch ? (
                                                <Badge
                                                    variant="warning"
                                                    title={t(
                                                        "payments.label_mismatch_hint",
                                                        "عقدان بنفس المفتاح واسمان مختلفان: تغيير الترتيب يغيّر النص الذي يراه العميل",
                                                    )}
                                                >
                                                    {t(
                                                        "payments.label_mismatch",
                                                        "اسمان مختلفان",
                                                    )}
                                                </Badge>
                                            ) : null}
                                        </div>

                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={index === 0}
                                                aria-label={t(
                                                    "payments.move_up",
                                                    "أعلى",
                                                )}
                                                onClick={() => move(index, -1)}
                                            >
                                                ▲
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    index === merged.length - 1
                                                }
                                                aria-label={t(
                                                    "payments.move_down",
                                                    "أسفل",
                                                )}
                                                onClick={() => move(index, 1)}
                                            >
                                                ▼
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── what the customer will see ─────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t(
                                "payments.customer_preview",
                                "ما سيراه العميل (:count)",
                                { count: customer_preview.length },
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {customer_preview.length === 0 ? (
                            <Alert
                                tone="warning"
                                title={t(
                                    "payments.customer_sees_nothing_title",
                                    "لن يرى العميل أي وسيلة دفع",
                                )}
                            >
                                {t(
                                    "payments.customer_sees_nothing_body",
                                    "لا توجد طريقة مفعَّلة تحت عقد مفعَّل. الطلبات لن تجد وسيلة سداد في هذا المتجر.",
                                )}
                            </Alert>
                        ) : (
                            <ol className="space-y-2">
                                {customer_preview.map((row, index) => (
                                    <li
                                        key={row.id}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <span
                                            className="w-5 text-center text-xs text-muted-foreground"
                                            dir="ltr"
                                        >
                                            {index + 1}
                                        </span>
                                        <span className="font-medium">
                                            {row.label ||
                                                methodLabel(t, row.method)}
                                        </span>
                                        <span
                                            className="text-xs text-muted-foreground font-mono"
                                            dir="ltr"
                                        >
                                            {methodLabel(t, row.method)}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <p className="pt-3 text-xs text-muted-foreground">
                            {t(
                                "payments.one_key_once",
                                "مفتاح واحد يظهر مرة واحدة فقط، حتى لو كان متاحًا تحت عقدين.",
                            )}
                        </p>
                    </CardContent>
                </Card>
            </div>

            {addingProvider ? (
                <ProviderDialog
                    base={base}
                    registry={registry}
                    provider={null}
                    onClose={() => setAddingProvider(false)}
                />
            ) : null}

            {editing !== null ? (
                <ProviderDialog
                    base={base}
                    registry={registry}
                    provider={editing}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            {methodTarget !== null ? (
                <MethodDialog
                    base={base}
                    methodKeys={method_keys}
                    provider={methodTarget.provider}
                    method={methodTarget.method}
                    onClose={() => setMethodTarget(null)}
                />
            ) : null}
        </ManageLayout>
    );
}

/**
 * Add or edit a contract.
 *
 * The credential inputs are always EMPTY on open — there is nothing to prefill them with, by
 * design — and `autoComplete="new-password"` keeps a password manager from filling them with
 * something unrelated.
 */
function ProviderDialog({
    base,
    registry,
    provider,
    onClose,
}: {
    base: string;
    registry: Array<{
        value: string;
        label: string;
        credential_fields: string[];
    }>;
    provider: ProviderRow | null;
    onClose: () => void;
}) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const [key, setKey] = useState(
        provider?.provider ?? registry[0]?.value ?? "",
    );
    const [enabled, setEnabled] = useState(provider?.is_enabled ?? true);
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const fields =
        provider !== null
            ? provider.credential_fields
            : (registry.find((entry) => entry.value === key)
                  ?.credential_fields ?? []);

    const submit = () => {
        setBusy(true);
        const payload = { provider: key, is_enabled: enabled, credentials };
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        };

        if (provider === null) {
            router.post(`${base}/providers`, payload, options);
        } else {
            router.put(`${base}/providers/${provider.id}`, payload, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent
                title={
                    provider === null
                        ? t("payments.new_contract", "عقد مزوّد جديد")
                        : t("payments.contract_of", "عقد :provider", {
                              provider: provider.provider,
                          })
                }
                className="space-y-4"
            >
                {provider === null ? (
                    <SelectField
                        label={t("payments.provider", "المزوّد")}
                        required
                        value={key}
                        onChange={setKey}
                        // Item 10: the server sends `label => $key`, so this picker offered
                        // `paymob` and `cod` as its only text.
                        options={registry.map((entry) => ({
                            value: entry.value,
                            label: providerLabel(t, entry.value),
                        }))}
                        error={errors.provider ?? null}
                        hint={t(
                            "payments.one_contract_per_provider",
                            "العقد واحد لكل مزوّد لكل متجر.",
                        )}
                    />
                ) : null}

                <SwitchField
                    label={t("common.active", "مفعّل")}
                    checked={enabled}
                    onChange={setEnabled}
                />

                {fields.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "payments.provider_needs_no_keys",
                            "هذا المزوّد لا يحتاج مفاتيح.",
                        )}
                    </p>
                ) : (
                    <div className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "payments.leave_blank_hint",
                                "اتركه فارغًا ليبقى المحفوظ كما هو. لا تُعرض القيم المحفوظة هنا ولا في أي مكان آخر.",
                            )}
                        </p>
                        {fields.map((field) => (
                            <div key={field} className="space-y-1.5">
                                <Label htmlFor={`cred-${field}`}>
                                    {fieldLabel(t, field)}
                                </Label>
                                <Input
                                    id={`cred-${field}`}
                                    type="password"
                                    dir="ltr"
                                    autoComplete="new-password"
                                    value={credentials[field] ?? ""}
                                    placeholder={
                                        provider !== null &&
                                        provider.credential_keys_present.includes(
                                            field,
                                        )
                                            ? t(
                                                  "payments.key_set_placeholder",
                                                  "مضبوط — اتركه فارغًا للإبقاء عليه",
                                              )
                                            : t(
                                                  "payments.key_unset_placeholder",
                                                  "غير مضبوط",
                                              )
                                    }
                                    onChange={(event) =>
                                        setCredentials((current) => ({
                                            ...current,
                                            [field]: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        {t("common.cancel", "إلغاء")}
                    </Button>
                    <Button type="button" disabled={busy} onClick={submit}>
                        {t("common.save", "حفظ")}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Add or edit one method under a contract. Labels are per row, so both locales live here. */
function MethodDialog({
    base,
    methodKeys,
    provider,
    method,
    onClose,
}: {
    base: string;
    methodKeys: string[];
    provider: ProviderRow;
    method: MethodRow | null;
    onClose: () => void;
}) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const form = useForm({
        storefront_payment_provider_id: provider.id,
        method: method?.method ?? methodKeys[0] ?? "card",
        integration_id: method?.integration_id ?? "",
        icon: method?.icon ?? "",
        is_enabled: method?.is_enabled ?? true,
        sort: String(method?.sort ?? provider.methods.length),
        label: { ar: method?.label.ar ?? "", en: method?.label.en ?? "" },
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (method === null) {
            form.post(`${base}/methods`, options);
        } else {
            form.put(`${base}/methods/${method.id}`, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent
                title={
                    method === null
                        ? t(
                              "payments.new_method_under",
                              "طريقة جديدة تحت :provider",
                              { provider: providerLabel(t, provider.provider) },
                          )
                        : t("payments.edit_method", "تعديل :method", {
                              method: method.method,
                          })
                }
                className="space-y-4"
            >
                <SelectField
                    label={t("payments.method_key", "المفتاح")}
                    required
                    value={form.data.method}
                    onChange={(value) => form.setData("method", value)}
                    options={methodKeys.map((key) => ({
                        value: key,
                        label: `${methodLabel(t, key)} (${key})`,
                    }))}
                    error={errors.method ?? null}
                    hint={t(
                        "payments.method_key_hint",
                        "المفتاح ثابت في الكود وليس في قاعدة البيانات.",
                    )}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label={t("common.name_ar", "الاسم (عربي)")}
                        required
                        value={form.data.label.ar}
                        onChange={(value) =>
                            form.setData("label", {
                                ...form.data.label,
                                ar: value,
                            })
                        }
                        error={errors["label.ar"] ?? null}
                        hint={t(
                            "payments.label_ar_hint",
                            "هذا ما يقرأه العميل.",
                        )}
                    />
                    <TextField
                        label={t("common.name_en", "الاسم (إنجليزي)")}
                        dir="ltr"
                        value={form.data.label.en}
                        onChange={(value) =>
                            form.setData("label", {
                                ...form.data.label,
                                en: value,
                            })
                        }
                        error={errors["label.en"] ?? null}
                    />
                    <TextField
                        label={t("payments.integration_id", "رقم العملية لدى المزوّد")}
                        dir="ltr"
                        value={form.data.integration_id}
                        onChange={(value) =>
                            form.setData("integration_id", value)
                        }
                        error={errors.integration_id ?? null}
                        hint={t(
                            "payments.integration_id_hint",
                            "ليس مفتاحًا سريًّا: يأتي في حمولة الرد الموقَّعة ويُستخدم لمطابقة العملية في لوحة المزوّد.",
                        )}
                    />
                    <TextField
                        label={t("payments.icon", "الأيقونة")}
                        dir="ltr"
                        value={form.data.icon}
                        onChange={(value) => form.setData("icon", value)}
                        error={errors.icon ?? null}
                    />
                    <TextField
                        label={t("common.sort", "الترتيب")}
                        type="number"
                        dir="ltr"
                        min={0}
                        value={form.data.sort}
                        onChange={(value) => form.setData("sort", value)}
                        error={errors.sort ?? null}
                        hint={t(
                            "payments.sort_hint",
                            "الأقل يفوز عند تكرار المفتاح.",
                        )}
                    />
                    <SwitchField
                        label={t("payments.method_active", "مفعَّلة")}
                        checked={form.data.is_enabled}
                        onChange={(checked) =>
                            form.setData("is_enabled", checked)
                        }
                    />
                </div>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        {t("common.cancel", "إلغاء")}
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={submit}
                    >
                        {t("common.save", "حفظ")}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
