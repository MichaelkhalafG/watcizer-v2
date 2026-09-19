import { router, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Ltr, Num } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { abilityLabel } from '@/lib/labels';
import type { SharedProps } from '@/types';

/**
 * The signed-in operator's own profile (wave 4D).
 *
 * ── Why three fields have a padlock instead of an input ─────────────────────────────────────
 *
 * The accounts table is shared with the live storefront and the old dashboard, and this
 * application reads it without ever writing to it — a rule enforced by the database itself, not by
 * a missing button. So name, e-mail and password are shown with a lock and a sentence saying where
 * they ARE changed. A disabled input with no explanation would leave the operator hunting for a
 * control that does not exist and cannot.
 *
 * What this screen CAN save is one thing, and it says which table it lands in.
 */

interface Grant {
    role: string;
    label: string;
    scope: string;
    abilities: string[];
    granted_at: string | null;
}

interface Props {
    identity: {
        name: string;
        first_name: string | null;
        last_name: string | null;
        email: string;
        phone: string | null;
        legacy_type: string | null;
    };
    identity_notice: string;
    grants: Grant[];
    locale: string;
    locales: Array<{ value: string; label: string }>;
    locale_notice: string;
    saves_notice: string;
}

/** A value the operator can read but not change, and the reason on its face. */
function LockedField({ label, value, dir }: { label: string; value: string | null; dir?: 'ltr' }) {
    return (
        <div className="space-y-1">
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Lock className="h-3 w-3" aria-hidden="true" />
                <span>{label}</span>
            </div>
            <div className="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                {value === null || value === '' ? (
                    <span className="text-muted-foreground">—</span>
                ) : dir === 'ltr' ? (
                    <Ltr>{value}</Ltr>
                ) : (
                    value
                )}
            </div>
        </div>
    );
}

export default function ProfileIndex({
    identity,
    identity_notice,
    grants,
    locale,
    locales,
    locale_notice,
    saves_notice,
}: Props) {
    const t = useT();
    const { auth } = usePage<SharedProps>().props;
    const [chosen, setChosen] = useState(locale);
    const [saving, setSaving] = useState(false);

    const dirty = chosen !== locale;

    const save = () => {
        setSaving(true);
        router.put(
            '/manage/profile',
            { locale: chosen },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <ManageLayout
            title={t('common.profile', 'الملف الشخصي')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.profile', 'الملف الشخصي') },
            ]}
        >
            <div className="max-w-3xl space-y-4">
                <Card>
                    <CardHeader className="flex-row items-center gap-3">
                        <span
                            aria-hidden="true"
                            className="flex h-11 w-11 items-center justify-center rounded-full bg-brand text-sm font-semibold text-brand-foreground"
                        >
                            {auth.user?.initials ?? '—'}
                        </span>
                        <div className="min-w-0">
                            <CardTitle className="truncate">{identity.name === '' ? '—' : identity.name}</CardTitle>
                            <p className="truncate text-xs text-muted-foreground">
                                <Ltr>{identity.email}</Ltr>
                            </p>
                        </div>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('profile.account_details', 'بيانات الحساب')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Alert tone="info" title={t('profile.read_only_title', 'هذه البيانات للقراءة فقط')}>
                            {identity_notice}
                        </Alert>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <LockedField label={t('profile.first_name', 'الاسم الأول')} value={identity.first_name} />
                            <LockedField label={t('profile.last_name', 'اسم العائلة')} value={identity.last_name} />
                            <LockedField
                                label={t('profile.email', 'البريد الإلكتروني')}
                                value={identity.email}
                                dir="ltr"
                            />
                            <LockedField label={t('profile.phone', 'رقم الهاتف')} value={identity.phone} dir="ltr" />
                        </div>

                        <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                            <div className="flex items-center gap-1.5 font-medium text-foreground">
                                <Lock className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('common.password', 'كلمة المرور')}
                            </div>
                            {/* Said plainly rather than shown as a dead button: the change is made
                                where the account lives, and that is a decision, not a gap. */}
                            <p className="mt-1">
                                {t(
                                    'profile.password_note',
                                    'تغيير كلمة المرور يتم من المتجر أو من الداشبورد القديم. لوحة التحكم الجديدة لا تكتب في جدول الحسابات إطلاقًا.',
                                )}
                            </p>
                        </div>

                        {identity.legacy_type === null ? null : (
                            <p className="text-xs text-muted-foreground">
                                {t('profile.legacy_type_label', 'نوع الحساب في النظام القديم:')}{' '}
                                <Ltr className="font-medium">{identity.legacy_type}</Ltr>{' '}
                                {t(
                                    'profile.legacy_type_note',
                                    '— لا أثر له هنا؛ الصلاحيات تأتي من الجدول أدناه وحده.',
                                )}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('profile.preferences', 'التفضيلات')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <label className="block max-w-xs space-y-1 text-sm">
                            <span>{t('profile.panel_language', 'لغة اللوحة')}</span>
                            <Select
                                id="profile-locale"
                                aria-label={t('profile.panel_language', 'لغة اللوحة')}
                                value={chosen}
                                onChange={(event) => setChosen(event.target.value)}
                            >
                                {locales.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </label>

                        <p className="text-xs text-muted-foreground">{locale_notice}</p>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button onClick={save} disabled={!dirty || saving}>
                                {saving ? t('common.saving', 'جارٍ الحفظ…') : t('common.save', 'حفظ')}
                            </Button>
                            {dirty ? (
                                <Button variant="outline" onClick={() => setChosen(locale)}>
                                    {t('profile.revert', 'تراجع')}
                                </Button>
                            ) : null}
                            <span className="text-xs text-muted-foreground">{saves_notice}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('common.your_abilities', 'صلاحياتك')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {grants.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('profile.no_grants', 'لا توجد صلاحيات ممنوحة لهذا الحساب.')}
                            </p>
                        ) : (
                            grants.map((grant) => (
                                <div key={`${grant.role}-${grant.scope}`} className="rounded-lg border p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="default">{grant.label}</Badge>
                                        <span className="text-sm">{grant.scope}</span>
                                        {grant.granted_at === null ? null : (
                                            <Num className="ms-auto text-xs text-muted-foreground">
                                                {grant.granted_at.slice(0, 10)}
                                            </Num>
                                        )}
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {grant.abilities.map((ability) => (
                                            <span
                                                key={ability}
                                                className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                                            >
                                                {abilityLabel(t, ability)}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'profile.grants_note',
                                'الصلاحيات تُمنح من شاشة «المستخدمون والصلاحيات».',
                            )}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}
