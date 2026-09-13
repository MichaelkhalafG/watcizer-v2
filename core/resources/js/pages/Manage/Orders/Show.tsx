import { router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

import { ConfirmAction } from '@/components/manage/ConfirmAction';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import type { SharedProps } from '@/types';

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
        customer: { name: string | null; email: string | null; phone: string | null; user_id: number | null };
        storefront: string | null;
        storefront_id: number | null;
        created_at: string | null;
        updated_at: string | null;
    };
    items: Item[];
    address: { id: number; line: string | null; phone: string | null; phone_alt: string | null; city: string | null } | null;
    attempts: Attempt[];
    movements: Movement[];
    /** What the domain says may happen next — never computed here (see OrderFulfilment). */
    options: { advance: string[]; may_cancel: boolean };
    /** Payment callbacks that need a human decision (🔴-1). Open ones first. */
    findings: Finding[];
    abilities: { fulfil: boolean; cancel: boolean; settle: boolean; resolve_findings: boolean };
}

const STATUS_TONE: Record<string, 'default' | 'neutral' | 'success' | 'warning' | 'destructive' | 'outline'> = {
    pending: 'warning',
    processing: 'default',
    shipped: 'default',
    // `delivered` is the green one: it is the state the customer cares about. `completed` means
    // CLOSED and is deliberately quiet, so a queue of green rows reads as "arrived", not "filed".
    delivered: 'success',
    completed: 'neutral',
    cancelled: 'destructive',
};

/** Mirrors `OrderFulfilment::label()` — the server is the source, this is the button text. */
const STATUS_LABEL: Record<string, string> = {
    pending: 'قيد الانتظار',
    processing: 'قيد التنفيذ',
    shipped: 'تم الشحن',
    delivered: 'تم التوصيل',
    completed: 'مغلق',
    cancelled: 'ملغى',
};

/** The ledger reasons in Arabic — the same vocabulary the inventory screens use, translated once. */
const REASON_LABEL: Record<string, string> = {
    order: 'حجز لطلب',
    order_cancel: 'إرجاع بعد إلغاء',
    payment_failed: 'إرجاع بعد فشل دفع',
    restock: 'توريد',
    manual: 'تعديل يدوي',
    import: 'استيراد',
    adjustment: 'تسوية',
    erp_sync: 'مزامنة ERP',
    transform: 'بناء أولي',
};

const BUCKET_LABEL: Record<string, string> = { express: 'إكسبريس', market: 'السوق' };

/**
 * What each finding kind means, in the words an operator needs to act.
 *
 * Every one of them is "the money and the order disagree, and the system deliberately did NOT
 * guess": the attempt is recorded, the order was left alone, and nothing re-reserved stock.
 */
const FINDING_LABEL: Record<string, { title: string; what: string }> = {
    callback_on_terminal_order: {
        title: 'رد دفع على طلب مُغلق أو ملغى',
        what: 'وصل رد من المزوّد بعد أن انتهى الطلب أو أُلغي. لم تُغيَّر حالة الطلب ولم يُحجز مخزون من جديد — قرِّر أنت: هل يُعاد المبلغ، أم يُنشأ طلب جديد؟',
    },
    decline_after_payment: {
        title: 'محاولة فاشلة بعد دفعة ناجحة',
        what: 'فشلت محاولة لاحقة على طلب مدفوع بالفعل. الطلب لم يُلغَ ومخزونه لم يُحرَّر — تحقّق في لوحة المزوّد أيّ العمليتين هي الحقيقية.',
    },
    refund_or_void: {
        title: 'استرجاع أو إلغاء عملية',
        what: 'رجع المبلغ للعميل. عكس البيع ليس تغيير حالة: هو قرار عن البضاعة والمخزون معًا، ولهذا لم يفعله النظام وحده.',
    },
    amount_mismatch: {
        title: 'المبلغ لا يطابق الطلب',
        what: 'المزوّد ذكر مبلغًا مختلفًا عن إجمالي الطلب. لم يُعتبر الطلب مدفوعًا — طابِق المبلغ في لوحة المزوّد قبل أي إجراء.',
    },
};

const OUTCOME_LABEL: Record<string, string> = {
    success: 'نجحت',
    failed: 'فشلت',
    refunded: 'استرجاع',
    voided: 'إلغاء عملية',
    pending: 'معلّقة',
};

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-1.5 text-sm">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <span className="text-end font-medium">{children}</span>
        </div>
    );
}

