import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { SelectField, TextField } from '@/components/form/TextField';
import { ConfirmAction } from '@/components/manage/ConfirmAction';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import type { SharedProps } from '@/types';

/**
 * Users & roles (wave 4C, admin only).
 *
 * ── GRANTS ONLY, and the absence of an "add user" button is the feature ──────────────────────
 *
 * `users` is a SHARED legacy table. Core does not create, rename, disable or password-reset an
 * account (AGENTS §3), so this screen writes exactly one table of its own — `core_user_roles` —
 * and everything it offers is "find an existing account, give it an ability here".
 *
 * An account is created where accounts are created: the storefront's registration, or the legacy
 * dashboard. The search box exists because granting requires finding, and it is capped at 20
 * results because the same table holds every customer and a dashboard has no business paging
 * through them.
 *
 * ── The command is not retired ───────────────────────────────────────────────────────────────
 *
 * `php artisan manage:role` stays the BOOTSTRAP path: the first grant on a fresh database cannot
 * be made from a screen that requires a grant to open. Rehearsal #3 needed exactly that, so the
 * screen says so rather than letting someone discover it locked out.
 */

interface Grant {
    id: number;
    user_id: number;
    email: string | null;
    name: string | null;
    /** The LEGACY admin flag, shown for contrast: core reads grants, never `users.type`. */
    legacy_type: string | null;
    role: string;
    storefront_id: number | null;
    storefront: string | null;
    granted_by: string | null;
    created_at: string | null;
}

interface Found {
    id: number;
    email: string | null;
    name: string | null;
    legacy_type: string | null;
    has_grant: boolean;
}

interface Props {
    grants: Grant[];
    search: { term: string; results: Found[]; searched: boolean };
    roles: Array<{ value: string; label: string }>;
    storefronts: Array<{ value: string; label: string }>;
    current_user_id: number;
}

/** Keyed by the enum's own VALUES (`App\Domain\Access\Role`), which are snake_case. */
const ROLE_LABEL: Record<string, string> = {
    admin: 'مدير',
    data_entry: 'إدخال بيانات',
};

