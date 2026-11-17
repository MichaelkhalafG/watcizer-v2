import { router } from '@inertiajs/react';
import { Check, Copy, EyeOff, MessageCircle, X } from 'lucide-react';
import { useState } from 'react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { ProductName } from '@/components/manage/ProductName';
import { Ltr } from '@/components/ui/bidi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';

/**
 * Reviews (SEO Phase 5, 2026-11-14; Michael's decisions of 2026-11-17). Every review is LIVE on its product page at
 * once — from a product page without a login, or from the review link after a delivery — unless it carries a link or a
 * phone number: then it is HELD here for the team. Never held for its stars. The team approves a held review or hides
 * any review; nobody edits what a customer wrote. "Verified buyer" = the e-mail (or the link's order) has a delivered
 * order of that product. Below: the latest review links (a fresh one for WhatsApp), and per branch its Google review
 * link, its in-store address (QR code), the WhatsApp message to copy, and how many times the address was scanned.
 * Times are Cairo time.
 */

interface Row {
    id: number;
    title: { en: string; ar: string };
    rating: number;
    body: string;
    name: string;
    photos: string[];
    order_number: string | null;
    created_at: string | null;
    rejection_reason: string | null;
    source: 'guest' | 'request';
    verified: boolean;
    held_reason: string | null;
}

interface Branch {
    id: number;
    name: { en: string; ar: string };
    is_active: boolean;
    google_review_url: string | null;
    review_key: string | null;
    whatsapp_review_template: string | null;
    review_link: string | null;
    scans_30_days: number;
    scans_total: number;
}

interface RequestRow {
    id: number;
    order_number: string | null;
    email: boolean;
    phone: string | null;
    sent_at: string | null;
    created_at: string | null;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    status: 'pending' | 'approved' | 'rejected';
    counts: Record<string, number>;
    summary: { count: number; average: number | null };
    emails_on: boolean;
    table: TablePayload<Row>;
    requests: RequestRow[];
    branches: Branch[];
}

