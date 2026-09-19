import { Link, router, usePage } from "@inertiajs/react";
import { Pencil, Plus } from "lucide-react";

import ManageLayout from "@/layouts/ManageLayout";
import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { ProductName } from "@/components/manage/ProductName";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Num } from "@/components/ui/bidi";
import { Switch } from "@/components/ui/switch";
import { useLocale, useT } from "@/lib/i18n";
import { titleOrCode } from "@/lib/title";
import type { SharedProps } from "@/types";

interface Row {
    id: number;
    slug: string;
    title: { ar: string; en: string };
    cover: string | null;
    is_published: boolean;
    published_at: string | null;
    updated_at: string | null;
    /** Whether it COULD be published — the same rule the writer enforces on the server. */
    has_body: boolean;
}

/**
 * /manage/blogs — the articles list (item 14, developer 2026-09-18).
 *
 * ── Drafts first ─────────────────────────────────────────────────────────────────────────────
 *
 * The server orders unpublished articles to the top. A draft is the row somebody is coming back to;
 * a published article is finished and only wants finding. That is the opposite of a product list,
 * where the finished rows are the work — and the difference is that an article is written once.
 *
 * ── The publish switch is disabled when there is nothing to read ─────────────────────────────
 *
 * `BlogWriter` refuses to publish an article with no body, and the refusal stands on the server
 * whatever this screen does. The switch is disabled so nobody learns the rule by being told no,
 * with the reason on the control — the same shape as every other refusal in this dashboard.
 */
export default function BlogsIndex({
    rows,
    storefront_note,
}: {
    rows: Row[];
    storefront_note: string;
}) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;

    return (
        <ManageLayout
            title={t("nav.blogs", "المقالات")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("nav.blogs", "المقالات") },
            ]}
            actions={
                <Button asChild size="sm" className="gap-1.5">
                    <Link href="/manage/blogs/create">
                        <Plus className="h-4 w-4" />
                        {t("blogs.new", "مقال جديد")}
                    </Link>
                </Button>
            }
        >
            {/* The honest limit of what this screen can do for a reader today. */}
            <Alert tone="info" title={t("blogs.not_served_title", "المقالات لا تظهر على الموقع بعد")}>
                {storefront_note}
            </Alert>

            {errors.is_published ? (
                <Alert tone="error" title={t("common.action_failed", "تعذّر تنفيذ العملية")}>
                    {errors.is_published}
                </Alert>
            ) : null}

            {rows.length === 0 ? (
                <Card>
                    <CardContent className="py-12 text-center">
                        <p className="text-sm text-muted-foreground">
                            {t("blogs.empty", "لا توجد مقالات بعد.")}
                        </p>
                        <Button asChild size="sm" className="mt-4 gap-1.5">
                            <Link href="/manage/blogs/create">
                                <Plus className="h-4 w-4" />
                                {t("blogs.new", "مقال جديد")}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((row) => (
                        <Card key={row.id}>
                            <CardContent className="flex flex-wrap items-center gap-4 p-4">
                                <div className="h-16 w-24 shrink-0 overflow-hidden rounded border bg-muted/40">
                                    {row.cover === null ? (
                                        <div className="flex h-full w-full items-center justify-center p-1 text-center text-[10px] text-muted-foreground">
                                            {t("blogs.no_cover", "بلا صورة")}
                                        </div>
                                    ) : (
                                        <img
                                            src={row.cover}
                                            alt=""
                                            className="h-full w-full object-cover"
                                            loading="lazy"
                                        />
                                    )}
                                </div>

                                <div className="min-w-[14rem] flex-1 space-y-1">
                                    {/* The operator's own language, the other one marked (item 1b). */}
                                    <ProductName title={row.title} />
                                    <div className="font-mono text-[11px] text-muted-foreground">
                                        <Num>/{row.slug}</Num>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                        {row.is_published ? (
                                            <Badge variant="success">
                                                {t("blogs.published_badge", "منشور")}
                                            </Badge>
                                        ) : (
                                            <Badge variant="warning">
                                                {t("blogs.draft", "مسودة")}
                                            </Badge>
                                        )}
                                        {row.has_body ? null : (
                                            <Badge
                                                variant="destructive"
                                                title={t(
                                                    "blogs.publish_needs_body",
                                                    "لا يمكن نشر مقال بلا نص. اكتب المحتوى أولاً، أو احفظه كمسودة وانشره لاحقًا.",
                                                )}
                                            >
                                                {t("blogs.no_body", "بلا نص")}
                                            </Badge>
                                        )}
                                        {row.published_at === null ? null : (
                                            <span className="text-xs text-muted-foreground">
                                                {t("blogs.published_on", "نُشر في :date", {
                                                    date: row.published_at,
                                                })}
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-center gap-3">
                                    <label className="flex items-center gap-1.5 text-xs">
                                        <Switch
                                            aria-label={t("blogs.toggle_published", "نشر :name", {
                                                name: titleOrCode(row.title, locale, row.slug),
                                            })}
                                            // The server refuses this too; the switch is disabled so
                                            // nobody discovers the rule by being refused.
                                            disabled={!row.is_published && !row.has_body}
                                            title={
                                                !row.is_published && !row.has_body
                                                    ? t(
                                                          "blogs.publish_needs_body",
                                                          "لا يمكن نشر مقال بلا نص. اكتب المحتوى أولاً، أو احفظه كمسودة وانشره لاحقًا.",
                                                      )
                                                    : undefined
                                            }
                                            checked={row.is_published}
                                            onCheckedChange={(checked) =>
                                                router.put(
                                                    `/manage/blogs/${row.id}/publish`,
                                                    { is_published: checked },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        {t("blogs.published_badge", "منشور")}
                                    </label>

                                    <Button asChild variant="ghost" size="icon">
                                        <Link
                                            href={`/manage/blogs/${row.id}/edit`}
                                            aria-label={t("blogs.edit_row", "تعديل :name", {
                                                name: titleOrCode(row.title, locale, row.slug),
                                            })}
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </Link>
                                    </Button>

                                    <ConfirmAction
                                        title={t("blogs.delete_title", "حذف المقال «:name»", {
                                            name: titleOrCode(row.title, locale, row.slug),
                                        })}
                                        consequence={
                                            <p>
                                                {t(
                                                    "blogs.delete_consequence",
                                                    "سيُحذف المقال ونصّه بالعربية والإنجليزية نهائيًا. لا يمكن التراجع عن هذا.",
                                                )}
                                            </p>
                                        }
                                        confirmLabel={t("blogs.delete_confirm", "احذف المقال")}
                                        onConfirm={() =>
                                            router.delete(`/manage/blogs/${row.id}`)
                                        }
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="text-destructive"
                                                aria-label={t("blogs.delete_row", "حذف :name", {
                                                    name: titleOrCode(row.title, locale, row.slug),
                                                })}
                                            >
                                                <span aria-hidden="true">×</span>
                                            </Button>
                                        }
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </ManageLayout>
    );
}
