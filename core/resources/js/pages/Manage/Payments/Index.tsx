import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { SelectField, TextField } from '@/components/form/TextField';
import { SwitchField } from '@/components/form/SwitchField';
import { ConfirmAction } from '@/components/manage/ConfirmAction';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ManageLayout from '@/layouts/ManageLayout';
import type { SharedProps } from '@/types';

/**
 * Payments — providers, methods and the merged order, for ONE storefront (study §3.9.7).
 *
 * ── The credential fields are WRITE-ONLY, and that is the whole design ──────────────────────
 *
 * No stored secret is ever sent to this page: the model hides the attribute, the controller sends
 * only booleans and key NAMES, and the inputs render empty with "leave blank to keep the current
 * value". There is no reveal control and there will not be one — a dashboard that can show a
 * secret is a dashboard that leaks it through a screenshot, a support session or a browser
 * extension. What the screen says instead is whether each key is SET and when the contract last
 * changed, which is everything a rotation actually needs to be checkable.
 *
 * `integration_id` is NOT a credential — the provider puts it in the signed callback payload and
 * the admin needs it to match a row in the merchant portal — so it is a normal text field.
 *
 * ── The order routes the money ──────────────────────────────────────────────────────────────
 *
 * Two contracts can both offer `card`. The customer sees ONE entry, and the row with the lowest
 * sort is the one that takes the money. So this ordering list is not cosmetic, and every row says
 * plainly whether it currently serves its method or which provider took it instead.
 */

interface MethodRow {
    id: number;
    method: string;
    integration_id: string | null;
    icon: string | null;
    is_enabled: boolean;
    sort: number;
    label: { ar: string; en: string };
}

interface ProviderRow {
    id: number;
    provider: string;
    is_enabled: boolean;
    /** Whether the registry has code for this key — a row can outlive its implementation. */
    implemented: boolean;
    credentials_set: boolean;
    credential_fields: string[];
    credential_keys_present: string[];
    credentials_complete: boolean;
    needs_credentials: boolean;
    methods: MethodRow[];
    updated_at: string | null;
}

interface MergedRow {
    id: number;
    method: string;
    label: string;
    icon: string | null;
    sort: number;
    provider: string;
    provider_id: number;
    is_enabled: boolean;
    provider_enabled: boolean;
    serves: boolean;
    served_by: string | null;
    label_mismatch: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    providers: ProviderRow[];
    merged: MergedRow[];
    customer_preview: Array<{ id: number; method: string; label: string; icon: string | null; sort: number }>;
    registry: Array<{ value: string; label: string; credential_fields: string[] }>;
    method_keys: string[];
}

const METHOD_LABEL: Record<string, string> = {
    card: 'بطاقة',
    valu: 'valU',
    tamara: 'Tamara',
    wallet: 'محفظة',
    fawry_code: 'كود فوري',
    cod: 'دفع عند الاستلام',
    whatsapp: 'واتساب',
};

/** The credential key names, in Arabic, so a rotation checklist reads like one. */
const FIELD_LABEL: Record<string, string> = {
    secret_key: 'Secret key',
    public_key: 'Public key',
    hmac_secret: 'HMAC secret',
};

