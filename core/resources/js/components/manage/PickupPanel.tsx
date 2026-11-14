import { router, usePage } from "@inertiajs/react";
import { useState } from "react";

import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Num } from "@/components/ui/bidi";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { useT } from "@/lib/i18n";
import type { SharedProps } from "@/types";

/**
 * The order page's pickup panel (click & collect, 2026-11-14): the store, the code, the clock, and the three
 * shop-floor steps — Ready for pickup, Extend (once), Collected (asks for the code). What may be pressed comes from
 * the server (`PickupLifecycle::options`); this only renders it. The WhatsApp button opens a ready-made message to the
 * customer for a person to send, and the slip prints for the parcel.
 */

type Bilingual = { en: string; ar: string };

export interface PickupInfo {
    status: string;
    status_label: string;
    code: string;
    pay_at_store: boolean;
    store: {
        name: Bilingual;
        address: Bilingual;
        hours: { en: string | null; ar: string | null };
        map_url: string | null;
        phone: string | null;
        whatsapp: string | null;
    };
    ready_sentence: Bilingual;
    reserved_at: string | null;
    ready_at: string | null;
    ready_by: string | null;
    expires_at: string | null;
    extended_at: string | null;
    extended_by: string | null;
    collected_at: string | null;
    collected_by: string | null;
    hold_hours: number;
    extend_hours: number;
    actions: { ready: boolean; extend: boolean; collect: boolean };
    overdue: boolean;
    late: boolean;
    whatsapp_url: string | null;
    slip_url: string;
}

const TONE: Record<string, "default" | "neutral" | "success" | "warning" | "destructive"> = {
    reserved: "warning",
    ready: "default",
    collected: "success",
    expired: "destructive",
    cancelled: "neutral",
};

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-1.5 text-sm">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <span className="text-end font-medium">{children}</span>
        </div>
    );
}

