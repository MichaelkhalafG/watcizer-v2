import { router } from '@inertiajs/react';
import { Check, Copy, Download, ExternalLink, EyeOff, Mail, MessageCircle, QrCode, X } from 'lucide-react';
import { useState } from 'react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { ProductName } from '@/components/manage/ProductName';
import { Ltr } from '@/components/ui/bidi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { useLocale, useT } from '@/lib/i18n';
import { localisedTitle } from '@/lib/title';
import { downloadCardPdf, downloadQrPng, type CardBranch } from '@/lib/qrCardDraw';
import type { CardLang, CardSize } from '@/lib/qrCard';
import type { TablePayload } from '@/types';

/**
 * Reviews — ONE PAGE FOR EVERY SHOP (Michael, 2026-11-19; the rules of 2026-11-17 unchanged). Every review is LIVE on its
 * product page at once unless it carries a link or a phone number: then it is HELD here. Never held for its stars; the
 * team approves a held review or hides any review, and nobody edits what a customer wrote.
 *
 *  • Reviews: a shop filter (All, or one shop), quick filters (held, stars, the last 7 days), the search, the Shop column,
 *    the product linked on its own shop, approve / hide / show again in the row.
 *  • Branches: every branch of the shops, its Google link, its address on our own shop (/review/<branch>, which the QR
 *    holds — never Google's link directly), the QR card (A5 table card or A6 counter card, English or Arabic, as a PDF;
 *    the QR alone as a PNG), the WhatsApp message with {link} filled, and the scans.
 *  • E-mail: whether the review e-mail is on, when it goes, the e-mail itself, and a test to the signed-in user.
 *
 * The moderation and the branches' save go to the per-shop routes, so a user scoped to one shop keeps exactly the rights
 * they had. Times are Cairo time.
 */

interface Row {
    id: number;
    storefront_id: number;
    shop: string;
    title: { en: string; ar: string };
    product_url: string | null;
    rating: number;
    body: string;
    name: string;
    photos: string[];
    order_number: string | null;
    created_at: string | null;
    status: 'pending' | 'approved' | 'rejected';
    rejection_reason: string | null;
    source: 'guest' | 'request';
    verified: boolean;
    held_reason: string | null;
}

interface Branch {
    id: number;
    storefront_id: number;
    shop: string;
    shop_code: string;
    logo: string;
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
    storefront_id: number;
    shop: string;
    order_number: string | null;
    email: boolean;
    phone: string | null;
    sent_at: string | null;
    created_at: string | null;
}

interface Shop {
    id: number;
    code: string;
    name: string;
}

interface Props {
    shops: Shop[];
    counts: Partial<Record<'held' | 'live' | 'hidden', number>>;
    visible: number;
    table: TablePayload<Row>;
    requests: RequestRow[];
    branches: Branch[];
    email: { on: boolean; from: string | null; after_days: number; within_days: number; expires_days: number; max_per_run: number; to: string };
}

type Tab = 'reviews' | 'branches' | 'email';

const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(Math.max(0, 5 - n));

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

function initialTab(): Tab {
    if (typeof window === 'undefined') return 'reviews';
    const hash = window.location.hash.replace('#', '');
    return hash === 'branches' || hash === 'email' ? hash : 'reviews';
}