export default function UsersIndex({ grants, search, roles, storefronts, current_user_id }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [term, setTerm] = useState(search.term);

    const form = useForm({
        email: '',
        role: roles[0]?.value ?? 'data-entry',
        storefront_id: '',
    });

    const runSearch = () => {
        router.get('/manage/users', term === '' ? {} : { q: term }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const unscopedAdmins = grants.filter((grant) => grant.role === 'admin' && grant.storefront_id === null);

    return (
        <ManageLayout title="المستخدمون والصلاحيات" crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'المستخدمون والصلاحيات' }]}>
            <div className="space-y-6">
                <Alert tone="info" title="هذه الشاشة تمنح الصلاحيات ولا تُنشئ حسابات">
                    جدول الحسابات مشترك مع المتجر والداشبورد القديم، فلا تُنشئ اللوحة حسابًا ولا تعدّله ولا تعيد تعيين
                    كلمة مروره. الحساب يُنشأ من المتجر أو من الداشبورد القديم، ثم يُمنح من هنا. وللبدء على قاعدة بيانات
                    جديدة يبقى الأمر <code dir="ltr">php artisan manage:role</code> هو الطريق الوحيد — لا يمكن منح أول
                    صلاحية من شاشة تحتاج صلاحية لفتحها.
                </Alert>

                {errors.grant ? <Alert tone="error">{errors.grant}</Alert> : null}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>منح صلاحية</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post('/manage/users/grants', {
                                        preserveScroll: true,
                                        onSuccess: () => form.setData('email', ''),
                                    });
                                }}
                            >
                                <TextField
                                    label="بريد الحساب"
                                    required
                                    dir="ltr"
                                    value={form.data.email}
                                    onChange={(value) => form.setData('email', value)}
                                    error={errors.email ?? null}
                                    hint="يجب أن يكون الحساب موجودًا بالفعل."
                                />
                                <SelectField
                                    label="الصلاحية"
                                    required
                                    value={form.data.role}
                                    onChange={(value) => form.setData('role', value)}
                                    options={roles}
                                    error={errors.role ?? null}
                                />
                                <SelectField
                                    label="النطاق"
                                    value={form.data.storefront_id}
                                    onChange={(value) => form.setData('storefront_id', value)}
                                    options={storefronts}
                                    error={errors.storefront_id ?? null}
                                    hint="«كل المتاجر» تعني صلاحية غير مقيّدة بمتجر."
                                />
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={form.processing}>
                                        امنح الصلاحية
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>ابحث عن حساب</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <form
                                className="flex flex-wrap gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    runSearch();
                                }}
                            >
                                <Input
                                    aria-label="بحث بالبريد أو الاسم"
                                    dir="ltr"
                                    className="min-w-[12rem] flex-1"
                                    value={term}
                                    onChange={(event) => setTerm(event.target.value)}
                                    placeholder="email or name"
                                />
                                <Button type="submit" variant="outline">
                                    ابحث
                                </Button>
                            </form>

                            {!search.searched ? (
                                <p className="text-sm text-muted-foreground">
                                    البحث لا يعرض الجدول كاملًا: هو يحتوي عملاء المتجر أيضًا. اكتب بريدًا أو اسمًا —
                                    أقصى عشرين نتيجة.
                                </p>
                            ) : search.results.length === 0 ? (
                                <p className="text-sm text-muted-foreground">لا نتائج. الحساب يُنشأ من المتجر أو من الداشبورد القديم.</p>
                            ) : (
                                <div className="space-y-2">
                                    {search.results.map((found) => (
                                        <div
                                            key={found.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3"
                                        >
                                            <div className="space-y-0.5">
                                                <div className="text-sm font-medium" dir="ltr">
                                                    {found.email ?? '—'}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {found.name ?? '—'}
                                                    {found.legacy_type !== null ? ` · ${found.legacy_type}` : ''}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {found.has_grant ? <Badge variant="neutral">له صلاحية</Badge> : null}
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => form.setData('email', found.email ?? '')}
                                                >
                                                    استخدم هذا البريد
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>الصلاحيات الممنوحة ({grants.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>الحساب</TableHead>
                                    <TableHead>الصلاحية</TableHead>
                                    <TableHead>النطاق</TableHead>
                                    <TableHead>منحها</TableHead>
                                    <TableHead>التاريخ</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grants.map((grant) => {
                                    const isSelfAdmin = grant.role === 'admin' && grant.user_id === current_user_id;
                                    const isLastAdmin =
                                        grant.role === 'admin' && grant.storefront_id === null && unscopedAdmins.length <= 1;
                                    const blocked = isSelfAdmin || isLastAdmin;

                                    return (
                                        <TableRow key={grant.id}>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    <div className="text-sm font-medium" dir="ltr">
                                                        {grant.email ?? `#${grant.user_id}`}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {grant.name ?? '—'}
                                                        {/* The legacy flag means nothing to core's gates. Showing it
                                                            next to the grant ends the "but they are SuperAdmin"
                                                            conversation before it starts. */}
                                                        {grant.legacy_type !== null ? (
                                                            <span title="علم الداشبورد القديم — لا تقرأه اللوحة الجديدة">
                                                                {' '}
                                                                · legacy: {grant.legacy_type}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={grant.role === 'admin' ? 'default' : 'neutral'}>
                                                    {ROLE_LABEL[grant.role] ?? grant.role}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {grant.storefront_id === null ? 'كل المتاجر' : (grant.storefront ?? `#${grant.storefront_id}`)}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground" dir="ltr">
                                                {grant.granted_by ?? 'command'}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground" dir="ltr">
                                                {grant.created_at ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <ConfirmAction
                                                    title="سحب الصلاحية"
                                                    confirmLabel="اسحب الصلاحية"
                                                    disabled={blocked}
                                                    consequence={
                                                        <p>
                                                            سيفقد <span dir="ltr">{grant.email ?? `#${grant.user_id}`}</span> صلاحية
                                                            «{ROLE_LABEL[grant.role] ?? grant.role}»{' '}
                                                            {grant.storefront_id === null
                                                                ? 'على كل المتاجر'
                                                                : `على ${grant.storefront ?? `#${grant.storefront_id}`}`}
                                                            . الحساب نفسه لا يتأثر — يبقى قادرًا على الدخول إلى المتجر كما كان.
                                                        </p>
                                                    }
                                                    trigger={
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={blocked}
                                                            title={
                                                                isSelfAdmin
                                                                    ? 'لا يمكنك سحب صلاحية المدير من نفسك — اطلب من مدير آخر.'
                                                                    : isLastAdmin
                                                                      ? 'هذه آخر صلاحية مدير عامة: سحبها يترك اللوحة بلا مدير.'
                                                                      : undefined
                                                            }
                                                        >
                                                            اسحب
                                                        </Button>
                                                    }
                                                    onConfirm={() =>
                                                        router.delete(`/manage/users/grants/${grant.id}`, { preserveScroll: true })
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {grants.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-6 text-center text-sm text-muted-foreground">
                                            لا توجد صلاحيات ممنوحة.
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </ManageLayout>
    );
}
