import { router, useForm, usePage } from '@inertiajs/react';

import { FormActions } from '@/components/form/FormActions';
import { SelectField, TextField } from '@/components/form/TextField';
import { SwitchField } from '@/components/form/SwitchField';
import { useDirtyGuard } from '@/components/form/useDirtyGuard';
import ManageLayout from '@/layouts/ManageLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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
}

/**
 * The worked example of the FORM system: `useForm` for state, field components for chrome and
 * error display, `useDirtyGuard` for the navigate-away prompt, `FormActions` for save/cancel.
 *
 * `code` is shown read-only on purpose — it is the storefront's identity in every URL, cache key
 * and compat payload, so renaming it is a migration and not a form field (see StorefrontController).
 */
export default function StorefrontEdit({ storefront, locale_options }: { storefront: StorefrontForm; locale_options: Array<{ value: string; label: string }> }) {
    const { errors } = usePage<SharedProps>().props;
    const form = useForm({
        name: storefront.name,
        domain: storefront.domain ?? '',
        locales: storefront.locales,
        default_locale: storefront.default_locale,
        currency: storefront.currency,
        is_active: storefront.is_active,
    });

    useDirtyGuard(form.isDirty);

    const toggleLocale = (locale: string, on: boolean) => {
        const next = on ? [...form.data.locales, locale] : form.data.locales.filter((item) => item !== locale);
        form.setData('locales', next);
    };

    return (
        <ManageLayout
            title={`إعدادات ${storefront.name}`}
            crumbs={[
                { label: 'الرئيسية', href: '/manage' },
                { label: 'المتاجر', href: '/manage/storefronts' },
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
                        <CardTitle>الهوية</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        <TextField label="الرمز" value={storefront.code} onChange={() => undefined} disabled dir="ltr" hint="ثابت: يُستخدم في مسارات الـAPI ومفاتيح الكاش." />
                        <TextField label="الاسم" required value={form.data.name} onChange={(value) => form.setData('name', value)} error={errors.name ?? null} />
                        <TextField
                            label="النطاق"
                            dir="ltr"
                            value={form.data.domain}
                            onChange={(value) => form.setData('domain', value)}
                            error={errors.domain ?? null}
                            placeholder="example.com"
                            hint="بدون بروتوكول."
                        />
                        <TextField
                            label="العملة"
                            required
                            dir="ltr"
                            value={form.data.currency}
                            onChange={(value) => form.setData('currency', value.toUpperCase().slice(0, 3))}
                            error={errors.currency ?? null}
                            hint="رمز ISO من ثلاثة أحرف، مثل EGP."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>اللغات والعرض</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="space-y-2">
                            <Label required>اللغات المفعّلة</Label>
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
                            label="اللغة الافتراضية"
                            required
                            value={form.data.default_locale}
                            onChange={(value) => form.setData('default_locale', value)}
                            options={locale_options.filter((option) => form.data.locales.includes(option.value))}
                            error={errors.default_locale ?? null}
                            hint="يجب أن تكون من اللغات المفعّلة."
                        />

                        <SwitchField
                            label="المتجر مفعّل"
                            checked={form.data.is_active}
                            onChange={(checked) => form.setData('is_active', checked)}
                            error={errors.is_active ?? null}
                        />
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
