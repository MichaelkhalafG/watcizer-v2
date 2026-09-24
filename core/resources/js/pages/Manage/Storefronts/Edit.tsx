import { router, useForm, usePage } from '@inertiajs/react';

import { FormActions } from '@/components/form/FormActions';
import { SelectField, TextField } from '@/components/form/TextField';
import { SwitchField } from '@/components/form/SwitchField';
import { useDirtyGuard } from '@/components/form/useDirtyGuard';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';

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
}

/**
 * The worked example of the FORM system: `useForm` for state, field components for chrome and
 * error display, `useDirtyGuard` for the navigate-away prompt, `FormActions` for save/cancel.
 *
 * `code` is shown read-only on purpose — it is the storefront's identity in every URL, cache key
 * and compat payload, so renaming it is a migration and not a form field (see StorefrontController).
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
    });

    useDirtyGuard(form.isDirty);

    const toggleLocale = (locale: string, on: boolean) => {
        const next = on ? [...form.data.locales, locale] : form.data.locales.filter((item) => item !== locale);
        form.setData('locales', next);
    };

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
                                    'في الدفع بالبطاقة (Paymob): الواجهة تطلب من المزوّد المبلغ الكامل، ثم يقارن فحصُ المبلغ في ردّ المزوّد بإجمالي الطلب المخفَّض ولا يتطابقان — فيُسجَّل الطلب كمحاولة فاشلة ولا يُدفع ولا يُلغى، ويحتاج تدخّلًا يدويًا. كل طلب بطاقة عليه خصم سينتهي في قائمة المطابقة.',
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
