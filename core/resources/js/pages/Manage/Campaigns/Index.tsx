import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { ConfirmAction } from '@/components/manage/ConfirmAction';
import ProductPicker from '@/components/manage/ProductPicker';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Ltr } from '@/components/ui/bidi';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * Timed campaigns — Hot Deals and Flash Sale (the twelve updates, Part 2.1, 2026-11-05).
 *
 * ONE page: this storefront's campaigns, newest start first, and the form for one. A campaign is five
 * things — a name, a presentation, a product selection, a start and an end, an on/off switch — and the
 * form has exactly those five. What the storefront shows follows from them: the home slider and the
 * page in that presentation's design, the countdown in the header while it is live, and for Hot Deals
 * the header switch.
 *
 * The list says where each campaign STANDS (scheduled, live, ended, off) from the shop's own clock, and
 * for one that has gone live the real numbers behind the storefront's progress bar: how many units its
 * products started with and how many of those were sold. Those numbers are recorded and counted by the
 * server (the stock ledger), never typed here.
 *
 * Every rule is in the form before it is a refusal (AGENTS §2.27): Save is disabled with the reason
 * beside it, and a server refusal (two active campaigns of one presentation overlapping) lands on the
 * field it is about.
 */

interface Campaign {
    id: number;
    name: string;
    presentation: string;
    /** `YYYY-MM-DDTHH:mm` in the shop's time zone — what a datetime-local input reads and writes. */
    starts_at: string;
    ends_at: string;
    is_active: boolean;
    state: 'off' | 'scheduled' | 'live' | 'ended';
    product_ids: number[];
    /** Products that have a recorded starting stock (the progress bar's "start"). */
    recorded: number;
    started_units: number;
    sold_units: number;
    activated_at: string | null;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    campaigns: Campaign[];
    presentations: string[];
    max_products: number;
    now: string;
    timezone: string;
}

interface Draft {
    id: number | null;
    name: string;
    presentation: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
    product_ids: number[];
}

const STATE_TONE: Record<Campaign['state'], 'neutral' | 'outline' | 'success' | 'warning'> = {
    live: 'success',
    scheduled: 'warning',
    ended: 'outline',
    off: 'neutral',
};

/** A date-time as the list prints it: `2026-11-05 18:00`, left to right in both languages. */
const shown = (value: string) => value.replace('T', ' ');

