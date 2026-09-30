import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import ProductPicker from '@/components/manage/ProductPicker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * The weekly "new picks for you" e-mail (2026-10-01).
 *
 * Each Monday at 10:00 the run is planned and the TEAM gets a preview; it goes out 24 hours later
 * unless it is paused here by then. While the audience is "team only" (the default), the run goes
 * to the team's addresses — the way to see what customers would get before any customer does.
 */

interface Run {
    id: number;
    week: string;
    audience: string;
    status: string;
    recipients: number;
    previewed_at: string | null;
    send_after: string | null;
    sent_at: string | null;
    manual: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    settings: { paused: boolean; audience: string; team_emails: string; team_effective: string[]; manual_product_ids: number[] };
    picks: { min: number; max: number };
    runs: Run[];
    optouts: number;
}

const TONE: Record<string, 'success' | 'warning' | 'neutral' | 'outline'> = {
    sent: 'success',
    previewed: 'warning',
    paused: 'neutral',
};

export default function ReEngagementIndex({ storefront, settings, picks, runs, optouts }: Props) {
    const t = useT();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [manual, setManual] = useState<number[]>(settings.manual_product_ids);
    const [paused, setPaused] = useState(settings.paused);
    const [audience, setAudience] = useState(settings.audience);
    const [teamEmails, setTeamEmails] = useState(settings.team_emails);
    const base = `/manage/storefronts/${storefront.id}/reengagement`;

    const save = (patch: { paused?: boolean } = {}) =>
        router.put(
            base,
            { paused: patch.paused ?? paused, audience, team_emails: teamEmails, manual_product_ids: manual },
            { preserveScroll: true, onSuccess: () => patch.paused !== undefined && setPaused(patch.paused) },
        );

    const statusLabel: Record<string, string> = {
        previewed: t('reengagement.status_previewed', 'بانتظار الإرسال'),
        sent: t('reengagement.status_sent', 'أُرسلت'),
        paused: t('reengagement.status_paused', 'أُوقفت'),
    };

    return (
        <ManageLayout
            title={t('reengagement.title', 'رسائل العودة')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('reengagement.title', 'رسائل العودة') }]}
        >
            <div className="space-y-6">
                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t(
                        'reengagement.intro',
                        'رسالة أسبوعية لعملاء غابوا 30 يوماً أو أكثر: حتى 6 منتجات متوفرة لم يتغير سعرها منذ 14 يوماً، لم تُرسل لهم من قبل. تُجهَّز كل إثنين 10:00 ويصل الفريق معاينة، وتُرسل بعدها بـ24 ساعة إلا إذا أوقفتها هنا.',
                    )}
                </p>

                <div className="rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge variant={paused ? 'neutral' : 'success'}>
                            {paused ? t('reengagement.paused', 'موقوفة') : t('reengagement.running', 'تعمل')}
                        </Badge>
                        <Button variant={paused ? 'default' : 'outline'} onClick={() => save({ paused: !paused })}>
                            {paused ? t('reengagement.resume', 'استئناف') : t('reengagement.pause', 'إيقاف مؤقت')}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            {t('reengagement.optouts', 'ألغى الاشتراك: :count', { count: optouts })}
                        </span>
                    </div>

                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <label className="space-y-1 text-sm">
                            <span>{t('reengagement.audience', 'المستلمون')}</span>
                            <Select value={audience} onChange={(event) => setAudience(event.target.value)}>
                                <option value="team">{t('reengagement.audience_team', 'الفريق فقط (للتجربة)')}</option>
                                <option value="customers">{t('reengagement.audience_customers', 'العملاء')}</option>
                            </Select>
                        </label>
                        <label className="space-y-1 text-sm sm:col-span-2">
                            <span>{t('reengagement.team_emails', 'بريد الفريق — تصله المعاينة (سطر لكل بريد)')}</span>
                            <textarea
                                dir="ltr"
                                rows={3}
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={teamEmails}
                                onChange={(event) => setTeamEmails(event.target.value)}
                            />
                            <span className="text-xs text-muted-foreground" dir="ltr">
                                {settings.team_effective.join(', ') || '—'}
                            </span>
                            {settings.team_effective.length === 0 ? (
                                <span className="block text-sm text-amber-700">
                                    {t(
                                        'reengagement.no_team',
                                        'لا يوجد بريد للمعاينة: لن تُجهَّز أي رسالة ولن تُرسل حتى تضيف بريداً واحداً على الأقل.',
                                    )}
                                </span>
                            ) : null}
                        </label>
                    </div>
                    {audience === 'customers' && settings.audience !== 'customers' ? (
                        <p className="mt-3 text-sm text-amber-700">
                            {t('reengagement.customers_warning', 'بعد الحفظ ستصل الرسالة القادمة إلى العملاء الحقيقيين.')}
                        </p>
                    ) : null}
                    <div className="mt-4 space-y-2 border-t pt-4">
                        <h2 className="font-medium">{t('reengagement.picks_title', 'منتجات الرسالة القادمة')}</h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'reengagement.picks_hint',
                                'اتركها فارغة لتختار الرسالة المنتجات تلقائياً. أو اختر من :min إلى :max منتجات بالترتيب: تُستخدم في الرسالة القادمة فقط (المتوفر والظاهر منها، بسعر ثابت منذ 14 يوماً)، ثم تعود الرسالة للاختيار التلقائي.',
                                { min: picks.min, max: picks.max },
                            )}
                        </p>
                        <ProductPicker
                            id="reengagement-picks"
                            searchUrl={`${base}/products`}
                            value={manual}
                            onChange={setManual}
                            max={picks.max}
                        />
                        {errors.manual_product_ids ? <p className="text-sm text-destructive">{errors.manual_product_ids}</p> : null}
                    </div>
                    <div className="mt-4">
                        <Button onClick={() => save()}>{t('common.save', 'حفظ')}</Button>
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <h2 className="border-b p-3 font-medium">{t('reengagement.runs', 'الأسابيع السابقة')}</h2>
                    {runs.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">{t('reengagement.no_runs', 'لم تُجهَّز أي رسالة بعد.')}</p>
                    ) : (
                        <ul className="divide-y">
                            {runs.map((run) => (
                                <li key={run.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
                                    <span className="w-24 font-medium tabular-nums" dir="ltr">
                                        {run.week}
                                    </span>
                                    <Badge variant={TONE[run.status] ?? 'outline'}>{statusLabel[run.status] ?? run.status}</Badge>
                                    <span>
                                        {run.audience === 'customers'
                                            ? t('reengagement.audience_customers', 'العملاء')
                                            : t('reengagement.audience_team', 'الفريق فقط (للتجربة)')}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {t('reengagement.recipients', ':count مستلم', { count: run.recipients })}
                                    </span>
                                    {run.manual ? <Badge variant="outline">{t('reengagement.manual_run', 'منتجات اختارها الفريق')}</Badge> : null}
                                    <span className="text-xs text-muted-foreground tabular-nums" dir="ltr">
                                        {run.sent_at ?? run.send_after ?? ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </ManageLayout>
    );
}
