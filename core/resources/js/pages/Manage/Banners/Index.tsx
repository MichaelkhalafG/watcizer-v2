import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Ltr } from '@/components/ui/bidi';

import { ImageField, type StoredImage } from '@/components/form/ImageField';
import { DataTable, type Column } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';

/**
 * Home-page banners (wave 4D).
 *
 * ── What this screen is for ─────────────────────────────────────────────────────────────────
 *
 * The first thing a customer sees. One image, one destination, one window. Everything on the row
 * answers a question the operator would otherwise open the record to ask: what does it look like,
 * where does it send people, and **is it showing right now** — because a banner can be switched on
 * with a window that closed last month, and an empty hero slot is the failure nobody reports.
 *
 * The destination is one of three things and the form makes you pick which, rather than offering
 * three boxes and storing whichever got filled: a product, a category, or a URL.
 */

interface Banner {
    id: number;
    image_path: string;
    image_url: string;
    sort_order: number;
    is_active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    target: string;
    product_id: number | null;
    storefront_category_id: number | null;
    link_url: string | null;
    destination: string;
    state: string;
    label: string;
    tone: string;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    table: TablePayload<Banner>;
    categories: Option[];
    targets: string[];
    media_type: string;
}

const TONE: Record<string, 'default' | 'neutral' | 'success' | 'warning' | 'destructive' | 'outline'> = {
    success: 'success',
    default: 'default',
    neutral: 'neutral',
    destructive: 'destructive',
    outline: 'outline',
};

/**
 * The three destinations plus "none", named for the operator.
 *
 * A function rather than a constant map because the labels are translated, and `useT()` is a hook:
 * it can only run inside the component, so the component calls this with its own `t`.
 */
function targetLabels(t: ReturnType<typeof useT>): Record<string, string> {
    return {
        product: t('banners.target_product', 'منتج'),
        category: t('common.category', 'تصنيف'),
        url: t('banners.target_url', 'رابط'),
        none: t('banners.target_none', 'بدون وجهة'),
    };
}

interface Draft {
    id: number | null;
    /** The FILENAME the row stores. `image` below is the full upload result, only while editing. */
    image_path: string;
    image: StoredImage | null;
    target: string;
    product_id: string;
    storefront_category_id: string;
    link_url: string;
    sort_order: string;
    is_active: boolean;
    starts_at: string;
    ends_at: string;
}

const EMPTY: Draft = {
    id: null,
    image_path: '',
    image: null,
    target: 'none',
    product_id: '',
    storefront_category_id: '',
    link_url: '',
    sort_order: '0',
    is_active: true,
    starts_at: '',
    ends_at: '',
};

