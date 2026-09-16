import { router, usePage } from "@inertiajs/react";
import { type ReactNode, useState } from "react";

import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import ManageLayout from "@/layouts/ManageLayout";
import type { SharedProps } from "@/types";
import { Ltr } from "@/components/ui/bidi";
import { useT } from "@/lib/i18n";

/**
 * One order (wave 4C).
 *
 * ── The screen is a LEDGER, not a form ───────────────────────────────────────────────────────
 *
 * Almost everything here is read-only, and that is the design. An order's lines, prices and
 * address are what the customer agreed to; editing them afterwards would make the order disagree
 * with the money taken for it. Exactly two controls write: advance the status, and cancel. Both go
 * through the domain (`OrderFulfilment`), and cancel returns stock through `InventoryService` —
 * never a column write (D-21).
 *
 * ── Why the movements table is on this page ──────────────────────────────────────────────────
 *
 * "Did the cancellation give the stock back?" has to be answerable by a person standing at a
 * counter, not only by a test. So the ledger rows this order caused are rendered with their reason
 * and the resulting quantity: a cancelled order shows the `order` reservation AND the
 * `order_cancel` release, and the pair is the proof.
 */

/** What `useT()` hands back — the label maps below are built from it, not from literals. */
type Translate = ReturnType<typeof useT>;

interface Item {
    id: number;
    product_id: number | null;
    wa_code: string | null;
    title: string | null;
    variant_id: number | null;
    variant: string | null;
    variant_sku: string | null;
    offer_id: number | null;
    quantity: number;
    piece_price: string;
    total_price: string;
    bucket: string | null;
    color_band: string | null;
    color_dial: string | null;
}

interface Attempt {
    id: number;
    provider: string | null;
    method: string | null;
    transaction_id: string | null;
    provider_order_id: string | null;
    amount: string | null;
    success: boolean;
    integration_id: string | null;
    created_at: string | null;
}

interface Movement {
    id: number;
    product_id: number | null;
    wa_code: string | null;
    variant: string | null;
    bucket: string;
    delta: number;
    after: number;
    reason: string;
    actor: string | null;
    note: string | null;
    created_at: string | null;
}

interface Finding {
    id: number;
    kind: string;
    outcome: string | null;
    /** The order's status WHEN the callback arrived — compare it with the current one. */
    order_status: string | null;
    amount: string | null;
    created_at: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
    note: string | null;
}

/**
 * One order e-mail: what it was, who it was for, and whether it got as far as the relay
 * (prerequisite (a)). `status` is the outbox row's own — sent | pending | sending | failed |
 * skipped — so a message still waiting for a retry and a message nobody will ever receive do not
 * look the same on this screen.
 */
interface Notification {
    id: number;
    event: string;
    kind: string;
    kind_label: string;
    recipient: string | null;
    status: string;
    attempts: number;
    created_at: string | null;
    processed_at: string | null;
    available_at: string | null;
    last_error: string | null;
}

interface Props {
    order: {
        id: number;
        order_number: string;
        status: string;
        status_label: string;
        total: string;
        payment_method: string | null;
        paid_via_provider: string | null;
        paid_via_method: string | null;
        note: string | null;
        customer: {
            name: string | null;
            email: string | null;
            phone: string | null;
            user_id: number | null;
        };
        storefront: string | null;
        storefront_id: number | null;
        created_at: string | null;
        updated_at: string | null;
    };
    items: Item[];
    address: {
        id: number;
        line: string | null;
        phone: string | null;
        phone_alt: string | null;
        city: string | null;
    } | null;
    attempts: Attempt[];
    movements: Movement[];
    /** Every order e-mail this order caused, newest first. */
    notifications: Notification[];
    /** What the domain says may happen next — never computed here (see OrderFulfilment). */
    options: { advance: string[]; may_cancel: boolean };
    /** Payment callbacks that need a human decision (🔴-1). Open ones first. */
    findings: Finding[];
    abilities: {
        fulfil: boolean;
        cancel: boolean;
        settle: boolean;
        resolve_findings: boolean;
    };
}

