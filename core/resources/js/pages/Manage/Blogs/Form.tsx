import { useForm, usePage } from "@inertiajs/react";

import ManageLayout from "@/layouts/ManageLayout";
import { FormActions } from "@/components/form/FormActions";
import { TextField } from "@/components/form/TextField";
import { SwitchField } from "@/components/form/SwitchField";
import { TranslatedField } from "@/components/form/TranslatedField";
import { MarkdownField } from "@/components/form/MarkdownField";
import { useDirtyGuard } from "@/components/form/useDirtyGuard";
import { ImageField, type StoredImage } from "@/components/form/ImageField";
import { Alert } from "@/components/ui/alert";
import { Select } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useLocale, useT } from "@/lib/i18n";
import { titleOrCode } from "@/lib/title";
import type { SharedProps } from "@/types";

type Pair = Record<string, string>;

interface BlogPayload {
    id: number;
    slug: string;
    storefront_id: number;
    cover_path: string | null;
    cover_url: string | null;
    body_format: "text" | "markdown";
    is_published: boolean;
    published_at: string | null;
    title: Pair;
    body: Pair;
    meta_title: Pair;
    meta_description: Pair;
}

const EMPTY: Pair = { ar: "", en: "" };

/**
 * /manage/blogs/{id}/edit — one article (item 14, developer 2026-09-18).
 *
 * ── The scope, and it is the whole scope ─────────────────────────────────────────────────────
 *
 * *"list, create, edit, publish/unpublish, with Arabic and English fields and the SEO fields. NO
 * image gallery for now — a single cover image is enough."*
 *
 * So one cover, two languages, the SEO pair, and a publish switch. No author field, no categories,
 * no tags, no scheduling: each is a decision nobody has made, and a field nobody has decided on is
 * a field that gets filled with guesses.
 *
 * ── Why the slug box may be left empty ───────────────────────────────────────────────────────
 *
 * The writer generates one from the English title, then the Arabic, through `LegacySlug` — the same
 * slugifier every other public URL here goes through. Requiring it would make somebody invent a URL
 * before they have written the first sentence.
 *
 * ── The body is Markdown (2026-10-02, option A) ──────────────────────────────────────────────
 *
 * `MarkdownField`: a toolbar, and a preview rendered by the same rules as the shop. An article written
 * before the editor is 'text' and is shown on the shop exactly as before until somebody saves it here;
 * the notice above the editor says so, because saving switches it to Markdown.
 */
