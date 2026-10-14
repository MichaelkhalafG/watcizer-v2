import { router, useForm, usePage } from '@inertiajs/react';

import { FormActions } from '@/components/form/FormActions';
import { PasswordField } from '@/components/form/PasswordField';
import { SelectField, TextareaField, TextField } from '@/components/form/TextField';
import { SwitchField } from '@/components/form/SwitchField';
import { useDirtyGuard } from '@/components/form/useDirtyGuard';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

interface StorefrontUrls {
    frontend: string;
    payment_return: string;
    api_host: string;
    asset_base: string;
}

interface StorefrontIdentity {
    mail_wordmark: string;
    mail_wordmark_1x: string;
    whatsapp_support: string;
    whatsapp_developer: string;
    copyright: string;
    mail_wordmark_exists: boolean;
    mail_wordmark_1x_exists: boolean;
}

interface StorefrontMailbox {
    exists: boolean;
    is_enabled: boolean;
    host: string;
    port: number;
    encryption: string;
    username: string;
    from_address: string;
    from_name: string;
    daily_cap: number;
    transactional_reserve: number;
    /** One address per line. */
    order_recipients: string;
    /** A boolean — the only thing the page is ever told about the password. */
    password_set: boolean;
    /** What a REAL send would still need; empty when the mailbox is usable. */
    missing: string[];
}

interface StorefrontForm {
    id: number;
    code: string;
    name: string;
    domain: string | null;
    locales: string[];
    default_locale: string;
    currency: string;
    is_active: boolean;
    /** Does this storefront's frontend compute and display a promotion-aware total? */
    money_rewards: boolean;
    urls: StorefrontUrls;
    /** What the shop uses today for each URL — the override or the derived value. */
    derived_urls: StorefrontUrls;
    identity: StorefrontIdentity;
    mailbox: StorefrontMailbox;
}

/**
 * The worked example of the FORM system: `useForm` for state, field components for chrome and
 * error display, `useDirtyGuard` for the navigate-away prompt, `FormActions` for save/cancel.
 *
 * `code` is shown read-only on purpose — it is the storefront's identity in every URL, cache key
 * and compat payload, so renaming it is a migration and not a form field (see StorefrontController).
 *
 * ── The three sections added on 2026-10-13 (§4.1) ───────────────────────────────────────────
 *
 * Addresses, e-mail identity and the mailbox were "SQL only" (backlog SF-URL-SETTINGS) until the
 * statement handed over for the identity would have written a literal `\$` as a JSON path once
 * PowerShell had escaped it. A value only a developer with a database client can set is a value
 * the shop cannot correct on a Friday evening. They are validated UP FRONT on the server with the
 * same conditions the readers refuse at send time (no host, no identity, wordmark file missing),
 * so a save that passes here is a save the first order after it will not fail on.
 *
 * The mailbox password is WRITE-ONLY: the page is told whether one is stored and never what it is;
 * blank keeps it, as on the payments screen.
 */
