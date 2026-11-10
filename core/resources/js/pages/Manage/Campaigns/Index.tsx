import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
 * the real numbers behind the storefront's progress bar: the units on the shelf after each product's last
 * restock and how many of those were sold since (2026-11-10). The server counts them from the stock ledger;
 * they are never typed here.
 *
 * Every rule is in the form before it is a refusal (AGENTS §2.27): Save is disabled with the reason
 * beside it, and a server refusal (two active campaigns of one presentation overlapping) lands on the
 * field it is about.
 *
 * CAMPAIGN PRICES (2026-11-10): under the picker, one row per picked product — "No change", a campaign
 * price, or an extra percentage off its current price — with, live, its normal price, its current price,
 * the campaign price that will apply, the % off the original and (for whom the product form shows cost)
 * the margin over the purchase price. A price that is not lower than the current one blocks Save on its
 * row; one under the purchase price, or more than 50 % extra, asks for a confirmation (the server asks
 * too: CampaignWriter). The arithmetic is the server's (CampaignPrices::campaignPrice), to the cent.
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
    /** product id → its campaign price rule (2026-11-10) */
    prices: Record<string, { mode: string; value: number }>;
    /** Products with a restock on record (the progress bar's "start" is the shelf right after it). */
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
    can_see_cost: boolean;
    confirm_percent: number;
    max_percent: number;
}

type PriceMode = '' | 'price' | 'percent';

interface PriceRow {
    mode: PriceMode;
    value: string;
}

interface PriceFact {
    title: string;
    code: string | null;
    selling: number;
    sale: number | null;
    current: number;
    purchase: number | null;
}

/** CampaignPrices::campaignPrice, to the cent: null when it would not be lower than the current price. */
function campaignPriceOf(row: PriceRow | undefined, fact: PriceFact | undefined): number | null {
    if (!row || row.mode === '' || !fact) return null;
    const value = Number(row.value);
    if (!Number.isFinite(value) || value <= 0) return null;
    const candidate =
        row.mode === 'price' ? Math.round(value * 100) / 100 : Math.round((Math.round(fact.current * 100) * (10000 - Math.round(value * 100))) / 1000000);
    return candidate > 0 && candidate < fact.current ? candidate : null;
}

const money = (n: number) => n.toLocaleString('en-US', { maximumFractionDigits: 2 });

interface Draft {
    id: number | null;
    name: string;
    presentation: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
    product_ids: number[];
    prices: Record<string, PriceRow>;
}

const STATE_TONE: Record<Campaign['state'], 'neutral' | 'outline' | 'success' | 'warning'> = {
    live: 'success',
    scheduled: 'warning',
    ended: 'outline',
    off: 'neutral',
};

/** A date-time as the list prints it: `2026-11-05 18:00`, left to right in both languages. */
const shown = (value: string) => value.replace('T', ' ');