export default function ReviewsIndex({ shops, counts, visible, table, requests, branches, email }: Props) {
    const t = useT();
    const locale = useLocale();
    const [tab, setTabState] = useState<Tab>(initialTab);
    const setTab = (next: Tab) => {
        setTabState(next);
        window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${next}`);
    };
    const many = shops.length > 1;
    const filters = table.meta.filters ?? {};

    const tabs: Array<[Tab, string]> = [
        ['reviews', t('reviews.tab_reviews', 'التقييمات')],
        ['branches', t('reviews.tab_branches', 'روابط جوجل للفروع')],
        ['email', t('reviews.tab_email', 'رسالة طلب التقييم')],
    ];

    return (
        <ManageLayout title={t('reviews.title', 'التقييمات')} crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('reviews.title', 'التقييمات') }]}>
            <div className="mb-4 space-y-1">
                <p className="text-sm text-muted-foreground">
                    {t(
                        'reviews.notice',
                        'كل تقييم يظهر في صفحة المنتج فورًا، إلا ما فيه رابط أو رقم هاتف: يبقى هنا حتى توافق عليه. لا يُحجز تقييم بسبب عدد النجوم، ولا يُعدَّل نص العميل. الظاهر الآن: :count تقييم.',
                        { count: visible },
                    )}
                </p>
            </div>

            <div role="tablist" aria-label={t('reviews.title', 'التقييمات')} className="mb-4 flex flex-wrap gap-1 border-b">
                {tabs.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        id={`reviews-tab-${key}`}
                        aria-selected={tab === key}
                        aria-controls={`reviews-panel-${key}`}
                        tabIndex={tab === key ? 0 : -1}
                        onClick={() => setTab(key)}
                        onKeyDown={(event) => {
                            const order = tabs.map(([k]) => k);
                            const i = order.indexOf(tab);
                            const rtl = document.documentElement.dir === 'rtl';
                            const next = event.key === (rtl ? 'ArrowLeft' : 'ArrowRight') ? order[(i + 1) % order.length]
                                : event.key === (rtl ? 'ArrowRight' : 'ArrowLeft') ? order[(i - 1 + order.length) % order.length]
                                : event.key === 'Home' ? order[0] : event.key === 'End' ? order[order.length - 1] : null;
                            if (next) {
                                event.preventDefault();
                                setTab(next);
                                document.getElementById(`reviews-tab-${next}`)?.focus();
                            }
                        }}
                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${tab === key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div role="tabpanel" id="reviews-panel-reviews" aria-labelledby="reviews-tab-reviews" hidden={tab !== 'reviews'}>
                <ReviewsTab table={table} shops={shops} many={many} counts={counts} filters={filters} requests={requests} />
            </div>
            <div role="tabpanel" id="reviews-panel-branches" aria-labelledby="reviews-tab-branches" hidden={tab !== 'branches'}>
                <BranchesTab branches={branches} shops={shops} locale={locale} />
            </div>
            <div role="tabpanel" id="reviews-panel-email" aria-labelledby="reviews-tab-email" hidden={tab !== 'email'}>
                {tab === 'email' ? <EmailTab email={email} shops={shops} /> : null}
            </div>
        </ManageLayout>
    );
}

function ReviewsTab({ table, shops, many, counts, filters, requests }: {
    table: TablePayload<Row>;
    shops: Shop[];
    many: boolean;
    counts: Props['counts'];
    filters: Record<string, string | null>;
    requests: RequestRow[];
}) {
    const t = useT();
    const heldLabel = (reason: string | null) =>
        reason === 'link' ? t('reviews.held_link', 'فيه رابط') : reason === 'phone' ? t('reviews.held_phone', 'فيه رقم هاتف') : reason === 'unverified' ? t('reviews.held_unverified', 'تعذّر التحقق') : null;
    const moderate = (row: Row, next: 'approved' | 'rejected') =>
        router.put(`/manage/storefronts/${row.storefront_id}/reviews/${row.id}`, { status: next }, { preserveScroll: true });
    const [links, setLinks] = useState<Record<number, { url: string; whatsapp: string | null }>>({});
    const whatsapp = async (req: RequestRow) => {
        const res = await fetch(`/manage/storefronts/${req.storefront_id}/reviews/requests/${req.id}/whatsapp`, {
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
    const statusBadge = (status: Row['status']) =>
        status === 'pending' ? (
            <Badge variant="warning">{t('reviews.state_held', 'محجوز')}</Badge>
        ) : status === 'approved' ? (
            <Badge variant="success">{t('reviews.state_live', 'ظاهر')}</Badge>
        ) : (
            <Badge variant="neutral">{t('reviews.state_hidden', 'مخفي')}</Badge>
        );

    const columns: Array<Column<Row>> = [
        ...(many ? [{ key: 'shop', header: t('reviews.shop', 'المتجر'), sortable: false, cell: (row: Row) => <span className="whitespace-nowrap">{row.shop}</span> }] : []),
        {
            key: 'product',
            header: t('reviews.product', 'المنتج'),
            sortable: false,
            cell: (row) =>
                row.product_url ? (
                    <a href={row.product_url} target="_blank" rel="noreferrer" className="inline-flex items-start gap-1 hover:underline" title={t('reviews.open_product', 'افتح المنتج في متجره')}>
                        <ProductName title={row.title} />
                        <ExternalLink className="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden="true" />
                    </a>
                ) : (
                    <ProductName title={row.title} />
                ),
        },
        { key: 'rating', header: t('reviews.rating', 'التقييم'), cell: (row) => <span className="whitespace-nowrap text-amber-500" aria-label={`${row.rating}/5`}>{stars(row.rating)}</span> },
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
                        {statusBadge(row.status)}
                        {row.verified ? <Badge variant="success">{t('reviews.verified', 'مشتري موثّق')}</Badge> : null}
                        <Badge variant="neutral">{row.source === 'guest' ? t('reviews.source_guest', 'من صفحة المنتج') : t('reviews.source_request', 'من رابط بعد الاستلام')}</Badge>
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

    const quick = (setFilter: (key: string, value: string | null) => void) => {
        const chip = (active: boolean, label: string, onClick: () => void) => (
            <Button type="button" size="sm" variant={active ? 'default' : 'outline'} aria-pressed={active} onClick={onClick}>
                {label}
            </Button>
        );
        return (
            <div className="flex flex-wrap items-center gap-2">
                {many ? (
                    <Select aria-label={t('reviews.shop', 'المتجر')} className="w-44" value={filters.shop ?? ''} onChange={(e) => setFilter('shop', e.target.value || null)}>
                        <option value="">{t('reviews.shop_all', 'كل المتاجر')}</option>
                        {shops.map((s) => (
                            <option key={s.id} value={String(s.id)}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                ) : null}
                {chip(!filters.status, t('reviews.filter_all', 'الكل'), () => setFilter('status', null))}
                {chip(filters.status === 'held', t('reviews.filter_held', 'محجوزة (:n)', { n: counts.held ?? 0 }), () => setFilter('status', filters.status === 'held' ? null : 'held'))}
                {chip(filters.status === 'live', t('reviews.filter_live', 'ظاهرة (:n)', { n: counts.live ?? 0 }), () => setFilter('status', filters.status === 'live' ? null : 'live'))}
                {chip(filters.status === 'hidden', t('reviews.filter_hidden', 'مخفية (:n)', { n: counts.hidden ?? 0 }), () => setFilter('status', filters.status === 'hidden' ? null : 'hidden'))}
                <Select aria-label={t('reviews.filter_stars', 'عدد النجوم')} className="w-36" value={filters.stars ?? ''} onChange={(e) => setFilter('stars', e.target.value || null)}>
                    <option value="">{t('reviews.stars_any', 'كل النجوم')}</option>
                    {[5, 4, 3, 2, 1].map((n) => (
                        <option key={n} value={String(n)}>
                            {stars(n)}
                        </option>
                    ))}
                </Select>
                {chip(filters.recent === '7', t('reviews.filter_recent', 'آخر 7 أيام'), () => setFilter('recent', filters.recent === '7' ? null : '7'))}
            </div>
        );
    };

    return (
        <>
            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t('reviews.search_placeholder', 'ابحث في نص التقييم أو الاسم…')}
                filters={(setFilter) => quick(setFilter)}
                rowActions={(row) =>
                    row.status === 'pending' ? (
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
                    ) : row.status === 'approved' ? (
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

            <section className="mt-8 space-y-2">
                <h2 className="text-base font-semibold">{t('reviews.requests_title', 'آخر روابط التقييم')}</h2>
                <ul className="divide-y rounded border text-sm">
                    {requests.map((req) => (
                        <li key={req.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                            {many ? <Badge variant="neutral">{req.shop}</Badge> : null}
                            <Ltr>{req.order_number ?? '—'}</Ltr>
                            <span className="text-muted-foreground">
                                {req.sent_at ? t('reviews.sent_at', 'أُرسل بالبريد :date', { date: req.sent_at }) : req.email ? t('reviews.not_sent', 'لم يُرسل بالبريد بعد') : t('reviews.no_email', 'بلا بريد')}
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
        </>
    );
}

function BranchesTab({ branches, shops, locale }: { branches: Branch[]; shops: Shop[]; locale: string }) {
    const t = useT();
    const [rows, setRows] = useState<Branch[]>(branches);
    const [copied, setCopied] = useState<string | null>(null);
    const [card, setCard] = useState<Record<number, { size: CardSize; lang: CardLang }>>({});
    const [busy, setBusy] = useState<number | null>(null);
    const setBranch = (id: number, patch: Partial<Branch>) => setRows((prev) => prev.map((b) => (b.id === id ? { ...b, ...patch } : b)));
    const saveShop = (sf: number) =>
        router.put(
            `/manage/storefronts/${sf}/reviews-branches`,
            { branches: rows.filter((b) => b.storefront_id === sf).map((b) => ({ id: b.id, google_review_url: b.google_review_url ?? '', review_key: b.review_key ?? '', whatsapp_review_template: b.whatsapp_review_template ?? '' })) },
            { preserveScroll: true },
        );
    const message = (b: Branch) => (b.whatsapp_review_template ?? '').split('{link}').join(b.review_link ?? '');
    const copy = async (key: string, text: string) => {
        await navigator.clipboard.writeText(text);
        setCopied(key);
        window.setTimeout(() => setCopied(null), 2000);
    };
    const cardOf = (b: Branch): CardBranch => ({
        shop: b.shop,
        shopCode: b.shop_code,
        branchName: localisedTitle(b.name, card[b.id]?.lang ?? (locale === 'en' ? 'en' : 'ar')).text,
        reviewKey: b.review_key ?? 'branch',
        link: b.review_link ?? '',
        logo: b.logo,
    });
    const download = async (b: Branch, kind: 'pdf' | 'png') => {
        setBusy(b.id);
        try {
            const c = card[b.id] ?? { size: 'A5' as CardSize, lang: (locale === 'en' ? 'en' : 'ar') as CardLang };
            if (kind === 'pdf') await downloadCardPdf({ ...cardOf(b), branchName: localisedTitle(b.name, c.lang).text }, c.size, c.lang);
            else await downloadQrPng(cardOf(b));
        } finally {
            setBusy(null);
        }
    };

    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">{t('reviews.no_branches', 'لا توجد فروع في المتاجر التي تديرها.')}</p>;
    }

    return (
        <div className="space-y-8">
            <p className="text-sm text-muted-foreground">
                {t(
                    'reviews.branches_notice',
                    'رابط مراجعة جوجل لكل فرع يظهر لكل عميل بعد تقييمه على الموقع وفي رسالة طلب التقييم، مهما كان عدد النجوم. عنوان الفرع على موقعنا هو ما يطبع في رمز QR، ويحسب عدد مرات المسح. في نص واتساب، اكتب {link} مكان الرابط.',
                )}
            </p>
            {shops
                .filter((s) => rows.some((b) => b.storefront_id === s.id))
                .map((shop) => (
                    <section key={shop.id} className="space-y-3">
                        <h2 className="text-base font-semibold">{shop.name}</h2>
                        {rows
                            .filter((b) => b.storefront_id === shop.id)
                            .map((b) => {
                                const c = card[b.id] ?? { size: 'A5' as CardSize, lang: (locale === 'en' ? 'en' : 'ar') as CardLang };
                                return (
                                    <div key={b.id} className="space-y-3 rounded border p-3" data-branch={b.id}>
                                        <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                            <span dir="auto">{localisedTitle(b.name, locale).text}</span>
                                            <Badge variant="neutral">{b.shop}</Badge>
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
                                        </label>
                                        {b.review_link ? (
                                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                                <span className="break-all text-muted-foreground" dir="ltr">
                                                    {b.review_link}
                                                </span>
                                                <Button type="button" variant="outline" size="sm" onClick={() => void copy(`link-${b.id}`, b.review_link ?? '')}>
                                                    <Copy className="h-4 w-4" />
                                                    {copied === `link-${b.id}` ? t('reviews.copied', 'تم النسخ') : t('reviews.copy_link', 'نسخ الرابط')}
                                                </Button>
                                                <Button asChild variant="outline" size="sm">
                                                    <a href={b.review_link} target="_blank" rel="noreferrer">
                                                        <ExternalLink className="h-4 w-4" />
                                                        {t('reviews.test_link', 'تجربة الرابط')}
                                                    </a>
                                                </Button>
                                            </div>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">{t('reviews.no_key', 'اكتب عنوانًا للفرع واحفظ، ليظهر رابطه ورمز QR.')}</p>
                                        )}

                                        {/* the QR card: always the branch's address on our shop, never Google's link */}
                                        <div className="flex flex-wrap items-center gap-2 rounded bg-muted/40 p-2 text-sm">
                                            <QrCode className="h-4 w-4" aria-hidden="true" />
                                            <Select aria-label={t('reviews.qr_size', 'حجم البطاقة')} className="w-44" value={c.size} onChange={(e) => setCard((p) => ({ ...p, [b.id]: { ...c, size: e.target.value as CardSize } }))}>
                                                <option value="A5">{t('reviews.qr_a5', 'A5 — بطاقة طاولة')}</option>
                                                <option value="A6">{t('reviews.qr_a6', 'A6 — بطاقة كاشير')}</option>
                                            </Select>
                                            <Select aria-label={t('reviews.qr_lang', 'لغة البطاقة')} className="w-32" value={c.lang} onChange={(e) => setCard((p) => ({ ...p, [b.id]: { ...c, lang: e.target.value as CardLang } }))}>
                                                <option value="ar">العربية</option>
                                                <option value="en">English</option>
                                            </Select>
                                            <Button type="button" size="sm" disabled={!b.review_link || busy === b.id} onClick={() => void download(b, 'pdf')} data-qr="pdf">
                                                <Download className="h-4 w-4" />
                                                {t('reviews.qr_pdf', 'تنزيل QR (PDF للطباعة)')}
                                            </Button>
                                            <Button type="button" size="sm" variant="outline" disabled={!b.review_link || busy === b.id} onClick={() => void download(b, 'png')} data-qr="png">
                                                <Download className="h-4 w-4" />
                                                {t('reviews.qr_png', 'الرمز وحده (PNG)')}
                                            </Button>
                                        </div>

                                        <label className="block space-y-1 text-sm">
                                            <span>{t('reviews.whatsapp_template', 'رسالة واتساب للعميل بعد الشراء من الفرع')}</span>
                                            <Textarea rows={3} dir="auto" value={b.whatsapp_review_template ?? ''} onChange={(e) => setBranch(b.id, { whatsapp_review_template: e.target.value })} />
                                        </label>
                                        {b.whatsapp_review_template && b.review_link ? (
                                            <div className="space-y-2 rounded border bg-muted/30 p-2 text-sm">
                                                <p className="text-xs text-muted-foreground">{t('reviews.whatsapp_ready', 'الرسالة كما تصل للعميل:')}</p>
                                                <p className="whitespace-pre-line" dir="auto">
                                                    {message(b)}
                                                </p>
                                                <Button type="button" variant="outline" size="sm" onClick={() => void copy(`msg-${b.id}`, message(b))}>
                                                    <Copy className="h-4 w-4" />
                                                    {copied === `msg-${b.id}` ? t('reviews.copied', 'تم النسخ') : t('reviews.copy_message', 'نسخ الرسالة')}
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                        <Button type="button" onClick={() => saveShop(shop.id)}>
                            {t('reviews.branches_save_shop', 'حفظ روابط فروع :shop', { shop: shop.name })}
                        </Button>
                    </section>
                ))}
        </div>
    );
}

function EmailTab({ email, shops }: { email: Props['email']; shops: Shop[] }) {
    const t = useT();
    const [shop, setShop] = useState<number>(shops[0]?.id ?? 0);
    const [sending, setSending] = useState(false);
    const sendTest = () => {
        setSending(true);
        router.post('/manage/reviews/email-test', { shop }, { preserveScroll: true, onFinish: () => setSending(false) });
    };

    return (
        <div className="space-y-4">
            <div className="space-y-2 rounded border p-3 text-sm">
                <p className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{t('reviews.email_state', 'رسالة طلب التقييم بالبريد:')}</span>
                    {email.on ? <Badge variant="success">{t('reviews.email_on', 'تعمل')}</Badge> : <Badge variant="warning">{t('reviews.emails_off', 'رسائل طلب التقييم بالبريد متوقفة')}</Badge>}
                </p>
                <p>
                    {t('reviews.email_when', 'تُرسل بعد :days أيام من تسليم الطلب، لطلبات تُسلَّم خلال :within يومًا، والرابط يعمل :expires يومًا. حتى :max رسالة في كل تشغيل.', {
                        days: email.after_days,
                        within: email.within_days,
                        expires: email.expires_days,
                        max: email.max_per_run,
                    })}
                </p>
                <p className="text-muted-foreground">
                    {email.from
                        ? t('reviews.email_from', 'للطلبات التي وُضعت منذ :date (بتوقيت القاهرة) فقط.', { date: email.from })
                        : t('reviews.email_no_from', 'لم يُسجَّل موعد البدء بعد، فلا تُنشأ طلبات تقييم.')}
                </p>
                <p className="text-xs text-muted-foreground">
                    {t('reviews.email_settings', 'التشغيل والإيقاف والموعد من إعدادات الخادم (REVIEW_REQUESTS_ENABLED وREVIEW_REQUEST_AFTER_DAYS)، لا من هذه الصفحة.')}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {shops.length > 1 ? (
                    <Select aria-label={t('reviews.shop', 'المتجر')} className="w-44" value={String(shop)} onChange={(e) => setShop(Number(e.target.value))}>
                        {shops.map((s) => (
                            <option key={s.id} value={String(s.id)}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                ) : null}
                <Button type="button" variant="outline" disabled={sending || !email.to} onClick={sendTest} data-email-test="true">
                    <Mail className="h-4 w-4" />
                    {t('reviews.email_test', 'أرسل لي رسالة تجربة')}
                </Button>
                <span className="text-xs text-muted-foreground" dir="auto">
                    {t('reviews.email_test_to', 'إلى بريدك فقط: :email', { email: email.to || '—' })}
                </span>
            </div>

            <p className="text-xs text-muted-foreground">{t('reviews.email_preview_note', 'المعاينة: الرسالة نفسها بالعربية والإنجليزية، برابط ومنتج للتجربة.')}</p>
            <iframe
                key={shop}
                title={t('reviews.email_preview', 'معاينة رسالة طلب التقييم')}
                src={`/manage/reviews/email-preview?shop=${shop}`}
                className="h-[900px] w-full max-w-2xl rounded border bg-white"
                sandbox=""
            />
        </div>
    );
}
