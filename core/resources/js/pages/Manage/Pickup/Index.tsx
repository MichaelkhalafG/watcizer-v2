import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * "Pick up in store" — the dashboard page of one storefront (click & collect, 2026-11-14).
 *
 * The team sets the feature up here: the switch (off by default; the server refuses to turn it on without an active
 * store), the TERMS — how long a ready order is held, the one extension, the ready windows in business days for our own
 * stock (express) and for items sourced first (market), the open pay-at-store reservations one phone may hold — and the
 * STORES, in the order the checkout lists them. The customer's sentence is shown as it reads today. The rules live in
 * App\Domain\Pickup (Pickup, PickupStores); this screen only shows them early. Pay-at-store and its amount limit are a
 * payment method on the Payments screen, like cash on delivery.
 */

interface Store {
    id: number | null;
    name_en: string;
    name_ar: string;
    address_en: string;
    address_ar: string;
    hours_en: string;
    hours_ar: string;
    map_url: string;
    phone: string;
    whatsapp: string;
    is_active: boolean;
    open: number;
}

interface SavedStore {
    id: number;
    name_en: string;
    name_ar: string;
    address_en: string;
    address_ar: string;
    hours_en: string | null;
    hours_ar: string | null;
    map_url: string | null;
    phone: string | null;
    whatsapp: string | null;
    is_active: boolean;
    open: number;
}

interface Terms {
    hold_hours: number | string;
    extend_hours: number | string;
    express_min: number | string;
    express_max: number | string;
    market_min: number | string;
    market_max: number | string;
    max_open_store_pay: number | string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: { value: string; label: string }[];
    enabled: boolean;
    terms: Terms;
    sentences: { express: { en: string; ar: string }; market: { en: string; ar: string } };
    stores: SavedStore[];
    limits: { hold: number; extend: number; days: number; open: number };
}

const toStore = (s: SavedStore): Store => ({
    id: s.id,
    name_en: s.name_en,
    name_ar: s.name_ar,
    address_en: s.address_en,
    address_ar: s.address_ar,
    hours_en: s.hours_en ?? '',
    hours_ar: s.hours_ar ?? '',
    map_url: s.map_url ?? '',
    phone: s.phone ?? '',
    whatsapp: s.whatsapp ?? '',
    is_active: s.is_active,
    open: s.open,
});

const blank = (): Store => ({
    id: null,
    name_en: '',
    name_ar: '',
    address_en: '',
    address_ar: '',
    hours_en: '',
    hours_ar: '',
    map_url: '',
    phone: '',
    whatsapp: '',
    is_active: true,
    open: 0,
});

