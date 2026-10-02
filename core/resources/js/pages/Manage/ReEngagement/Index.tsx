import { router, usePage } from '@inertiajs/react';
import { Pause, Play, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';

import { useDirtyGuard } from '@/components/form/useDirtyGuard';
import { ConfirmAction } from '@/components/manage/ConfirmAction';
import EmailChips from '@/components/manage/EmailChips';
import ProductPicker from '@/components/manage/ProductPicker';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * The weekly "new picks for you" e-mail (2026-10-01; reworked the same day, developer-approved).
 *
 * Each Monday at 10:00 the run is planned and the TEAM gets a preview; it goes out 24 hours later
 * unless it is paused by then. While the audience is "team only" (the default), the run goes to the
 * team's addresses — the way to see what customers would get before any customer does.
 *
 * The rework, point by point: the state in plain words at the top; a three-line explanation;
 * Pause/Resume is its own confirmed action and no longer posts the form; the audience choice spells
 * out who it reaches; the team's addresses are chips, each checked; the picker shows picture, name,
 * code and price and reorders by dragging; every refusal is shown beside its field with a summary,
 * and the result of an action is shown beside the button that was pressed — not only at the top of
 * a page that is scrolled away from it; the past weeks read as sentences.
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
    settings: { paused: boolean; audience: string; team_emails: string[]; manual_product_ids: number[] };
    picks: { min: number; max: number };
    state: { week: string; next_plan_at: string; customers_estimate: number };
    runs: Run[];
    optouts: number;
}

type Where = 'save' | 'pause' | 'replan';

/** 'YYYY-MM-DD HH:MM:SS' in the shop's time zone, shown as written — no browser time-zone shift. */
function parseStamp(stamp: string): Date {
    const [d, tm = '00:00:00'] = stamp.split(' ');
    const [y, mo, da] = d.split('-').map(Number);
    const [h, mi] = tm.split(':').map(Number);
    return new Date(Date.UTC(y, mo - 1, da, h, mi));
}

/** Monday and Sunday of an ISO week ('2026-W40'). */
function weekRange(week: string): [Date, Date] {
    const [y, w] = week.split('-W').map(Number);
    const jan4 = new Date(Date.UTC(y, 0, 4));
    const monday = new Date(jan4.getTime() - ((jan4.getUTCDay() + 6) % 7) * 86400000 + (w - 1) * 7 * 86400000);
    return [monday, new Date(monday.getTime() + 6 * 86400000)];
}

