import { router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { ConfirmAction } from '@/components/manage/ConfirmAction';
import ProductPicker from '@/components/manage/ProductPicker';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * The e-mail campaigns and the abandoned-cart e-mail (2026-11-24, plan items 1 and 5).
 *
 * The abandoned-cart card first (one switch, off until the team turns it on), then the campaigns, each made in six steps:
 * audience (ready filters that combine, or an uploaded list) with the count before sending, message, optional code,
 * preview, a test send to the signed-in staff address, and send now or schedule. Nothing reaches a customer before the
 * last step; the sending limits are said on the page.
 */

// a type, not an interface: Inertia's payload type needs the index signature a type alias has
type Audience = {
    accounts: boolean;
    guests: boolean;
    no_order_days: number | null;
    min_orders: number | null;
    min_spent: number | null;
    brand_ids: number[];
    category_ids: number[];
    never_ordered: boolean;
    this_shop: boolean;
    list_id: number | null;
};

interface Campaign {
    id: number;
    name: string;
    status: string;
    subject_ar: string;
    subject_en: string;
    body_ar: string;
    body_en: string;
    product_ids: number[];
    audience: Audience;
    with_code: boolean;
    code_percent: number | null;
    code_cap: number | null;
    code_days: number | null;
    scheduled_at: string | null;
    recipients: number;
    progress: { pending: number; queued: number; sent: number; skipped: number; failed: number };
}

interface Reminder {
    enabled: boolean;
    delay_hours: number;
    with_code: boolean;
    percent: number;
    cap: number | null;
    valid_days: number;
    totals: { sent: number; used: number; skipped: Record<string, number>; unsubscribed: number };
}

interface Props {
    storefront: { id: number; code: string; name: string };
    reminder: Reminder;
    campaigns: Campaign[];
    lists: { id: number; name: string; rows: number; consent_at: string }[];
    brands: { id: number; name: string }[];
    categories: { id: number; name: string; depth: number }[];
    limits: { money_rewards: boolean; daily_cap: number; reserve: number; per_run: number; max_rows: number };
}

interface Draft {
    name: string;
    subject_ar: string;
    subject_en: string;
    body_ar: string;
    body_en: string;
    product_ids: number[];
    audience: Audience;
    with_code: boolean;
    code_percent: string;
    code_cap: string;
    code_days: string;
}

const EMPTY_AUDIENCE: Audience = { accounts: true, guests: true, no_order_days: null, min_orders: null, min_spent: null, brand_ids: [], category_ids: [], never_ordered: false, this_shop: false, list_id: null };

const csrf = (): string => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const draftOf = (c: Campaign | null): Draft => ({
    name: c?.name ?? '',
    subject_ar: c?.subject_ar ?? '',
    subject_en: c?.subject_en ?? '',
    body_ar: c?.body_ar ?? '',
    body_en: c?.body_en ?? '',
    product_ids: c?.product_ids ?? [],
    audience: c?.audience ?? EMPTY_AUDIENCE,
    with_code: c?.with_code ?? false,
    code_percent: c?.code_percent ? String(c.code_percent) : '10',
    code_cap: c?.code_cap ? String(c.code_cap) : '500',
    code_days: c?.code_days ? String(c.code_days) : '7',
});

export default function MailCampaignsIndex({ storefront, reminder, campaigns, lists, brands, categories, limits }: Props) {
    const t = useT();
    const page = usePage<SharedProps & { errors?: Record<string, string> }>();
    const errors = page.props.errors ?? {};
    const base = `/manage/storefronts/${storefront.id}/mail-campaigns`;
    const perDay = Math.max(0, limits.daily_cap - limits.reserve);

    // ── the abandoned-cart card ─────────────────────────────────────────────────────────────
    const [r, setR] = useState({
        enabled: reminder.enabled,
        delay_hours: String(reminder.delay_hours),
        with_code: reminder.with_code,
        percent: String(reminder.percent),
        cap: reminder.cap === null ? '' : String(reminder.cap),
        valid_days: String(reminder.valid_days),
    });
    const saveReminder = () =>
        router.put(`${base}/reminder`, { ...r, cap: r.cap === '' ? null : r.cap }, { preserveScroll: true });

    // ── the campaign being made ─────────────────────────────────────────────────────────────
    const [editing, setEditing] = useState<Campaign | null | 'new'>(null);
    const current = editing === 'new' ? null : editing;
    const [d, setD] = useState<Draft>(draftOf(null));
    const [step, setStep] = useState(1);
    const [count, setCount] = useState<{ count: number; held: number; unsubscribed: number; days: number } | null>(null);
    const [counting, setCounting] = useState(false);
    const [when, setWhen] = useState<'now' | 'schedule'>('now');
    const [at, setAt] = useState('');
    const open = (c: Campaign | null) => {
        setEditing(c ?? 'new');
        setD(draftOf(c));
        setStep(1);
        setCount(null);
    };
    const a = d.audience;
    const setA = (patch: Partial<Audience>) => {
        setD({ ...d, audience: { ...a, ...patch } });
        setCount(null);
    };
    const runCount = async () => {
        setCounting(true);
        try {
            const res = await fetch(`${base}/count`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
                body: JSON.stringify({ audience: a }),
            });
            setCount(res.ok ? await res.json() : null);
        } finally {
            setCounting(false);
        }
    };
    const payload = () => ({ ...d, code_cap: d.code_cap === '' ? null : d.code_cap });
    const saveDraft = () =>
        current === null
            ? router.post(base, payload(), { preserveScroll: true })
            : router.put(`${base}/${current.id}`, payload(), { preserveScroll: true });

    // ── the list upload ─────────────────────────────────────────────────────────────────────
    const [file, setFile] = useState<File | null>(null);
    const [listName, setListName] = useState('');
    const [consent, setConsent] = useState(false);
    const [report, setReport] = useState<{ ok: boolean; valid?: number; read?: number; duplicates?: number; unsubscribed?: number; errors?: unknown; list_id?: number | null } | null>(null);
    const upload = async (save: boolean) => {
        if (file === null) return;
        const body = new FormData();
        body.append('file', file);
        body.append('name', listName);
        body.append('save', save ? '1' : '0');
        body.append('consent', consent ? '1' : '0');
        const res = await fetch(`${base}/upload`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, credentials: 'same-origin', body });
        const json = await res.json().catch(() => ({ ok: false }));
        setReport(json);
        if (save && res.ok && json.list_id) router.reload({ only: ['lists'] });
    };
    const rowErrors = useMemo(() => (Array.isArray(report?.errors) ? (report?.errors as { row: number; reason: string }[]) : []), [report]);
    const reason = (code: string) =>
        code === 'bad_email' ? t('mailcampaigns.err_email', 'بريد غير صحيح') : code === 'bad_phone' ? t('mailcampaigns.err_phone', 'هاتف مصري غير صحيح') : t('mailcampaigns.err_rows', 'أكثر من :max سطر', { max: limits.max_rows });

    const STATUS: Record<string, string> = {
        draft: t('mailcampaigns.status_draft', 'مسودة'),
        scheduled: t('mailcampaigns.status_scheduled', 'مجدولة'),
        sending: t('mailcampaigns.status_sending', 'قيد الإرسال'),
        sent: t('mailcampaigns.status_sent', 'أُرسلت'),
        cancelled: t('mailcampaigns.status_cancelled', 'أُوقفت'),
    };
    const steps = [
        t('mailcampaigns.step_audience', 'الجمهور'),
        t('mailcampaigns.step_message', 'الرسالة'),
        t('mailcampaigns.step_code', 'الكود'),
        t('mailcampaigns.step_preview', 'المعاينة'),
        t('mailcampaigns.step_test', 'تجربة إليّ'),
        t('mailcampaigns.step_send', 'الإرسال'),
    ];
    const editable = current === null || current.status === 'draft' || current.status === 'scheduled';

    return (
        <ManageLayout
            title={t('mailcampaigns.title', 'حملات البريد')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('mailcampaigns.title', 'حملات البريد') }]}
        >
            <div className="space-y-6">
                <Alert>
                    {t('mailcampaigns.limits', 'الإرسال من بريد المتجر نفسه، على دفعات: حتى :run رسالة كل 5 دقائق و:day رسالة في اليوم، ويبقى :reserve للطلبات. رسائل الطلبات لا تنتظر أبدًا.', { run: limits.per_run, day: perDay, reserve: limits.reserve })}
                </Alert>

                {/* ── the abandoned-cart e-mail ── */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('mailcampaigns.reminder_title', 'رسالة السلة المتروكة')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {t('mailcampaigns.reminder_intro', 'رسالة واحدة لكل سلة بها منتجات ولدينا بريد صاحبها (حساب، أو زائر كتب بريده عند الدفع)، بعد المدة المحددة. لا تُرسل إذا طلب منذ ذلك، أو فرغت السلة، أو وصله تذكير خلال 30 يومًا، أو ألغى الاشتراك.')}
                        </p>
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <input type="checkbox" checked={r.enabled} onChange={(e) => setR({ ...r, enabled: e.target.checked })} />
                            {t('mailcampaigns.reminder_on', 'تشغيل رسالة السلة المتروكة لهذا المتجر')}
                        </label>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="r-delay">{t('mailcampaigns.reminder_delay', 'بعد (ساعات)')}</Label>
                                <Input id="r-delay" type="number" min={1} max={336} className="w-28" dir="ltr" value={r.delay_hours} onChange={(e) => setR({ ...r, delay_hours: e.target.value })} />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={r.with_code} disabled={!limits.money_rewards} onChange={(e) => setR({ ...r, with_code: e.target.checked })} />
                                {t('mailcampaigns.with_code', 'مع كود خصم خاص بكل عميل')}
                            </label>
                            <div className="space-y-1">
                                <Label htmlFor="r-percent">{t('mailcampaigns.percent', 'النسبة ٪')}</Label>
                                <Input id="r-percent" type="number" min={1} max={90} className="w-24" dir="ltr" value={r.percent} disabled={!r.with_code} onChange={(e) => setR({ ...r, percent: e.target.value })} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="r-cap">{t('mailcampaigns.cap', 'حد أقصى (ج.م)')}</Label>
                                <Input id="r-cap" type="number" min={1} className="w-28" dir="ltr" value={r.cap} disabled={!r.with_code} onChange={(e) => setR({ ...r, cap: e.target.value })} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="r-days">{t('mailcampaigns.days', 'صلاحية الكود (أيام)')}</Label>
                                <Input id="r-days" type="number" min={1} max={60} className="w-24" dir="ltr" value={r.valid_days} disabled={!r.with_code} onChange={(e) => setR({ ...r, valid_days: e.target.value })} />
                            </div>
                        </div>
                        {!limits.money_rewards ? <p className="text-xs text-destructive">{t('wheel.money_off', 'الخصومات المالية مغلقة في هذا المتجر (المتاجر ← الخصومات المالية)، فلا يمكن منح هذه الجائزة. افتحها، أو اختر شحنًا مجانيًا أو هدية أو «حظًا أوفر».')}</p> : null}
                        {Object.entries(errors).filter(([k]) => ['enabled', 'delay_hours', 'percent', 'cap', 'valid_days', 'test'].includes(k)).map(([k, v]) => (
                            <p key={k} className="text-xs text-destructive">{v}</p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={saveReminder}>{t('common.save', 'حفظ')}</Button>
                            <Button type="button" variant="outline" onClick={() => router.post(`${base}/reminder/test`, {}, { preserveScroll: true })}>{t('mailcampaigns.test_me', 'أرسل تجربة إليّ')}</Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('mailcampaigns.reminder_totals', 'أُرسلت :sent، استُخدم :used كودًا، ألغى الاشتراك :unsubscribed.', { sent: reminder.totals.sent, used: reminder.totals.used, unsubscribed: reminder.totals.unsubscribed })}
                        </p>
                    </CardContent>
                </Card>

                {/* ── the campaigns ── */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <CardTitle>{t('mailcampaigns.campaigns', 'الحملات')}</CardTitle>
                        <Button type="button" onClick={() => open(null)}>{t('mailcampaigns.new', 'حملة جديدة')}</Button>
                    </CardHeader>
                    <CardContent>
                        {campaigns.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('mailcampaigns.none', 'لا توجد حملات بعد. ابدأ بحملة جديدة.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border">
                                {campaigns.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
                                        <span className="flex-1 font-medium">{c.name}</span>
                                        <Badge variant="outline">{STATUS[c.status] ?? c.status}</Badge>
                                        {c.status !== 'draft' ? (
                                            <span className="text-xs text-muted-foreground">
                                                {t('mailcampaigns.progress', 'أُرسلت :sent من :all، تنتظر :waiting، تُركت :skipped', { sent: c.progress.sent, all: c.recipients, waiting: c.progress.pending + c.progress.queued, skipped: c.progress.skipped })}
                                            </span>
                                        ) : null}
                                        <Button type="button" size="sm" variant="outline" onClick={() => open(c)}>{t('common.open', 'فتح')}</Button>
                                        {c.status === 'sending' || c.status === 'scheduled' ? (
                                            <ConfirmAction
                                                title={t('mailcampaigns.cancel_title', 'إيقاف الحملة؟')}
                                                consequence={t('mailcampaigns.cancel_consequence', 'لن يُرسل ما لم يُرسل بعد. ما أُرسل لا يُسترجع.')}
                                                confirmLabel={t('mailcampaigns.cancel', 'إيقاف')}
                                                trigger={<Button type="button" size="sm" variant="outline">{t('mailcampaigns.cancel', 'إيقاف')}</Button>}
                                                onConfirm={() => router.post(`${base}/${c.id}/cancel`, {}, { preserveScroll: true })}
                                            />
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {editing !== null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>{current === null ? t('mailcampaigns.new', 'حملة جديدة') : current.name}</CardTitle>
                            <ol className="mt-2 flex flex-wrap gap-2 text-xs">
                                {steps.map((label, i) => (
                                    <li key={label}>
                                        <button type="button" onClick={() => setStep(i + 1)} className={`rounded-full border px-3 py-1 ${step === i + 1 ? 'bg-primary text-primary-foreground' : ''}`}>
                                            {i + 1}. {label}
                                        </button>
                                    </li>
                                ))}
                            </ol>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {!editable ? <Alert>{t('mailcampaigns.locked', 'الحملة بدأت الإرسال؛ لا يمكن تعديلها. انسخها في حملة جديدة.')}</Alert> : null}
                            {Object.entries(errors).filter(([k]) => !['enabled', 'delay_hours', 'percent', 'cap', 'valid_days'].includes(k)).map(([k, v]) => (
                                <p key={k} className="text-sm text-destructive">{v}</p>
                            ))}

                            {step === 1 ? (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap gap-4 text-sm">
                                        <label className="flex items-center gap-2"><input type="checkbox" checked={a.list_id === null} onChange={(e) => setA({ list_id: e.target.checked ? null : lists[0]?.id ?? null })} />{t('mailcampaigns.by_filters', 'كل العناوين لدينا، بالفلاتر')}</label>
                                        <label className="flex items-center gap-2"><input type="checkbox" checked={a.list_id !== null} disabled={lists.length === 0} onChange={(e) => setA({ list_id: e.target.checked ? lists[0]?.id ?? null : null })} />{t('mailcampaigns.by_list', 'قائمة مرفوعة')}</label>
                                    </div>
                                    {a.list_id !== null ? (
                                        <Select aria-label={t('mailcampaigns.list', 'القائمة')} value={String(a.list_id)} onChange={(e) => setA({ list_id: Number(e.target.value) })}>
                                            {lists.map((l) => (
                                                <option key={l.id} value={l.id}>{l.name} ({l.rows})</option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={a.accounts} onChange={(e) => setA({ accounts: e.target.checked })} />{t('mailcampaigns.accounts', 'أصحاب الحسابات')}</label>
                                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={a.guests} onChange={(e) => setA({ guests: e.target.checked })} />{t('mailcampaigns.guests', 'الزوار الذين طلبوا أو كتبوا بريدهم عند الدفع')}</label>
                                            <div className="space-y-1">
                                                <Label>{t('mailcampaigns.no_order', 'لم يطلب منذ')}</Label>
                                                <Select value={a.no_order_days === null ? '' : String(a.no_order_days)} onChange={(e) => setA({ no_order_days: e.target.value === '' ? null : Number(e.target.value) })}>
                                                    <option value="">{t('mailcampaigns.any', 'بدون هذا الشرط')}</option>
                                                    {[30, 60, 90].map((n) => <option key={n} value={n}>{t('mailcampaigns.days_n', ':n يومًا', { n })}</option>)}
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label htmlFor="a-orders">{t('mailcampaigns.min_orders', 'عدد طلبات على الأقل')}</Label>
                                                <Input id="a-orders" type="number" min={1} dir="ltr" value={a.min_orders ?? ''} onChange={(e) => setA({ min_orders: e.target.value === '' ? null : Number(e.target.value) })} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label htmlFor="a-spent">{t('mailcampaigns.min_spent', 'أنفق على الأقل (ج.م)')}</Label>
                                                <Input id="a-spent" type="number" min={1} dir="ltr" value={a.min_spent ?? ''} onChange={(e) => setA({ min_spent: e.target.value === '' ? null : Number(e.target.value) })} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>{t('mailcampaigns.brands', 'اشترى من الماركات')}</Label>
                                                <Select multiple className="h-24" value={a.brand_ids.map(String)} onChange={(e) => setA({ brand_ids: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                                                    {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label>{t('mailcampaigns.categories', 'اشترى من الأقسام')}</Label>
                                                <Select multiple className="h-24" value={a.category_ids.map(String)} onChange={(e) => setA({ category_ids: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}>
                                                    {categories.map((c) => <option key={c.id} value={c.id}>{c.depth > 0 ? '— ' : ''}{c.name}</option>)}
                                                </Select>
                                            </div>
                                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={a.never_ordered} onChange={(e) => setA({ never_ordered: e.target.checked })} />{t('mailcampaigns.never_ordered', 'سجّل ولم يطلب أبدًا')}</label>
                                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={a.this_shop} onChange={(e) => setA({ this_shop: e.target.checked })} />{t('mailcampaigns.this_shop', 'عملاء هذا المتجر فقط (طلبوا منه)')}</label>
                                        </div>
                                    )}
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button type="button" variant="outline" disabled={counting} onClick={() => void runCount()}>{t('mailcampaigns.count', 'احسب العدد')}</Button>
                                        {count !== null ? (
                                            <span className="text-sm" role="status">
                                                {t('mailcampaigns.count_line', 'تصل إلى :count عنوانًا (استُبعد :unsubscribed ألغوا الاشتراك). مدة الإرسال: نحو :days يوم.', { count: count.count, unsubscribed: count.unsubscribed, days: count.days })}
                                            </span>
                                        ) : null}
                                    </div>

                                    <details className="rounded-md border p-3">
                                        <summary className="cursor-pointer text-sm font-medium">{t('mailcampaigns.upload_title', 'رفع قائمة من Excel')}</summary>
                                        <div className="mt-3 space-y-3 text-sm">
                                            <a className="underline" href={`${base}/template`}>{t('mailcampaigns.template', 'نزّل النموذج (البريد، الاسم، الهاتف اختياري)')}</a>
                                            <Input type="file" accept=".xlsx,.csv" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null); }} />
                                            <Input placeholder={t('mailcampaigns.list_name', 'اسم القائمة')} value={listName} onChange={(e) => setListName(e.target.value)} />
                                            <Button type="button" variant="outline" disabled={file === null} onClick={() => void upload(false)}>{t('mailcampaigns.check', 'افحص الملف')}</Button>
                                            {report !== null ? (
                                                <div className="space-y-1" role="status">
                                                    {report.ok || report.valid !== undefined ? (
                                                        <p>{t('mailcampaigns.report', ':valid سطرًا صالحًا من :read؛ مكرر :duplicates، ألغوا الاشتراك :unsubscribed، أخطاء :errors.', { valid: report.valid ?? 0, read: report.read ?? 0, duplicates: report.duplicates ?? 0, unsubscribed: report.unsubscribed ?? 0, errors: rowErrors.length })}</p>
                                                    ) : null}
                                                    {rowErrors.slice(0, 30).map((er) => <p key={er.row} className="text-xs text-destructive">{t('mailcampaigns.row', 'السطر :row: :reason', { row: er.row, reason: reason(er.reason) })}</p>)}
                                                    {!Array.isArray(report.errors) && report.errors ? Object.values(report.errors as Record<string, string[]>).flat().map((m) => <p key={m} className="text-xs text-destructive">{m}</p>) : null}
                                                </div>
                                            ) : null}
                                            <label className="flex items-center gap-2"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />{t('mailcampaigns.consent', 'هؤلاء الأشخاص وافقوا على تلقّي رسائلنا')}</label>
                                            <Button type="button" disabled={file === null || !consent} onClick={() => void upload(true)}>{t('mailcampaigns.save_list', 'احفظ الأسطر الصالحة كقائمة')}</Button>
                                        </div>
                                    </details>
                                </div>
                            ) : null}

                            {step === 2 ? (
                                <div className="grid gap-3">
                                    <Input placeholder={t('mailcampaigns.name', 'اسم الحملة (للفريق)')} value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} />
                                    <Input placeholder={t('mailcampaigns.subject_ar', 'العنوان بالعربية')} value={d.subject_ar} onChange={(e) => setD({ ...d, subject_ar: e.target.value })} />
                                    <Input dir="ltr" placeholder={t('mailcampaigns.subject_en', 'العنوان بالإنجليزية')} value={d.subject_en} onChange={(e) => setD({ ...d, subject_en: e.target.value })} />
                                    <textarea className="min-h-28 rounded-md border bg-background p-2 text-sm" placeholder={t('mailcampaigns.body_ar', 'النص بالعربية')} value={d.body_ar} onChange={(e) => setD({ ...d, body_ar: e.target.value })} />
                                    <textarea dir="ltr" className="min-h-28 rounded-md border bg-background p-2 text-sm" placeholder={t('mailcampaigns.body_en', 'النص بالإنجليزية')} value={d.body_en} onChange={(e) => setD({ ...d, body_en: e.target.value })} />
                                    <Label>{t('mailcampaigns.products', 'منتجات (اختياري، حتى 6)')}</Label>
                                    <ProductPicker id="campaign-products" searchUrl={`${base}/products`} value={d.product_ids} onChange={(ids) => setD({ ...d, product_ids: ids })} max={6} />
                                </div>
                            ) : null}

                            {step === 3 ? (
                                <div className="space-y-3 text-sm">
                                    <label className="flex items-center gap-2"><input type="checkbox" checked={d.with_code} disabled={!limits.money_rewards} onChange={(e) => setD({ ...d, with_code: e.target.checked })} />{t('mailcampaigns.with_code', 'مع كود خصم خاص بكل عميل')}</label>
                                    {!limits.money_rewards ? <p className="text-xs text-destructive">{t('wheel.money_off', 'الخصومات المالية مغلقة في هذا المتجر (المتاجر ← الخصومات المالية)، فلا يمكن منح هذه الجائزة. افتحها، أو اختر شحنًا مجانيًا أو هدية أو «حظًا أوفر».')}</p> : null}
                                    <div className="flex flex-wrap gap-3">
                                        <Input type="number" min={1} max={90} className="w-24" dir="ltr" aria-label={t('mailcampaigns.percent', 'النسبة ٪')} value={d.code_percent} disabled={!d.with_code} onChange={(e) => setD({ ...d, code_percent: e.target.value })} />
                                        <Input type="number" min={1} className="w-28" dir="ltr" aria-label={t('mailcampaigns.cap', 'حد أقصى (ج.م)')} value={d.code_cap} disabled={!d.with_code} onChange={(e) => setD({ ...d, code_cap: e.target.value })} />
                                        <Input type="number" min={1} max={60} className="w-24" dir="ltr" aria-label={t('mailcampaigns.days', 'صلاحية الكود (أيام)')} value={d.code_days} disabled={!d.with_code} onChange={(e) => setD({ ...d, code_days: e.target.value })} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">{t('mailcampaigns.code_rule', 'كود واحد لكل عنوان، لطلب واحد، لهذا البريد فقط. لا يُطبَّق على منتجات عليها عرض، ولا يُجمع مع عرض آخر — والرسالة تقول ذلك.')}</p>
                                </div>
                            ) : null}

                            {step === 4 ? (
                                current === null ? (
                                    <p className="text-sm text-muted-foreground">{t('mailcampaigns.save_first', 'احفظ المسودة أولًا لترى المعاينة.')}</p>
                                ) : (
                                    <iframe title={t('mailcampaigns.step_preview', 'المعاينة')} src={`${base}/${current.id}/preview`} className="h-[640px] w-full rounded-md border bg-white" />
                                )
                            ) : null}

                            {step === 5 ? (
                                <div className="space-y-2 text-sm">
                                    <p className="text-muted-foreground">{t('mailcampaigns.test_intro', 'تصل رسالة التجربة إلى بريد حسابك فقط، بكود تجريبي لا يعمل.')}</p>
                                    <Button type="button" variant="outline" disabled={current === null} onClick={() => current && router.post(`${base}/${current.id}/test`, {}, { preserveScroll: true })}>{t('mailcampaigns.test_me', 'أرسل تجربة إليّ')}</Button>
                                </div>
                            ) : null}

                            {step === 6 ? (
                                <div className="space-y-3 text-sm">
                                    {count !== null ? <p>{t('mailcampaigns.count_line', 'تصل إلى :count عنوانًا (استُبعد :unsubscribed ألغوا الاشتراك). مدة الإرسال: نحو :days يوم.', { count: count.count, unsubscribed: count.unsubscribed, days: count.days })}</p> : <p className="text-muted-foreground">{t('mailcampaigns.count_first', 'احسب العدد في الخطوة 1 قبل الإرسال.')}</p>}
                                    <div className="flex flex-wrap gap-4">
                                        <label className="flex items-center gap-2"><input type="radio" checked={when === 'now'} onChange={() => setWhen('now')} />{t('mailcampaigns.now', 'الآن')}</label>
                                        <label className="flex items-center gap-2"><input type="radio" checked={when === 'schedule'} onChange={() => setWhen('schedule')} />{t('mailcampaigns.schedule', 'في موعد')}</label>
                                        {when === 'schedule' ? <Input type="datetime-local" dir="ltr" className="w-56" value={at} onChange={(e) => setAt(e.target.value)} /> : null}
                                    </div>
                                    <ConfirmAction
                                        title={t('mailcampaigns.send_title', 'إرسال الحملة إلى العملاء؟')}
                                        consequence={t('mailcampaigns.send_consequence', 'تُرسل إلى :count عنوانًا على دفعات، ولا تُسترجع رسالة أُرسلت.', { count: count?.count ?? '…' })}
                                        confirmLabel={t('mailcampaigns.send', 'أرسل')}
                                        tone="default"
                                        disabled={current === null || !editable || count === null || (when === 'schedule' && at === '')}
                                        trigger={<Button type="button" disabled={current === null || !editable || count === null}>{t('mailcampaigns.send', 'أرسل')}</Button>}
                                        onConfirm={() => current && router.post(`${base}/${current.id}/send`, { when, scheduled_at: when === 'schedule' ? at : null }, { preserveScroll: true })}
                                    />
                                </div>
                            ) : null}

                            <div className="flex flex-wrap gap-2 border-t pt-3">
                                <Button type="button" variant="outline" disabled={step === 1} onClick={() => setStep(step - 1)}>{t('common.back', 'السابق')}</Button>
                                <Button type="button" variant="outline" disabled={step === 6} onClick={() => setStep(step + 1)}>{t('common.next', 'التالي')}</Button>
                                <Button type="button" disabled={!editable} onClick={saveDraft}>{t('mailcampaigns.save_draft', 'احفظ المسودة')}</Button>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </ManageLayout>
    );
}