export default function StorefrontEdit({ storefront, locale_options }: { storefront: StorefrontForm; locale_options: Array<{ value: string; label: string }> }) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const form = useForm({
        name: storefront.name,
        domain: storefront.domain ?? '',
        locales: storefront.locales,
        default_locale: storefront.default_locale,
        currency: storefront.currency,
        is_active: storefront.is_active,
        money_rewards: storefront.money_rewards,
        urls: {
            frontend: storefront.urls.frontend,
            payment_return: storefront.urls.payment_return,
            api_host: storefront.urls.api_host,
            asset_base: storefront.urls.asset_base,
        },
        identity: {
            mail_wordmark: storefront.identity.mail_wordmark,
            mail_wordmark_1x: storefront.identity.mail_wordmark_1x,
            whatsapp_support: storefront.identity.whatsapp_support,
            whatsapp_developer: storefront.identity.whatsapp_developer,
            copyright: storefront.identity.copyright,
        },
        mailbox: {
            is_enabled: storefront.mailbox.is_enabled,
            host: storefront.mailbox.host,
            port: String(storefront.mailbox.port),
            encryption: storefront.mailbox.encryption,
            username: storefront.mailbox.username,
            password: '',
            from_address: storefront.mailbox.from_address,
            from_name: storefront.mailbox.from_name,
            daily_cap: String(storefront.mailbox.daily_cap),
            transactional_reserve: String(storefront.mailbox.transactional_reserve),
            order_recipients: storefront.mailbox.order_recipients,
        },
    });

    useDirtyGuard(form.isDirty);

    const toggleLocale = (locale: string, on: boolean) => {
        const next = on ? [...form.data.locales, locale] : form.data.locales.filter((item) => item !== locale);
        form.setData('locales', next);
    };

    const setUrl = (key: keyof StorefrontUrls, value: string) => form.setData('urls', { ...form.data.urls, [key]: value });
    const setIdentity = (key: keyof typeof form.data.identity, value: string) => form.setData('identity', { ...form.data.identity, [key]: value });
    const setMailbox = (key: keyof typeof form.data.mailbox, value: string | boolean) => form.setData('mailbox', { ...form.data.mailbox, [key]: value });

    const urlFields: Array<{ key: keyof StorefrontUrls; label: string }> = [
        { key: 'frontend', label: t('storefronts.url_frontend', 'موقع المتجر') },
        { key: 'payment_return', label: t('storefronts.url_payment_return', 'صفحة العودة بعد الدفع بالبطاقة') },
        { key: 'api_host', label: t('storefronts.url_api_host', 'مضيف الـAPI') },
        { key: 'asset_base', label: t('storefronts.url_asset_base', 'مضيف الصور') },
    ];

    const encryptionOptions = [
        { value: 'tls', label: t('storefronts.mailbox_encryption_tls', 'TLS (STARTTLS، منفذ 587)') },
        { value: 'ssl', label: t('storefronts.mailbox_encryption_ssl', 'SSL (منفذ 465)') },
        { value: 'none', label: t('storefronts.mailbox_encryption_none', 'بدون') },
    ];

    return (
        <ManageLayout
            title={t('storefronts.edit_title', 'إعدادات :name', { name: storefront.name })}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.storefronts', 'المتاجر'), href: '/manage/storefronts' },
                { label: storefront.name },
            ]}
        >
            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/manage/storefronts/${storefront.id}`, { preserveScroll: true });
                }}
            >
                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.edit_identity', 'الهوية')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        <TextField
                            label={t('storefronts.code', 'الرمز')}
                            value={storefront.code}
                            onChange={() => undefined}
                            disabled
                            dir="ltr"
                            hint={t('storefronts.edit_code_hint', 'ثابت: يُستخدم في مسارات الـAPI ومفاتيح الكاش.')}
                        />
                        <TextField
                            label={t('common.name', 'الاسم')}
                            required
                            value={form.data.name}
                            onChange={(value) => form.setData('name', value)}
                            error={errors.name ?? null}
                        />
                        <TextField
                            label={t('common.domain', 'النطاق')}
                            dir="ltr"
                            value={form.data.domain}
                            onChange={(value) => form.setData('domain', value)}
                            error={errors.domain ?? null}
                            placeholder="example.com"
                            hint={t('storefronts.edit_domain_hint', 'بدون بروتوكول.')}
                        />
                        <TextField
                            label={t('common.currency', 'العملة')}
                            required
                            dir="ltr"
                            value={form.data.currency}
                            onChange={(value) => form.setData('currency', value.toUpperCase().slice(0, 3))}
                            error={errors.currency ?? null}
                            hint={t('storefronts.edit_currency_hint', 'رمز ISO من ثلاثة أحرف، مثل EGP.')}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.edit_languages_section', 'اللغات والعرض')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="space-y-2">
                            <Label required>{t('storefronts.edit_enabled_locales', 'اللغات المفعّلة')}</Label>
                            <div className="flex flex-wrap gap-4">
                                {locale_options.map((option) => (
                                    <label key={option.value} className="flex cursor-pointer items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.locales.includes(option.value)}
                                            onCheckedChange={(value) => toggleLocale(option.value, value === true)}
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                            {errors.locales !== undefined ? (
                                <p role="alert" className="text-xs font-medium text-destructive">
                                    {errors.locales}
                                </p>
                            ) : null}
                        </div>

                        <SelectField
                            label={t('storefronts.edit_default_locale', 'اللغة الافتراضية')}
                            required
                            value={form.data.default_locale}
                            onChange={(value) => form.setData('default_locale', value)}
                            options={locale_options.filter((option) => form.data.locales.includes(option.value))}
                            error={errors.default_locale ?? null}
                            hint={t('storefronts.edit_default_locale_hint', 'يجب أن تكون من اللغات المفعّلة.')}
                        />

                        <SwitchField
                            label={t('storefronts.edit_is_active', 'المتجر مفعّل')}
                            checked={form.data.is_active}
                            onChange={(checked) => form.setData('is_active', checked)}
                            error={errors.is_active ?? null}
                        />
                    </CardContent>
                </Card>

                {/* ── Addresses: blank = derived from the domain, and the derived value is shown ── */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.urls_section', 'العناوين (الروابط)')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'storefronts.urls_intro',
                                'اترك الحقل فارغًا لاستخدام القيمة المشتقّة من النطاق، المعروضة بجواره. املأه فقط حين يعيش المتجر في مكان آخر (مضيف مؤقت، مضيف API منفصل).',
                            )}
                        </p>
                        <div className="grid gap-5 sm:grid-cols-2">
                            {urlFields.map(({ key, label }) => (
                                <TextField
                                    key={key}
                                    label={label}
                                    dir="ltr"
                                    value={form.data.urls[key]}
                                    onChange={(value) => setUrl(key, value)}
                                    error={errors[`urls.${key}`] ?? null}
                                    placeholder="https://"
                                    hint={t('storefronts.url_uses', 'المستخدم الآن: :url', { url: storefront.derived_urls[key] })}
                                />
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* ── E-mail identity: the five values every e-mail reads (W2) ── */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.identity_section', 'هوية البريد')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'storefronts.identity_intro',
                                'ما تعرضه كل رسالة بريد من هذا المتجر. الخمسة كلها مطلوبة: متجر ينقصه أيّ منها لا يُرسِل شيئًا، ولا يستخدم قيم متجر آخر أبدًا.',
                            )}
                        </p>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <TextField
                                label={t('storefronts.identity_mail_wordmark', 'شعار البريد (PNG بدقة 2x، مسار تحت public/)')}
                                required
                                dir="ltr"
                                value={form.data.identity.mail_wordmark}
                                onChange={(value) => setIdentity('mail_wordmark', value)}
                                error={errors['identity.mail_wordmark'] ?? null}
                                placeholder="assets/brand/example-mail-wordmark@2x.png"
                                hint={
                                    storefront.identity.mail_wordmark === ''
                                        ? undefined
                                        : storefront.identity.mail_wordmark_exists
                                          ? t('storefronts.identity_file_exists', 'الملف موجود')
                                          : t('storefronts.identity_file_missing', 'الملف غير موجود تحت public/')
                                }
                            />
                            <TextField
                                label={t('storefronts.identity_mail_wordmark_1x', 'شعار البريد بدقة 1x (اختياري، مسار تحت public/)')}
                                dir="ltr"
                                value={form.data.identity.mail_wordmark_1x}
                                onChange={(value) => setIdentity('mail_wordmark_1x', value)}
                                error={errors['identity.mail_wordmark_1x'] ?? null}
                                hint={
                                    storefront.identity.mail_wordmark_1x === ''
                                        ? undefined
                                        : storefront.identity.mail_wordmark_1x_exists
                                          ? t('storefronts.identity_file_exists', 'الملف موجود')
                                          : t('storefronts.identity_file_missing', 'الملف غير موجود تحت public/')
                                }
                            />
                            <TextField
                                label={t('storefronts.identity_whatsapp_support', 'واتساب خدمة العملاء (أرقام)')}
                                required
                                dir="ltr"
                                inputMode="numeric"
                                value={form.data.identity.whatsapp_support}
                                onChange={(value) => setIdentity('whatsapp_support', value)}
                                error={errors['identity.whatsapp_support'] ?? null}
                                hint={t('storefronts.identity_whatsapp_support_hint', 'أرقام دولية بلا +. الرقم الذي يصل إليه العميل من رسالة البريد.')}
                            />
                            <TextField
                                label={t('storefronts.identity_whatsapp_developer', 'واتساب المطوّر (أرقام)')}
                                required
                                dir="ltr"
                                inputMode="numeric"
                                value={form.data.identity.whatsapp_developer}
                                onChange={(value) => setIdentity('whatsapp_developer', value)}
                                error={errors['identity.whatsapp_developer'] ?? null}
                                hint={t('storefronts.identity_whatsapp_developer_hint', 'أرقام دولية بلا +. يظهر في رأس الموقع وتذييله فقط، ولا يظهر في أي رسالة بريد.')}
                            />
                            <TextField
                                label={t('storefronts.identity_copyright', 'سطر حقوق النشر')}
                                required
                                value={form.data.identity.copyright}
                                onChange={(value) => setIdentity('copyright', value)}
                                error={errors['identity.copyright'] ?? null}
                                className="sm:col-span-2"
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* ── Mailbox: the SMTP row, its budget and the team addresses (W3/W4) ── */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.mailbox_section', 'صندوق البريد (الرسائل الصادرة)')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'storefronts.mailbox_intro',
                                'صندوق SMTP الذي تخرج منه رسائل هذا المتجر، وحدّه اليومي، وعناوين الفريق التي تصلها نسخة الإدارة من كل طلب. لا يُرسِل المتجر عبر صندوق متجر آخر أبدًا.',
                            )}
                        </p>

                        {storefront.mailbox.missing.length === 0 ? (
                            <Alert tone="success" title={t('storefronts.mailbox_usable', 'جاهز: كل ما يحتاجه الإرسال الفعلي مضبوط.')} />
                        ) : (
                            <Alert tone="warning" title={t('storefronts.mailbox_missing_title', 'غير جاهز للإرسال الفعلي بعد')}>
                                <p>
                                    {t(
                                        'storefronts.mailbox_missing_body',
                                        'ينقص: :fields. رسائل الطلبات لهذا المتجر تُعاد محاولتها نحو ساعة ونصف ثم تفشل بشكل ظاهر، والرسائل الجماعية تنتظر.',
                                        { fields: storefront.mailbox.missing.join(', ') },
                                    )}
                                </p>
                            </Alert>
                        )}

                        <SwitchField
                            label={t('storefronts.mailbox_enabled', 'صندوق البريد مفعّل')}
                            checked={form.data.mailbox.is_enabled}
                            onChange={(checked) => setMailbox('is_enabled', checked)}
                            error={errors['mailbox.is_enabled'] ?? null}
                        />

                        <div className="grid gap-5 sm:grid-cols-2">
                            <TextField
                                label={t('storefronts.mailbox_host', 'مضيف SMTP')}
                                dir="ltr"
                                value={form.data.mailbox.host}
                                onChange={(value) => setMailbox('host', value)}
                                error={errors['mailbox.host'] ?? null}
                                placeholder="smtp.hostinger.com"
                            />
                            <TextField
                                label={t('storefronts.mailbox_port', 'المنفذ')}
                                dir="ltr"
                                type="number"
                                min={1}
                                max={65535}
                                value={form.data.mailbox.port}
                                onChange={(value) => setMailbox('port', value)}
                                error={errors['mailbox.port'] ?? null}
                            />
                            <SelectField
                                label={t('storefronts.mailbox_encryption', 'التشفير')}
                                value={form.data.mailbox.encryption}
                                onChange={(value) => setMailbox('encryption', value)}
                                options={encryptionOptions}
                                error={errors['mailbox.encryption'] ?? null}
                            />
                            <TextField
                                label={t('storefronts.mailbox_username', 'اسم دخول SMTP')}
                                dir="ltr"
                                value={form.data.mailbox.username}
                                onChange={(value) => setMailbox('username', value)}
                                error={errors['mailbox.username'] ?? null}
                                autoComplete="off"
                            />
                            <PasswordField
                                label={t('storefronts.mailbox_password', 'كلمة مرور SMTP')}
                                value={form.data.mailbox.password}
                                onChange={(value) => setMailbox('password', value)}
                                error={errors['mailbox.password'] ?? null}
                                autoComplete="new-password"
                                hint={
                                    storefront.mailbox.password_set
                                        ? t('storefronts.mailbox_password_set', 'توجد كلمة مرور محفوظة. اترك هذا فارغًا للإبقاء عليها، أو اكتب جديدة لاستبدالها.')
                                        : t('storefronts.mailbox_password_unset', 'لا توجد كلمة مرور محفوظة بعد.')
                                }
                            />
                            <TextField
                                label={t('storefronts.mailbox_from_address', 'عنوان المرسِل')}
                                dir="ltr"
                                type="email"
                                value={form.data.mailbox.from_address}
                                onChange={(value) => setMailbox('from_address', value)}
                                error={errors['mailbox.from_address'] ?? null}
                            />
                            <TextField
                                label={t('storefronts.mailbox_from_name', 'اسم المرسِل')}
                                value={form.data.mailbox.from_name}
                                onChange={(value) => setMailbox('from_name', value)}
                                error={errors['mailbox.from_name'] ?? null}
                            />
                            <TextField
                                label={t('storefronts.mailbox_daily_cap', 'الحد اليومي (رسائل)')}
                                dir="ltr"
                                type="number"
                                min={1}
                                value={form.data.mailbox.daily_cap}
                                onChange={(value) => setMailbox('daily_cap', value)}
                                error={errors['mailbox.daily_cap'] ?? null}
                                hint={t('storefronts.mailbox_daily_cap_hint', 'Hostinger يسمح بـ1,000 لكل صندوق في كل 24 ساعة متحركة؛ هذا العدّاد يُصفَّر عند منتصف الليل، فاترك هامشًا: 800.')}
                            />
                            <TextField
                                label={t('storefronts.mailbox_reserve', 'المحجوز لرسائل الطلبات والحسابات')}
                                dir="ltr"
                                type="number"
                                min={0}
                                value={form.data.mailbox.transactional_reserve}
                                onChange={(value) => setMailbox('transactional_reserve', value)}
                                error={errors['mailbox.transactional_reserve'] ?? null}
                                hint={t('storefronts.mailbox_reserve_hint', 'الرسائل الجماعية (تنبيهات التوفر، الحملات) تستخدم على الأكثر الحد − المحجوز يوميًا. 200 من 800.')}
                            />
                            <TextareaField
                                label={t('storefronts.mailbox_recipients', 'عناوين الفريق لإشعارات الطلبات')}
                                value={form.data.mailbox.order_recipients}
                                onChange={(value) => setMailbox('order_recipients', value)}
                                error={errors['mailbox.order_recipients'] ?? null}
                                rows={3}
                                className="sm:col-span-2"
                                hint={t('storefronts.mailbox_recipients_hint', 'عنوان في كل سطر. يصل كلًّا منها نسخة الإدارة من كل طلب على هذا المتجر. فارغ = لا يُخبَر أحد، ويسجَّل لكل طلب فشل ظاهر.')}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/*
                 * ── The money switch, in its own card with the warning beside it ─────────────
                 *
                 * Separated from the identity settings on purpose: this one does not change how the
                 * storefront LOOKS, it changes what customers are charged, and the consequence of
                 * turning it on too early is failed card orders rather than a cosmetic mistake.
                 *
                 * The warning is stated next to the control and not in a tooltip, because the
                 * person who needs it is exactly the person who has already decided to switch it on.
                 */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('storefronts.edit_promotions_section', 'العروض التي تغيّر المبلغ')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <SwitchField
                            label={t('storefronts.edit_money_rewards', 'اسمح بالخصومات والشحن المجاني على هذا المتجر')}
                            checked={form.data.money_rewards}
                            onChange={(checked) => form.setData('money_rewards', checked)}
                            error={errors.money_rewards ?? null}
                        />

                        <Alert tone="warning" title={t('storefronts.money_rewards_warning_title', 'لا تفعّل هذا قبل انتقال واجهة المتجر إلى الإصدار الثاني')}>
                            <p>
                                {t(
                                    'storefronts.money_rewards_warning_body',
                                    'أنواع المكافآت التي تخفض المبلغ — نسبة خصم، مبلغ خصم، شحن مجاني — لن تعمل حتى تنتقل واجهة هذا المتجر إلى الإصدار الثاني. الواجهة الحالية تحسب الإجمالي بنفسها ولا تعرف بالعرض، فترسل الرقم الكامل.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'storefronts.money_rewards_warning_cod',
                                    'في الدفع عند الاستلام: العميل يرى رقمًا ويُحصَّل منه رقم أقل.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'storefronts.money_rewards_warning_card',
                                    'في الدفع بالبطاقة (Paymob): يرسل النظام إلى المزوّد الإجمالي المخزَّن بعد الخصم، فيتطابق فحصُ المبلغ في ردّ المزوّد ولا يصل شيء إلى قائمة المطابقة — لكن العميل يُحصَّل منه أقل من الرقم الذي عرضته عليه الواجهة، وهو الفارق نفسه كما في الدفع عند الاستلام.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'storefronts.money_rewards_warning_safe',
                                    'حتى ذلك الحين اترك هذا مغلقًا. القواعد التي تمنح هدايا مجانية تعمل على كل المتاجر في كل الأحوال.',
                                )}
                            </p>
                        </Alert>
                    </CardContent>
                </Card>

                <FormActions
                    processing={form.processing}
                    dirty={form.isDirty}
                    onCancel={() => router.get('/manage/storefronts')}
                />
            </form>
        </ManageLayout>
    );
}