/**
 * The tone of a notification row. `failed` is destructive because a failed notification is a real
 * person who was not told something; `skipped` is merely neutral because there was nobody to tell
 * (a guest order with no e-mail address), which is information, not a fault.
 */
const MAIL_TONE: Record<
    string,
    "default" | "neutral" | "success" | "warning" | "destructive" | "outline"
> = {
    sent: "success",
    pending: "warning",
    sending: "warning",
    failed: "destructive",
    skipped: "neutral",
};

const mailStatusLabels = (t: Translate): Record<string, string> => ({
    sent: t("orders.mail_sent", "تم الإرسال"),
    pending: t("orders.mail_pending", "في الانتظار"),
    sending: t("orders.mail_sending", "جاري الإرسال"),
    failed: t("orders.mail_failed", "فشل"),
    skipped: t("orders.mail_skipped", "لا يوجد مستلم"),
});

const STATUS_TONE: Record<
    string,
    "default" | "neutral" | "success" | "warning" | "destructive" | "outline"
> = {
    pending: "warning",
    processing: "default",
    shipped: "default",
    // `delivered` is the green one: it is the state the customer cares about. `completed` means
    // CLOSED and is deliberately quiet, so a queue of green rows reads as "arrived", not "filed".
    delivered: "success",
    completed: "neutral",
    cancelled: "destructive",
};

/** Mirrors `OrderFulfilment::label()` — the server is the source, this is the button text. */
const statusLabels = (t: Translate): Record<string, string> => ({
    pending: t("common.status_pending", "قيد الانتظار"),
    processing: t("common.status_processing", "قيد التنفيذ"),
    shipped: t("common.status_shipped", "تم الشحن"),
    delivered: t("common.status_delivered", "تم التوصيل"),
    completed: t("common.status_completed", "مغلق"),
    cancelled: t("common.status_cancelled", "ملغى"),
});

/** The ledger reasons in Arabic — the same vocabulary the inventory screens use, translated once. */
const reasonLabels = (t: Translate): Record<string, string> => ({
    order: t("common.reason_order", "حجز لطلب"),
    order_cancel: t("common.reason_order_cancel", "إرجاع بعد إلغاء"),
    payment_failed: t("common.reason_payment_failed", "إرجاع بعد فشل دفع"),
    restock: t("common.reason_restock", "توريد"),
    manual: t("common.reason_manual", "تعديل يدوي"),
    import: t("common.reason_import", "استيراد"),
    adjustment: t("orders.reason_adjustment", "تسوية"),
    erp_sync: t("common.reason_erp_sync", "مزامنة ERP"),
    transform: t("common.reason_transform", "بناء أولي"),
});

const bucketLabels = (t: Translate): Record<string, string> => ({
    express: t("common.stock_express", "إكسبريس"),
    market: t("common.stock_market", "ماركت"),
});

/**
 * What each finding kind means, in the words an operator needs to act.
 *
 * Every one of them is "the money and the order disagree, and the system deliberately did NOT
 * guess": the attempt is recorded, the order was left alone, and nothing re-reserved stock.
 */
const findingLabels = (
    t: Translate,
): Record<string, { title: string; what: string }> => ({
    callback_on_terminal_order: {
        title: t(
            "orders.finding_terminal_title",
            "رد دفع على طلب مُغلق أو ملغى",
        ),
        what: t(
            "orders.finding_terminal_what",
            "وصل رد من المزوّد بعد أن انتهى الطلب أو أُلغي. لم تُغيَّر حالة الطلب ولم يُحجز مخزون من جديد — قرِّر أنت: هل يُعاد المبلغ، أم يُنشأ طلب جديد؟",
        ),
    },
    decline_after_payment: {
        title: t("orders.finding_decline_title", "محاولة فاشلة بعد دفعة ناجحة"),
        what: t(
            "orders.finding_decline_what",
            "فشلت محاولة لاحقة على طلب مدفوع بالفعل. الطلب لم يُلغَ ومخزونه لم يُحرَّر — تحقّق في لوحة المزوّد أيّ العمليتين هي الحقيقية.",
        ),
    },
    refund_or_void: {
        title: t("orders.finding_refund_title", "استرجاع أو إلغاء عملية"),
        what: t(
            "orders.finding_refund_what",
            "رجع المبلغ للعميل. عكس البيع ليس تغيير حالة: هو قرار عن البضاعة والمخزون معًا، ولهذا لم يفعله النظام وحده.",
        ),
    },
    amount_mismatch: {
        title: t("orders.finding_amount_title", "المبلغ لا يطابق الطلب"),
        what: t(
            "orders.finding_amount_what",
            "المزوّد ذكر مبلغًا مختلفًا عن إجمالي الطلب. لم يُعتبر الطلب مدفوعًا — طابِق المبلغ في لوحة المزوّد قبل أي إجراء.",
        ),
    },
});