export default function BlogForm({
    blog,
    storefronts,
    assetBase,
}: {
    blog: BlogPayload | null;
    storefronts: { value: string; label: string }[];
    assetBase: string;
}) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const isNew = blog === null;

    const form = useForm({
        _complete: 1,
        slug: blog?.slug ?? "",
        storefront_id: String(blog?.storefront_id ?? storefronts[0]?.value ?? "1"),
        cover_path: blog?.cover_path ?? "",
        is_published: blog?.is_published ?? false,
        title: blog?.title ?? EMPTY,
        body: blog?.body ?? EMPTY,
        meta_title: blog?.meta_title ?? EMPTY,
        meta_description: blog?.meta_description ?? EMPTY,
    });

    useDirtyGuard(form.isDirty);

    const submit = () => {
        if (isNew) {
            form.post("/manage/blogs");

            return;
        }
        form.put(`/manage/blogs/${blog.id}`);
    };

    const newTitle = t("blogs.new", "مقال جديد");

    return (
        <ManageLayout
            title={isNew ? newTitle : titleOrCode(blog.title, locale, blog.slug)}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("nav.blogs", "المقالات"), href: "/manage/blogs" },
                { label: isNew ? newTitle : titleOrCode(blog.title, locale, blog.slug) },
            ]}
        >
            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {errors.is_published ? (
                    <Alert tone="error" title={t("common.action_failed", "تعذّر تنفيذ العملية")}>
                        {errors.is_published}
                    </Alert>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>{t("blogs.content", "المقال")}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <TranslatedField
                            label={t("blogs.title_field", "العنوان")}
                            name="title"
                            required
                            value={form.data.title}
                            onChange={(value) => form.setData("title", value)}
                            errors={errors}
                        />

                        {blog?.body_format === "text" ? (
                            <Alert tone="warning">
                                {t(
                                    "blogs.text_to_markdown",
                                    "هذا المقال كُتب قبل محرر Markdown، ويظهر في المتجر كما كان. عند الحفظ يُنشر بتنسيق Markdown: راجع «معاينة كما في المتجر» قبل الحفظ، فهي ما سيراه العملاء.",
                                )}
                            </Alert>
                        ) : null}
                        <MarkdownField
                            label={t("blogs.body", "النص")}
                            name="body"
                            hint={t(
                                "blogs.body_hint",
                                "النص الكامل للمقال بالعربية والإنجليزية. المقال بلا نص يمكن حفظه كمسودة، ولا يمكن نشره.",
                            )}
                            value={form.data.body}
                            onChange={(value) => form.setData("body", value)}
                            errors={errors}
                            assetBase={assetBase}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t("blogs.cover", "صورة الغلاف")}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* One cover, deliberately: the developer scoped the gallery out. */}
                        <ImageField
                            label={t("blogs.cover", "صورة الغلاف")}
                            type="banner"
                            value={
                                form.data.cover_path === ""
                                    ? null
                                    : ({
                                          file: form.data.cover_path,
                                          folder: "",
                                          url: blog?.cover_url ?? "",
                                          width: 0,
                                          height: 0,
                                          bytes: 0,
                                          renditions: {},
                                          skipped: [],
                                      } satisfies StoredImage)
                            }
                            onChange={(image: StoredImage | null) =>
                                form.setData(
                                    "cover_path",
                                    image === null ? "" : `${image.folder}/${image.file}`,
                                )
                            }
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>{t("blogs.publishing", "النشر والرابط")}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        {/* Which storefront shows it — articles are per storefront (2026-10-01). */}
                        <label className="block space-y-1 text-sm">
                            <span>{t("blogs.storefront", "المتجر")}</span>
                            <Select
                                value={form.data.storefront_id}
                                onChange={(event) => form.setData("storefront_id", event.target.value)}
                            >
                                {storefronts.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            {errors.storefront_id ? <span className="text-xs text-destructive">{errors.storefront_id}</span> : null}
                        </label>
                        <TextField
                            label={t("common.slug", "الرابط (slug)")}
                            dir="ltr"
                            hint={t(
                                "blogs.slug_hint",
                                "اتركه فارغًا ليُولَّد من العنوان الإنجليزي تلقائيًا. الروابط تُكتب بحروف لاتينية وأرقام وشرطات.",
                            )}
                            error={errors.slug ?? null}
                            value={form.data.slug}
                            onChange={(value) => form.setData("slug", value)}
                            placeholder="my-article"
                        />

                        <SwitchField
                            label={t("blogs.published_badge", "منشور")}
                            hint={
                                blog?.published_at == null
                                    ? t("blogs.publish_hint", "المقال بلا نص لا يمكن نشره.")
                                    : t("blogs.published_on", "نُشر في :date", {
                                          date: blog.published_at,
                                      })
                            }
                            checked={form.data.is_published}
                            onChange={(checked) => form.setData("is_published", checked)}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>{t("products.seo", "بيانات SEO وكلمات البحث")}</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "blogs.seo_hint",
                                "ما يظهر في نتائج البحث. اتركه فارغًا ليستخدم محرك البحث عنوان المقال ونصّه.",
                            )}
                        </p>
                    </CardHeader>
                    <CardContent className="grid gap-5 lg:grid-cols-2">
                        <TranslatedField
                            label={t("products.field_meta_title", "عنوان SEO")}
                            name="meta_title"
                            value={form.data.meta_title}
                            onChange={(value) => form.setData("meta_title", value)}
                            errors={errors}
                        />
                        <TranslatedField
                            label={t("products.field_meta_description", "وصف SEO")}
                            name="meta_description"
                            multiline
                            value={form.data.meta_description}
                            onChange={(value) => form.setData("meta_description", value)}
                            errors={errors}
                        />
                    </CardContent>
                </Card>

                <FormActions processing={form.processing} dirty={form.isDirty} />
            </form>
        </ManageLayout>
    );
}
