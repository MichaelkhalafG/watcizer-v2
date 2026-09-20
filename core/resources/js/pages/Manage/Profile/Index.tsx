import { router, usePage } from '@inertiajs/react';
import { KeyRound, Lock } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Ltr, Num } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { abilityLabel } from '@/lib/labels';
import type { SharedProps } from '@/types';

/**
 * The signed-in operator's own profile (wave 4D).
 *
 * ── Why the name has a padlock and the password does not ───────────────────────────────────
 *
 * The accounts table is shared with the storefront. Core writes exactly two things to it — a new
 * dashboard account, and a password change — both through `DashboardAccounts` (AGENTS §2.18,
 * rewritten 2026-09-20 when the standalone deployment left the legacy host unreachable).
 *
 * So the PASSWORD has a real form here, and the name, e-mail and phone keep their padlock: those
 * are the customer-facing identity the storefront owns, and nobody asked for them. A disabled
 * input with no explanation would leave the operator hunting for a control that does not exist,
 * so each lock carries the reason on its face.
 *
 * The password form says what it does NOT do, too: other devices stay signed in, because
 * `logoutOtherDevices()` writes `users.remember_token` and that is one of the three framework
 * writes wave 4A turned off deliberately.
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
    password_note: string;
    password_min: number;
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
    password_note,
    password_min,
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

    /*
     * The password form is its own state and its own request. Kept apart from the language save on
     * purpose: they write different tables, they can fail for different reasons, and a single form
     * would make "Save" ambiguous about which of the two it just did.
     */
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirm, setConfirm] = useState('');
    const [changing, setChanging] = useState(false);
    const { errors } = usePage<SharedProps>().props;

    const tooShort = next !== '' && next.length < password_min;
    const mismatch = confirm !== '' && next !== confirm;
    const canChange =
        current !== '' && next !== '' && confirm !== '' && !tooShort && !mismatch && !changing;

    const changePassword = () => {
        setChanging(true);
        router.put(
            '/manage/profile/password',
            { current_password: current, password: next, password_confirmation: confirm },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCurrent('');
                    setNext('');
                    setConfirm('');
                },
                onFinish: () => setChanging(false),
            },
        );
    };

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

                        {identity.legacy_type === null ? null : (
                            <p className="text-xs text-muted-foreground">
                                {t('profile.legacy_type_label', 'خانة قديمة في جدول الحسابات:')}{' '}
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
                    <CardHeader className="flex-row items-center gap-3">
                        <KeyRound className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        <CardTitle>{t('profile.password_heading', 'تغيير كلمة المرور')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">{password_note}</p>

                        {errors.current_password ? (
                            <Alert tone="error">{errors.current_password}</Alert>
                        ) : null}
                        {errors.password ? <Alert tone="error">{errors.password}</Alert> : null}

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="space-y-1 text-sm sm:col-span-2 sm:max-w-xs">
                                <span>{t('profile.password_current', 'كلمة المرور الحالية')}</span>
                                <Input
                                    id="profile-password-current"
                                    type="password"
                                    autoComplete="current-password"
                                    value={current}
                                    onChange={(event) => setCurrent(event.target.value)}
                                />
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('profile.password_new', 'كلمة المرور الجديدة')}</span>
                                <Input
                                    id="profile-password-new"
                                    type="password"
                                    autoComplete="new-password"
                                    value={next}
                                    onChange={(event) => setNext(event.target.value)}
                                />
                                {/* Said as a requirement while they type, not as a refusal after
                                    they submit — the server enforces the same number either way. */}
                                {tooShort ? (
                                    <span className="block text-xs text-destructive">
                                        {t('profile.password_too_short', 'كلمة المرور لا تقل عن :count حروف.').replace(
                                            ':count',
                                            String(password_min),
                                        )}
                                    </span>
                                ) : null}
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('profile.password_confirm', 'أعد كتابة كلمة المرور الجديدة')}</span>
                                <Input
                                    id="profile-password-confirm"
                                    type="password"
                                    autoComplete="new-password"
                                    value={confirm}
                                    onChange={(event) => setConfirm(event.target.value)}
                                />
                                {mismatch ? (
                                    <span className="block text-xs text-destructive">
                                        {t('profile.password_mismatch', 'الكلمتان غير متطابقتين.')}
                                    </span>
                                ) : null}
                            </label>
                        </div>

                        <Button onClick={changePassword} disabled={!canChange}>
                            {changing
                                ? t('common.saving', 'جارٍ الحفظ…')
                                : t('profile.password_submit', 'تغيير كلمة المرور')}
                        </Button>
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