export default function PaymentsIndex({ storefront, providers, merged, customer_preview, registry, method_keys }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [addingProvider, setAddingProvider] = useState(false);
    const [editing, setEditing] = useState<ProviderRow | null>(null);
    const [methodTarget, setMethodTarget] = useState<{ provider: ProviderRow; method: MethodRow | null } | null>(null);

    const base = `/manage/storefronts/${storefront.id}/payments`;

    // ── the ordering list ────────────────────────────────────────────────────────────────────
    // Up/down rather than drag-and-drop: it is keyboard-operable, works on a tablet, and posts the
    // whole id list in its new order — which is the contract the server validates ownership on.
    const move = (index: number, direction: -1 | 1) => {
        const next = [...merged];
        const swapWith = index + direction;
        if (swapWith < 0 || swapWith >= next.length) {
            return;
        }
        const moved = next[swapWith];
        const current = next[index];
        if (moved === undefined || current === undefined) {
            return;
        }
        next[swapWith] = current;
        next[index] = moved;

        router.post(`${base}/order`, { ids: next.map((row) => row.id) }, { preserveScroll: true });
    };

    return (
        <ManageLayout
            title={`وسائل الدفع — ${storefront.name}`}
            crumbs={[
                { label: 'الرئيسية', href: '/manage' },
                { label: 'المتاجر', href: '/manage/storefronts' },
                { label: storefront.name, href: `/manage/storefronts/${storefront.id}/edit` },
                { label: 'وسائل الدفع' },
            ]}
            actions={
                <Button size="sm" onClick={() => setAddingProvider(true)}>
                    أضف عقد مزوّد
                </Button>
            }
        >
            <div className="space-y-6">
                <Alert tone="info" title="المفاتيح تُكتب ولا تُقرأ">
                    لا تُرسل هذه الشاشة أي مفتاح محفوظ إلى المتصفح، ولا يوجد زر لإظهاره. تظهر الحقول فارغة دائمًا:
                    اكتب قيمة جديدة لتستبدل القديمة، واتركها فارغة ليبقى المحفوظ كما هو. ما تقوله الشاشة عن المفتاح هو
                    أنه «مضبوط» ومتى تغيّر العقد — لا أكثر.
                </Alert>

                {errors.ids ? <Alert tone="error">{errors.ids}</Alert> : null}
                {errors.provider ? <Alert tone="error">{errors.provider}</Alert> : null}

                {/* ── contracts ──────────────────────────────────────────────────────────── */}
                <div className="space-y-4">
                    {providers.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                لا يوجد عقد مزوّد لهذا المتجر بعد. «دفع عند الاستلام» و«واتساب» يحتاجان عقدًا بلا مفاتيح
                                (المزوّد <code dir="ltr">offline</code>).
                            </CardContent>
                        </Card>
                    ) : null}

                    {providers.map((provider) => (
                        <Card key={provider.id}>
                            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                <CardTitle className="flex flex-wrap items-center gap-2">
                                    <span dir="ltr">{provider.provider}</span>
                                    <Badge variant={provider.is_enabled ? 'success' : 'neutral'}>
                                        {provider.is_enabled ? 'مفعَّل' : 'موقوف'}
                                    </Badge>
                                    {!provider.implemented ? (
                                        <Badge variant="destructive" title="لا يوجد كود لهذا المزوّد في السجل">
                                            غير مُنفَّذ
                                        </Badge>
                                    ) : null}
                                    {provider.needs_credentials ? (
                                        <Badge variant={provider.credentials_complete ? 'success' : 'warning'}>
                                            {provider.credentials_complete
                                                ? 'المفاتيح مضبوطة'
                                                : provider.credentials_set
                                                  ? 'مفاتيح ناقصة'
                                                  : 'بلا مفاتيح'}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">لا يحتاج مفاتيح</Badge>
                                    )}
                                </CardTitle>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setEditing(provider)}>
                                        المفاتيح والتفعيل
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => setMethodTarget({ provider, method: null })}>
                                        أضف طريقة
                                    </Button>
                                    <ConfirmAction
                                        title="حذف العقد"
                                        confirmLabel="احذف العقد وطرقه"
                                        consequence={
                                            <p>
                                                سيُحذف عقد <span dir="ltr">{provider.provider}</span> مع{' '}
                                                {provider.methods.length} طريقة دفع تحته، وستتوقف هذه الطرق عن الظهور
                                                للعملاء فورًا. محاولات الدفع المسجّلة لا تُحذف — تبقى في سجل المحاولات
                                                مع اسم المزوّد.
                                            </p>
                                        }
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                احذف
                                            </Button>
                                        }
                                        onConfirm={() =>
                                            router.delete(`${base}/providers/${provider.id}`, { preserveScroll: true })
                                        }
                                    />
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {provider.needs_credentials ? (
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        {provider.credential_fields.map((field) => (
                                            <Badge
                                                key={field}
                                                variant={provider.credential_keys_present.includes(field) ? 'success' : 'warning'}
                                            >
                                                {FIELD_LABEL[field] ?? field}
                                                {provider.credential_keys_present.includes(field) ? ' ✓' : ' —'}
                                            </Badge>
                                        ))}
                                        {provider.updated_at !== null ? (
                                            <span className="text-muted-foreground" dir="ltr">
                                                updated {provider.updated_at}
                                            </span>
                                        ) : null}
                                    </div>
                                ) : null}

                                {provider.methods.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">لا توجد طرق دفع تحت هذا العقد.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {provider.methods.map((method) => (
                                            <div
                                                key={method.id}
                                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                                            >
                                                <div className="space-y-0.5">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                        {method.label.ar || (METHOD_LABEL[method.method] ?? method.method)}
                                                        <Badge variant="outline" className="font-mono">
                                                            <span dir="ltr">{method.method}</span>
                                                        </Badge>
                                                        {!method.is_enabled ? <Badge variant="neutral">موقوفة</Badge> : null}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground" dir="ltr">
                                                        sort {method.sort}
                                                        {method.integration_id !== null ? ` · integration ${method.integration_id}` : ''}
                                                        {method.label.en !== '' ? ` · ${method.label.en}` : ''}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setMethodTarget({ provider, method })}
                                                    >
                                                        عدّل
                                                    </Button>
                                                    <ConfirmAction
                                                        title="حذف طريقة الدفع"
                                                        confirmLabel="احذف الطريقة"
                                                        consequence={
                                                            <p>
                                                                ستتوقف «{method.label.ar || method.method}» عن الظهور للعملاء
                                                                في هذا المتجر. إن كانت طريقة أخرى بنفس المفتاح تحت عقد آخر،
                                                                فستصبح هي المستقبِلة للأموال.
                                                            </p>
                                                        }
                                                        trigger={
                                                            <Button variant="outline" size="sm">
                                                                احذف
                                                            </Button>
                                                        }
                                                        onConfirm={() =>
                                                            router.delete(`${base}/methods/${method.id}`, { preserveScroll: true })
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* ── the merged order ───────────────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>الترتيب الموحَّد</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            ترتيب واحد يعبر العقود. عند تكرار نفس المفتاح تحت عقدين، الأعلى في هذه القائمة هو الذي
                            يستقبل الأموال — فالترتيب هنا قرار توجيه لا قرار شكل.
                        </p>

                        {merged.length === 0 ? (
                            <p className="text-sm text-muted-foreground">لا توجد طرق دفع بعد.</p>
                        ) : (
                            <div className="space-y-2">
                                {merged.map((row, index) => (
                                    <div
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="w-6 text-center text-xs text-muted-foreground" dir="ltr">
                                                {index + 1}
                                            </span>
                                            <span className="text-sm font-medium">{row.label || (METHOD_LABEL[row.method] ?? row.method)}</span>
                                            <Badge variant="outline" className="font-mono">
                                                <span dir="ltr">{row.method}</span>
                                            </Badge>
                                            <Badge variant="neutral">
                                                <span dir="ltr">{row.provider}</span>
                                            </Badge>

                                            {/* Who takes the money for this key. Never inferred from
                                                position by the reader — the server says it. */}
                                            {row.serves ? (
                                                <Badge variant="success">تستقبل الأموال</Badge>
                                            ) : row.served_by !== null ? (
                                                <Badge variant="warning" title="مفتاح مكرَّر: عقد آخر يستقبل الأموال">
                                                    مغطّاة بـ <span dir="ltr">{row.served_by}</span>
                                                </Badge>
                                            ) : (
                                                <Badge variant="neutral">
                                                    {!row.is_enabled || !row.provider_enabled ? 'موقوفة' : 'لا تستقبل'}
                                                </Badge>
                                            )}

                                            {row.label_mismatch ? (
                                                <Badge
                                                    variant="warning"
                                                    title="عقدان بنفس المفتاح واسمان مختلفان: تغيير الترتيب يغيّر النص الذي يراه العميل"
                                                >
                                                    اسمان مختلفان
                                                </Badge>
                                            ) : null}
                                        </div>

                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={index === 0}
                                                aria-label="أعلى"
                                                onClick={() => move(index, -1)}
                                            >
                                                ▲
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={index === merged.length - 1}
                                                aria-label="أسفل"
                                                onClick={() => move(index, 1)}
                                            >
                                                ▼
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── what the customer will see ─────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>ما سيراه العميل ({customer_preview.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {customer_preview.length === 0 ? (
                            <Alert tone="warning" title="لن يرى العميل أي وسيلة دفع">
                                لا توجد طريقة مفعَّلة تحت عقد مفعَّل. الطلبات لن تجد وسيلة سداد في هذا المتجر.
                            </Alert>
                        ) : (
                            <ol className="space-y-2">
                                {customer_preview.map((row, index) => (
                                    <li key={row.id} className="flex items-center gap-3 text-sm">
                                        <span className="w-5 text-center text-xs text-muted-foreground" dir="ltr">
                                            {index + 1}
                                        </span>
                                        <span className="font-medium">{row.label || (METHOD_LABEL[row.method] ?? row.method)}</span>
                                        <span className="text-xs text-muted-foreground font-mono" dir="ltr">
                                            {row.method}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <p className="pt-3 text-xs text-muted-foreground">
                            مفتاح واحد يظهر مرة واحدة فقط، حتى لو كان متاحًا تحت عقدين.
                        </p>
                    </CardContent>
                </Card>
            </div>

            {addingProvider ? (
                <ProviderDialog
                    base={base}
                    registry={registry}
                    provider={null}
                    onClose={() => setAddingProvider(false)}
                />
            ) : null}

            {editing !== null ? (
                <ProviderDialog
                    base={base}
                    registry={registry}
                    provider={editing}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            {methodTarget !== null ? (
                <MethodDialog
                    base={base}
                    methodKeys={method_keys}
                    provider={methodTarget.provider}
                    method={methodTarget.method}
                    onClose={() => setMethodTarget(null)}
                />
            ) : null}
        </ManageLayout>
    );
}

/**
 * Add or edit a contract.
 *
 * The credential inputs are always EMPTY on open — there is nothing to prefill them with, by
 * design — and `autoComplete="new-password"` keeps a password manager from filling them with
 * something unrelated.
 */
function ProviderDialog({
    base,
    registry,
    provider,
    onClose,
}: {
    base: string;
    registry: Array<{ value: string; label: string; credential_fields: string[] }>;
    provider: ProviderRow | null;
    onClose: () => void;
}) {
    const { errors } = usePage<SharedProps>().props;
    const [key, setKey] = useState(provider?.provider ?? (registry[0]?.value ?? ''));
    const [enabled, setEnabled] = useState(provider?.is_enabled ?? true);
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const fields = provider !== null
        ? provider.credential_fields
        : (registry.find((entry) => entry.value === key)?.credential_fields ?? []);

    const submit = () => {
        setBusy(true);
        const payload = { provider: key, is_enabled: enabled, credentials };
        const options = { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) };

        if (provider === null) {
            router.post(`${base}/providers`, payload, options);
        } else {
            router.put(`${base}/providers/${provider.id}`, payload, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent title={provider === null ? 'عقد مزوّد جديد' : `عقد ${provider.provider}`} className="space-y-4">
                {provider === null ? (
                    <SelectField
                        label="المزوّد"
                        required
                        value={key}
                        onChange={setKey}
                        options={registry.map((entry) => ({ value: entry.value, label: entry.label }))}
                        error={errors.provider ?? null}
                        hint="العقد واحد لكل مزوّد لكل متجر."
                    />
                ) : null}

                <SwitchField label="مفعَّل" checked={enabled} onChange={setEnabled} />

                {fields.length === 0 ? (
                    <p className="text-sm text-muted-foreground">هذا المزوّد لا يحتاج مفاتيح.</p>
                ) : (
                    <div className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            اتركه فارغًا ليبقى المحفوظ كما هو. لا تُعرض القيم المحفوظة هنا ولا في أي مكان آخر.
                        </p>
                        {fields.map((field) => (
                            <div key={field} className="space-y-1.5">
                                <Label htmlFor={`cred-${field}`}>{FIELD_LABEL[field] ?? field}</Label>
                                <Input
                                    id={`cred-${field}`}
                                    type="password"
                                    dir="ltr"
                                    autoComplete="new-password"
                                    value={credentials[field] ?? ''}
                                    placeholder={
                                        provider !== null && provider.credential_keys_present.includes(field)
                                            ? 'مضبوط — اتركه فارغًا للإبقاء عليه'
                                            : 'غير مضبوط'
                                    }
                                    onChange={(event) =>
                                        setCredentials((current) => ({ ...current, [field]: event.target.value }))
                                    }
                                />
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        إلغاء
                    </Button>
                    <Button type="button" disabled={busy} onClick={submit}>
                        احفظ
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Add or edit one method under a contract. Labels are per row, so both locales live here. */
function MethodDialog({
    base,
    methodKeys,
    provider,
    method,
    onClose,
}: {
    base: string;
    methodKeys: string[];
    provider: ProviderRow;
    method: MethodRow | null;
    onClose: () => void;
}) {
    const { errors } = usePage<SharedProps>().props;
    const form = useForm({
        storefront_payment_provider_id: provider.id,
        method: method?.method ?? (methodKeys[0] ?? 'card'),
        integration_id: method?.integration_id ?? '',
        icon: method?.icon ?? '',
        is_enabled: method?.is_enabled ?? true,
        sort: String(method?.sort ?? provider.methods.length),
        label: { ar: method?.label.ar ?? '', en: method?.label.en ?? '' },
    });

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (method === null) {
            form.post(`${base}/methods`, options);
        } else {
            form.put(`${base}/methods/${method.id}`, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent
                title={method === null ? `طريقة جديدة تحت ${provider.provider}` : `تعديل ${method.method}`}
                className="space-y-4"
            >
                <SelectField
                    label="المفتاح"
                    required
                    value={form.data.method}
                    onChange={(value) => form.setData('method', value)}
                    options={methodKeys.map((key) => ({ value: key, label: `${METHOD_LABEL[key] ?? key} (${key})` }))}
                    error={errors.method ?? null}
                    hint="المفتاح ثابت في الكود وليس في قاعدة البيانات."
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label="الاسم (عربي)"
                        required
                        value={form.data.label.ar}
                        onChange={(value) => form.setData('label', { ...form.data.label, ar: value })}
                        error={errors['label.ar'] ?? null}
                        hint="هذا ما يقرأه العميل."
                    />
                    <TextField
                        label="الاسم (إنجليزي)"
                        dir="ltr"
                        value={form.data.label.en}
                        onChange={(value) => form.setData('label', { ...form.data.label, en: value })}
                        error={errors['label.en'] ?? null}
                    />
                    <TextField
                        label="Integration id"
                        dir="ltr"
                        value={form.data.integration_id}
                        onChange={(value) => form.setData('integration_id', value)}
                        error={errors.integration_id ?? null}
                        hint="ليس مفتاحًا سريًّا: يأتي في حمولة الرد الموقَّعة ويُستخدم لمطابقة العملية في لوحة المزوّد."
                    />
                    <TextField
                        label="الأيقونة"
                        dir="ltr"
                        value={form.data.icon}
                        onChange={(value) => form.setData('icon', value)}
                        error={errors.icon ?? null}
                    />
                    <TextField
                        label="الترتيب"
                        type="number"
                        dir="ltr"
                        min={0}
                        value={form.data.sort}
                        onChange={(value) => form.setData('sort', value)}
                        error={errors.sort ?? null}
                        hint="الأقل يفوز عند تكرار المفتاح."
                    />
                    <SwitchField
                        label="مفعَّلة"
                        checked={form.data.is_enabled}
                        onChange={(checked) => form.setData('is_enabled', checked)}
                    />
                </div>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        إلغاء
                    </Button>
                    <Button type="button" disabled={form.processing} onClick={submit}>
                        احفظ
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
