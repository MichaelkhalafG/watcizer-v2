import { router } from '@inertiajs/react';
import { Check, MessageCircle, RotateCcw, X } from 'lucide-react';
import { useState } from 'react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { ProductName } from '@/components/manage/ProductName';
import { Ltr } from '@/components/ui/bidi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';

/**
 * Reviews (SEO Phase 5, 2026-11-14): what customers wrote from the review link of a DELIVERED order — every one is a
 * verified purchase. A review waits here until the team approves it; only approved reviews appear on the product page
 * and in its search-engine markup. Below: the latest review links, with a button that gives a fresh link to send on
 * WhatsApp (for a customer with no e-mail, or while the e-mails are switched off). Times are Cairo time.
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
}

const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(Math.max(0, 5 - n));

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export default function ReviewsIndex({ storefront, status, counts, summary, emails_on, table, requests }: Props) {
    const t = useT();
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
                        'كل تقييم هنا من عميل استلم طلبه (شراء مؤكد). لا يظهر في صفحة المنتج إلا بعد موافقتك. المعتمد حتى الآن: :count تقييم.',
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
                        <option value="pending">{t('reviews.status_pending', 'في الانتظار (:n)', { n: counts.pending ?? 0 })}</option>
                        <option value="approved">{t('reviews.status_approved', 'معتمدة (:n)', { n: counts.approved ?? 0 })}</option>
                        <option value="rejected">{t('reviews.status_rejected', 'مرفوضة (:n)', { n: counts.rejected ?? 0 })}</option>
                    </Select>
                )}
                rowActions={(row) =>
                    status === 'pending' ? (
                        <div className="flex gap-1">
                            <Button variant="ghost" size="sm" onClick={() => moderate(row, 'approved')}>
                                <Check className="h-4 w-4" />
                                {t('reviews.approve', 'موافقة')}
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => moderate(row, 'rejected')}>
                                <X className="h-4 w-4" />
                                {t('reviews.reject', 'رفض')}
                            </Button>
                        </div>
                    ) : (
                        <Button variant="ghost" size="sm" onClick={() => moderate(row, 'pending')}>
                            <RotateCcw className="h-4 w-4" />
                            {t('reviews.back_to_pending', 'إعادة للانتظار')}
                        </Button>
                    )
                }
                emptyTitle={t('reviews.empty_title', 'لا توجد تقييمات هنا')}
                emptyDescription={t('reviews.empty_description', 'يُرسل رابط التقييم للعميل بعد استلام طلبه بأيام.')}
            />

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
