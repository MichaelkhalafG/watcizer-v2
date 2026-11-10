import { router, useForm } from '@inertiajs/react';
import { Plus, RotateCw, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Ltr } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * "Spin the wheel" — the dashboard page (2026-11-07; the prize editor since 2026-11-08; the client's prize types since
 * 2026-11-09).
 *
 * The team AUTHORS the wheel here: four to twelve segments, each with its name in both languages, its TYPE, its odds and
 * its colour, and the type's own terms — a percentage (value, cap, optionally one category and a minimum number of items
 * in it, off every eligible item or off the cheapest one), an amount, free shipping, "better luck" (no prize), "invite a
 * friend" (the percentage both get) or a gift (a list of products; one in stock is given; a list with nothing in stock is
 * not drawn and this screen says so); plus the code's validity in hours and an optional minimum order. The odds total is
 * shown live and the server refuses a save that is not exactly 100 (App\Domain\Wheel\WheelPrizeWriter — the rules live
 * there, this screen only shows them early). A saved change applies to NEW spins only.
 *
 * Below the editor: the round and its button, who spun (the phone MASKED — personal data), what they won, which ad
 * brought them, whether the code was used and on which order, the invites, with the totals in all, by source, by prize
 * and by round. The switch turns the wheel ON or OFF for this storefront.
 */

interface Spin {
    id: number;
    created_at: string;
    phone_hint: string;
    prize_label: { en: string; ar: string };
    prize_type: string;
    color: string;
    code: string | null;
    invite_code: string | null;
    campaign: string;
    utm_source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    locale: string;
    expires_at: string;
    state: 'active' | 'used' | 'expired' | 'none';
    order_id: number | null;
    order_number: string | null;
    order_total: string | null;
    discount: string | null;
}

interface Source {
    source: string;
    spins: number;
    used: number;
    discount_text: string;
    sales_text: string;
}

interface Round {
    campaign: string;
    round: number | null;
    spins: number;
    used: number;
    discount_text: string;
    sales_text: string;
}

interface PrizeRow {
    label: { en: string; ar: string };
    spins: number;
    used: number;
}

interface Prize {
    id?: number;
    label_en: string;
    label_ar: string;
    type: string;
    value: number | null;
    odds: number;
    color: string;
    cap: number | null;
    scope_category_id?: number | null;
    min_quantity?: number | null;
    apply_mode?: string;
    gift_product_ids?: number[];
    gift_available?: number[];
}

/** A row as the editor holds it: every number a string, so an emptied box stays empty while the operator types. */
interface Segment {
    label_en: string;
    label_ar: string;
    type: string;
    value: string;
    odds: string;
    color: string;
    cap: string;
    scope_category_id: string;
    min_quantity: string;
    apply_mode: string;
    gift_product_ids: number[];
}

interface Option {
    value: string;
    label: string;
}

interface Category {
    id: number;
    label: string;
    depth: number;
}

interface GiftBrief {
    title: string;
    in_stock: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    enabled: boolean;
    ttl_hours: number;
    min_order: number | null;
    campaign: string;
    round: number;
    round_started_at: string | null;
    by_round: Round[];
    prizes: Prize[];
    types: string[];
    modes: string[];
    categories: Category[];
    gift_products: Record<string, GiftBrief>;
    referrals: { placed: number; delivered: number; cancelled: number };
    limits: { min: number; max: number; max_hours: number };
    fresh: Prize;
    totals: { spins: number; used: number; active: number; expired: number; discount: string; sales: string };
    by_source: Source[];
    by_prize: PrizeRow[];
    spins: Spin[];
    limit: number;
    spin_url: string;
    now: string;
}

const STATE_TONE: Record<Spin['state'], 'success' | 'neutral' | 'outline'> = {
    used: 'success',
    active: 'neutral',
    expired: 'outline',
    none: 'outline',
};

/** `1,250.00` → `1,250` for the totals; the amounts are EGP. */
const egp = (text: string | null) => (text === null ? '—' : Number(text).toLocaleString('en-US', { maximumFractionDigits: 0 }));

const asText = (n: number | null | undefined) => (n === null || n === undefined ? '' : String(n));

const toSegment = (p: Prize): Segment => ({
    label_en: p.label_en,
    label_ar: p.label_ar,
    type: p.type,
    value: asText(p.value),
    odds: String(p.odds),
    color: p.color,
    cap: asText(p.cap),
    scope_category_id: asText(p.scope_category_id ?? null),
    min_quantity: asText(p.min_quantity ?? null),
    apply_mode: p.apply_mode ?? 'order',
    gift_product_ids: p.gift_product_ids ?? [],
});

interface SearchHit {
    id: number;
    title: string;
    wa_code: string | null;
    stock_express: number;
    stock_market: number;
    is_visible: boolean;
}