const outcomeLabels = (t: Translate): Record<string, string> => ({
    success: t("orders.outcome_success", "نجحت"),
    failed: t("orders.outcome_failed", "فشلت"),
    refunded: t("orders.outcome_refunded", "استرجاع"),
    voided: t("orders.outcome_voided", "إلغاء عملية"),
    pending: t("orders.outcome_pending", "معلّقة"),
});

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-1.5 text-sm">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <span className="text-end font-medium">{children}</span>
        </div>
    );
}

export default function OrderShow({
    order,
    items,
    address,
    attempts,
    movements,
    notifications,
    options,
    findings,
    abilities,
}: Props) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const [note, setNote] = useState("");
    const [busy, setBusy] = useState(false);
    const [resolving, setResolving] = useState<number | null>(null);
    const [resolveNote, setResolveNote] = useState("");

    const MAIL_STATUS_LABEL = mailStatusLabels(t);
    const STATUS_LABEL = statusLabels(t);
    const REASON_LABEL = reasonLabels(t);
    const BUCKET_LABEL = bucketLabels(t);
    const FINDING_LABEL = findingLabels(t);
    const OUTCOME_LABEL = outcomeLabels(t);

    const openFindings = findings.filter(
        (finding) => finding.resolved_at === null,
    );
    const clearedFindings = findings.filter(
        (finding) => finding.resolved_at !== null,
    );

    const advance = (status: string) => {
        setBusy(true);
        router.put(
            `/manage/orders/${order.id}/status`,
            { status },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const cancel = () => {
        setBusy(true);
        router.post(
            `/manage/orders/${order.id}/cancel`,
            { note },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    // What cancelling will actually do to stock, counted from the rows already on the page: a
    // reservation that has not been released yet. This is the sentence ConfirmAction wants — a
    // consequence with a number in it, not "are you sure?". The server decides regardless; this
    // only tells the truth about what it is about to decide.
    const reserved = movements.filter(
        (movement) => movement.reason === "order",
    );
    const released = movements.filter(
        (movement) =>
            movement.reason === "order_cancel" ||
            movement.reason === "payment_failed",
    );
    const willRestore = released.length === 0 && reserved.length > 0;

    return (
        <ManageLayout
            title={t("orders.show_title", "طلب :number", {
                number: order.order_number,
            })}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                {
                    label: t("common.orders", "الطلبات"),
                    href: "/manage/orders",
                },
                { label: order.order_number },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {/* The status buttons come from the domain's transition map, so a status with
                        nowhere to go renders no button at all — and exactly ONE button shows at a
                        time, because the flow is one step per move: processing → shipped →
                        delivered → completed (`completed` = closed). */}
                    {abilities.fulfil
                        ? options.advance.map((next) => (
                              <Button
                                  key={next}
                                  size="sm"
                                  disabled={busy}
                                  onClick={() => advance(next)}
                              >
                                  {STATUS_LABEL[next] ?? next}
                              </Button>
                          ))
                        : null}

                    {abilities.cancel && options.may_cancel ? (
                        <ConfirmAction
                            title={t("orders.cancel_order", "إلغاء الطلب")}
                            confirmLabel={t(
                                "orders.cancel_confirm",
                                "ألغِ الطلب وأرجع المخزون",
                            )}
                            consequence={
                                <div className="space-y-2">
                                    <p>
                                        {t(
                                            "orders.cancel_consequence",
                                            "سيصبح الطلب :number ملغى، ولا يمكن التراجع عن ذلك من هذه الشاشة.",
                                            { number: order.order_number },
                                        )}
                                    </p>
                                    {willRestore ? (
                                        <p>
                                            {t(
                                                "orders.cancel_restores",
                                                "سيُرجَع المخزون المحجوز: :count حركة في سجل المخزون، عبر خدمة المخزون وليس بتعديل مباشر للأرصدة.",
                                                { count: reserved.length },
                                            )}
                                        </p>
                                    ) : (
                                        <p>
                                            {t(
                                                "orders.cancel_already_restored",
                                                "المخزون كان قد أُرجع سابقًا (:count حركة إرجاع مسجّلة)، فلن تُسجَّل حركة جديدة — الإرجاع يحدث مرة واحدة فقط.",
                                                { count: released.length },
                                            )}
                                        </p>
                                    )}
                                    <div className="pt-1">
                                        <label
                                            className="mb-1 block text-xs text-muted-foreground"
                                            htmlFor="cancel-note"
                                        >
                                            {t(
                                                "orders.cancel_note_label",
                                                "سبب الإلغاء (يُكتب في السجل)",
                                            )}
                                        </label>
                                        <Input
                                            id="cancel-note"
                                            value={note}
                                            maxLength={255}
                                            onChange={(event) =>
                                                setNote(event.target.value)
                                            }
                                            placeholder={t(
                                                "orders.cancel_note_placeholder",
                                                "مثال: العميل ألغى بالهاتف",
                                            )}
                                        />
                                    </div>
                                </div>
                            }
                            trigger={
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    disabled={busy}
                                >
                                    {t("orders.cancel_order", "إلغاء الطلب")}
                                </Button>
                            }
                            onConfirm={cancel}
                        />
                    ) : null}
                </div>
            }
        >
            <div className="space-y-6">
                {errors.status ? (
                    <Alert tone="error">{errors.status}</Alert>
                ) : null}
                {errors.cancel ? (
                    <Alert tone="error">{errors.cancel}</Alert>
                ) : null}

                {/* `orders.storefront_id` is new this wave and NULL on every order that predates it.
                    Saying so on the screen is cheaper than someone concluding the data is broken. */}
                {order.storefront_id === null ? (
                    <Alert
                        tone="info"
                        title={t(
                            "orders.no_storefront_title",
                            "هذا الطلب بلا متجر محدد",
                        )}
                    >
                        {t(
                            "orders.no_storefront_body",
                            "عمود المتجر أُضيف في هذه الموجة، فالطلبات الأقدم منه لا تحمله. تُعامل هذه الطلبات على أنها تابعة للمتجر الأساسي (Watchizer).",
                        )}
                    </Alert>
                ) : null}

                {/* Payment findings, ABOVE everything else on the page: each one means money and
                    this order disagree, and the system deliberately did not guess (review item 1). */}
                {openFindings.length > 0 ? (
                    <Alert
                        tone="error"
                        title={t(
                            "orders.findings_open_title",
                            ":count مطابقة دفع تحتاج قرارًا",
                            { count: openFindings.length },
                        )}
                    >
                        <div className="space-y-4">
                            {openFindings.map((finding) => (
                                <div
                                    key={finding.id}
                                    className="space-y-2 border-t border-destructive/20 pt-3 first:border-0 first:pt-0"
                                >
                                    <div className="font-medium">
                                        {FINDING_LABEL[finding.kind]?.title ??
                                            finding.kind}
                                        {finding.outcome !== null ? (
                                            <Badge
                                                variant="outline"
                                                className="mx-2"
                                            >
                                                {OUTCOME_LABEL[
                                                    finding.outcome
                                                ] ?? finding.outcome}
                                            </Badge>
                                        ) : null}
                                        {finding.amount !== null ? (
                                            <span dir="ltr">
                                                {" "}
                                                {finding.amount}
                                            </span>
                                        ) : null}
                                    </div>

                                    <p className="text-sm">
                                        {FINDING_LABEL[finding.kind]?.what ??
                                            ""}
                                    </p>

                                    {/* The order's state WHEN the callback arrived, beside its state now: if they
                                        differ, someone has already acted and this may be stale. */}
                                    <p className="text-xs opacity-80">
                                        {t(
                                            "orders.finding_state_line",
                                            "حالة الطلب وقت وصول الرد: :then · الآن: :now",
                                            {
                                                then:
                                                    finding.order_status ?? "—",
                                                now: order.status,
                                            },
                                        )}
                                        {finding.created_at !== null ? (
                                            <span dir="ltr">
                                                {" "}
                                                · {finding.created_at}
                                            </span>
                                        ) : null}
                                    </p>

                                    {abilities.resolve_findings ? (
                                        resolving === finding.id ? (
                                            <div className="space-y-2">
                                                <Input
                                                    value={resolveNote}
                                                    maxLength={255}
                                                    placeholder={t(
                                                        "orders.resolve_note_placeholder",
                                                        "ماذا تحقّقت منه، وما القرار؟",
                                                    )}
                                                    onChange={(event) =>
                                                        setResolveNote(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                {errors.note ? (
                                                    <p className="text-xs font-medium">
                                                        {errors.note}
                                                    </p>
                                                ) : null}
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        disabled={
                                                            resolveNote.trim()
                                                                .length < 3
                                                        }
                                                        onClick={() =>
                                                            router.post(
                                                                `/manage/orders/${order.id}/findings/${finding.id}/resolve`,
                                                                {
                                                                    note: resolveNote,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () => {
                                                                            setResolving(
                                                                                null,
                                                                            );
                                                                            setResolveNote(
                                                                                "",
                                                                            );
                                                                        },
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            "orders.resolve_confirm",
                                                            "صفِّ المطابقة",
                                                        )}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setResolving(null)
                                                        }
                                                    >
                                                        {t(
                                                            "common.cancel",
                                                            "إلغاء",
                                                        )}
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setResolving(finding.id)
                                                }
                                            >
                                                {t(
                                                    "orders.resolve_start",
                                                    "صفِّ هذه المطابقة",
                                                )}
                                            </Button>
                                        )
                                    ) : (
                                        // A sentence, not a hidden control: the reader should know the finding is
                                        // real and who can clear it.
                                        <p className="text-xs opacity-80">
                                            {t(
                                                "orders.resolve_forbidden",
                                                "تصفية المطابقة من صلاحية إدارة المدفوعات.",
                                            )}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Alert>
                ) : null}

                {clearedFindings.length > 0 ? (
                    <Alert
                        tone="info"
                        title={t(
                            "orders.findings_cleared_title",
                            ":count مطابقة مُصفّاة",
                            { count: clearedFindings.length },
                        )}
                    >
                        <ul className="space-y-1 text-xs">
                            {clearedFindings.map((finding) => (
                                <li key={finding.id}>
                                    {FINDING_LABEL[finding.kind]?.title ??
                                        finding.kind}{" "}
                                    — {finding.note ?? "—"}
                                    <span className="opacity-70">
                                        {" "}
                                        ({finding.resolved_by ?? "—"}
                                        {finding.resolved_at !== null ? (
                                            <span dir="ltr">
                                                , {finding.resolved_at}
                                            </span>
                                        ) : null}
                                        )
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Alert>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("orders.order_card", "الطلب")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            <Line label={t("common.status", "الحالة")}>
                                <Badge
                                    variant={
                                        STATUS_TONE[order.status] ?? "neutral"
                                    }
                                >
                                    {order.status_label}
                                </Badge>
                            </Line>
                            <Line label={t("common.total", "الإجمالي")}>
                                <span dir="ltr">{order.total}</span>
                            </Line>
                            <Line label={t("common.payment", "الدفع")}>
                                {order.paid_via_provider !== null ? (
                                    <span dir="ltr">
                                        {order.paid_via_provider}
                                        {order.paid_via_method !== null
                                            ? ` · ${order.paid_via_method}`
                                            : ""}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        {order.payment_method ??
                                            t("orders.unpaid", "غير مدفوع")}
                                    </span>
                                )}
                            </Line>
                            <Line label={t("common.storefront", "المتجر")}>
                                {order.storefront ??
                                    t(
                                        "orders.storefront_default",
                                        "الأساسي (غير محدد)",
                                    )}
                            </Line>
                            <Line label={t("orders.created", "أُنشئ")}>
                                <span dir="ltr">{order.created_at ?? "—"}</span>
                            </Line>
                            <Line label={t("common.last_updated", "آخر تحديث")}>
                                <span dir="ltr">{order.updated_at ?? "—"}</span>
                            </Line>
                            {order.note !== null ? (
                                <Line label={t("common.note", "ملاحظة")}>
                                    {order.note}
                                </Line>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("common.customer", "العميل")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            <Line label={t("common.name", "الاسم")}>
                                {order.customer.name ?? "—"}
                            </Line>
                            <Line label={t("common.phone", "الهاتف")}>
                                <span dir="ltr">
                                    {order.customer.phone ?? "—"}
                                </span>
                            </Line>
                            <Line label={t("common.email", "البريد")}>
                                <span dir="ltr">
                                    {order.customer.email ?? "—"}
                                </span>
                            </Line>
                            <Line
                                label={t("orders.account_type", "نوع الحساب")}
                            >
                                {order.customer.user_id === null
                                    ? t("common.guest", "ضيف")
                                    : t(
                                          "orders.account_registered",
                                          "مسجَّل (#:id)",
                                          { id: order.customer.user_id },
                                      )}
                            </Line>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("orders.shipping", "الشحن")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            {address === null ? (
                                <p className="py-2 text-sm text-muted-foreground">
                                    {t(
                                        "orders.no_address",
                                        "لا يوجد عنوان مرفق بهذا الطلب.",
                                    )}
                                </p>
                            ) : (
                                <>
                                    <Line label={t("common.city", "المدينة")}>
                                        {address.city ?? "—"}
                                    </Line>
                                    <Line
                                        label={t("common.address", "العنوان")}
                                    >
                                        {address.line ?? "—"}
                                    </Line>
                                    <Line label={t("common.phone", "الهاتف")}>
                                        <span dir="ltr">
                                            {address.phone ?? "—"}
                                        </span>
                                    </Line>
                                    {address.phone_alt !== null ? (
                                        <Line
                                            label={t(
                                                "orders.phone_alt",
                                                "هاتف بديل",
                                            )}
                                        >
                                            <span dir="ltr">
                                                {address.phone_alt}
                                            </span>
                                        </Line>
                                    ) : null}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t("orders.items_count", "الأصناف (:count)", {
                                count: items.length,
                            })}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t("common.product", "المنتج")}
                                    </TableHead>
                                    <TableHead>
                                        {t("orders.variant", "المتغيّر")}
                                    </TableHead>
                                    <TableHead>
                                        {t("orders.bucket", "المخزن")}
                                    </TableHead>
                                    <TableHead>
                                        {t("common.quantity", "الكمية")}
                                    </TableHead>
                                    <TableHead>
                                        {t("orders.unit_price", "سعر الوحدة")}
                                    </TableHead>
                                    <TableHead>
                                        {t("common.total", "الإجمالي")}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <div className="space-y-0.5">
                                                <div className="font-medium">
                                                    {item.title ?? "—"}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    <Ltr>
                                                        {item.wa_code ?? "—"}
                                                        {item.offer_id !== null
                                                            ? ` · ${t("orders.offer", "عرض")}`
                                                            : ""}
                                                    </Ltr>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {/* The variant is what the warehouse picks. An order that predates
                                                variants has none, so the colours the legacy cart stored on the
                                                LINE are shown instead of an empty cell. */}
                                            {item.variant !== null ? (
                                                <div className="space-y-0.5">
                                                    <div>{item.variant}</div>
                                                    {item.variant_sku !==
                                                    null ? (
                                                        <div className="text-xs text-muted-foreground">
                                                            <Ltr>
                                                                {
                                                                    item.variant_sku
                                                                }
                                                            </Ltr>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    {[
                                                        item.color_dial,
                                                        item.color_band,
                                                    ]
                                                        .filter(
                                                            (value) =>
                                                                value !== null,
                                                        )
                                                        .join(" / ") || "—"}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {item.bucket === null
                                                ? "—"
                                                : (BUCKET_LABEL[item.bucket] ??
                                                  item.bucket)}
                                        </TableCell>
                                        <TableCell dir="ltr">
                                            {item.quantity}
                                        </TableCell>
                                        <TableCell dir="ltr">
                                            {item.piece_price}
                                        </TableCell>
                                        <TableCell
                                            className="font-medium"
                                            dir="ltr"
                                        >
                                            {item.total_price}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {items.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-6 text-center text-sm text-muted-foreground"
                                        >
                                            {t(
                                                "orders.no_items",
                                                "لا توجد أصناف على هذا الطلب.",
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t(
                                    "orders.attempts_count",
                                    "محاولات الدفع (:count)",
                                    { count: attempts.length },
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                "orders.provider_method",
                                                "المزوّد / الطريقة",
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                "orders.transaction_id",
                                                "رقم العملية",
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t("orders.amount", "المبلغ")}
                                        </TableHead>
                                        <TableHead>
                                            {t("orders.outcome", "النتيجة")}
                                        </TableHead>
                                        <TableHead>
                                            {t("common.date", "التاريخ")}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {attempts.map((attempt) => (
                                        <TableRow key={attempt.id}>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    <div>
                                                        <Ltr>
                                                            {attempt.provider ??
                                                                "—"}
                                                            {attempt.method !==
                                                            null
                                                                ? ` · ${attempt.method}`
                                                                : ""}
                                                        </Ltr>
                                                    </div>
                                                    {/* The integration id is NOT a credential: the provider puts it
                                                        in the signed payload, and it is how an admin finds this row
                                                        in the merchant portal. No secret is ever sent to a screen. */}
                                                    {attempt.integration_id !==
                                                    null ? (
                                                        <div className="text-xs text-muted-foreground">
                                                            <Ltr>
                                                                integration{" "}
                                                                {
                                                                    attempt.integration_id
                                                                }
                                                            </Ltr>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell
                                                className="text-xs"
                                                dir="ltr"
                                            >
                                                <div>
                                                    {attempt.transaction_id ??
                                                        "—"}
                                                </div>
                                                {attempt.provider_order_id !==
                                                null ? (
                                                    <div className="text-muted-foreground">
                                                        {
                                                            attempt.provider_order_id
                                                        }
                                                    </div>
                                                ) : null}
                                            </TableCell>
                                            <TableCell dir="ltr">
                                                {attempt.amount ?? "—"}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        attempt.success
                                                            ? "success"
                                                            : "destructive"
                                                    }
                                                >
                                                    {attempt.success
                                                        ? t(
                                                              "orders.outcome_success",
                                                              "نجحت",
                                                          )
                                                        : t(
                                                              "orders.outcome_failed",
                                                              "فشلت",
                                                          )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell
                                                className="text-xs text-muted-foreground"
                                                dir="ltr"
                                            >
                                                {attempt.created_at ?? "—"}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {attempts.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="py-6 text-center text-sm text-muted-foreground"
                                            >
                                                {t(
                                                    "orders.no_attempts",
                                                    "لا توجد محاولات دفع مسجّلة — دفع عند الاستلام، أو طلب أقدم من جدول المحاولات.",
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t(
                                    "orders.movements_count",
                                    "حركات المخزون لهذا الطلب (:count)",
                                    { count: movements.length },
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 p-0">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t("common.product", "المنتج")}
                                            </TableHead>
                                            <TableHead>
                                                {t("orders.bucket", "المخزن")}
                                            </TableHead>
                                            <TableHead>
                                                {t("common.change", "التغيير")}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    "orders.balance_after",
                                                    "الرصيد بعدها",
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t("common.reason", "السبب")}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {movements.map((movement) => (
                                            <TableRow key={movement.id}>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <div className="text-xs">
                                                            <Ltr>
                                                                {movement.wa_code ??
                                                                    `#${movement.product_id ?? "—"}`}
                                                            </Ltr>
                                                        </div>
                                                        {movement.variant !==
                                                        null ? (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    movement.variant
                                                                }
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {BUCKET_LABEL[
                                                        movement.bucket
                                                    ] ?? movement.bucket}
                                                </TableCell>
                                                <TableCell dir="ltr">
                                                    <span
                                                        className={
                                                            movement.delta > 0
                                                                ? "font-medium text-emerald-700 dark:text-emerald-400"
                                                                : "font-medium text-destructive"
                                                        }
                                                    >
                                                        {movement.delta > 0
                                                            ? `+${movement.delta}`
                                                            : movement.delta}
                                                    </span>
                                                </TableCell>
                                                <TableCell dir="ltr">
                                                    {movement.after}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <Badge variant="outline">
                                                            {REASON_LABEL[
                                                                movement.reason
                                                            ] ??
                                                                movement.reason}
                                                        </Badge>
                                                        {movement.note !==
                                                        null ? (
                                                            <div className="text-xs text-muted-foreground">
                                                                {movement.note}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {movements.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={5}
                                                    className="py-6 text-center text-sm text-muted-foreground"
                                                >
                                                    {t(
                                                        "orders.no_movements",
                                                        "لا توجد حركات مخزون لهذا الطلب.",
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </TableBody>
                                </Table>
                            </div>
                            <p className="px-4 pb-4 text-xs text-muted-foreground">
                                {t(
                                    "orders.ledger_read_only",
                                    "السجل للقراءة فقط: لا توجد شاشة ولا مسار يعدّله. كل سطر هنا كتبته خدمة المخزون.",
                                )}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t(
                                    "orders.notifications_count",
                                    "رسائل هذا الطلب (:count)",
                                    { count: notifications.length },
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 p-0">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t("orders.message", "الرسالة")}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    "orders.recipient",
                                                    "المستلم",
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t("common.status", "الحالة")}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    "orders.attempts",
                                                    "المحاولات",
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t("common.date", "التاريخ")}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {notifications.map((notification) => (
                                            <TableRow key={notification.id}>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <div className="text-sm">
                                                            {
                                                                notification.kind_label
                                                            }
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            <Ltr>
                                                                {
                                                                    notification.event
                                                                }
                                                            </Ltr>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell
                                                    className="text-xs"
                                                    dir="ltr"
                                                >
                                                    {notification.recipient ??
                                                        "—"}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <Badge
                                                            variant={
                                                                MAIL_TONE[
                                                                    notification
                                                                        .status
                                                                ] ?? "outline"
                                                            }
                                                        >
                                                            {MAIL_STATUS_LABEL[
                                                                notification
                                                                    .status
                                                            ] ??
                                                                notification.status}
                                                        </Badge>
                                                        {notification.last_error !==
                                                        null ? (
                                                            <div className="max-w-xs text-xs break-words text-muted-foreground">
                                                                <Ltr>
                                                                    {
                                                                        notification.last_error
                                                                    }
                                                                </Ltr>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell dir="ltr">
                                                    {notification.attempts}
                                                </TableCell>
                                                <TableCell
                                                    className="text-xs"
                                                    dir="ltr"
                                                >
                                                    {notification.processed_at ??
                                                        notification.created_at ??
                                                        "—"}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {notifications.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={5}
                                                    className="py-6 text-center text-sm text-muted-foreground"
                                                >
                                                    {t(
                                                        "orders.no_notifications",
                                                        "لم تُرسل أي رسالة عن هذا الطلب.",
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </TableBody>
                                </Table>
                            </div>
                            <p className="px-4 pb-4 text-xs text-muted-foreground">
                                {t(
                                    "orders.mail_note_before",
                                    "الرسائل تُرسل فور تغيّر حالة الطلب. لو فشل الإرسال يبقى السطر في الانتظار ويعيد",
                                )}
                                <span dir="ltr"> mail:drain </span>
                                {t(
                                    "orders.mail_note_after",
                                    "المحاولة كل دقيقة؛ و«فشل» يعني أن أحدًا لم يُبلَّغ ويحتاج تدخّلًا.",
                                )}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </ManageLayout>
    );
}