export default function ReEngagementIndex({ storefront, settings, picks, state, runs, optouts }: Props) {
    const t = useT();
    const page = usePage<SharedProps & { errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};
    const locale = page.props.locale === 'en' ? 'en-GB' : 'ar-EG-u-nu-latn';
    const fmt = useMemo(() => {
        const when = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' });
        const day = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' });
        return { when: (s: string) => when.format(parseStamp(s)), day: (d: Date) => day.format(d) };
    }, [locale]);

    const [audience, setAudience] = useState(settings.audience);
    const [teamEmails, setTeamEmails] = useState<string[]>(settings.team_emails);
    const [manual, setManual] = useState<number[]>(settings.manual_product_ids);
    const [busy, setBusy] = useState<Where | null>(null);
    const [notice, setNotice] = useState<{ where: Where; tone: 'success' | 'error'; text: string } | null>(null);
    const base = `/manage/storefronts/${storefront.id}/reengagement`;

    const dirty =
        audience !== settings.audience ||
        teamEmails.join('\n') !== settings.team_emails.join('\n') ||
        manual.join(',') !== settings.manual_product_ids.join(',');
    useDirtyGuard(dirty);

    const current = runs.find((run) => run.week === state.week) ?? null;
    const pending = current !== null && current.status !== 'sent';
    const nextPlan = fmt.when(state.next_plan_at);
    const estimate = state.customers_estimate;
    const savedTeam = settings.team_emails.length;

    /** One request's options: its result is shown beside the button that sent it (and the flash at the top as before). */
    const visit = (where: Where) => {
        setBusy(where);
        setNotice(null);
        return {
            preserveScroll: true,
            onSuccess: (next: { props: unknown }) => {
                const flash = (next.props as SharedProps).flash;
                if (flash.error) setNotice({ where, tone: 'error', text: flash.error });
                else if (flash.status) setNotice({ where, tone: 'success', text: flash.status });
            },
            onError: (bag: Record<string, string>) =>
                setNotice({
                    where,
                    tone: 'error',
                    text: t('reengagement.not_saved', 'لم يُحفظ شيء: صحّح :count من الأخطاء المشار إليها أدناه.', { count: Object.keys(bag).length }),
                }),
            onFinish: () => setBusy(null),
        };
    };
    const save = () => router.put(base, { audience, team_emails: teamEmails, manual_product_ids: manual }, visit('save'));
    const setPaused = (paused: boolean) => router.post(`${base}/pause`, { paused }, visit('pause'));
    const replan = () => router.post(`${base}/replan`, {}, visit('replan'));

    const Notice = ({ where }: { where: Where }) =>
        notice && notice.where === where ? (
            <p className={cn('text-sm', notice.tone === 'success' ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive')} role="status">
                {notice.text}
            </p>
        ) : null;

    const who = (run: { audience: string; recipients: number }) =>
        run.audience === 'customers'
            ? t('reengagement.n_customers', ':count عميل', { count: run.recipients })
            : t('reengagement.n_team', ':count من عناوين الفريق', { count: run.recipients });

    // ── the state, in plain words ──
    let stateLine: string;
    let stateTone: 'success' | 'warning' | 'info' = 'info';
    if (settings.paused) {
        stateTone = 'warning';
        stateLine =
            pending && current
                ? t('reengagement.state_paused_pending', 'موقوفة. لن يُرسل شيء حتى تستأنفها — ومنها رسالة هذا الأسبوع المجهّزة لـ:who.', { who: who(current) })
                : t('reengagement.state_paused', 'موقوفة. لن يُرسل شيء حتى تستأنفها.');
    } else if (current?.status === 'sent') {
        stateTone = 'success';
        stateLine = t('reengagement.state_sent', 'رسالة هذا الأسبوع أُرسلت :when إلى :who. الرسالة القادمة تُجهَّز :next.', {
            when: current.sent_at ? fmt.when(current.sent_at) : '',
            who: who(current),
            next: nextPlan,
        });
    } else if (current?.status === 'previewed') {
        stateLine = t('reengagement.state_previewed', 'رسالة هذا الأسبوع جاهزة ووصلت معاينتها للفريق. تُرسل :when إلى :who، إلا إذا أوقفتها قبل ذلك.', {
            when: current.send_after ? fmt.when(current.send_after) : '',
            who: who(current),
        });
    } else if (current?.status === 'paused') {
        stateTone = 'warning';
        stateLine = t('reengagement.state_skipped', 'رسالة هذا الأسبوع لم تُرسل لأنها كانت موقوفة. أعد تجهيزها أدناه لإرسالها، وإلا تُجهَّز التالية :next.', { next: nextPlan });
    } else if (savedTeam === 0) {
        stateTone = 'warning';
        stateLine = t('reengagement.state_no_team', 'لن تُجهَّز أي رسالة: أضف بريداً واحداً على الأقل للفريق واحفظ.');
    } else {
        stateLine =
            settings.audience === 'customers'
                ? t('reengagement.state_next_customers', 'تعمل. الرسالة القادمة تُجهَّز :next: تصل المعاينة للفريق، ثم تُرسل بعد 24 ساعة إلى حوالي :count عميل.', { next: nextPlan, count: estimate })
                : t('reengagement.state_next_team', 'تعمل (الفريق فقط). الرسالة القادمة تُجهَّز :next وتُرسل بعد 24 ساعة إلى عناوين الفريق فقط — لا يصل شيء للعملاء.', { next: nextPlan });
    }

    const audienceOptions = [
        {
            value: 'team',
            label: t('reengagement.audience_team', 'الفريق فقط (للتجربة)'),
            effect: t('reengagement.audience_team_effect', 'تصل الرسالة إلى عناوين الفريق أدناه فقط، ولا يصل شيء لأي عميل.'),
        },
        {
            value: 'customers',
            label: t('reengagement.audience_customers', 'العملاء'),
            effect: t('reengagement.audience_customers_effect', 'تصل إلى العملاء الغائبين 30 يوماً أو أكثر — حوالي :count عميل اليوم. ويبقى الفريق يستلم المعاينة أولاً.', { count: estimate }),
        },
    ];

    const errorList = Object.values(errors);
    const emailErrors = Object.entries(errors)
        .filter(([key]) => key === 'team_emails' || key.startsWith('team_emails.'))
        .map(([, message]) => message);
    const pickErrors = Object.entries(errors)
        .filter(([key]) => key === 'manual_product_ids' || key.startsWith('manual_product_ids.'))
        .map(([, message]) => message);

    const saveLabel =
        audience === 'customers' && settings.audience !== 'customers'
            ? t('reengagement.save_to_customers', 'احفظ — الرسالة القادمة تصل للعملاء')
            : audience === 'team' && settings.audience === 'customers'
              ? t('reengagement.save_to_team', 'احفظ — الرسالة القادمة للفريق فقط')
              : t('reengagement.save', 'احفظ الإعدادات');

    return (
        <ManageLayout
            title={t('reengagement.title', 'رسائل العودة')}
            crumbs={[{ label: t('common.home', 'الرئيسية'), href: '/manage' }, { label: t('reengagement.title', 'رسائل العودة') }]}
        >
            <div className="max-w-4xl space-y-6">
                {/* 1. Where things stand, then what this is. */}
                <Alert tone={stateTone}>{stateLine}</Alert>
                <div className="space-y-1 text-sm text-muted-foreground">
                    <p>
                        {t(
                            'reengagement.explain_what',
                            'رسالة أسبوعية للعملاء الذين لم يزوروا المتجر منذ 30 يوماً أو أكثر: حتى 6 منتجات متوفرة لم يتغير سعرها منذ 14 يوماً، ولم تُرسل لهم من قبل.',
                        )}
                    </p>
                    <p>
                        {t(
                            'reengagement.explain_when',
                            'تُجهَّز كل إثنين الساعة 10:00 وتصل معاينتها لعناوين الفريق، ثم تُرسل بعد 24 ساعة إلا إذا أوقفتها.',
                        )}
                    </p>
                    <p>
                        {t(
                            'reengagement.explain_safe',
                            'اختيار «الفريق فقط» يرسلها لعناوين الفريق وحدها، لتراها قبل أي عميل. وفي كل رسالة رابط لإلغاء الاشتراك (ألغى :count حتى الآن).',
                            { count: optouts },
                        )}
                    </p>
                </div>

                {/* 2. Pause / resume — its own action; it never saves the form. */}
                <section className="space-y-3 rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge variant={settings.paused ? 'warning' : 'success'}>
                            {settings.paused ? t('reengagement.paused', 'موقوفة') : t('reengagement.running', 'تعمل')}
                        </Badge>
                        {settings.paused ? (
                            <ConfirmAction
                                title={t('reengagement.resume_title', 'استئناف رسائل العودة؟')}
                                tone="default"
                                consequence={
                                    current?.status === 'paused' || pending
                                        ? t('reengagement.resume_consequence_skipped', 'تُجهَّز الرسالة القادمة :next كالمعتاد. رسالة هذا الأسبوع لا تُرسل إلا إذا أعدت تجهيزها أدناه بعد الاستئناف.', { next: nextPlan })
                                        : t('reengagement.resume_consequence', 'تُجهَّز الرسالة القادمة :next وتُرسل بعد 24 ساعة.', { next: nextPlan })
                                }
                                confirmLabel={t('reengagement.resume_confirm', 'استأنف الرسائل')}
                                onConfirm={() => setPaused(false)}
                                trigger={
                                    <Button type="button" disabled={busy !== null}>
                                        <Play />
                                        {t('reengagement.resume', 'استئناف')}
                                    </Button>
                                }
                            />
                        ) : (
                            <ConfirmAction
                                title={t('reengagement.pause_title', 'إيقاف رسائل العودة مؤقتاً؟')}
                                consequence={
                                    pending && current
                                        ? t('reengagement.pause_consequence_pending', 'لن يُرسل شيء حتى تستأنفها — ومنها رسالة هذا الأسبوع المجهّزة لـ:who.', { who: who(current) })
                                        : t('reengagement.pause_consequence', 'لن يُرسل شيء حتى تستأنفها. إعداداتك تبقى كما هي.')
                                }
                                confirmLabel={t('reengagement.pause_confirm', 'أوقف الرسائل')}
                                onConfirm={() => setPaused(true)}
                                trigger={
                                    <Button type="button" variant="outline" disabled={busy !== null}>
                                        <Pause />
                                        {t('reengagement.pause', 'إيقاف مؤقت')}
                                    </Button>
                                }
                            />
                        )}
                        <span className="text-xs text-muted-foreground">
                            {t('reengagement.pause_hint', 'الإيقاف والاستئناف لا يحفظان التغييرات في الإعدادات أدناه.')}
                        </span>
                    </div>
                    <Notice where="pause" />
                </section>

                {/* 3. Settings — saved together, never the pause. */}
                <section className="space-y-5 rounded-lg border bg-card p-4">
                    {errorList.length > 0 ? (
                        <Alert tone="error">
                            {t('reengagement.errors_summary', 'لم يُحفظ شيء. صحّح ما يلي:')}
                            <ul className="mt-1 list-disc ps-5">
                                {errorList.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </Alert>
                    ) : null}

                    <fieldset className="space-y-2">
                        <legend className="font-medium">{t('reengagement.audience', 'المستلمون')}</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {audienceOptions.map((option) => (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex cursor-pointer gap-3 rounded-md border p-3 text-sm',
                                        audience === option.value ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="audience"
                                        value={option.value}
                                        checked={audience === option.value}
                                        onChange={() => setAudience(option.value)}
                                        className="mt-1"
                                    />
                                    <span className="space-y-1">
                                        <span className="block font-medium">{option.label}</span>
                                        <span className="block text-muted-foreground">{option.effect}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {audience === 'customers' && settings.audience !== 'customers' ? (
                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                {t('reengagement.customers_warning_n', 'بعد الحفظ تصل الرسالة القادمة إلى عملاء حقيقيين — حوالي :count عميل.', { count: estimate })}
                            </p>
                        ) : null}
                    </fieldset>

                    <div className="space-y-2">
                        <label htmlFor="reengagement-team" className="block font-medium">
                            {t('reengagement.team_emails_label', 'عناوين الفريق')}
                        </label>
                        <p className="text-sm text-muted-foreground">
                            {t('reengagement.team_emails_hint', 'تصلها المعاينة كل إثنين قبل الإرسال بـ24 ساعة. بدون أي عنوان لا تُجهَّز أي رسالة ولا تُرسل.')}
                        </p>
                        <EmailChips id="reengagement-team" value={teamEmails} onChange={setTeamEmails} errors={emailErrors} />
                    </div>

                    <div className="space-y-2">
                        <label htmlFor="reengagement-picks" className="block font-medium">
                            {t('reengagement.picks_title', 'منتجات الرسالة القادمة')}
                        </label>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'reengagement.picks_hint',
                                'اتركها فارغة لتختار الرسالة المنتجات تلقائياً. أو اختر من :min إلى :max منتجات بالترتيب: تُستخدم في الرسالة القادمة فقط (المتوفر والظاهر منها، بسعر ثابت منذ 14 يوماً)، ثم تعود الرسالة للاختيار التلقائي.',
                                { min: picks.min, max: picks.max },
                            )}
                        </p>
                        <ProductPicker id="reengagement-picks" searchUrl={`${base}/products`} value={manual} onChange={setManual} max={picks.max} />
                        {manual.length > 0 && manual.length < picks.min ? (
                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                {t('reengagement.picks_short', 'اخترت :count — أضف حتى :min على الأقل، أو أزلها كلها للاختيار التلقائي.', { count: manual.length, min: picks.min })}
                            </p>
                        ) : null}
                        {pickErrors.map((message) => (
                            <p key={message} className="text-sm text-destructive">
                                {message}
                            </p>
                        ))}
                    </div>

                    <div className="space-y-2 border-t pt-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="button" onClick={save} disabled={busy !== null || !dirty}>
                                {busy === 'save' ? t('reengagement.saving', 'جاري الحفظ…') : saveLabel}
                            </Button>
                            {dirty ? (
                                <span className="text-sm text-amber-700 dark:text-amber-300">{t('reengagement.unsaved', 'تغييرات غير محفوظة.')}</span>
                            ) : null}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {pending
                                ? t('reengagement.save_applies_pending', 'الحفظ يُطبَّق على الرسالة التي تُجهَّز :next. رسالة هذا الأسبوع جاهزة بالإعدادات السابقة، إلا إذا أعدت تجهيزها أدناه.', { next: nextPlan })
                                : t('reengagement.save_applies', 'الحفظ يُطبَّق على الرسالة التي تُجهَّز :next.', { next: nextPlan })}
                        </p>
                        <Notice where="save" />
                    </div>
                </section>

                {/* 4. This week, from the saved settings, now. */}
                {current?.status !== 'sent' ? (
                    <section className="space-y-2 rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {current ? t('reengagement.replan_title', 'إعادة تجهيز رسالة هذا الأسبوع') : t('reengagement.plan_now_title', 'تجهيز رسالة هذا الأسبوع الآن')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'reengagement.replan_hint',
                                'تُبنى من الإعدادات المحفوظة: من يستلمها وأي منتجات. تصل معاينة جديدة للفريق، وتُرسل بعد 24 ساعة من الآن إلا إذا أوقفتها.',
                            )}
                        </p>
                        <div className="flex flex-wrap items-center gap-3">
                            <ConfirmAction
                                title={current ? t('reengagement.replan_title', 'إعادة تجهيز رسالة هذا الأسبوع') : t('reengagement.plan_now_title', 'تجهيز رسالة هذا الأسبوع الآن')}
                                tone="default"
                                disabled={dirty || savedTeam === 0 || busy !== null}
                                consequence={
                                    settings.audience === 'customers'
                                        ? t('reengagement.replan_consequence_customers', 'تصل معاينة جديدة إلى :team من عناوين الفريق الآن، ثم تُرسل الرسالة بعد 24 ساعة إلى حوالي :count عميل — إلا إذا أوقفتها.', { team: savedTeam, count: estimate })
                                        : t('reengagement.replan_consequence_team', 'تصل معاينة جديدة إلى :team من عناوين الفريق الآن، ثم تُرسل الرسالة بعد 24 ساعة إلى عناوين الفريق فقط.', { team: savedTeam })
                                }
                                confirmLabel={current ? t('reengagement.replan_confirm', 'أعد التجهيز وأرسل المعاينة') : t('reengagement.plan_now_confirm', 'جهّزها وأرسل المعاينة')}
                                onConfirm={replan}
                                trigger={
                                    <Button type="button" variant="outline" disabled={dirty || savedTeam === 0 || busy !== null}>
                                        <RefreshCw />
                                        {current ? t('reengagement.replan_button', 'أعد تجهيز هذا الأسبوع') : t('reengagement.plan_now_button', 'جهّز هذا الأسبوع الآن')}
                                    </Button>
                                }
                            />
                            {dirty ? (
                                <span className="text-sm text-muted-foreground">{t('reengagement.replan_save_first', 'احفظ تغييراتك أولاً: التجهيز يستخدم الإعدادات المحفوظة.')}</span>
                            ) : savedTeam === 0 ? (
                                <span className="text-sm text-muted-foreground">{t('reengagement.replan_need_team', 'أضف بريداً للفريق واحفظ أولاً.')}</span>
                            ) : null}
                        </div>
                        <Notice where="replan" />
                    </section>
                ) : null}

                {/* 5. Past weeks, in words. */}
                <section className="rounded-lg border bg-card">
                    <h2 className="border-b p-3 font-medium">{t('reengagement.runs', 'الأسابيع السابقة')}</h2>
                    {runs.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">{t('reengagement.no_runs', 'لم تُجهَّز أي رسالة بعد.')}</p>
                    ) : (
                        <ul className="divide-y">
                            {runs.map((run) => {
                                const [from, to] = weekRange(run.week);
                                const range = `${fmt.day(from)} – ${fmt.day(to)}`;
                                const sentence =
                                    run.status === 'sent'
                                        ? t('reengagement.run_sent', 'أُرسلت :when إلى :who.', { when: run.sent_at ? fmt.when(run.sent_at) : '', who: who(run) })
                                        : run.status === 'paused'
                                          ? t('reengagement.run_paused', 'لم تُرسل — كانت موقوفة.')
                                          : t('reengagement.run_previewed', 'جاهزة؛ تُرسل :when إلى :who.', { when: run.send_after ? fmt.when(run.send_after) : '', who: who(run) });
                                return (
                                    <li key={run.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3 text-sm">
                                        <span className="w-36 shrink-0 font-medium tabular-nums">{range}</span>
                                        <span className="flex-1">{sentence}</span>
                                        {run.manual ? <Badge variant="outline">{t('reengagement.manual_run', 'منتجات اختارها الفريق')}</Badge> : null}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>
        </ManageLayout>
    );
}