export default function OrderShow({ order, items, address, attempts, movements, options, findings, abilities }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [resolving, setResolving] = useState<number | null>(null);
    const [resolveNote, setResolveNote] = useState('');

    const openFindings = findings.filter((finding) => finding.resolved_at === null);
    const clearedFindings = findings.filter((finding) => finding.resolved_at !== null);

    const advance = (status: string) => {
        setBusy(true);
        router.put(`/manage/orders/${order.id}/status`, { status }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const cancel = () => {
        setBusy(true);
        router.post(`/manage/orders/${order.id}/cancel`, { note }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    // What cancelling will actually do to stock, counted from the rows already on the page: a
    // reservation that has not been released yet. This is the sentence ConfirmAction wants — a
    // consequence with a number in it, not "are you sure?". The server decides regardless; this
    // only tells the truth about what it is about to decide.
    const reserved = movements.filter((movement) => movement.reason === 'order');
    const released = movements.filter((movement) => movement.reason === 'order_cancel' || movement.reason === 'payment_failed');
    const willRestore = released.length === 0 && reserved.length > 0;

    return (
        <ManageLayout
            title={`طلب ${order.order_number}`}
            crumbs={[
                { label: 'الرئيسية', href: '/manage' },
                { label: 'الطلبات', href: '/manage/orders' },
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
                              <Button key={next} size="sm" disabled={busy} onClick={() => advance(next)}>
                                  {STATUS_LABEL[next] ?? next}
                              </Button>
                          ))
                        : null}

                    {abilities.cancel && options.may_cancel ? (
                        <ConfirmAction
                            title="إلغاء الطلب"
                            confirmLabel="ألغِ الطلب وأرجع المخزون"
                            consequence={
                                <div className="space-y-2">
                                    <p>
                                        سيصبح الطلب <span className="font-medium">{order.order_number}</span> ملغى، ولا
                                        يمكن التراجع عن ذلك من هذه الشاشة.
                                    </p>
                                    {willRestore ? (
                                        <p>
                                            سيُرجَع المخزون المحجوز: {reserved.length} حركة في سجل المخزون، عبر خدمة
                                            المخزون وليس بتعديل مباشر للأرصدة.
                                        </p>
                                    ) : (
                                        <p>
                                            المخزون كان قد أُرجع سابقًا ({released.length} حركة إرجاع مسجّلة)، فلن تُسجَّل
                                            حركة جديدة — الإرجاع يحدث مرة واحدة فقط.
                                        </p>
                                    )}
                                    <div className="pt-1">
                                        <label className="mb-1 block text-xs text-muted-foreground" htmlFor="cancel-note">
                                            سبب الإلغاء (يُكتب في السجل)
                                        </label>
                                        <Input
                                            id="cancel-note"
                                            value={note}
                                            maxLength={255}
                                            onChange={(event) => setNote(event.target.value)}
                                            placeholder="مثال: العميل ألغى بالهاتف"
                                        />
                                    </div>
                                </div>
                            }
                            trigger={
                                <Button variant="destructive" size="sm" disabled={busy}>
                                    إلغاء الطلب
                                </Button>
                            }
                            onConfirm={cancel}
                        />
                    ) : null}
                </div>
            }
        >
            <div className="space-y-6">
                {errors.status ? <Alert tone="error">{errors.status}</Alert> : null}
                {errors.cancel ? <Alert tone="error">{errors.cancel}</Alert> : null}

                {/* `orders.storefront_id` is new this wave and NULL on every order that predates it.
                    Saying so on the screen is cheaper than someone concluding the data is broken. */}
                {order.storefront_id === null ? (
                    <Alert tone="info" title="هذا الطلب بلا متجر محدد">
                        عمود المتجر أُضيف في هذه الموجة، فالطلبات الأقدم منه لا تحمله. تُعامل هذه الطلبات على أنها تابعة
                        للمتجر الأساسي (Watchizer).
                    </Alert>
                ) : null}

                {/* Payment findings, ABOVE everything else on the page: each one means money and
                    this order disagree, and the system deliberately did not guess (review item 1). */}
                {openFindings.length > 0 ? (
                    <Alert tone="error" title={`${openFindings.length} مطابقة دفع تحتاج قرارًا`}>
                        <div className="space-y-4">
                            {openFindings.map((finding) => (
                                <div
                                    key={finding.id}
                                    className="space-y-2 border-t border-destructive/20 pt-3 first:border-0 first:pt-0"
                                >
                                    <div className="font-medium">
                                        {FINDING_LABEL[finding.kind]?.title ?? finding.kind}
                                        {finding.outcome !== null ? (
                                            <Badge variant="outline" className="mx-2">
                                                {OUTCOME_LABEL[finding.outcome] ?? finding.outcome}
                                            </Badge>
                                        ) : null}
                                        {finding.amount !== null ? <span dir="ltr"> {finding.amount}</span> : null}
                                    </div>

                                    <p className="text-sm">{FINDING_LABEL[finding.kind]?.what ?? ''}</p>

                                    {/* The order's state WHEN the callback arrived, beside its state now: if they
                                        differ, someone has already acted and this may be stale. */}
                                    <p className="text-xs opacity-80">
                                        حالة الطلب وقت وصول الرد: {finding.order_status ?? '—'} · الآن: {order.status}
                                        {finding.created_at !== null ? <span dir="ltr"> · {finding.created_at}</span> : null}
                                    </p>

                                    {abilities.resolve_findings ? (
                                        resolving === finding.id ? (
                                            <div className="space-y-2">
                                                <Input
                                                    value={resolveNote}
                                                    maxLength={255}
                                                    placeholder="ماذا تحقّقت منه، وما القرار؟"
                                                    onChange={(event) => setResolveNote(event.target.value)}
                                                />
                                                {errors.note ? <p className="text-xs font-medium">{errors.note}</p> : null}
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        disabled={resolveNote.trim().length < 3}
                                                        onClick={() =>
                                                            router.post(
                                                                `/manage/orders/${order.id}/findings/${finding.id}/resolve`,
                                                                { note: resolveNote },
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess: () => {
                                                                        setResolving(null);
                                                                        setResolveNote('');
                                                                    },
                                                                },
                                                            )
                                                        }
                                                    >
                                                        صفِّ المطابقة
                                                    </Button>
                                                    <Button size="sm" variant="outline" onClick={() => setResolving(null)}>
                                                        إلغاء
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <Button size="sm" variant="outline" onClick={() => setResolving(finding.id)}>
                                                صفِّ هذه المطابقة
                                            </Button>
                                        )
                                    ) : (
                                        // A sentence, not a hidden control: the reader should know the finding is
                                        // real and who can clear it.
                                        <p className="text-xs opacity-80">تصفية المطابقة من صلاحية إدارة المدفوعات.</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Alert>
                ) : null}

                {clearedFindings.length > 0 ? (
                    <Alert tone="info" title={`${clearedFindings.length} مطابقة مُصفّاة`}>
                        <ul className="space-y-1 text-xs">
                            {clearedFindings.map((finding) => (
                                <li key={finding.id}>
                                    {FINDING_LABEL[finding.kind]?.title ?? finding.kind} — {finding.note ?? '—'}
                                    <span className="opacity-70">
                                        {' '}
                                        ({finding.resolved_by ?? '—'}
                                        {finding.resolved_at !== null ? <span dir="ltr">, {finding.resolved_at}</span> : null})
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Alert>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>الطلب</CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            <Line label="الحالة">
                                <Badge variant={STATUS_TONE[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                            </Line>
                            <Line label="الإجمالي">
                                <span dir="ltr">{order.total}</span>
                            </Line>
                            <Line label="الدفع">
                                {order.paid_via_provider !== null ? (
                                    <span dir="ltr">
                                        {order.paid_via_provider}
                                        {order.paid_via_method !== null ? ` · ${order.paid_via_method}` : ''}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">{order.payment_method ?? 'غير مدفوع'}</span>
                                )}
                            </Line>
                            <Line label="المتجر">{order.storefront ?? 'الأساسي (غير محدد)'}</Line>
                            <Line label="أُنشئ">
                                <span dir="ltr">{order.created_at ?? '—'}</span>
                            </Line>
                            <Line label="آخر تحديث">
                                <span dir="ltr">{order.updated_at ?? '—'}</span>
                            </Line>
                            {order.note !== null ? <Line label="ملاحظة">{order.note}</Line> : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>العميل</CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            <Line label="الاسم">{order.customer.name ?? '—'}</Line>
                            <Line label="الهاتف">
                                <span dir="ltr">{order.customer.phone ?? '—'}</span>
                            </Line>
                            <Line label="البريد">
                                <span dir="ltr">{order.customer.email ?? '—'}</span>
                            </Line>
                            <Line label="نوع الحساب">
                                {order.customer.user_id === null ? 'ضيف' : `مسجَّل (#${order.customer.user_id})`}
                            </Line>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>الشحن</CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y divide-border">
                            {address === null ? (
                                <p className="py-2 text-sm text-muted-foreground">لا يوجد عنوان مرفق بهذا الطلب.</p>
                            ) : (
                                <>
                                    <Line label="المدينة">{address.city ?? '—'}</Line>
                                    <Line label="العنوان">{address.line ?? '—'}</Line>
                                    <Line label="هاتف">
                                        <span dir="ltr">{address.phone ?? '—'}</span>
                                    </Line>
                                    {address.phone_alt !== null ? (
                                        <Line label="هاتف بديل">
                                            <span dir="ltr">{address.phone_alt}</span>
                                        </Line>
                                    ) : null}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>الأصناف ({items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>المنتج</TableHead>
                                    <TableHead>المتغيّر</TableHead>
                                    <TableHead>المخزن</TableHead>
                                    <TableHead>الكمية</TableHead>
                                    <TableHead>سعر الوحدة</TableHead>
                                    <TableHead>الإجمالي</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <div className="space-y-0.5">
                                                <div className="font-medium">{item.title ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground" dir="ltr">
                                                    {item.wa_code ?? '—'}
                                                    {item.offer_id !== null ? ' · عرض' : ''}
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
                                                    {item.variant_sku !== null ? (
                                                        <div className="text-xs text-muted-foreground" dir="ltr">
                                                            {item.variant_sku}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    {[item.color_dial, item.color_band].filter((value) => value !== null).join(' / ') || '—'}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{item.bucket === null ? '—' : (BUCKET_LABEL[item.bucket] ?? item.bucket)}</TableCell>
                                        <TableCell dir="ltr">{item.quantity}</TableCell>
                                        <TableCell dir="ltr">{item.piece_price}</TableCell>
                                        <TableCell className="font-medium" dir="ltr">
                                            {item.total_price}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {items.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-6 text-center text-sm text-muted-foreground">
                                            لا توجد أصناف على هذا الطلب.
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
                            <CardTitle>محاولات الدفع ({attempts.length})</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>المزوّد / الطريقة</TableHead>
                                        <TableHead>رقم العملية</TableHead>
                                        <TableHead>المبلغ</TableHead>
                                        <TableHead>النتيجة</TableHead>
                                        <TableHead>التاريخ</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {attempts.map((attempt) => (
                                        <TableRow key={attempt.id}>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    <div dir="ltr">
                                                        {attempt.provider ?? '—'}
                                                        {attempt.method !== null ? ` · ${attempt.method}` : ''}
                                                    </div>
                                                    {/* The integration id is NOT a credential: the provider puts it
                                                        in the signed payload, and it is how an admin finds this row
                                                        in the merchant portal. No secret is ever sent to a screen. */}
                                                    {attempt.integration_id !== null ? (
                                                        <div className="text-xs text-muted-foreground" dir="ltr">
                                                            integration {attempt.integration_id}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-xs" dir="ltr">
                                                <div>{attempt.transaction_id ?? '—'}</div>
                                                {attempt.provider_order_id !== null ? (
                                                    <div className="text-muted-foreground">{attempt.provider_order_id}</div>
                                                ) : null}
                                            </TableCell>
                                            <TableCell dir="ltr">{attempt.amount ?? '—'}</TableCell>
                                            <TableCell>
                                                <Badge variant={attempt.success ? 'success' : 'destructive'}>
                                                    {attempt.success ? 'نجحت' : 'فشلت'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground" dir="ltr">
                                                {attempt.created_at ?? '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {attempts.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-6 text-center text-sm text-muted-foreground">
                                                لا توجد محاولات دفع مسجّلة — دفع عند الاستلام، أو طلب أقدم من جدول
                                                المحاولات.
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>حركات المخزون لهذا الطلب ({movements.length})</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 p-0">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>المنتج</TableHead>
                                            <TableHead>المخزن</TableHead>
                                            <TableHead>التغيير</TableHead>
                                            <TableHead>الرصيد بعدها</TableHead>
                                            <TableHead>السبب</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {movements.map((movement) => (
                                            <TableRow key={movement.id}>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <div className="text-xs" dir="ltr">
                                                            {movement.wa_code ?? `#${movement.product_id ?? '—'}`}
                                                        </div>
                                                        {movement.variant !== null ? (
                                                            <div className="text-xs text-muted-foreground">{movement.variant}</div>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell>{BUCKET_LABEL[movement.bucket] ?? movement.bucket}</TableCell>
                                                <TableCell dir="ltr">
                                                    <span
                                                        className={
                                                            movement.delta > 0
                                                                ? 'font-medium text-emerald-700 dark:text-emerald-400'
                                                                : 'font-medium text-destructive'
                                                        }
                                                    >
                                                        {movement.delta > 0 ? `+${movement.delta}` : movement.delta}
                                                    </span>
                                                </TableCell>
                                                <TableCell dir="ltr">{movement.after}</TableCell>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <Badge variant="outline">{REASON_LABEL[movement.reason] ?? movement.reason}</Badge>
                                                        {movement.note !== null ? (
                                                            <div className="text-xs text-muted-foreground">{movement.note}</div>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {movements.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="py-6 text-center text-sm text-muted-foreground">
                                                    لا توجد حركات مخزون لهذا الطلب.
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </TableBody>
                                </Table>
                            </div>
                            <p className="px-4 pb-4 text-xs text-muted-foreground">
                                السجل للقراءة فقط: لا توجد شاشة ولا مسار يعدّله. كل سطر هنا كتبته خدمة المخزون.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </ManageLayout>
    );
}
