import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Ltr } from '@/components/ui/bidi';

import { ImageCrop, type CropRect } from '@/components/form/ImageCrop';
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
    /** both | desktop | mobile — which device shows this banner (F1, 2026-10-20). */
    device: string;
    image_original_path: string | null;
    image_original_url: string | null;
    crop: CropRect | null;
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
    /** both | desktop | mobile (F1, 2026-10-20). */
    devices: string[];
    /** Per device: the shape to crop to, and the pixel size to ask the operator for. */
    crop_ratios: Record<string, { w: number; h: number; recommended: string }>;
    /** Which hero this storefront's home draws, and the two it can draw (the addendum, 2026-10-20). */
    hero: string;
    heroes: string[];
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
    device: string;
    /** The upload the crop was taken from, kept so it can be re-cropped without re-uploading. */
    image_original_path: string;
    crop: CropRect | null;
    /** While the crop dialog is open: where to read the original from (object URL or same-origin path). */
    cropping: string | null;
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
    device: 'both',
    image_original_path: '',
    crop: null,
    cropping: null,
    target: 'none',
    product_id: '',
    storefront_category_id: '',
    link_url: '',
    sort_order: '0',
    is_active: true,
    starts_at: '',
    ends_at: '',
};

export default function BannersIndex({ storefront, storefronts, table, categories, targets, devices, crop_ratios, hero, heroes, media_type }: Props) {
    const t = useT();
    const [draft, setDraft] = useState<Draft | null>(null);
    const [cropBusy, setCropBusy] = useState(false);

    const DEVICE_LABELS: Record<string, string> = {
        both: t('banners.device_both', 'الكمبيوتر والهاتف'),
        desktop: t('banners.device_desktop', 'الكمبيوتر فقط'),
        mobile: t('banners.device_mobile', 'الهاتف فقط'),
    };
    /** The shape and the size this device's banner is cropped to — core measured them on the hero. */
    const shapeFor = (device: string) => crop_ratios[device] ?? crop_ratios.both;

    const HERO_LABELS: Record<string, string> = {
        three_d: t('banners.hero_three_d', 'الساعة ثلاثية الأبعاد'),
        banner: t('banners.hero_banner', 'البانرات'),
    };

    /**
     * Upload a cropped file and put it on the draft, keeping the ORIGINAL beside it.
     *
     * The same endpoint `ImageField` posts to, so one upload path and one set of rules.
     */
    const useCropped = async (file: File, rect: CropRect, original: StoredImage | null) => {
        if (draft === null) return;
        setCropBusy(true);
        try {
            const body = new FormData();
            body.append('type', media_type);
            body.append('file', file);
            const response = await fetch('/manage/media', { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const stored = (await response.json()) as StoredImage;
            setDraft((d) =>
                d === null
                    ? d
                    : {
                          ...d,
                          image: stored,
                          image_path: `${stored.folder}/${stored.file}`,
                          // the first crop records the original; a re-crop keeps the one already stored
                          image_original_path: original === null ? d.image_original_path : `${original.folder}/${original.file}`,
                          crop: rect,
                          cropping: null,
                      },
            );
        } finally {
            setCropBusy(false);
        }
    };

    const TARGET_LABELS = targetLabels(t);

    const base = `/manage/storefronts/${storefront.id}/banners`;

    const edit = (row: Banner) =>
        setDraft({
            id: row.id,
            image_path: row.image_path,
            // Reconstructed from what the list already carries: enough for the preview, and the
            // upload endpoint replaces it wholesale the moment a new file is chosen.
            image: {
                file: row.image_path.includes('/') ? row.image_path.slice(row.image_path.lastIndexOf('/') + 1) : row.image_path,
                folder: row.image_path.includes('/') ? row.image_path.slice(0, row.image_path.lastIndexOf('/')) : '',
                url: row.image_url,
                width: 0,
                height: 0,
                bytes: 0,
                renditions: {},
                skipped: [],
            },
            device: row.device,
            image_original_path: row.image_original_path ?? '',
            crop: row.crop,
            cropping: null,
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
            image_original_path: draft.image_original_path === '' ? null : draft.image_original_path,
            crop: draft.crop === null ? null : JSON.stringify(draft.crop),
            device: draft.device,
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
            key: 'device',
            header: t('banners.device', 'الجهاز'),
            // Which device shows it (F1). On the row because "why is this banner not on my phone"
            // is otherwise a question you have to open the record to answer.
            cell: (row) => <Badge variant={row.device === 'both' ? 'neutral' : 'outline'}>{DEVICE_LABELS[row.device] ?? row.device}</Badge>,
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

                {/*
                  THE HERO SWITCH (the addendum, 2026-10-20). It is on THIS screen because "why are my
                  banners not showing" is the question it answers. Changing it changes what shoppers see
                  on this storefront's home page, so the screen says that in plain words and says how
                  long it takes, rather than leaving the operator to discover both.
                */}
                <div className="rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium">{t('banners.hero', 'شكل أعلى الصفحة الرئيسية')}</span>
                        <Select
                            aria-label={t('banners.hero', 'شكل أعلى الصفحة الرئيسية')}
                            className="w-56"
                            value={hero}
                            onChange={(event) =>
                                router.post(`/manage/storefronts/${storefront.id}/banners/hero`, { hero: event.target.value }, { preserveScroll: true })
                            }
                        >
                            {heroes.map((mode) => (
                                <option key={mode} value={mode}>
                                    {HERO_LABELS[mode] ?? mode}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'banners.hero_note',
                            'يحدّد ما يظهر أعلى الصفحة الرئيسية لهذا المتجر: الساعة ثلاثية الأبعاد أو البانرات التي بالأسفل. التغيير يصل للزوار خلال دقائق قليلة.',
                        )}
                    </p>
                    {hero !== 'banner' ? (
                        <p className="mt-2 text-sm font-medium text-amber-600 dark:text-amber-500">
                            {t('banners.hero_off', 'البانرات بالأسفل لا تظهر للزوار الآن، لأن هذا المتجر يعرض الساعة ثلاثية الأبعاد.')}
                        </p>
                    ) : null}
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
                                    onChange={(image) => {
                                        if (image === null) {
                                            setDraft({ ...draft, image: null, image_path: '', image_original_path: '', crop: null, cropping: null });

                                            return;
                                        }
                                        // A chosen image is cropped before it is stored (F1): the upload that just
                                        // happened becomes the ORIGINAL, and the crop produces the file the shop serves.
                                        // It is read back from THIS host, not the storefront's asset host, so the canvas
                                        // is same-origin and `toBlob` is allowed to hand over the bytes.
                                        setDraft({
                                            ...draft,
                                            image,
                                            image_path: `${image.folder}/${image.file}`,
                                            image_original_path: `${image.folder}/${image.file}`,
                                            crop: null,
                                            cropping: `/Uploads_Images/${image.folder}/${image.file}`,
                                        });
                                    }}
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('banners.recommended_size', 'المقاس المقترح لـ:device — :size بكسل', {
                                        device: DEVICE_LABELS[draft.device] ?? draft.device,
                                        size: shapeFor(draft.device).recommended,
                                    })}
                                </p>

                                {draft.cropping === null && draft.image_original_path !== '' ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="mt-2"
                                        onClick={() => setDraft({ ...draft, cropping: `/Uploads_Images/${draft.image_original_path}` })}
                                    >
                                        {t('banners.recrop', 'إعادة القصّ من الصورة الأصلية')}
                                    </Button>
                                ) : null}

                                {draft.cropping !== null ? (
                                    <div className="mt-3 rounded-md border border-border p-3">
                                        <p className="mb-2 text-sm font-medium">
                                            {t('banners.crop_heading', 'اقصص الصورة لمقاس :device', {
                                                device: DEVICE_LABELS[draft.device] ?? draft.device,
                                            })}
                                        </p>
                                        <ImageCrop
                                            src={draft.cropping}
                                            ratio={{ w: shapeFor(draft.device).w, h: shapeFor(draft.device).h }}
                                            output={{ w: shapeFor(draft.device).w * 2, h: shapeFor(draft.device).h * 2 }}
                                            initial={draft.crop}
                                            busy={cropBusy}
                                            onCancel={() => setDraft({ ...draft, cropping: null })}
                                            onDone={(file, rect) => void useCropped(file, rect, draft.crop === null ? draft.image : null)}
                                        />
                                    </div>
                                ) : null}
                            </div>

                            <label className="space-y-1 text-sm">
                                <span>{t('banners.device', 'الجهاز')}</span>
                                <Select
                                    value={draft.device}
                                    onChange={(event) => setDraft({ ...draft, device: event.target.value })}
                                >
                                    {devices.map((device) => (
                                        <option key={device} value={device}>
                                            {DEVICE_LABELS[device] ?? device}
                                        </option>
                                    ))}
                                </Select>
                            </label>

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
                                aria-label={t('banners.device', 'الجهاز')}
                                value={current['b.device'] ?? ''}
                                onChange={(event) => setFilter('b.device', event.target.value || null)}
                            >
                                <option value="">{t('banners.device_all', 'كل الأجهزة')}</option>
                                {devices.map((device) => (
                                    <option key={device} value={device}>
                                        {DEVICE_LABELS[device] ?? device}
                                    </option>
                                ))}
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