const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(Math.max(0, 5 - n));

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export default function ReviewsIndex({ storefront, status, counts, summary, emails_on, table, requests, branches }: Props) {
    const t = useT();
    const [rows, setRows] = useState<Branch[]>(branches);
    const [copied, setCopied] = useState<number | null>(null);
    const setBranch = (id: number, patch: Partial<Branch>) => setRows((prev) => prev.map((b) => (b.id === id ? { ...b, ...patch } : b)));
    const saveBranches = () =>
        router.put(
            `/manage/storefronts/${storefront.id}/reviews-branches`,
            {
                branches: rows.map((b) => ({
                    id: b.id,
                    google_review_url: b.google_review_url ?? '',
                    review_key: b.review_key ?? '',
                    whatsapp_review_template: b.whatsapp_review_template ?? '',
                })),
            },
            { preserveScroll: true },
        );
    // the message with the branch's own address in place of {link}, to paste into WhatsApp
    const copyMessage = async (b: Branch) => {
        const link = b.review_link ?? '';
        await navigator.clipboard.writeText((b.whatsapp_review_template ?? '').split('{link}').join(link));
        setCopied(b.id);
        window.setTimeout(() => setCopied(null), 2000);
    };
    const heldLabel = (reason: string | null) =>
        reason === 'link'
            ? t('reviews.held_link', 'فيه رابط')
            : reason === 'phone'
              ? t('reviews.held_phone', 'فيه رقم هاتف')
              : reason === 'unverified'
                ? t('reviews.held_unverified', 'تعذّر التحقق')
                : null;
    const [links, setLinks] = useState<Record<number, { url: string; whatsapp: string | null }>>({});
    const moderate = (row: Row, next: 'approved' | 'rejected' | 'pending') =>
        router.put(`/manage/storefronts/${storefront.id}/reviews/${row.id}`, { status: next }, { preserveScroll: true });
    const whatsapp = async (req: RequestRow) => {
        const res = await fetch(`/manage/storefronts/${storefront.id}/reviews/requests/${req.id}/whatsapp`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (res.ok) {
            const data = (await res.json()) as { url: string; whatsapp: string | null };
            setLinks((prev) => ({ ...prev, [req.id]: data }));
            if (data.whatsapp) window.open(data.whatsapp, '_blank', 'noopener');
        }
    };

    const columns: Array<Column<Row>> = [
        { key: 'product', header: t('reviews.product', 'المنتج'), sortable: false, cell: (row) => <ProductName title={row.title} /> },
        { key: 'rating', header: t('reviews.rating', 'التقييم'), cell: (row) => <span className="text-amber-500">{stars(row.rating)}</span> },
        {
            key: 'body',
            header: t('reviews.body', 'التقييم المكتوب'),
            sortable: false,
            cell: (row) => (
                <div className="max-w-md space-y-1">
                    <p className="text-sm whitespace-pre-line">
                        <span dir="auto">{row.body}</span>
                    </p>
                    <p className="flex flex-wrap gap-1">
                        {row.verified ? <Badge variant="success">{t('reviews.verified', 'مشتري موثّق')}</Badge> : null}
                        <Badge variant="neutral">
                            {row.source === 'guest' ? t('reviews.source_guest', 'من صفحة المنتج') : t('reviews.source_request', 'من رابط بعد الاستلام')}
                        </Badge>
                        {heldLabel(row.held_reason) ? <Badge variant="warning">{heldLabel(row.held_reason)}</Badge> : null}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        <span dir="auto">{row.name}</span>
                        {row.order_number ? (
                            <>
                                {' · '}
                                <Ltr>{row.order_number}</Ltr>
                            </>
                        ) : null}
                    </p>
                    {row.photos.length > 0 ? (
                        <div className="flex gap-2 pt-1">
                            {row.photos.map((src) => (
                                <a key={src} href={src} target="_blank" rel="noreferrer">
                                    <img src={src} alt="" className="h-14 w-14 rounded border object-cover" loading="lazy" />
                                </a>
                            ))}
                        </div>
                    ) : null}
                </div>
            ),
        },
        { key: 'created_at', header: t('reviews.created_at', 'التاريخ'), hideBelow: 'lg', cell: (row) => <Ltr>{row.created_at ?? '—'}</Ltr> },
    ];

    return (
        <ManageLayout
            title={t('reviews.title', 'التقييمات')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: storefront.name }, { label: t('reviews.title', 'التقييمات') }]}
        >
            <div className="mb-4 space-y-1">
                <h1 className="text-lg font-semibold">{t('reviews.title', 'التقييمات')}</h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'reviews.notice',
                        'كل تقييم يظهر في صفحة المنتج فورًا، إلا ما فيه رابط أو رقم هاتف: يبقى هنا حتى توافق عليه. لا يُحجز تقييم بسبب عدد النجوم، ولا يُعدَّل نص العميل. الظاهر الآن: :count تقييم.',
                        { count: summary.count },
                    )}
                </p>
                {emails_on ? null : (
                    <p className="text-sm">
                        <Badge variant="warning">{t('reviews.emails_off', 'رسائل طلب التقييم بالبريد متوقفة')}</Badge>
                    </p>
                )}
            </div>
            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t('reviews.search_placeholder', 'ابحث في نص التقييم أو الاسم…')}
                filters={(setFilter) => (
                    <Select aria-label={t('reviews.filter_status', 'الحالة')} className="w-44" value={status} onChange={(event) => setFilter('status', event.target.value)}>
                        <option value="pending">{t('reviews.status_pending', 'محجوزة للمراجعة (:n)', { n: counts.pending ?? 0 })}</option>
                        <option value="approved">{t('reviews.status_approved', 'ظاهرة (:n)', { n: counts.approved ?? 0 })}</option>
                        <option value="rejected">{t('reviews.status_rejected', 'مخفية (:n)', { n: counts.rejected ?? 0 })}</option>
                    </Select>
                )}
                rowActions={(row) =>
                    status === 'pending' ? (
                        <div className="flex gap-1">
                            <Button variant="ghost" size="sm" onClick={() => moderate(row, 'approved')}>
                                <Check className="h-4 w-4" />
                                {t('reviews.approve', 'إظهار')}
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => moderate(row, 'rejected')}>
                                <X className="h-4 w-4" />
                                {t('reviews.reject', 'إخفاء')}
                            </Button>
                        </div>
                    ) : status === 'approved' ? (
                        <Button variant="ghost" size="sm" onClick={() => moderate(row, 'rejected')}>
                            <EyeOff className="h-4 w-4" />
                            {t('reviews.hide', 'إخفاء')}
                        </Button>
                    ) : (
                        <Button variant="ghost" size="sm" onClick={() => moderate(row, 'approved')}>
                            <Check className="h-4 w-4" />
                            {t('reviews.show_again', 'إظهار مرة أخرى')}
                        </Button>
                    )
                }
                emptyTitle={t('reviews.empty_title', 'لا توجد تقييمات هنا')}
                emptyDescription={t('reviews.empty_description', 'يكتب العملاء تقييماتهم من صفحة المنتج، أو من الرابط الذي يصلهم بعد استلام الطلب.')}
            />

            {rows.length > 0 ? (
                <section className="mt-8 space-y-3">
                    <h2 className="text-base font-semibold">{t('reviews.branches_title', 'تقييمات جوجل للفروع')}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'reviews.branches_notice',
                            'رابط مراجعة جوجل لكل فرع يظهر لكل عميل بعد تقييمه على الموقع وفي رسالة طلب التقييم، مهما كان عدد النجوم. عنوان الفرع على موقعنا هو ما يطبع في رمز QR، ويحسب عدد مرات المسح. في نص واتساب، اكتب {link} مكان الرابط.',
                        )}
                    </p>
                    {rows.map((b) => (
                        <div key={b.id} className="space-y-3 rounded border p-3">
                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                <span dir="auto">{b.name.ar}</span>
                                {b.is_active ? null : <Badge variant="neutral">{t('reviews.branch_off', 'الفرع موقوف')}</Badge>}
                                <span className="text-xs text-muted-foreground">
                                    {t('reviews.scans', 'مرات المسح: :n في آخر 30 يومًا (:total إجمالًا)', { n: b.scans_30_days, total: b.scans_total })}
                                </span>
                            </p>
                            <label className="block space-y-1 text-sm">
                                <span>{t('reviews.google_url', 'رابط كتابة مراجعة على جوجل')}</span>
                                <Input dir="ltr" value={b.google_review_url ?? ''} onChange={(e) => setBranch(b.id, { google_review_url: e.target.value })} />
                            </label>
                            <label className="block space-y-1 text-sm">
                                <span>{t('reviews.review_key', 'عنوان الفرع على موقعنا (/review/…)')}</span>
                                <Input dir="ltr" value={b.review_key ?? ''} onChange={(e) => setBranch(b.id, { review_key: e.target.value })} />
                                {b.review_link ? (
                                    <span className="block text-xs break-all text-muted-foreground" dir="ltr">
                                        {b.review_link}
                                    </span>
                                ) : null}
                            </label>
                            <label className="block space-y-1 text-sm">
                                <span>{t('reviews.whatsapp_template', 'رسالة واتساب للعميل بعد الشراء من الفرع')}</span>
                                <Textarea
                                    rows={3}
                                    dir="auto"
                                    value={b.whatsapp_review_template ?? ''}
                                    onChange={(e) => setBranch(b.id, { whatsapp_review_template: e.target.value })}
                                />
                            </label>
                            <Button type="button" variant="outline" size="sm" disabled={!b.whatsapp_review_template || !b.review_link} onClick={() => void copyMessage(b)}>
                                <Copy className="h-4 w-4" />
                                {copied === b.id ? t('reviews.copied', 'تم النسخ') : t('reviews.copy_message', 'نسخ الرسالة')}
                            </Button>
                        </div>
                    ))}
                    <Button type="button" onClick={saveBranches}>
                        {t('reviews.branches_save', 'حفظ روابط الفروع')}
                    </Button>
                </section>
            ) : null}

            <section className="mt-8 space-y-2">
                <h2 className="text-base font-semibold">{t('reviews.requests_title', 'آخر روابط التقييم')}</h2>
                <ul className="divide-y rounded border text-sm">
                    {requests.map((req) => (
                        <li key={req.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                            <Ltr>{req.order_number ?? '—'}</Ltr>
                            <span className="text-muted-foreground">
                                {req.sent_at
                                    ? t('reviews.sent_at', 'أُرسل بالبريد :date', { date: req.sent_at })
                                    : req.email
                                      ? t('reviews.not_sent', 'لم يُرسل بالبريد بعد')
                                      : t('reviews.no_email', 'بلا بريد')}
                            </span>
                            {req.phone ? (
                                <Button variant="ghost" size="sm" onClick={() => void whatsapp(req)}>
                                    <MessageCircle className="h-4 w-4" />
                                    {t('reviews.whatsapp', 'إرسال على واتساب')}
                                </Button>
                            ) : null}
                            {links[req.id] ? (
                                <span className="text-xs break-all" dir="ltr">
                                    {links[req.id].url}
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            </section>
        </ManageLayout>
    );
}