export default function CampaignsIndex({ storefront, storefronts, campaigns, presentations, max_products, now, timezone, can_see_cost, confirm_percent, max_percent }: Props) {
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
        prices: {},
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
            prices: Object.fromEntries(
                Object.entries(campaign.prices ?? {}).map(([id, rule]) => [id, { mode: (rule.mode === 'percent' ? 'percent' : 'price') as PriceMode, value: String(rule.value) }]),
            ),
        });

    /** the rows that carry a price, as the server reads them: product id → {mode, value} */
    const pricesOf = (rows: Record<string, PriceRow>, ids: number[]) =>
        Object.fromEntries(
            Object.entries(rows)
                .filter(([id, row]) => row.mode !== '' && row.value.trim() !== '' && ids.includes(Number(id)))
                .map(([id, row]) => [id, { mode: row.mode, value: Number(row.value) }]),
        );

    const payloadOf = (source: Draft | Campaign, patch: Partial<Draft> = {}, confirmPrices = false) => {
        const next = { ...source, ...patch };
        const prices =
            'prices' in next && next.prices
                ? pricesOf(
                      Object.fromEntries(Object.entries(next.prices).map(([id, row]) => [id, { mode: row.mode as PriceMode, value: String(row.value) }])),
                      next.product_ids,
                  )
                : {};

        return {
            name: next.name,
            presentation: next.presentation,
            starts_at: next.starts_at,
            ends_at: next.ends_at,
            is_active: next.is_active,
            product_ids: next.product_ids,
            prices,
            confirm_prices: confirmPrices,
        };
    };

    // the facts the price rows compute from, for every picked product not yet known
    const [facts, setFacts] = useState<Record<string, PriceFact>>({});
    const pickedIds = draft?.product_ids ?? [];
    const missingFacts = pickedIds.filter((id) => !(String(id) in facts));
    useEffect(() => {
        if (missingFacts.length === 0) return;
        let alive = true;
        void fetch(`${base}/price-facts?ids=${missingFacts.join(',')}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((body: { data?: Record<string, PriceFact> } | null) => {
                if (alive && body?.data) setFacts((all) => ({ ...all, ...body.data }));
            })
            .catch(() => {});
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- the list of missing ids is the trigger
    }, [missingFacts.join(','), base]);

    // each picked product's row, judged live
    const priceRows =
        draft === null
            ? []
            : draft.product_ids.map((id) => {
                  const row = draft.prices[String(id)] ?? { mode: '' as PriceMode, value: '' };
                  const fact = facts[String(id)];
                  const result = campaignPriceOf(row, fact);
                  const value = Number(row.value);
                  const invalid =
                      row.mode !== '' &&
                      fact !== undefined &&
                      (row.value.trim() === '' || !Number.isFinite(value) || value <= 0 || (row.mode === 'percent' && value > max_percent) || result === null);
                  const belowCost = result !== null && fact?.purchase != null && result < fact.purchase;
                  const deep = row.mode === 'percent' && Number.isFinite(value) && value > confirm_percent;
                  return { id, row, fact, result, invalid, belowCost, deep };
              });
    const priceInvalid = priceRows.some((r) => r.invalid);
    const priceWarnings = priceRows.filter((r) => !r.invalid && r.result !== null && (r.belowCost || r.deep));
    const setPrice = (id: number, patch: Partial<PriceRow>) =>
        draft &&
        setDraft({ ...draft, prices: { ...draft.prices, [String(id)]: { ...(draft.prices[String(id)] ?? { mode: '', value: '' }), ...patch } } });

    const submit = (confirmPrices = false) => {
        if (draft === null) {
            return;
        }
        const done = { preserveScroll: true, onSuccess: () => setDraft(null) };
        draft.id === null
            ? router.post(base, payloadOf(draft, {}, confirmPrices), done)
            : router.put(`${base}/${draft.id}`, payloadOf(draft, {}, confirmPrices), done);
    };

    // the switch keeps the campaign's prices as they are (they were confirmed when they were saved)
    const toggle = (campaign: Campaign) =>
        router.put(`${base}/${campaign.id}`, payloadOf(campaign, { is_active: !campaign.is_active }, true), { preserveScroll: true });

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
                    : priceInvalid
                      ? t('campaigns.price_err_not_lower', 'يجب أن يكون أقل من السعر الحالي')
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

                            {draft.product_ids.length > 0 ? (
                                <div className="space-y-2 text-sm sm:col-span-2">
                                    <span className="font-medium">{t('campaigns.prices_heading', 'أسعار الحملة')}</span>
                                    <p className="max-w-3xl text-xs text-muted-foreground">
                                        {t(
                                            'campaigns.prices_hint',
                                            'اختياري لكل منتج: سعر للحملة، أو نسبة خصم إضافية على سعره الحالي. يسري في كل مكان من بداية الحملة إلى نهايتها، ثم يعود السعر العادي تلقائيًا. المنتج المتروك فارغًا يحتفظ بسعره العادي.',
                                        )}
                                    </p>
                                    {errors.confirm_prices ? <Alert tone="warning">{errors.confirm_prices}</Alert> : null}
                                    <div className="overflow-x-auto rounded-md border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>{t('campaigns.price_col_product', 'المنتج')}</TableHead>
                                                    <TableHead className="text-end">{t('campaigns.price_col_normal', 'السعر العادي')}</TableHead>
                                                    <TableHead className="text-end">{t('campaigns.price_col_current', 'السعر الحالي')}</TableHead>
                                                    <TableHead>{t('campaigns.price_col_how', 'خصم الحملة')}</TableHead>
                                                    <TableHead className="text-end">{t('campaigns.price_col_result', 'سعر الحملة')}</TableHead>
                                                    <TableHead className="text-end">{t('campaigns.price_col_off', 'الخصم من الأصلي')}</TableHead>
                                                    {can_see_cost ? <TableHead className="text-end">{t('campaigns.price_col_margin', 'الهامش')}</TableHead> : null}
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {priceRows.map(({ id, row, fact, result, invalid, belowCost, deep }) => {
                                                    const shown = result ?? fact?.current ?? null;
                                                    const off = fact && shown !== null && fact.selling > 0 ? Math.round(((fact.selling - shown) / fact.selling) * 100) : null;
                                                    const margin = fact?.purchase != null && shown !== null ? shown - fact.purchase : null;
                                                    const serverError = errors[`prices.${id}`];
                                                    return (
                                                        <TableRow key={id} className="align-top">
                                                            <TableCell className="max-w-64">
                                                                <div className="truncate font-medium" title={fact?.title}>
                                                                    {fact?.title ?? `#${id}`}
                                                                </div>
                                                                {fact?.code ? <Ltr className="text-xs text-muted-foreground">{fact.code}</Ltr> : null}
                                                            </TableCell>
                                                            <TableCell className="text-end tabular-nums">
                                                                {fact ? <Ltr>{money(fact.selling)}</Ltr> : <span className="text-muted-foreground">{t('campaigns.price_loading', 'جارٍ تحميل الأسعار…')}</span>}
                                                            </TableCell>
                                                            <TableCell className="text-end tabular-nums">{fact ? <Ltr>{money(fact.current)}</Ltr> : null}</TableCell>
                                                            <TableCell>
                                                                <div className="flex min-w-56 items-center gap-2">
                                                                    <Select
                                                                        aria-label={t('campaigns.price_col_how', 'خصم الحملة')}
                                                                        className="w-36"
                                                                        value={row.mode}
                                                                        onChange={(event) => setPrice(id, { mode: event.target.value as PriceMode })}
                                                                    >
                                                                        <option value="">{t('campaigns.price_mode_none', 'بدون تغيير')}</option>
                                                                        <option value="price">{t('campaigns.price_mode_price', 'سعر الحملة')}</option>
                                                                        <option value="percent">{t('campaigns.price_mode_percent', 'نسبة خصم إضافية')}</option>
                                                                    </Select>
                                                                    {row.mode !== '' ? (
                                                                        <Input
                                                                            aria-label={row.mode === 'price' ? t('campaigns.price_mode_price', 'سعر الحملة') : t('campaigns.price_mode_percent', 'نسبة خصم إضافية')}
                                                                            aria-invalid={invalid || serverError ? true : undefined}
                                                                            className="w-24"
                                                                            dir="ltr"
                                                                            inputMode="decimal"
                                                                            min={0}
                                                                            max={row.mode === 'percent' ? max_percent : undefined}
                                                                            step={row.mode === 'percent' ? 1 : 1}
                                                                            type="number"
                                                                            value={row.value}
                                                                            onChange={(event) => setPrice(id, { value: event.target.value })}
                                                                        />
                                                                    ) : null}
                                                                    {row.mode === 'percent' ? <span className="text-muted-foreground">%</span> : null}
                                                                </div>
                                                                {invalid ? <p className="mt-1 text-xs text-destructive">{t('campaigns.price_err_not_lower', 'يجب أن يكون أقل من السعر الحالي')}</p> : null}
                                                                {serverError ? <p className="mt-1 text-xs text-destructive">{serverError}</p> : null}
                                                                {belowCost ? <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{t('campaigns.price_warn_cost', 'أقل من سعر الشراء')}</p> : null}
                                                                {deep ? <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{t('campaigns.price_warn_percent', 'خصم إضافي أكثر من :pct%', { pct: confirm_percent })}</p> : null}
                                                            </TableCell>
                                                            <TableCell className="text-end font-medium tabular-nums">
                                                                {result !== null ? <Ltr>{money(result)}</Ltr> : <span className="text-muted-foreground">—</span>}
                                                            </TableCell>
                                                            <TableCell className="text-end tabular-nums">{off !== null && off > 0 ? <Ltr>{`−${off}%`}</Ltr> : '—'}</TableCell>
                                                            {can_see_cost ? (
                                                                <TableCell className={`text-end tabular-nums ${margin !== null && margin < 0 ? 'text-destructive' : ''}`}>
                                                                    {margin !== null ? (
                                                                        <Ltr>
                                                                            {money(margin)}
                                                                            {shown ? ` (${Math.round((margin / shown) * 100)}%)` : ''}
                                                                        </Ltr>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </TableCell>
                                                            ) : null}
                                                        </TableRow>
                                                    );
                                                })}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            ) : null}

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
                            {priceWarnings.length > 0 && blocked === null ? (
                                <ConfirmAction
                                    title={t('campaigns.price_confirm_title', 'حفظ أسعار الحملة هذه؟')}
                                    consequence={t(
                                        'campaigns.price_confirm_body',
                                        'هذه الأسعار أقل من سعر الشراء أو بخصم إضافي أكثر من :pct%: :list. ستسري من بداية الحملة إلى نهايتها.',
                                        { pct: confirm_percent, list: priceWarnings.map((r) => r.fact?.title ?? `#${r.id}`).join(t('campaigns.list_separator', '، ')) },
                                    )}
                                    confirmLabel={t('campaigns.price_confirm_save', 'تأكيد وحفظ')}
                                    trigger={<Button>{t('common.save', 'حفظ')}</Button>}
                                    onConfirm={() => submit(true)}
                                />
                            ) : (
                                <Button onClick={() => submit(false)} disabled={blocked !== null}>
                                    {t('common.save', 'حفظ')}
                                </Button>
                            )}
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
                                                        ? t('campaigns.sales_pending', 'يُحسب من آخر توريد لكل منتج — لا توريد مسجَّل بعد')
                                                        : t('campaigns.sales_none', 'لا توريد مسجَّل')}
                                                </span>
                                            ) : (
                                                <span>
                                                    {t('campaigns.sales_line', ':sold من :start وحدة منذ آخر توريد (:count منتج)', {
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