export function PickupPanel({
    orderId,
    orderNumber,
    email,
    pickup,
    canFulfil,
}: {
    orderId: number;
    orderNumber: string;
    email: string | null;
    pickup: PickupInfo;
    canFulfil: boolean;
}) {
    const t = useT();
    const { errors, locale } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);
    const [code, setCode] = useState("");
    const pick = (value: Bilingual) => (locale === "en" ? value.en : value.ar);

    const post = (step: "ready" | "extend" | "collect", body: Record<string, string> = {}) => {
        setBusy(true);
        router.post(`/manage/orders/${orderId}/pickup/${step}`, body, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const mailSentence =
        email === null
            ? t("pickup.mail_none", "لا يوجد بريد إلكتروني لهذا الطلب، فلن تُرسَل رسالة. استخدم زر واتساب.")
            : t("pickup.mail_to", "ستُرسَل رسالة إلى :email فورًا.", { email });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    {t("pickup.panel_title", "استلام من الفرع")}
                    <Badge variant={TONE[pickup.status] ?? "neutral"}>{pickup.status_label}</Badge>
                    {pickup.overdue ? (
                        <Badge variant="destructive">{t("pickup.state_overdue", "تجاوز موعد الاستلام")}</Badge>
                    ) : null}
                    {pickup.late ? (
                        <Badge variant="warning">{t("pickup.state_late", "متأخر التجهيز")}</Badge>
                    ) : null}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {errors.pickup ? <Alert tone="error">{errors.pickup}</Alert> : null}
                {errors.code ? <Alert tone="error">{errors.code}</Alert> : null}

                {pickup.overdue && !pickup.pay_at_store ? (
                    <Alert tone="warning">
                        {t(
                            "pickup.overdue_prepaid",
                            "مرّ موعد الاستلام وهذا الطلب مدفوع أونلاين، فلا يُلغى تلقائيًا. تواصل مع العميل: مدّد الحجز، أو ألغِ الطلب واسترجع المبلغ.",
                        )}
                    </Alert>
                ) : null}
                {pickup.late ? (
                    <Alert tone="warning">
                        {t(
                            "pickup.late_body",
                            "تجاوز الطلب أقصى مدة تجهيز وعدنا بها العميل (:sentence). جهّزه أو تواصل معه.",
                            { sentence: pick(pickup.ready_sentence) },
                        )}
                    </Alert>
                ) : null}

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="divide-y divide-border">
                        <Row label={t("pickup.store", "الفرع")}>{pick(pickup.store.name)}</Row>
                        <Row label={t("pickup.address", "العنوان")}>{pick(pickup.store.address)}</Row>
                        <Row label={t("pickup.code", "كود الاستلام")}>
                            <span className="font-mono text-lg tracking-[0.3em]" dir="ltr">
                                {pickup.code}
                            </span>
                        </Row>
                        <Row label={t("common.payment", "الدفع")}>
                            {pickup.pay_at_store
                                ? t("pickup.pay_at_store", "الدفع في الفرع — يُحصَّل عند التسليم")
                                : t("pickup.paid_online", "مدفوع أونلاين — لا يُحصَّل شيء")}
                        </Row>
                    </div>
                    <div className="divide-y divide-border">
                        <Row label={t("pickup.promised", "وعدنا العميل")}>{pick(pickup.ready_sentence)}</Row>
                        <Row label={t("pickup.reserved_at", "حُجز")}>
                            <Num>{pickup.reserved_at ?? "—"}</Num>
                        </Row>
                        {pickup.ready_at ? (
                            <Row label={t("pickup.ready_at", "جاهز منذ")}>
                                <Num>{pickup.ready_at}</Num>
                                {pickup.ready_by ? ` · ${pickup.ready_by}` : ""}
                            </Row>
                        ) : null}
                        {pickup.expires_at ? (
                            <Row label={t("pickup.collect_by", "يُستلم قبل")}>
                                <Num>{pickup.expires_at}</Num>
                                {pickup.extended_at
                                    ? ` · ${t("pickup.extended_by", "مُدّد بواسطة :name", { name: pickup.extended_by ?? "—" })}`
                                    : ""}
                            </Row>
                        ) : null}
                        {pickup.collected_at ? (
                            <Row label={t("pickup.collected_at", "استُلم")}>
                                <Num>{pickup.collected_at}</Num>
                                {pickup.collected_by ? ` · ${pickup.collected_by}` : ""}
                            </Row>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {canFulfil && pickup.actions.ready ? (
                        <ConfirmAction
                            tone="default"
                            title={t("pickup.ready_title", "الطلب :number جاهز في :store", {
                                number: orderNumber,
                                store: pick(pickup.store.name),
                            })}
                            confirmLabel={t("pickup.ready_confirm", "جاهز — أبلغ العميل")}
                            consequence={
                                <div className="space-y-2">
                                    <p>
                                        {t(
                                            "pickup.ready_consequence",
                                            "اضغط فقط بعد وصول المنتج إلى الفرع. يبدأ وقت الحجز الآن: :hours ساعة، ثم يُلغى الحجز تلقائيًا إن كان الدفع في الفرع.",
                                            { hours: pickup.hold_hours },
                                        )}
                                    </p>
                                    <p>{mailSentence}</p>
                                </div>
                            }
                            onConfirm={() => post("ready")}
                            disabled={busy}
                            trigger={
                                <Button size="sm" disabled={busy}>
                                    {t("pickup.ready_button", "جاهز للاستلام")}
                                </Button>
                            }
                        />
                    ) : null}

                    {canFulfil && pickup.actions.collect ? (
                        <ConfirmAction
                            tone="default"
                            title={t("pickup.collect_title", "تسليم الطلب :number", { number: orderNumber })}
                            confirmLabel={t("pickup.collect_confirm", "تم التسليم")}
                            consequence={
                                <div className="space-y-2">
                                    <p>
                                        {t(
                                            "pickup.collect_consequence",
                                            "اطلب من العميل رقم الطلب وكود الاستلام، وتحقق من آخر أرقام هاتفه. اكتب الكود الذي يقوله العميل:",
                                        )}
                                    </p>
                                    <Input
                                        inputMode="numeric"
                                        autoComplete="off"
                                        maxLength={4}
                                        dir="ltr"
                                        aria-label={t("pickup.code", "كود الاستلام")}
                                        value={code}
                                        onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))}
                                        placeholder="0000"
                                    />
                                    {pickup.pay_at_store ? (
                                        <p>{t("pickup.collect_cash", "حصّل المبلغ قبل التسليم.")}</p>
                                    ) : null}
                                    <p>{mailSentence}</p>
                                </div>
                            }
                            onConfirm={() => post("collect", { code })}
                            disabled={busy}
                            trigger={
                                <Button size="sm" disabled={busy}>
                                    {t("pickup.collect_button", "تم الاستلام")}
                                </Button>
                            }
                        />
                    ) : null}

                    {canFulfil && pickup.actions.extend ? (
                        <ConfirmAction
                            tone="default"
                            title={t("pickup.extend_title", "تمديد حجز الطلب :number", { number: orderNumber })}
                            confirmLabel={t("pickup.extend_confirm", "مدّد :hours ساعة", { hours: pickup.extend_hours })}
                            consequence={
                                <div className="space-y-2">
                                    <p>
                                        {t(
                                            "pickup.extend_consequence",
                                            "بطلب من العميل فقط. يُمدّد الحجز مرة واحدة :hours ساعة، ولا يمكن التمديد مرة أخرى.",
                                            { hours: pickup.extend_hours },
                                        )}
                                    </p>
                                    <p>{mailSentence}</p>
                                </div>
                            }
                            onConfirm={() => post("extend")}
                            disabled={busy}
                            trigger={
                                <Button size="sm" variant="outline" disabled={busy}>
                                    {t("pickup.extend_button", "تمديد الحجز")}
                                </Button>
                            }
                        />
                    ) : null}

                    {pickup.whatsapp_url ? (
                        <Button asChild size="sm" variant="outline">
                            <a href={pickup.whatsapp_url} target="_blank" rel="noopener noreferrer">
                                {t("pickup.whatsapp_button", "رسالة واتساب للعميل")}
                            </a>
                        </Button>
                    ) : null}

                    <Button asChild size="sm" variant="outline">
                        <a href={pickup.slip_url} target="_blank" rel="noopener">
                            {t("pickup.slip_button", "طباعة إيصال الاستلام")}
                        </a>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