/** The gift list of one prize: the chosen products as chips (name, stock), a search box that adds one. */
function GiftPicker({ ids, briefs, storefrontId, onChange, labels }: { ids: number[]; briefs: Record<string, GiftBrief>; storefrontId: number; onChange: (ids: number[]) => void; labels: { search: string; add: string; remove: string } }) {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<SearchHit[]>([]);
    const [picked, setPicked] = useState<Record<number, GiftBrief>>({});
    const search = async (term: string) => {
        setQuery(term);
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        try {
            const response = await fetch(`/manage/promotions/products?q=${encodeURIComponent(term.trim())}&storefront_id=${storefrontId}`, { headers: { Accept: 'application/json' } });
            const body = (await response.json()) as { data?: SearchHit[] };
            setHits((body.data ?? []).filter((hit) => !ids.includes(hit.id)).slice(0, 8));
        } catch {
            setHits([]);
        }
    };
    const add = (hit: SearchHit) => {
        setPicked((all) => ({ ...all, [hit.id]: { title: hit.title, in_stock: hit.stock_express + hit.stock_market > 0 } }));
        onChange([...ids, hit.id]);
        setQuery('');
        setHits([]);
    };
    return (
        <div className="min-w-56 space-y-2">
            {ids.length > 0 ? (
                <ul className="flex flex-wrap gap-1">
                    {ids.map((id) => {
                        const brief = briefs[String(id)] ?? picked[id] ?? { title: `#${id}`, in_stock: true };
                        return (
                            <li key={id} className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${brief.in_stock ? 'border-emerald-500/40 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200' : 'border-destructive/40 bg-destructive/10 text-destructive'}`} title={brief.in_stock ? '' : 'out of stock'}>
                                <span className="max-w-40 truncate">{brief.title}</span>
                                <button aria-label={labels.remove} className="rounded-full p-0.5 hover:bg-black/10" type="button" onClick={() => onChange(ids.filter((x) => x !== id))}>
                                    <X className="size-3" />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
            <div className="relative">
                <Input aria-label={labels.search} className="min-w-56 text-xs" placeholder={labels.search} value={query} onChange={(e) => void search(e.target.value)} />
                {hits.length > 0 ? (
                    <ul className="absolute z-20 mt-1 max-h-56 w-72 overflow-auto rounded-md border bg-popover p-1 text-xs shadow-md">
                        {hits.map((hit) => (
                            <li key={hit.id}>
                                <button className="flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-start hover:bg-accent" type="button" onClick={() => add(hit)}>
                                    <span className="truncate">{hit.title}</span>
                                    <span className={`shrink-0 ${hit.stock_express + hit.stock_market > 0 ? 'text-muted-foreground' : 'text-destructive'}`}>
                                        {hit.stock_express + hit.stock_market} · {labels.add}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
        </div>
    );
}

export default function WheelIndex({ storefront, storefronts, enabled, ttl_hours, min_order, round, round_started_at, by_round, prizes, types, modes, categories, gift_products, referrals, limits, fresh, totals, by_source, by_prize, spins, limit, spin_url }: Props) {
    const t = useT();
    const base = `/manage/storefronts/${storefront.id}/wheel`;

    const STATE: Record<Spin['state'], string> = {
        active: t('wheel.state_active', 'ساري'),
        used: t('wheel.state_used', 'مستخدم'),
        expired: t('wheel.state_expired', 'منتهي'),
        none: t('wheel.state_none', 'بدون جائزة'),
    };
    const TYPE: Record<string, string> = {
        percent_discount: t('wheel.type_percent', 'خصم بالنسبة'),
        fixed_discount: t('wheel.type_fixed', 'خصم بالجنيه'),
        free_shipping: t('wheel.type_shipping', 'شحن مجاني'),
        none: t('wheel.type_none', 'حظًا أوفر (بدون جائزة)'),
        referral: t('wheel.type_referral', 'ادعُ صديقًا (نسبة لكما)'),
        gift: t('wheel.type_gift', 'هدية مجانية (من قائمة)'),
    };
    const MODE: Record<string, string> = {
        order: t('wheel.mode_order', 'على كل قطعة مؤهلة'),
        cheapest: t('wheel.mode_cheapest', 'على الأرخص فقط'),
    };

    const form = useForm<{ segments: Segment[]; ttl_hours: string; min_order: string }>({
        segments: prizes.map(toSegment),
        ttl_hours: String(ttl_hours),
        min_order: asText(min_order),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const oddsTotal = form.data.segments.reduce((sum, s) => sum + (Number.parseInt(s.odds, 10) || 0), 0);
    const count = form.data.segments.length;

    const setSegment = (i: number, patch: Partial<Segment>) =>
        form.setData(
            'segments',
            form.data.segments.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );
    const addSegment = () => count < limits.max && form.setData('segments', [...form.data.segments, toSegment(fresh)]);
    const removeSegment = (i: number) => count > limits.min && form.setData('segments', form.data.segments.filter((_, j) => j !== i));
    const save = () => form.put(`${base}/prizes`, { preserveScroll: true });

    const toggle = (next: boolean) => router.post(`${base}/switch`, { enabled: next }, { preserveScroll: true });

    // a new round: asked twice (the second time in plain words), never undone
    const [confirmRound, setConfirmRound] = useState(false);
    const [startingRound, setStartingRound] = useState(false);
    const startRound = () =>
        router.post(
            `${base}/round`,
            {},
            {
                preserveScroll: true,
                onStart: () => setStartingRound(true),
                onFinish: () => {
                    setStartingRound(false);
                    setConfirmRound(false);
                },
            },
        );

    const oddsOk = oddsTotal === 100;
    const countOk = count >= limits.min && count <= limits.max;
    const giftLabels = { search: t('wheel.gift_search', 'ابحث عن منتج بالاسم أو الكود…'), add: t('wheel.gift_add', 'إضافة'), remove: t('wheel.remove_segment', 'حذف') };

    return (
        <ManageLayout
            title={t('wheel.title', 'عجلة الحظ')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('wheel.title', 'عجلة الحظ') }]}
            actions={
                <label className="flex items-center gap-3 text-sm">
                    <Switch checked={enabled} onCheckedChange={toggle} aria-label={t('wheel.switch_aria', 'تشغيل العجلة')} />
                    <span>{enabled ? t('wheel.switch_on', 'العجلة تعمل') : t('wheel.switch_off', 'العجلة متوقفة')}</span>
                </label>
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/wheel`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t(
                            'wheel.intro',
                            'صفحة /spin: الزائر يكتب رقم موبايله، يدير العجلة مرة واحدة لكل جولة، وقد يربح كودًا يُستخدم مرة واحدة خلال :hours ساعة مع رقم الهاتف نفسه عند إتمام الطلب — أو لا يربح شيئًا. الجوائز ونسبها تُضبط هنا. عند إيقاف العجلة تعرض الصفحة أن العرض انتهى ويختفي الزر العائم، والأكواد الموجودة تعمل حتى انتهاء صلاحيتها.',
                            { hours: ttl_hours },
                        )}
                    </p>
                </div>

                {!enabled ? (
                    <Alert>{t('wheel.off_note', 'العجلة متوقفة الآن: /spin تعرض «انتهى هذا العرض» ولا يظهر الزر العائم في المتجر. الأكواد التي رُبحت من قبل تعمل حتى انتهاء صلاحيتها.')}</Alert>
                ) : null}

                {/* ── the round ────────────────────────────────────────────────────────────────── */}
                <div className="rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="font-medium">
                                {t('wheel.round_heading', 'الجولة :round', { round })}{' '}
                                <span className="text-sm font-normal text-muted-foreground">
                                    {round_started_at ? t('wheel.round_since', 'منذ :date', { date: round_started_at }) : t('wheel.round_first', 'الجولة الأولى')}
                                </span>
                            </h2>
                            <p className="mt-1 max-w-3xl text-xs text-muted-foreground">
                                {t('wheel.round_hint', 'كل رقم موبايل وكل جهاز يدير العجلة مرة واحدة في الجولة. بدء جولة جديدة يسمح للجميع بالإدارة مرة أخرى؛ الأكواد المربوحة في جولات سابقة تحتفظ بشروطها وصلاحيتها.')}
                            </p>
                        </div>
                        {confirmRound ? (
                            <div className="flex max-w-md flex-col gap-2 rounded-md border border-amber-500/40 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200" role="alertdialog" aria-live="assertive">
                                <p>{t('wheel.round_confirm', 'بدء الجولة :round؟ يمكن لكل رقم موبايل وكل جهاز إدارة العجلة مرة أخرى. الأكواد المربوحة من قبل تعمل حتى انتهاء صلاحيتها. لا يمكن التراجع.', { round: round + 1 })}</p>
                                <div className="flex gap-2">
                                    <Button disabled={startingRound} size="sm" type="button" onClick={startRound}>
                                        <RotateCw /> {t('wheel.round_start', 'بدء جولة جديدة')}
                                    </Button>
                                    <Button disabled={startingRound} size="sm" type="button" variant="outline" onClick={() => setConfirmRound(false)}>
                                        {t('wheel.cancel', 'إلغاء')}
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <Button size="sm" type="button" variant="outline" onClick={() => setConfirmRound(true)}>
                                <RotateCw /> {t('wheel.round_start', 'بدء جولة جديدة')}
                            </Button>
                        )}
                    </div>
                    {by_round.length > 0 ? (
                        <Table className="mt-3">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('wheel.col_round', 'الجولة')}</TableHead>
                                    <TableHead className="text-end">{t('wheel.totals_spins', 'مرات الإدارة')}</TableHead>
                                    <TableHead className="text-end">{t('wheel.totals_used', 'أكواد استُخدمت')}</TableHead>
                                    <TableHead className="text-end">{t('wheel.col_discount', 'الخصم')}</TableHead>
                                    <TableHead className="text-end">{t('wheel.col_sales', 'المبيعات')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {by_round.map((row) => (
                                    <TableRow key={row.campaign}>
                                        <TableCell>
                                            {row.round === null ? <Ltr>{row.campaign}</Ltr> : t('wheel.round_heading', 'الجولة :round', { round: row.round })}
                                            {row.round === round ? <Badge className="ms-2" variant="neutral">{t('wheel.state_active', 'ساري')}</Badge> : null}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">{row.spins}</TableCell>
                                        <TableCell className="text-end tabular-nums">{row.used}</TableCell>
                                        <TableCell className="text-end tabular-nums">{egp(row.discount_text)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{egp(row.sales_text)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : null}
                </div>

                {/* ── the editor ───────────────────────────────────────────────────────────────── */}
                <form
                    className="rounded-lg border bg-card"
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                >
                    <div className="flex flex-wrap items-start justify-between gap-2 border-b p-4">
                        <div>
                            <h2 className="font-medium">{t('wheel.editor_heading', 'الجوائز على العجلة')}</h2>
                            <p className="mt-1 max-w-3xl text-xs text-muted-foreground">
                                {t(
                                    'wheel.editor_hint',
                                    'بترتيب العجلة، مع اتجاه الساعة من المؤشر. مجموع النسب يجب أن يكون 100%. هدية قائمتها كلها غير متوفرة لا تُسحب وتتوزع نسبتها على الباقي. التغيير يسري على الإدارات الجديدة فقط؛ الأكواد المربوحة من قبل تحتفظ بشروطها.',
                                )}
                            </p>
                        </div>
                        <div className={`rounded-md border px-3 py-2 text-sm tabular-nums ${oddsOk ? 'border-emerald-500/40 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200' : 'border-destructive/40 bg-destructive/10 text-destructive'}`} aria-live="polite">
                            {t('wheel.odds_live', 'مجموع النسب')}: <strong>{oddsTotal}%</strong>
                            {oddsOk ? null : <span className="ms-2 text-xs">{t('wheel.odds_total', 'مجموع النسب :total%. يجب أن يكون 100% بالضبط.', { total: oddsTotal })}</span>}
                        </div>
                    </div>

                    {errors.segments ? (
                        <div className="px-4 pt-3">
                            <Alert tone="error">{errors.segments}</Alert>
                        </div>
                    ) : null}
                    {errors.odds_total ? (
                        <div className="px-4 pt-3">
                            <Alert tone="error">{errors.odds_total}</Alert>
                        </div>
                    ) : null}

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8">#</TableHead>
                                <TableHead>{t('wheel.col_label_ar', 'الاسم (عربي)')}</TableHead>
                                <TableHead>{t('wheel.col_label_en', 'الاسم (إنجليزي)')}</TableHead>
                                <TableHead>{t('wheel.col_type', 'النوع')}</TableHead>
                                <TableHead>{t('wheel.col_value', 'القيمة')}</TableHead>
                                <TableHead>{t('wheel.col_cap', 'الحد الأقصى (ج.م)')}</TableHead>
                                <TableHead>{t('wheel.col_scope', 'التصنيف')}</TableHead>
                                <TableHead>{t('wheel.col_odds', 'النسبة %')}</TableHead>
                                <TableHead>{t('wheel.col_color', 'اللون')}</TableHead>
                                <TableHead className="text-end">{t('wheel.totals_spins', 'مرات الإدارة')}</TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {form.data.segments.map((segment, i) => {
                                const err = (key: string) => errors[`segments.${i}.${key}`];
                                const won = by_prize.find((row) => row.label.en === segment.label_en);
                                const isPercent = segment.type === 'percent_discount';
                                const isReferral = segment.type === 'referral';
                                const isGift = segment.type === 'gift';
                                const hasValue = isPercent || isReferral || segment.type === 'fixed_discount';
                                const saved = prizes[i];
                                const giftInStock = isGift && saved && saved.type === 'gift' ? (saved.gift_available ?? []).length : null;
                                return (
                                    <TableRow key={i} className="align-top">
                                        <TableCell className="pt-4 tabular-nums text-muted-foreground">{i + 1}</TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={t('wheel.col_label_ar', 'الاسم (عربي)')}
                                                aria-invalid={err('label_ar') ? true : undefined}
                                                className="min-w-32"
                                                dir="rtl"
                                                maxLength={80}
                                                value={segment.label_ar}
                                                onChange={(e) => setSegment(i, { label_ar: e.target.value })}
                                            />
                                            {err('label_ar') ? <p className="mt-1 text-xs text-destructive">{err('label_ar')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={t('wheel.col_label_en', 'الاسم (إنجليزي)')}
                                                aria-invalid={err('label_en') ? true : undefined}
                                                className="min-w-32"
                                                dir="ltr"
                                                maxLength={80}
                                                value={segment.label_en}
                                                onChange={(e) => setSegment(i, { label_en: e.target.value })}
                                            />
                                            {err('label_en') ? <p className="mt-1 text-xs text-destructive">{err('label_en')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                aria-label={t('wheel.col_type', 'النوع')}
                                                aria-invalid={err('type') ? true : undefined}
                                                className="min-w-40"
                                                value={segment.type}
                                                onChange={(e) => {
                                                    const type = e.target.value;
                                                    const keepsValue = type === 'percent_discount' || type === 'referral' || type === 'fixed_discount';
                                                    setSegment(i, {
                                                        type,
                                                        value: keepsValue ? segment.value : '',
                                                        cap: type === 'percent_discount' || type === 'referral' ? segment.cap : '',
                                                        scope_category_id: type === 'percent_discount' ? segment.scope_category_id : '',
                                                        min_quantity: type === 'percent_discount' ? segment.min_quantity : '',
                                                        apply_mode: type === 'percent_discount' ? segment.apply_mode : 'order',
                                                        gift_product_ids: type === 'gift' ? segment.gift_product_ids : [],
                                                    });
                                                }}
                                            >
                                                {types.map((type) => (
                                                    <option key={type} value={type}>
                                                        {TYPE[type] ?? type}
                                                    </option>
                                                ))}
                                            </Select>
                                            {err('type') ? <p className="mt-1 text-xs text-destructive">{err('type')}</p> : null}
                                            {segment.type === 'none' ? <p className="mt-1 max-w-44 text-xs text-muted-foreground">{t('wheel.none_hint', 'بدون كود وبدون جائزة. الرقم والجهاز أدارا العجلة لهذه الجولة.')}</p> : null}
                                            {isReferral ? <p className="mt-1 max-w-56 text-xs text-muted-foreground">{t('wheel.referral_hint', 'الفائز يحصل على كود دعوة (صالح 7 أيام). أول طلب لصديق به يأخذ هذه النسبة؛ وعند توصيله يحصل الفائز على النسبة نفسها كودًا خاصًا به (48 ساعة).')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            {hasValue ? (
                                                <>
                                                    <Input
                                                        aria-label={t('wheel.col_value', 'القيمة')}
                                                        aria-invalid={err('value') ? true : undefined}
                                                        className="w-24"
                                                        dir="ltr"
                                                        inputMode="decimal"
                                                        min={isPercent || isReferral ? 1 : 0.01}
                                                        max={isPercent || isReferral ? 100 : undefined}
                                                        step={isPercent || isReferral ? 1 : 0.01}
                                                        type="number"
                                                        value={segment.value}
                                                        onChange={(e) => setSegment(i, { value: e.target.value })}
                                                    />
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {isPercent || isReferral ? t('wheel.value_hint_percent', 'النسبة المئوية') : t('wheel.value_hint_fixed', 'جنيهات تُخصم من الطلب')}
                                                    </p>
                                                </>
                                            ) : (
                                                <p className="pt-2 text-xs text-muted-foreground">
                                                    {segment.type === 'free_shipping' ? t('wheel.value_hint_shipping', 'بدون قيمة: التوصيل مجاني') : '—'}
                                                </p>
                                            )}
                                            {err('value') ? <p className="mt-1 text-xs text-destructive">{err('value')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            {isPercent || isReferral ? (
                                                <>
                                                    <Input
                                                        aria-label={t('wheel.col_cap', 'الحد الأقصى (ج.م)')}
                                                        aria-invalid={err('cap') ? true : undefined}
                                                        className="w-28"
                                                        dir="ltr"
                                                        inputMode="decimal"
                                                        min={1}
                                                        step={1}
                                                        type="number"
                                                        value={segment.cap}
                                                        onChange={(e) => setSegment(i, { cap: e.target.value })}
                                                    />
                                                    <p className="mt-1 text-xs text-muted-foreground">{t('wheel.cap_hint', 'للجوائز بالنسبة فقط')}</p>
                                                </>
                                            ) : (
                                                <span className="block pt-2 text-muted-foreground">—</span>
                                            )}
                                            {err('cap') ? <p className="mt-1 text-xs text-destructive">{err('cap')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            {isPercent ? (
                                                <div className="space-y-2">
                                                    <Select
                                                        aria-label={t('wheel.col_scope', 'التصنيف')}
                                                        aria-invalid={err('scope_category_id') ? true : undefined}
                                                        className="min-w-48"
                                                        value={segment.scope_category_id}
                                                        onChange={(e) => setSegment(i, { scope_category_id: e.target.value, min_quantity: e.target.value === '' ? '' : segment.min_quantity, apply_mode: e.target.value === '' ? 'order' : segment.apply_mode })}
                                                    >
                                                        <option value="">{t('wheel.scope_any', 'أي منتج')}</option>
                                                        {categories.map((c) => (
                                                            <option key={c.id} value={String(c.id)}>
                                                                {c.label}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                    {segment.scope_category_id !== '' ? (
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Input
                                                                aria-label={t('wheel.col_min_quantity', 'الحد الأدنى للقطع')}
                                                                aria-invalid={err('min_quantity') ? true : undefined}
                                                                className="w-16"
                                                                dir="ltr"
                                                                inputMode="numeric"
                                                                max={20}
                                                                min={1}
                                                                placeholder="—"
                                                                step={1}
                                                                title={t('wheel.col_min_quantity', 'الحد الأدنى للقطع')}
                                                                type="number"
                                                                value={segment.min_quantity}
                                                                onChange={(e) => setSegment(i, { min_quantity: e.target.value })}
                                                            />
                                                            <Select aria-label={t('wheel.mode_order', 'على كل قطعة مؤهلة')} aria-invalid={err('apply_mode') ? true : undefined} className="min-w-36" value={segment.apply_mode} onChange={(e) => setSegment(i, { apply_mode: e.target.value })}>
                                                                {modes.map((mode) => (
                                                                    <option key={mode} value={mode}>
                                                                        {MODE[mode] ?? mode}
                                                                    </option>
                                                                ))}
                                                            </Select>
                                                        </div>
                                                    ) : null}
                                                    {segment.apply_mode === 'cheapest' ? <p className="max-w-56 text-xs text-muted-foreground">{t('wheel.cheapest_hint', 'مع تصنيف وحد أدنى 2: النسبة تُخصم من قطعة واحدة — الأرخص المؤهلة.')}</p> : null}
                                                    {err('scope_category_id') ? <p className="text-xs text-destructive">{err('scope_category_id')}</p> : null}
                                                    {err('min_quantity') ? <p className="text-xs text-destructive">{err('min_quantity')}</p> : null}
                                                    {err('apply_mode') ? <p className="text-xs text-destructive">{err('apply_mode')}</p> : null}
                                                </div>
                                            ) : isGift ? (
                                                <div className="space-y-1">
                                                    <GiftPicker ids={segment.gift_product_ids} briefs={gift_products} storefrontId={storefront.id} labels={giftLabels} onChange={(ids) => setSegment(i, { gift_product_ids: ids })} />
                                                    {giftInStock !== null ? (
                                                        giftInStock > 0 ? (
                                                            <p className="text-xs text-emerald-700 dark:text-emerald-300">{t('wheel.gift_in_stock', ':n من :total متوفر', { n: giftInStock, total: (saved?.gift_product_ids ?? []).length })}</p>
                                                        ) : (
                                                            <p className="max-w-56 text-xs text-destructive">{t('wheel.gift_none_in_stock', 'لا شيء متوفر — هذه الجائزة لا تُسحب؛ نسبتها تذهب للباقي')}</p>
                                                        )
                                                    ) : null}
                                                    {err('gift_product_ids') ? <p className="text-xs text-destructive">{err('gift_product_ids')}</p> : null}
                                                </div>
                                            ) : (
                                                <span className="block pt-2 text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={t('wheel.col_odds', 'النسبة %')}
                                                aria-invalid={err('odds') ? true : undefined}
                                                className="w-20"
                                                dir="ltr"
                                                inputMode="numeric"
                                                min={1}
                                                max={100}
                                                step={1}
                                                type="number"
                                                value={segment.odds}
                                                onChange={(e) => setSegment(i, { odds: e.target.value })}
                                            />
                                            {err('odds') ? <p className="mt-1 text-xs text-destructive">{err('odds')}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <input
                                                    aria-label={t('wheel.col_color', 'اللون')}
                                                    className="h-10 w-10 cursor-pointer rounded-md border border-input bg-background p-1"
                                                    type="color"
                                                    value={/^#[0-9a-fA-F]{6}$/.test(segment.color) ? segment.color : '#000000'}
                                                    onChange={(e) => setSegment(i, { color: e.target.value.toUpperCase() })}
                                                />
                                                <Input
                                                    aria-label={t('wheel.col_color', 'اللون')}
                                                    aria-invalid={err('color') ? true : undefined}
                                                    className="w-24 font-mono text-xs uppercase"
                                                    dir="ltr"
                                                    maxLength={7}
                                                    value={segment.color}
                                                    onChange={(e) => setSegment(i, { color: e.target.value.toUpperCase() })}
                                                />
                                            </div>
                                            {err('color') ? <p className="mt-1 text-xs text-destructive">{err('color')}</p> : null}
                                        </TableCell>
                                        <TableCell className="pt-4 text-end tabular-nums">
                                            {won ? (
                                                <span title={`${t('wheel.totals_used', 'أكواد استُخدمت')}: ${won.used}`}>
                                                    {won.spins} <span className="text-muted-foreground">/ {won.used}</span>
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                aria-label={t('wheel.remove_segment', 'حذف')}
                                                disabled={count <= limits.min}
                                                size="icon"
                                                title={count <= limits.min ? t('wheel.segments_count', 'العجلة تحتاج من :min إلى :max جائزة.', { min: limits.min, max: limits.max }) : t('wheel.remove_segment', 'حذف')}
                                                type="button"
                                                variant="ghost"
                                                onClick={() => removeSegment(i)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>

                    <div className="flex flex-wrap items-end justify-between gap-4 border-t p-4">
                        <div className="flex flex-wrap items-end gap-4">
                            <Button disabled={count >= limits.max} size="sm" type="button" variant="outline" onClick={addSegment}>
                                <Plus /> {t('wheel.add_segment', 'إضافة جائزة')}
                            </Button>
                            <label className="block text-sm">
                                <span className="mb-1 block text-xs text-muted-foreground">{t('wheel.ttl_hours', 'صلاحية الكود (ساعات)')}</span>
                                <Input
                                    aria-invalid={errors.ttl_hours ? true : undefined}
                                    className="w-28"
                                    dir="ltr"
                                    inputMode="numeric"
                                    max={limits.max_hours}
                                    min={1}
                                    step={1}
                                    type="number"
                                    value={form.data.ttl_hours}
                                    onChange={(e) => form.setData('ttl_hours', e.target.value)}
                                />
                                {errors.ttl_hours ? <p className="mt-1 text-xs text-destructive">{errors.ttl_hours}</p> : null}
                            </label>
                            <label className="block text-sm">
                                <span className="mb-1 block text-xs text-muted-foreground">{t('wheel.min_order', 'الحد الأدنى للطلب (ج.م، فارغ = بدون)')}</span>
                                <Input
                                    aria-invalid={errors.min_order ? true : undefined}
                                    className="w-36"
                                    dir="ltr"
                                    inputMode="decimal"
                                    min={0}
                                    step={1}
                                    type="number"
                                    value={form.data.min_order}
                                    onChange={(e) => form.setData('min_order', e.target.value)}
                                />
                                {errors.min_order ? <p className="mt-1 text-xs text-destructive">{errors.min_order}</p> : null}
                            </label>
                        </div>
                        <div className="flex items-center gap-3">
                            {form.isDirty ? <span className="text-xs text-muted-foreground">{t('wheel.unsaved', 'تغييرات غير محفوظة')}</span> : null}
                            {!oddsOk || !countOk ? <span className="text-xs text-destructive">{t('wheel.need_fix', 'صحّح الحقول المعلَّمة قبل الحفظ.')}</span> : null}
                            <Button disabled={form.processing || !oddsOk || !countOk} type="submit">
                                {t('wheel.save_prizes', 'حفظ الجوائز')}
                            </Button>
                        </div>
                    </div>
                </form>

                {/* ── the numbers ──────────────────────────────────────────────────────────────── */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        [t('wheel.totals_spins', 'مرات الإدارة'), String(totals.spins)],
                        [t('wheel.totals_used', 'أكواد استُخدمت'), String(totals.used)],
                        [t('wheel.totals_discount', 'خصم مُنح (ج.م)'), egp(totals.discount)],
                        [t('wheel.totals_sales', 'مبيعات بأكواد العجلة (ج.م)'), egp(totals.sales)],
                        [t('wheel.col_invite', 'الدعوات'), `${referrals.placed + referrals.delivered} ${t('wheel.friends_placed', 'صديقًا طلب')} · ${referrals.delivered} ${t('wheel.friends_delivered', 'وصل')}`],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border bg-card p-4">
                            <div className="text-xs text-muted-foreground">{label}</div>
                            <div className="mt-1 text-2xl font-semibold tabular-nums">
                                <Ltr>{value}</Ltr>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">{t('wheel.by_source', 'حسب المصدر (utm_source)')}</h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('wheel.by_source_hint', 'رابط الإعلان يحمل utm_source وutm_campaign؛ يُسجَّل مع كل إدارة، فتعرف أي إعلان جاء بها.')}
                        </p>
                        {by_source.length === 0 ? (
                            <p className="mt-3 text-sm text-muted-foreground">{t('wheel.empty', 'لا توجد إدارات بعد.')}</p>
                        ) : (
                            <Table className="mt-3">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('wheel.source', 'المصدر')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.totals_spins', 'مرات الإدارة')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.totals_used', 'أكواد استُخدمت')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.col_discount', 'الخصم')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.col_sales', 'المبيعات')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {by_source.map((row) => (
                                        <TableRow key={row.source}>
                                            <TableCell>{row.source === '' ? t('wheel.source_direct', 'بدون مصدر (مباشر)') : <Ltr>{row.source}</Ltr>}</TableCell>
                                            <TableCell className="text-end tabular-nums">{row.spins}</TableCell>
                                            <TableCell className="text-end tabular-nums">{row.used}</TableCell>
                                            <TableCell className="text-end tabular-nums">{egp(row.discount_text)}</TableCell>
                                            <TableCell className="text-end tabular-nums">{egp(row.sales_text)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </div>

                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">{t('wheel.prizes_heading', 'ما رُبح، حسب الجائزة')}</h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('wheel.prizes_hint', 'كل إدارة تحفظ شروط جائزتها كما كانت لحظة الربح؛ لذلك تظهر هنا جوائز قديمة أيضًا.')}
                        </p>
                        {by_prize.length === 0 ? (
                            <p className="mt-3 text-sm text-muted-foreground">{t('wheel.empty', 'لا توجد إدارات بعد.')}</p>
                        ) : (
                            <Table className="mt-3">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('wheel.col_prize', 'الجائزة')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.totals_spins', 'مرات الإدارة')}</TableHead>
                                        <TableHead className="text-end">{t('wheel.totals_used', 'أكواد استُخدمت')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {by_prize.map((row) => (
                                        <TableRow key={row.label.en}>
                                            <TableCell>
                                                {row.label.ar} <span className="text-muted-foreground">({row.label.en})</span>
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums">{row.spins}</TableCell>
                                            <TableCell className="text-end tabular-nums">{row.used}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t('wheel.link', 'رابط الصفحة للإعلانات')}: <Ltr className="select-all">{spin_url}</Ltr>
                        </p>
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                        <h2 className="font-medium">{t('wheel.spins_heading', 'آخر الإدارات')}</h2>
                        <p className="text-xs text-muted-foreground">
                            {t('wheel.privacy_note', 'أرقام الهواتف مخفية جزئيًا: بيانات شخصية. لا تصدير. تُعرض آخر :limit إدارة.', { limit })}
                        </p>
                    </div>
                    {spins.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">{t('wheel.empty', 'لا توجد إدارات بعد.')}</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('wheel.col_when', 'الوقت')}</TableHead>
                                    <TableHead>{t('wheel.col_phone', 'الهاتف')}</TableHead>
                                    <TableHead>{t('wheel.col_prize', 'الجائزة')}</TableHead>
                                    <TableHead>{t('wheel.col_code', 'الكود')}</TableHead>
                                    <TableHead>{t('wheel.col_source', 'المصدر / الحملة')}</TableHead>
                                    <TableHead>{t('wheel.col_expires', 'ينتهي')}</TableHead>
                                    <TableHead>{t('wheel.col_state', 'الحالة')}</TableHead>
                                    <TableHead>{t('wheel.col_order', 'الطلب')}</TableHead>
                                    <TableHead className="text-end">{t('wheel.col_discount', 'الخصم')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {spins.map((spin) => (
                                    <TableRow key={spin.id}>
                                        <TableCell className="whitespace-nowrap">
                                            <Ltr>{spin.created_at}</Ltr>
                                        </TableCell>
                                        <TableCell>
                                            <Ltr>{spin.phone_hint}</Ltr>
                                        </TableCell>
                                        <TableCell>
                                            <span className="me-1.5 inline-block size-2.5 rounded-full align-middle" style={{ background: spin.color }} aria-hidden="true" />
                                            {spin.prize_label.ar}
                                            <span className="ms-1 text-xs text-muted-foreground">({TYPE[spin.prize_type] ?? spin.prize_type})</span>
                                        </TableCell>
                                        <TableCell>
                                            {spin.code ? <Ltr className="font-mono text-xs">{spin.code}</Ltr> : null}
                                            {spin.invite_code ? (
                                                <Ltr className="font-mono text-xs">
                                                    {spin.invite_code}
                                                </Ltr>
                                            ) : null}
                                            {!spin.code && !spin.invite_code ? <span className="text-muted-foreground">—</span> : null}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {spin.utm_source ? (
                                                <Ltr>
                                                    {spin.utm_source}
                                                    {spin.utm_campaign ? ` / ${spin.utm_campaign}` : ''}
                                                </Ltr>
                                            ) : (
                                                <span className="text-muted-foreground">{t('wheel.source_direct', 'بدون مصدر (مباشر)')}</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <Ltr>{spin.expires_at}</Ltr>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={STATE_TONE[spin.state]}>{STATE[spin.state]}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            {spin.order_id !== null ? (
                                                <a className="underline" href={`/manage/orders/${spin.order_id}`}>
                                                    <Ltr>{spin.order_number ?? `#${spin.order_id}`}</Ltr>
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">{spin.discount === null ? '—' : egp(spin.discount)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </div>
        </ManageLayout>
    );
}