export default function CampaignsIndex({ storefront, storefronts, campaigns, presentations, max_products, now, timezone }: Props) {
    const t = useT();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [draft, setDraft] = useState<Draft | null>(null);
    const base = `/manage/storefronts/${storefront.id}/campaigns`;

    const PRESENTATION: Record<string, string> = {
        hot_deals: t('campaigns.presentation_hot_deals', 'عروض ساخنة (Hot Deals)'),
        flash_sale: t('campaigns.presentation_flash_sale', 'تخفيضات خاطفة (Flash Sale)'),
    };
    const STATE: Record<Campaign['state'], string> = {
        live: t('campaigns.state_live', 'تعمل الآن'),
        scheduled: t('campaigns.state_scheduled', 'مجدولة'),
        ended: t('campaigns.state_ended', 'انتهت'),
        off: t('campaigns.state_off', 'متوقفة'),
    };

    const fresh = (): Draft => ({
        id: null,
        name: '',
        presentation: presentations[0] ?? 'flash_sale',
        starts_at: now,
        ends_at: '',
        is_active: true,
        product_ids: [],
    });

    const edit = (campaign: Campaign) =>
        setDraft({
            id: campaign.id,
            name: campaign.name,
            presentation: campaign.presentation,
            starts_at: campaign.starts_at,
            ends_at: campaign.ends_at,
            is_active: campaign.is_active,
            product_ids: campaign.product_ids,
        });

    const payloadOf = (source: Draft | Campaign, patch: Partial<Draft> = {}) => {
        const next = { ...source, ...patch };

        return {
            name: next.name,
            presentation: next.presentation,
            starts_at: next.starts_at,
            ends_at: next.ends_at,
            is_active: next.is_active,
            product_ids: next.product_ids,
        };
    };

    const submit = () => {
        if (draft === null) {
            return;
        }
        const done = { preserveScroll: true, onSuccess: () => setDraft(null) };
        draft.id === null ? router.post(base, payloadOf(draft), done) : router.put(`${base}/${draft.id}`, payloadOf(draft), done);
    };

    const toggle = (campaign: Campaign) =>
        router.put(`${base}/${campaign.id}`, payloadOf(campaign, { is_active: !campaign.is_active }), { preserveScroll: true });

    // Why Save is disabled, said next to it.
    const blocked =
        draft === null
            ? null
            : draft.name.trim() === ''
              ? t('campaigns.need_name', 'اكتب اسمًا للحملة.')
              : draft.starts_at === '' || draft.ends_at === ''
                ? t('campaigns.need_window', 'حدّد البداية والنهاية.')
                : draft.ends_at <= draft.starts_at
                  ? t('campaigns.error_window', 'يجب أن تكون النهاية بعد البداية.')
                  : draft.product_ids.length === 0
                    ? t('campaigns.need_products', 'اختر منتجًا واحدًا على الأقل.')
                    : null;

    const listErrors = Object.entries(errors).filter(([key]) => key === 'product_ids' || key.startsWith('product_ids.'));

    return (
        <ManageLayout
            title={t('campaigns.title', 'الحملات المؤقتة')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('campaigns.title', 'الحملات المؤقتة') }]}
            actions={<Button onClick={() => setDraft(fresh())}>{t('campaigns.new', 'حملة جديدة')}</Button>}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/campaigns`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t(
                            'campaigns.intro',
                            'حملة = منتجات مختارة بين بداية ونهاية. تظهر في الصفحة الرئيسية كشريط، ولها صفحة خاصة وعدّاد تنازلي في أعلى الموقع ما دامت تعمل. عند النهاية تختفي تلقائيًا ولا تتجدد. لا يمكن أن تعمل حملتان من الشكل نفسه في وقت واحد.',
                        )}
                    </p>
                </div>

                {draft !== null ? (
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {draft.id === null ? t('campaigns.new', 'حملة جديدة') : t('campaigns.edit_heading', 'تعديل الحملة: :name', { name: draft.name })}
                        </h2>

                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <label className="space-y-1 text-sm">
                                <span>{t('campaigns.name', 'الاسم')}</span>
                                <Input
                                    value={draft.name}
                                    maxLength={120}
                                    placeholder={t('campaigns.name_placeholder', 'مثال: تخفيضات نهاية الأسبوع')}
                                    onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                                />
                                <span className="block text-xs text-muted-foreground">
                                    {t('campaigns.name_hint', 'اسم للفريق فقط؛ العميل يرى عنوان الشكل المختار.')}
                                </span>
                                {errors.name ? <span className="text-xs text-destructive">{errors.name}</span> : null}
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('campaigns.presentation', 'شكل العرض')}</span>
                                <Select value={draft.presentation} onChange={(event) => setDraft({ ...draft, presentation: event.target.value })}>
                                    {presentations.map((key) => (
                                        <option key={key} value={key}>
                                            {PRESENTATION[key] ?? key}
                                        </option>
                                    ))}
                                </Select>
                                <span className="block text-xs text-muted-foreground">
                                    {draft.presentation === 'hot_deals'
                                        ? t(
                                              'campaigns.presentation_hot_deals_hint',
                                              'شريط وصفحة «Hot Deals»، وزر تبديل في أعلى الموقع يحوّل المتجر كله إلى وضع العروض الساخنة ما دامت الحملة تعمل.',
                                          )
                                        : t('campaigns.presentation_flash_sale_hint', 'شريط وصفحة «Flash Sale»، مع العدّاد التنازلي في شريط التصفح.')}
                                </span>
                                {errors.presentation ? <span className="text-xs text-destructive">{errors.presentation}</span> : null}
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('campaigns.starts_at', 'البداية')}</span>
                                <Input
                                    dir="ltr"
                                    type="datetime-local"
                                    value={draft.starts_at}
                                    onChange={(event) => setDraft({ ...draft, starts_at: event.target.value })}
                                />
                                {errors.starts_at ? <span className="text-xs text-destructive">{errors.starts_at}</span> : null}
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('campaigns.ends_at', 'النهاية')}</span>
                                <Input
                                    dir="ltr"
                                    type="datetime-local"
                                    min={draft.starts_at}
                                    value={draft.ends_at}
                                    onChange={(event) => setDraft({ ...draft, ends_at: event.target.value })}
                                />
                                <span className="block text-xs text-muted-foreground">
                                    {t('campaigns.window_hint', 'بتوقيت المتجر (:zone). العدّاد في الموقع ينتهي في هذه اللحظة بالضبط.', { zone: timezone })}
                                </span>
                                {errors.ends_at ? <span className="text-xs text-destructive">{errors.ends_at}</span> : null}
                            </label>

                            <div className="space-y-2 text-sm sm:col-span-2">
                                <span>{t('campaigns.products', 'منتجات الحملة، بالترتيب الذي تظهر به')}</span>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'campaigns.products_hint',
                                        'ابحث بالاسم أو الكود وأضف. المنتج المخفي في هذا المتجر لا يظهر في الحملة. حتى :max منتجًا. شريط «نسبة المباع» يُحسب لكل منتج من مخزونه لحظة بدء الحملة ومن مبيعاته الفعلية بعدها.',
                                        { max: max_products },
                                    )}
                                </p>
                                <ProductPicker
                                    id="campaign-products"
                                    searchUrl={`${base}/products`}
                                    value={draft.product_ids}
                                    onChange={(ids) => setDraft({ ...draft, product_ids: ids })}
                                    max={max_products}
                                />
                                {listErrors.map(([key, message]) => (
                                    <p key={key} className="text-sm text-destructive">
                                        {message}
                                    </p>
                                ))}
                            </div>

                            <label className="flex items-center gap-3 text-sm sm:col-span-2">
                                <Switch checked={draft.is_active} onCheckedChange={(checked) => setDraft({ ...draft, is_active: checked })} />
                                <span>
                                    {draft.is_active
                                        ? t('campaigns.active_on', 'مفعّلة: تظهر في المتجر بين البداية والنهاية.')
                                        : t('campaigns.active_off', 'متوقفة: لا تظهر في المتجر حتى لو حلّ وقتها.')}
                                </span>
                            </label>
                        </div>

                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <Button onClick={submit} disabled={blocked !== null}>
                                {t('common.save', 'حفظ')}
                            </Button>
                            <Button variant="outline" onClick={() => setDraft(null)}>
                                {t('common.cancel', 'إلغاء')}
                            </Button>
                            {blocked !== null ? <span className="text-sm text-muted-foreground">{blocked}</span> : null}
                        </div>
                    </div>
                ) : null}

                {campaigns.length === 0 ? (
                    <Alert tone="info" title={t('campaigns.empty_title', 'لا توجد حملات بعد')}>
                        {t('campaigns.empty_body', 'أنشئ حملة: اختر منتجاتها وحدّد بدايتها ونهايتها، وستظهر في المتجر في وقتها.')}
                    </Alert>
                ) : (
                    <div className="overflow-x-auto rounded-lg border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('campaigns.name', 'الاسم')}</TableHead>
                                    <TableHead>{t('campaigns.presentation', 'شكل العرض')}</TableHead>
                                    <TableHead>{t('campaigns.window', 'الفترة')}</TableHead>
                                    <TableHead>{t('campaigns.state', 'الحالة')}</TableHead>
                                    <TableHead>{t('campaigns.sales', 'المباع فعليًا')}</TableHead>
                                    <TableHead className="text-end">{t('common.actions', 'إجراءات')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {campaigns.map((campaign) => (
                                    <TableRow key={campaign.id}>
                                        <TableCell>
                                            <div className="font-medium">{campaign.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {t('campaigns.product_count', ':count منتج', { count: campaign.product_ids.length })}
                                            </div>
                                        </TableCell>
                                        <TableCell>{PRESENTATION[campaign.presentation] ?? campaign.presentation}</TableCell>
                                        <TableCell className="text-xs">
                                            <div>
                                                <Ltr>{shown(campaign.starts_at)}</Ltr>
                                            </div>
                                            <div>
                                                <Ltr>{shown(campaign.ends_at)}</Ltr>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={STATE_TONE[campaign.state]}>{STATE[campaign.state]}</Badge>
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {campaign.recorded === 0 ? (
                                                <span className="text-muted-foreground">
                                                    {campaign.state === 'scheduled' || campaign.state === 'off'
                                                        ? t('campaigns.sales_pending', 'يُسجَّل المخزون عند بدء الحملة')
                                                        : t('campaigns.sales_none', 'لا مخزون مسجَّل')}
                                                </span>
                                            ) : (
                                                <span>
                                                    {t('campaigns.sales_line', ':sold من :start وحدة (:count منتج مسجَّل)', {
                                                        sold: campaign.sold_units,
                                                        start: campaign.started_units,
                                                        count: campaign.recorded,
                                                    })}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center justify-end gap-2">
                                                {campaign.state !== 'ended' ? (
                                                    <Switch
                                                        checked={campaign.is_active}
                                                        onCheckedChange={() => toggle(campaign)}
                                                        aria-label={t('campaigns.toggle_aria', 'تفعيل أو إيقاف الحملة :name', { name: campaign.name })}
                                                    />
                                                ) : null}
                                                <Button variant="outline" size="sm" onClick={() => edit(campaign)}>
                                                    {t('common.edit', 'تعديل')}
                                                </Button>
                                                <ConfirmAction
                                                    title={t('campaigns.delete_title', 'حذف الحملة «:name»؟', { name: campaign.name })}
                                                    consequence={
                                                        campaign.state === 'live'
                                                            ? t(
                                                                  'campaigns.delete_live',
                                                                  'الحملة تعمل الآن: ستختفي من المتجر فورًا — الشريط والصفحة والعدّاد — ويُحذف معها المخزون المسجَّل عند بدايتها.',
                                                              )
                                                            : t('campaigns.delete_body', 'تُحذف الحملة ومنتجاتها المختارة. المنتجات نفسها لا تتأثر.')
                                                    }
                                                    confirmLabel={t('common.delete', 'حذف')}
                                                    tone="destructive"
                                                    trigger={
                                                        <Button variant="outline" size="sm">
                                                            {t('common.delete', 'حذف')}
                                                        </Button>
                                                    }
                                                    onConfirm={() => router.delete(`${base}/${campaign.id}`, { preserveScroll: true })}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </ManageLayout>
    );
}