export default function BannersIndex({ storefront, storefronts, table, categories, targets, media_type }: Props) {
    const t = useT();
    const [draft, setDraft] = useState<Draft | null>(null);

    const TARGET_LABELS = targetLabels(t);

    const base = `/manage/storefronts/${storefront.id}/banners`;

    const edit = (row: Banner) =>
        setDraft({
            id: row.id,
            image_path: row.image_path,
            // Reconstructed from what the list already carries: enough for the preview, and the
            // upload endpoint replaces it wholesale the moment a new file is chosen.
            image: {
                file: row.image_path,
                folder: '',
                url: row.image_url,
                width: 0,
                height: 0,
                bytes: 0,
                renditions: {},
                skipped: [],
            },
            target: row.target,
            product_id: row.product_id === null ? '' : String(row.product_id),
            storefront_category_id: row.storefront_category_id === null ? '' : String(row.storefront_category_id),
            link_url: row.link_url ?? '',
            sort_order: String(row.sort_order),
            is_active: row.is_active,
            starts_at: row.starts_at?.slice(0, 16).replace(' ', 'T') ?? '',
            ends_at: row.ends_at?.slice(0, 16).replace(' ', 'T') ?? '',
        });

    const submit = () => {
        if (draft === null) {
            return;
        }
        const payload = {
            image_path: draft.image_path,
            target: draft.target,
            product_id: draft.target === 'product' && draft.product_id !== '' ? Number(draft.product_id) : null,
            storefront_category_id:
                draft.target === 'category' && draft.storefront_category_id !== '' ? Number(draft.storefront_category_id) : null,
            link_url: draft.target === 'url' ? draft.link_url : null,
            sort_order: Number(draft.sort_order || 0),
            is_active: draft.is_active,
            starts_at: draft.starts_at === '' ? null : draft.starts_at.replace('T', ' '),
            ends_at: draft.ends_at === '' ? null : draft.ends_at.replace('T', ' '),
        };

        const done = { preserveScroll: true, onSuccess: () => setDraft(null) };
        draft.id === null ? router.post(base, payload, done) : router.put(`${base}/${draft.id}`, payload, done);
    };

    const columns: Array<Column<Banner>> = [
        {
            key: 'image',
            header: t('banners.banner', 'البانر'),
            cell: (row) => (
                <div className="flex items-center gap-3">
                    {/* The thing itself. A banner list without the picture is a list of dates. */}
                    <img
                        src={row.image_url}
                        alt=""
                        loading="lazy"
                        className="h-12 w-28 rounded border bg-muted object-cover"
                    />
                    <span className="text-xs text-muted-foreground" dir="ltr">
                        #{row.id}
                    </span>
                </div>
            ),
        },
        {
            key: 'state',
            header: t('common.status', 'الحالة'),
            cell: (row) => (
                <div className="space-y-1">
                    <Badge variant={TONE[row.tone] ?? 'outline'}>{row.label}</Badge>
                    <div className="text-xs text-muted-foreground">
                        <Ltr>{row.starts_at?.slice(0, 10) ?? '—'} → {row.ends_at?.slice(0, 10) ?? '∞'}</Ltr>
                    </div>
                </div>
            ),
        },
        {
            key: 'destination',
            header: t('banners.opens', 'يفتح'),
            cell: (row) => (
                <div className="space-y-0.5">
                    <div className="text-xs text-muted-foreground">{TARGET_LABELS[row.target] ?? row.target}</div>
                    <div className="max-w-[22rem] truncate">{row.destination}</div>
                </div>
            ),
        },
        {
            key: 'b.sort_order',
            header: t('common.sort', 'الترتيب'),
            sortable: true,
            cell: (row) => <span dir="ltr">{row.sort_order}</span>,
        },
    ];

    return (
        <ManageLayout
            title={t('banners.title', 'البانرات')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('banners.title', 'البانرات') },
            ]}
            actions={<Button onClick={() => setDraft({ ...EMPTY })}>{t('banners.new', 'بانر جديد')}</Button>}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/banners`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="text-sm text-muted-foreground">
                        {t('banners.intro', 'بانرات الصفحة الرئيسية لهذا المتجر. كل متجر له بانراته.')}
                    </p>
                </div>

                {draft !== null ? (
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {draft.id === null
                                ? t('banners.new', 'بانر جديد')
                                : t('banners.edit_heading', 'تعديل البانر #:id', { id: draft.id })}
                        </h2>

                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <ImageField
                                    label={t('banners.image', 'صورة البانر')}
                                    type={media_type}
                                    value={draft.image}
                                    onChange={(image) => setDraft({ ...draft, image, image_path: image?.file ?? '' })}
                                />
                            </div>

                            <label className="space-y-1 text-sm">
                                <span>{t('banners.opens', 'يفتح')}</span>
                                <Select
                                    value={draft.target}
                                    onChange={(event) => setDraft({ ...draft, target: event.target.value })}
                                >
                                    {targets.map((target) => (
                                        <option key={target} value={target}>
                                            {TARGET_LABELS[target] ?? target}
                                        </option>
                                    ))}
                                </Select>
                            </label>

                            {draft.target === 'product' ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('common.product_number', 'رقم المنتج')}</span>
                                    <Input
                                        dir="ltr"
                                        inputMode="numeric"
                                        value={draft.product_id}
                                        onChange={(event) => setDraft({ ...draft, product_id: event.target.value })}
                                    />
                                </label>
                            ) : null}

                            {draft.target === 'category' ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('banners.category', 'التصنيف')}</span>
                                    <Select
                                        value={draft.storefront_category_id}
                                        onChange={(event) => setDraft({ ...draft, storefront_category_id: event.target.value })}
                                    >
                                        <option value="">{t('common.choose', 'اختر…')}</option>
                                        {categories.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                            ) : null}

                            {draft.target === 'url' ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('common.link', 'الرابط')}</span>
                                    <Input
                                        dir="ltr"
                                        placeholder={t('banners.link_placeholder', 'https://… أو /category/watches')}
                                        value={draft.link_url}
                                        onChange={(event) => setDraft({ ...draft, link_url: event.target.value })}
                                    />
                                </label>
                            ) : null}

                            <label className="space-y-1 text-sm">
                                <span>{t('common.starts', 'يبدأ')}</span>
                                <Input
                                    type="datetime-local"
                                    value={draft.starts_at}
                                    onChange={(event) => setDraft({ ...draft, starts_at: event.target.value })}
                                />
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('common.ends', 'ينتهي')}</span>
                                <Input
                                    type="datetime-local"
                                    value={draft.ends_at}
                                    onChange={(event) => setDraft({ ...draft, ends_at: event.target.value })}
                                />
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('common.sort', 'الترتيب')}</span>
                                <Input
                                    dir="ltr"
                                    inputMode="numeric"
                                    value={draft.sort_order}
                                    onChange={(event) => setDraft({ ...draft, sort_order: event.target.value })}
                                />
                            </label>

                            <label className="flex items-center gap-2 self-end text-sm">
                                <input
                                    type="checkbox"
                                    checked={draft.is_active}
                                    onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })}
                                />
                                <span>{t('common.active', 'مفعّل')}</span>
                            </label>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button onClick={submit} disabled={draft.image_path === ''}>
                                {t('common.save', 'حفظ')}
                            </Button>
                            <Button variant="outline" onClick={() => setDraft(null)}>
                                {t('common.cancel', 'إلغاء')}
                            </Button>
                            {draft.id !== null ? (
                                <Button
                                    variant="destructive"
                                    className="ms-auto"
                                    onClick={() =>
                                        router.delete(`${base}/${draft.id}`, {
                                            preserveScroll: true,
                                            onSuccess: () => setDraft(null),
                                        })
                                    }
                                >
                                    {t('banners.delete', 'حذف')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.id}
                    emptyTitle={t('banners.empty_title', 'لا توجد بانرات')}
                    emptyDescription={t('banners.empty_description', 'أضف بانرًا ليظهر في الصفحة الرئيسية لهذا المتجر.')}
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('common.status', 'الحالة')}
                                value={current.state ?? ''}
                                onChange={(event) => setFilter('state', event.target.value || null)}
                            >
                                <option value="">{t('common.all_statuses', 'كل الحالات')}</option>
                                <option value="running">{t('banners.state_running', 'ظاهر الآن')}</option>
                                <option value="scheduled">{t('banners.state_scheduled', 'مجدول')}</option>
                                <option value="expired">{t('banners.state_expired', 'انتهى')}</option>
                                <option value="inactive">{t('common.suspended', 'موقوف')}</option>
                            </Select>

                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('banners.enabled', 'التفعيل')}
                                value={current.is_active ?? ''}
                                onChange={(event) => setFilter('is_active', event.target.value || null)}
                            >
                                <option value="">{t('common.all', 'الكل')}</option>
                                <option value="1">{t('common.active', 'مفعّل')}</option>
                                <option value="0">{t('common.suspended', 'موقوف')}</option>
                            </Select>
                        </>
                    )}
                    rowActions={(row) => (
                        <Button variant="outline" size="sm" onClick={() => edit(row)}>
                            {t('common.edit', 'تعديل')}
                        </Button>
                    )}
                />
            </div>
        </ManageLayout>
    );
}