export default function PickupIndex({ storefront, storefronts, enabled, terms, sentences, stores, limits }: Props) {
    const t = useT();
    const { locale } = usePage<SharedProps>().props;
    const base = `/manage/storefronts/${storefront.id}/pickup`;
    const toggle = (next: boolean) => router.post(`${base}/switch`, { enabled: next }, { preserveScroll: true });

    const termsForm = useForm<Terms>({ ...terms });
    const termErr = (k: keyof Terms) => (termsForm.errors as Record<string, string | undefined>)[k];
    const saveTerms = () => termsForm.put(`${base}/terms`, { preserveScroll: true });

    const storesForm = useForm<{ stores: Store[] }>({ stores: stores.map(toStore) });
    const storeErrors = storesForm.errors as Record<string, string | undefined>;
    const err = (i: number, k: string) => storeErrors[`stores.${i}.${k}`];
    const setStore = (i: number, patch: Partial<Store>) =>
        storesForm.setData(
            'stores',
            storesForm.data.stores.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );
    const move = (i: number, by: number) => {
        const list = [...storesForm.data.stores];
        const j = i + by;
        if (j < 0 || j >= list.length) return;
        [list[i], list[j]] = [list[j], list[i]];
        storesForm.setData('stores', list);
    };
    const remove = (i: number) => storesForm.setData('stores', storesForm.data.stores.filter((_, j) => j !== i));
    // the count of open reservations is shown, never sent
    storesForm.transform((data) => ({ stores: data.stores.map(({ open: _open, ...rest }) => rest) }));
    const saveStores = () => storesForm.put(`${base}/stores`, { preserveScroll: true });

    const field = (i: number, k: keyof Store, label: string, opts: { dir?: 'rtl' | 'ltr'; max: number; placeholder?: string }) => (
        <label className="block text-xs">
            <span className="mb-1 block text-muted-foreground">{label}</span>
            <Input
                aria-invalid={err(i, k) ? true : undefined}
                dir={opts.dir}
                maxLength={opts.max}
                placeholder={opts.placeholder}
                value={String(storesForm.data.stores[i][k] ?? '')}
                onChange={(e) => setStore(i, { [k]: e.target.value } as Partial<Store>)}
            />
            {err(i, k) ? <span className="mt-1 block text-destructive">{err(i, k)}</span> : null}
        </label>
    );

    const termField = (k: keyof Terms, label: string, max: number) => (
        <label className="block text-xs">
            <span className="mb-1 block text-muted-foreground">{label}</span>
            <Input
                type="number"
                inputMode="numeric"
                min={0}
                max={max}
                className="w-28 tabular-nums"
                aria-invalid={termErr(k) ? true : undefined}
                value={String(termsForm.data[k])}
                onChange={(e) => termsForm.setData(k, e.target.value)}
            />
            {termErr(k) ? <span className="mt-1 block text-destructive">{termErr(k)}</span> : null}
        </label>
    );

    return (
        <ManageLayout
            title={t('pickup.title', 'الاستلام من الفرع')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('pickup.title', 'الاستلام من الفرع') }]}
            actions={
                <label className="flex items-center gap-3 text-sm">
                    <Switch checked={enabled} onCheckedChange={toggle} aria-label={t('pickup.switch_aria', 'تشغيل الاستلام من الفرع')} />
                    <span>{enabled ? t('pickup.switch_on', 'الاستلام من الفرع يعمل') : t('pickup.switch_off', 'الاستلام من الفرع متوقف')}</span>
                </label>
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/pickup`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t(
                            'pickup.intro',
                            'عند التشغيل يختار العميل في صفحة إتمام الطلب بين التوصيل والاستلام من أحد الفروع: بدون رسوم توصيل وبدون عنوان. يُحجز الطلب، ويحضره الفريق إلى الفرع ثم يضغط «جاهز للاستلام» فيصل للعميل بريد بذلك، ويبدأ وقت الحجز من تلك اللحظة. الدفع أونلاين كالمعتاد، أو في الفرع (وسيلة دفع في شاشة وسائل الدفع بحدها الأقصى، مثل الدفع عند الاستلام).',
                        )}
                    </p>
                </div>

                {!enabled ? (
                    <Alert>{t('pickup.off_note', 'الاستلام من الفرع متوقف: لا يظهر الاختيار في صفحة إتمام الطلب. الحجوزات الموجودة تبقى كما هي.')}</Alert>
                ) : null}

                {/* ── the terms ──────────────────────────────────────────────────────────────── */}
                <section className="space-y-3 rounded-lg border bg-card p-4">
                    <h2 className="font-semibold">{t('pickup.terms_heading', 'الشروط')}</h2>
                    <div className="flex flex-wrap gap-4">
                        {termField('hold_hours', t('pickup.hold_hours', 'مدة الحجز بعد «جاهز» (ساعة)'), limits.hold)}
                        {termField('extend_hours', t('pickup.extend_hours', 'تمديد واحد بطلب العميل (ساعة)'), limits.extend)}
                        {termField('max_open_store_pay', t('pickup.max_open', 'حجوزات «الدفع في الفرع» المفتوحة لكل رقم'), limits.open)}
                    </div>
                    <div className="flex flex-wrap gap-6">
                        <div className="space-y-1">
                            <p className="text-sm font-medium">{t('pickup.express_heading', 'منتجات إكسبريس (من مخزوننا): جاهز خلال')}</p>
                            <div className="flex gap-3">
                                {termField('express_min', t('pickup.days_from', 'من (يوم عمل)'), limits.days)}
                                {termField('express_max', t('pickup.days_to', 'إلى (يوم عمل)'), limits.days)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                <span dir="rtl">{sentences.express.ar}</span> · <span dir="ltr">{sentences.express.en}</span>
                            </p>
                        </div>
                        <div className="space-y-1">
                            <p className="text-sm font-medium">{t('pickup.market_heading', 'منتجات ماركت (تُوفَّر أولًا): جاهز خلال')}</p>
                            <div className="flex gap-3">
                                {termField('market_min', t('pickup.days_from', 'من (يوم عمل)'), limits.days)}
                                {termField('market_max', t('pickup.days_to', 'إلى (يوم عمل)'), limits.days)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                <span dir="rtl">{sentences.market.ar}</span> · <span dir="ltr">{sentences.market.en}</span>
                            </p>
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('pickup.window_note', 'الطلب الذي فيه منتج ماركت واحد على الأقل يأخذ مدة ماركت. يرى العميل هذه الجملة في صفحة إتمام الطلب وفي بريد التأكيد.')}
                    </p>
                    <Button onClick={saveTerms} disabled={termsForm.processing}>
                        {t('pickup.save_terms', 'حفظ الشروط')}
                    </Button>
                </section>

                {/* ── the stores ─────────────────────────────────────────────────────────────── */}
                <section className="space-y-3 rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-semibold">{t('pickup.stores_heading', 'الفروع')}</h2>
                        <Button variant="outline" size="sm" onClick={() => storesForm.setData('stores', [...storesForm.data.stores, blank()])}>
                            <Plus className="size-4" />
                            {t('pickup.add_store', 'إضافة فرع')}
                        </Button>
                    </div>
                    {storeErrors.stores ? <Alert tone="error">{storeErrors.stores}</Alert> : null}
                    {storesForm.data.stores.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('pickup.no_stores', 'لا توجد فروع بعد.')}</p>
                    ) : null}
                    {storesForm.data.stores.map((store, i) => (
                        <div key={store.id ?? `new-${i}`} className="space-y-3 rounded-md border p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{(locale === 'en' ? store.name_en || store.name_ar : store.name_ar || store.name_en) || t('pickup.new_store', 'فرع جديد')}</span>
                                {store.open > 0 ? <Badge variant="warning">{t('pickup.open_count', ':count حجز مفتوح', { count: store.open })}</Badge> : null}
                                <label className="ms-auto flex items-center gap-2 text-xs">
                                    <Switch checked={store.is_active} onCheckedChange={(v) => setStore(i, { is_active: v })} aria-label={t('pickup.store_active', 'الفرع مفعّل')} />
                                    <span>{store.is_active ? t('pickup.store_active', 'الفرع مفعّل') : t('pickup.store_inactive', 'الفرع متوقف')}</span>
                                </label>
                                <Button variant="ghost" size="icon" aria-label={t('pickup.move_up', 'لأعلى')} onClick={() => move(i, -1)} disabled={i === 0}>
                                    <ArrowUp className="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" aria-label={t('pickup.move_down', 'لأسفل')} onClick={() => move(i, 1)} disabled={i === storesForm.data.stores.length - 1}>
                                    <ArrowDown className="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" aria-label={t('pickup.remove_store', 'حذف الفرع')} onClick={() => remove(i)} disabled={store.open > 0}>
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {field(i, 'name_ar', t('pickup.name_ar', 'اسم الفرع (عربي)'), { dir: 'rtl', max: 120 })}
                                {field(i, 'name_en', t('pickup.name_en', 'اسم الفرع (إنجليزي)'), { dir: 'ltr', max: 120 })}
                                {field(i, 'address_ar', t('pickup.address_ar', 'العنوان (عربي)'), { dir: 'rtl', max: 255 })}
                                {field(i, 'address_en', t('pickup.address_en', 'العنوان (إنجليزي)'), { dir: 'ltr', max: 255 })}
                                {field(i, 'hours_ar', t('pickup.hours_ar', 'مواعيد العمل (عربي)'), { dir: 'rtl', max: 255, placeholder: t('pickup.hours_ar_hint', 'يوميًا ١١ ص – ١١ م') })}
                                {field(i, 'hours_en', t('pickup.hours_en', 'مواعيد العمل (إنجليزي)'), { dir: 'ltr', max: 255, placeholder: 'Daily 11:00–23:00' })}
                                {field(i, 'map_url', t('pickup.map_url_label', 'رابط الخريطة'), { dir: 'ltr', max: 500, placeholder: 'https://maps.app.goo.gl/…' })}
                                {field(i, 'phone', t('pickup.phone', 'هاتف الفرع'), { dir: 'ltr', max: 32 })}
                                {field(i, 'whatsapp', t('pickup.whatsapp', 'واتساب الفرع'), { dir: 'ltr', max: 32, placeholder: '01xxxxxxxxx' })}
                            </div>
                            {store.open > 0 ? (
                                <p className="text-xs text-muted-foreground">{t('pickup.cannot_remove', 'لا يُحذف فرع عليه حجوزات مفتوحة؛ أوقفه بدلًا من ذلك.')}</p>
                            ) : null}
                        </div>
                    ))}
                    <Button onClick={saveStores} disabled={storesForm.processing}>
                        {t('pickup.save_stores', 'حفظ الفروع')}
                    </Button>
                </section>
            </div>
        </ManageLayout>
    );
}
