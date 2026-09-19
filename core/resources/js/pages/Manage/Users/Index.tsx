import { router, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";

import { SelectField, TextField } from "@/components/form/TextField";
import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import ManageLayout from "@/layouts/ManageLayout";
import type { SharedProps } from "@/types";
import { Ltr, Num } from "@/components/ui/bidi";
import { ExportLink } from "@/components/table/ExportLink";
import { useT } from "@/lib/i18n";

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

export default function UsersIndex({
    grants,
    search,
    roles,
    storefronts,
    current_user_id,
}: Props) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const [term, setTerm] = useState(search.term);

    /**
     * Keyed by the enum's own VALUES (`App\Domain\Access\Role`), which are snake_case. Built inside
     * the component because each label goes through `t()`, and a hook cannot run at module level.
     */
    const roleLabel: Record<string, string> = {
        admin: t("users.role_admin", "مدير النظام"),
        data_entry: t("users.role_data_entry", "إدخال بيانات"),
    };

    const form = useForm({
        email: "",
        /*
         * `data_entry`, named rather than taken from `roles[0]` (J-2).
         *
         * Position is not a promise. Reading the default off the first option made the safest
         * grant depend on the ORDER of a list on the server, which is exactly how this became
         * "administrator" in the first place — and it would become administrator again the moment
         * somebody reordered that array for an unrelated reason.
         */
        role: "data_entry",
        storefront_id: "",
    });

    const runSearch = () => {
        router.get("/manage/users", term === "" ? {} : { q: term }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const unscopedAdmins = grants.filter(
        (grant) => grant.role === "admin" && grant.storefront_id === null,
    );

    return (
        <ManageLayout
            title={t("users.title", "المستخدمون والصلاحيات")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("users.title", "المستخدمون والصلاحيات") },
            ]}
            /* The GRANTS, never the account search — see UserRoleController for why. */
            actions={
                <ExportLink
                    count={grants.length}
                    label={t("users.export_grants", "تصدير الصلاحيات")}
                />
            }
        >
            <div className="space-y-6">
                <Alert
                    tone="info"
                    title={t(
                        "users.grants_only_title",
                        "هذه الشاشة تمنح الصلاحيات ولا تُنشئ حسابات",
                    )}
                >
                    {/* D-18: the sentence ended on `php artisan manage:role`. That command is the
                        bootstrap path for a FRESH database — a developer's first grant, on a
                        machine with no administrator yet. It is not something anybody reading this
                        screen will ever type, because reaching this screen already requires the
                        grant it would create. The fact worth keeping is the first half: this
                        dashboard does not own accounts. */}
                    {t(
                        "users.grants_only_body",
                        "جدول الحسابات مشترك مع المتجر والداشبورد القديم، فلا تُنشئ اللوحة حسابًا ولا تعدّله ولا تعيد تعيين كلمة مروره. الحساب يُنشأ من المتجر أو من الداشبورد القديم، ثم تُمنح صلاحياته من هنا.",
                    )}
                </Alert>

                {errors.grant ? (
                    <Alert tone="error">{errors.grant}</Alert>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("users.grant_title", "منح صلاحية")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post("/manage/users/grants", {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            form.setData("email", ""),
                                    });
                                }}
                            >
                                <TextField
                                    label={t(
                                        "users.account_email",
                                        "بريد الحساب",
                                    )}
                                    required
                                    dir="ltr"
                                    value={form.data.email}
                                    onChange={(value) =>
                                        form.setData("email", value)
                                    }
                                    error={errors.email ?? null}
                                    hint={t(
                                        "users.account_must_exist",
                                        "يجب أن يكون الحساب موجودًا بالفعل.",
                                    )}
                                />
                                <SelectField
                                    label={t("users.role", "الصلاحية")}
                                    required
                                    hint={t(
                                        "users.role_hint",
                                        "«مدير» يفتح كل شيء: المدفوعات وإعدادات المتجر والصلاحيات وإلغاء الطلبات. امنحه عن قصد لا بالسهو.",
                                    )}
                                    value={form.data.role}
                                    onChange={(value) =>
                                        form.setData("role", value)
                                    }
                                    options={roles}
                                    error={errors.role ?? null}
                                />
                                <SelectField
                                    label={t("users.scope", "النطاق")}
                                    value={form.data.storefront_id}
                                    onChange={(value) =>
                                        form.setData("storefront_id", value)
                                    }
                                    options={storefronts}
                                    error={errors.storefront_id ?? null}
                                    hint={t(
                                        "users.scope_hint",
                                        "«كل المتاجر» تعني صلاحية غير مقيّدة بمتجر.",
                                    )}
                                />
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {t(
                                            "users.grant_submit",
                                            "امنح الصلاحية",
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("users.search_title", "ابحث عن حساب")}
                            </CardTitle>
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
                                    aria-label={t(
                                        "users.search_aria",
                                        "بحث بالبريد أو الاسم",
                                    )}
                                    dir="ltr"
                                    className="min-w-[12rem] flex-1"
                                    value={term}
                                    onChange={(event) =>
                                        setTerm(event.target.value)
                                    }
                                    placeholder={t('users.search_placeholder', 'بريد أو اسم')}
                                />
                                <Button type="submit" variant="outline">
                                    {t("common.search", "ابحث")}
                                </Button>
                            </form>

                            {!search.searched ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        "users.search_hint",
                                        "البحث لا يعرض الجدول كاملًا: هو يحتوي عملاء المتجر أيضًا. اكتب بريدًا أو اسمًا — أقصى عشرين نتيجة.",
                                    )}
                                </p>
                            ) : search.results.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        "users.no_results",
                                        "لا نتائج. الحساب يُنشأ من المتجر أو من الداشبورد القديم.",
                                    )}
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {search.results.map((found) => (
                                        <div
                                            key={found.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3"
                                        >
                                            <div className="space-y-0.5">
                                                <div className="text-sm font-medium">
                                                    <Ltr>
                                                        {found.email ?? "—"}
                                                    </Ltr>
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {found.name ?? "—"}
                                                    {/* Item 10: the flag was orphaned — a bare
                                                        `SuperAdmin` with nothing saying what it
                                                        was. The VALUE is real legacy data and
                                                        stays; only the label is new. */}
                                                    {found.legacy_type !== null
                                                        ? ` · ${t("users.legacy_type", "في النظام القديم")}: ${found.legacy_type}`
                                                        : ""}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {found.has_grant ? (
                                                    <Badge variant="neutral">
                                                        {t(
                                                            "users.has_grant",
                                                            "له صلاحية",
                                                        )}
                                                    </Badge>
                                                ) : null}
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        form.setData(
                                                            "email",
                                                            found.email ?? "",
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        "users.use_this_email",
                                                        "استخدم هذا البريد",
                                                    )}
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
                        <CardTitle>
                            {t(
                                "users.granted_title",
                                "الصلاحيات الممنوحة (:count)",
                                { count: grants.length },
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t("users.account", "الحساب")}
                                    </TableHead>
                                    <TableHead>
                                        {t("users.role", "الصلاحية")}
                                    </TableHead>
                                    <TableHead>
                                        {t("users.scope", "النطاق")}
                                    </TableHead>
                                    <TableHead>
                                        {t("users.granted_by", "منحها")}
                                    </TableHead>
                                    <TableHead>
                                        {t("common.date", "التاريخ")}
                                    </TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grants.map((grant) => {
                                    const isSelfAdmin =
                                        grant.role === "admin" &&
                                        grant.user_id === current_user_id;
                                    const isLastAdmin =
                                        grant.role === "admin" &&
                                        grant.storefront_id === null &&
                                        unscopedAdmins.length <= 1;
                                    const blocked = isSelfAdmin || isLastAdmin;

                                    return (
                                        <TableRow key={grant.id}>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    <div className="text-sm font-medium">
                                                        <Ltr>
                                                            {grant.email ??
                                                                `#${grant.user_id}`}
                                                        </Ltr>
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {grant.name ?? "—"}
                                                        {/* The legacy flag means nothing to core's gates. Showing it
                                                            next to the grant ends the "but they are SuperAdmin"
                                                            conversation before it starts. */}
                                                        {grant.legacy_type !==
                                                        null ? (
                                                            <span
                                                                title={t(
                                                                    "users.legacy_flag_hint",
                                                                    "علم الداشبورد القديم — لا تقرأه اللوحة الجديدة",
                                                                )}
                                                            >
                                                                {" "}
                                                                ·{" "}
                                                                {t("users.legacy_type", "في النظام القديم")}
                                                                :{" "}
                                                                {
                                                                    grant.legacy_type
                                                                }
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        grant.role === "admin"
                                                            ? "default"
                                                            : "neutral"
                                                    }
                                                >
                                                    {roleLabel[grant.role] ??
                                                        grant.role}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {grant.storefront_id === null
                                                    ? t(
                                                          "common.all_storefronts",
                                                          "كل المتاجر",
                                                      )
                                                    : (grant.storefront ??
                                                      `#${grant.storefront_id}`)}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                <Ltr>
                                                    {grant.granted_by ??
                                                        t("users.granted_by_command", "من سطر الأوامر")}
                                                </Ltr>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                <Num>{grant.created_at ?? "—"}</Num>
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <ConfirmAction
                                                    title={t(
                                                        "users.revoke_title",
                                                        "سحب الصلاحية",
                                                    )}
                                                    confirmLabel={t(
                                                        "users.revoke_confirm",
                                                        "اسحب الصلاحية",
                                                    )}
                                                    disabled={blocked}
                                                    consequence={
                                                        <p>
                                                            <span dir="ltr">
                                                                {grant.email ??
                                                                    `#${grant.user_id}`}
                                                            </span>{" "}
                                                            {t(
                                                                "users.revoke_consequence",
                                                                "سيفقد صلاحية «:role» :scope. الحساب نفسه لا يتأثر — يبقى قادرًا على الدخول إلى المتجر كما كان.",
                                                                {
                                                                    role:
                                                                        roleLabel[
                                                                            grant
                                                                                .role
                                                                        ] ??
                                                                        grant.role,
                                                                    scope:
                                                                        grant.storefront_id ===
                                                                        null
                                                                            ? t(
                                                                                  "users.on_all_storefronts",
                                                                                  "على كل المتاجر",
                                                                              )
                                                                            : t(
                                                                                  "users.on_storefront",
                                                                                  "على :storefront",
                                                                                  {
                                                                                      storefront:
                                                                                          grant.storefront ??
                                                                                          `#${grant.storefront_id}`,
                                                                                  },
                                                                              ),
                                                                },
                                                            )}
                                                        </p>
                                                    }
                                                    trigger={
                                                        /* ── The reason, VISIBLE (J-8) ────────

                                                           This button looks close enough to
                                                           enabled, does nothing when clicked, and
                                                           explained itself only after about a
                                                           second of hover — and never on touch at
                                                           all. Reported as "the dashboard is
                                                           broken" rather than understood as a
                                                           rule. AGENTS §2.27 asks for the reason
                                                           ON the control, so it is printed beside
                                                           it and the `title` is gone. */
                                                        <span className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                                            {blocked ? (
                                                                <span className="text-[11px] leading-snug text-muted-foreground">
                                                                    {isSelfAdmin
                                                                        ? t(
                                                                              "users.cannot_revoke_self",
                                                                              "لا يمكنك سحب صلاحية المدير من نفسك — اطلب من مدير آخر.",
                                                                          )
                                                                        : t(
                                                                              "users.cannot_revoke_last_admin",
                                                                              "هذه آخر صلاحية مدير عامة: سحبها يترك اللوحة بلا مدير.",
                                                                          )}
                                                                </span>
                                                            ) : null}
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={blocked}
                                                            >
                                                                {t(
                                                                    "users.revoke",
                                                                    "اسحب",
                                                                )}
                                                            </Button>
                                                        </span>
                                                    }
                                                    onConfirm={() =>
                                                        router.delete(
                                                            `/manage/users/grants/${grant.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {grants.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-6 text-center text-sm text-muted-foreground"
                                        >
                                            {t(
                                                "users.empty",
                                                "لا توجد صلاحيات ممنوحة.",
                                            )}
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
