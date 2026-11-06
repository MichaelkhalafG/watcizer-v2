import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { QuickSearch, matchesTerm, useQueryTerm } from '@/components/table/QuickSearch';
import { Ltr } from '@/components/ui/bidi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MarkdownField } from '@/components/form/MarkdownField';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import { LayoutPreview, StructuredFields, toPayload, type Structure } from './StructuredFields';

/**
 * Page texts — the introduction and meta description of every brand and category page, in both languages (M1y,
 * SEO item 5, 2026-10-30).
 *
 * The list is the storefront's own pages: the brands it shows and its menu categories, keyed by the address the
 * storefront serves them at, so a text written here is a text a page actually shows. An empty field is not an
 * error — the storefront keeps its automatic sentence — which is why the row says "No text yet" in grey rather
 * than in red.
 *
 * The meta description counter is advice, not a rule: Google cuts at about 160 characters, so a longer one is
 * saved and flagged.
 */

type Locale = 'ar' | 'en';

interface Texts {
    description: string;
    meta_description: string;
}

interface Subject {
    type: 'brand' | 'category';
    key: string;
    name: string;
    products?: number;
    depth?: number;
    texts: Record<Locale, Texts>;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    subjects: Subject[];
    meta_limit: number;
    notice: string;
    /** The image host the Markdown preview reads an uploaded picture from. */
    assetBase: string;
}

const LOCALES: Locale[] = ['ar', 'en'];

// The address the storefront serves the page at: a top-level category under /category, a sub-type under /subtypes.
function pathOf(subject: Subject): string {
    if (subject.type === 'brand') {
        return `/brand/${subject.key}`;
    }
    const segments = subject.key.split('/');

    return segments.length > 1 ? `/subtypes/${segments[segments.length - 1]}` : `/category/${subject.key}`;
}

function filled(subject: Subject): boolean {
    return LOCALES.some((l) => subject.texts[l].description.trim() !== '' || subject.texts[l].meta_description.trim() !== '');
}

export default function PageTextsIndex({ storefront, subjects, meta_limit, notice, assetBase }: Props) {
    const t = useT();
    const [tab, setTab] = useState<'brand' | 'category'>('brand');
    const [term, setTerm] = useQueryTerm();
    const [editing, setEditing] = useState<string | null>(null);

    const ofTab = subjects.filter((s) => s.type === tab);
    const rows = ofTab.filter((s) => matchesTerm(term, s.name, s.key));

    return (
        <ManageLayout title={t('page_texts.title', 'نصوص الصفحات')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">{t('page_texts.title', 'نصوص الصفحات')}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {storefront.name} —{' '}
                        {t('page_texts.count_note', ':count صفحة. الحقول الفارغة تُبقي النص التلقائي للمتجر.', {
                            count: ofTab.length,
                        })}
                    </p>
                </div>
                <div className="flex gap-1.5" role="tablist">
                    <Button
                        size="sm"
                        role="tab"
                        aria-selected={tab === 'brand'}
                        variant={tab === 'brand' ? 'default' : 'outline'}
                        onClick={() => {
                            setTab('brand');
                            setEditing(null);
                        }}
                    >
                        {t('page_texts.tab_brands', 'الماركات')}
                    </Button>
                    <Button
                        size="sm"
                        role="tab"
                        aria-selected={tab === 'category'}
                        variant={tab === 'category' ? 'default' : 'outline'}
                        onClick={() => {
                            setTab('category');
                            setEditing(null);
                        }}
                    >
                        {t('page_texts.tab_categories', 'التصنيفات')}
                    </Button>
                </div>
            </div>

            <p className="mt-3 rounded-md border border-dashed p-3 text-xs text-muted-foreground">{notice}</p>

            <div className="mt-4">
                <QuickSearch
                    value={term}
                    onChange={setTerm}
                    placeholder={t('page_texts.search_placeholder', 'ابحث باسم الماركة أو التصنيف أو العنوان…')}
                    count={rows.length}
                    total={ofTab.length}
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{tab === 'brand' ? t('page_texts.tab_brands', 'الماركات') : t('page_texts.tab_categories', 'التصنيفات')}</TableHead>
                            <TableHead>{t('page_texts.view_page', 'عنوان الصفحة')}</TableHead>
                            <TableHead />
                            <TableHead className="w-[9rem]" align="end">{t('common.actions', 'إجراءات')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((subject) => {
                            const id = `${subject.type}:${subject.key}`;

                            return editing === id ? (
                                <TableRow key={id}>
                                    <TableCell colSpan={4}>
                                        <TextForm
                                            storefrontId={storefront.id}
                                            subject={subject}
                                            metaLimit={meta_limit}
                                            assetBase={assetBase}
                                            onDone={() => setEditing(null)}
                                        />
                                    </TableCell>
                                </TableRow>
                            ) : (
                                <TableRow key={id}>
                                    <TableCell>
                                        <span style={subject.depth ? { paddingInlineStart: `${(subject.depth - 1) * 1.25}rem` } : undefined}>
                                            {subject.name}
                                        </span>
                                        {subject.products !== undefined && (
                                            <span className="ms-2 text-xs text-muted-foreground">
                                                {t('page_texts.products', ':count منتج', { count: subject.products })}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Ltr className="text-xs text-muted-foreground">{pathOf(subject)}</Ltr>
                                    </TableCell>
                                    <TableCell>
                                        {filled(subject) ? (
                                            <Badge variant="success">{t('page_texts.filled', 'مكتوب')}</Badge>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">{t('page_texts.empty', 'لا يوجد نص بعد')}</span>
                                        )}
                                    </TableCell>
                                    <TableCell align="end">
                                        <Button variant="outline" size="sm" onClick={() => setEditing(id)}>
                                            {t('page_texts.edit', 'كتابة النص')}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </ManageLayout>
    );
}

function TextForm({
    storefrontId,
    subject,
    metaLimit,
    assetBase,
    onDone,
}: {
    storefrontId: number;
    subject: Subject;
    metaLimit: number;
    assetBase: string;
    onDone: () => void;
}) {
    const t = useT();
    const [data, setData] = useState<Record<Locale, Texts>>({ ar: { ...subject.texts.ar }, en: { ...subject.texts.en } });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = (locale: Locale, field: keyof Texts, value: string) =>
        setData((d) => ({ ...d, [locale]: { ...d[locale], [field]: value } }));

    /*
     * THE TEXT AS ITS PARTS (item 43 B, 2026-11-05): core reads this page's text into the parts its layout draws —
     * intro, "<name> at <shop>" with the catalogue's facts, the other sections with their points, the questions —
     * and the form shows those as fields. A language whose text is not in that shape (written freely) keeps the
     * Markdown editor; so does the whole form when the admin asks for it ("Markdown").
     */
    const [parts, setParts] = useState<Record<Locale, Structure> | null>(null);
    const [mode, setMode] = useState<'fields' | 'markdown'>('markdown');
    useEffect(() => {
        let alive = true;
        fetch(`/manage/storefronts/${storefrontId}/page-texts/structure?type=${subject.type}&key=${encodeURIComponent(subject.key)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: Record<Locale, Structure> | null) => {
                if (alive && body && LOCALES.every((l) => body[l]?.structured)) {
                    setParts(body);
                    setMode('fields');
                }
            })
            .catch(() => {
                // the Markdown editor stays: nothing is lost
            });

        return () => {
            alive = false;
        };
    }, [storefrontId, subject.type, subject.key]);
    const structured = mode === 'fields' && parts !== null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.put(
            `/manage/storefronts/${storefrontId}/page-texts`,
            structured && parts
                ? {
                      type: subject.type,
                      key: subject.key,
                      ar: { meta_description: data.ar.meta_description, structure: toPayload(parts.ar) },
                      en: { meta_description: data.en.meta_description, structure: toPayload(parts.en) },
                  }
                : { type: subject.type, key: subject.key, ar: { ...data.ar }, en: { ...data.en } },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs),
                onSuccess: () => onDone(),
            },
        );
    };

    return (
        <form onSubmit={submit} className="my-2 grid gap-4 rounded-md border p-3 lg:grid-cols-2">
            {/*
              The page text is MARKDOWN since 2026-11-05 (the twelve updates, Part 6.1): the same editor the articles
              use — toolbar, live preview, the same rules as the shop (headings, lists, bold, links to https://,
              mailto:, tel: or a site path only, pictures uploaded here only; any HTML typed is shown as text and
              never run). A text written before that day is plain paragraphs, which Markdown draws as the same
              paragraphs, so nothing already on a page changes until somebody edits it.
            */}
            {parts !== null && (
                <div className="flex items-center gap-2 text-sm lg:col-span-2">
                    <span className="text-muted-foreground">{t('page_texts.s_mode', 'طريقة التحرير')}</span>
                    <Button type="button" size="sm" variant={mode === 'fields' ? 'default' : 'outline'} onClick={() => setMode('fields')}>
                        {t('page_texts.s_mode_fields', 'حقول')}
                    </Button>
                    <Button type="button" size="sm" variant={mode === 'markdown' ? 'default' : 'outline'} onClick={() => setMode('markdown')}>
                        {t('page_texts.s_mode_markdown', 'ماركداون')}
                    </Button>
                    {mode === 'markdown' && (
                        <span className="text-xs text-amber-700">
                            {t('page_texts.s_mode_note', 'ما تكتبه هنا يُحفظ كما هو؛ ما كُتب في الحقول ولم يُحفظ لا يُنقل إلى هنا.')}
                        </span>
                    )}
                </div>
            )}
            {structured && parts
                ? LOCALES.map((locale) => (
                      <div key={`parts-${locale}`} className="grid content-start gap-3">
                          <span className="text-sm font-medium">
                              {locale === 'ar' ? t('page_texts.lang_ar', 'العربية') : t('page_texts.lang_en', 'الإنجليزية')}
                          </span>
                          <StructuredFields locale={locale} value={parts[locale]} onChange={(next) => setParts({ ...parts, [locale]: next })} />
                          <LayoutPreview locale={locale} value={parts[locale]} />
                      </div>
                  ))
                : null}
            <div className={structured ? 'hidden' : 'lg:col-span-2'}>
                <MarkdownField
                    label={t('page_texts.description', 'المقدمة (تظهر تحت المنتجات)')}
                    name="description"
                    value={{ ar: data.ar.description, en: data.en.description }}
                    onChange={(pair) =>
                        setData((d) => ({
                            ar: { ...d.ar, description: pair.ar ?? '' },
                            en: { ...d.en, description: pair.en ?? '' },
                        }))
                    }
                    errors={Object.fromEntries(
                        LOCALES.filter((l) => Boolean(errors[`${l}.description`])).map((l) => [
                            `description.${l}`,
                            errors[`${l}.description`],
                        ]),
                    )}
                    assetBase={assetBase}
                    hint={t(
                        'page_texts.description_hint',
                        'اكتب عناوين الأقسام بـ «عنوان» من الشريط، والقوائم بنقاط. الأسئلة والأجوبة: كل سؤال عنوان فرعي ينتهي بعلامة استفهام وتحته جوابه — تظهر لجوجل كأسئلة شائعة.',
                    )}
                />
            </div>
            {LOCALES.map((locale) => {
                const meta = data[locale].meta_description;
                const dir = locale === 'ar' ? 'rtl' : 'ltr';

                return (
                    <fieldset key={locale} className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {locale === 'ar' ? t('page_texts.lang_ar', 'العربية') : t('page_texts.lang_en', 'الإنجليزية')}
                        </legend>
                        <label className="text-xs text-muted-foreground" htmlFor={`meta_${locale}`}>
                            {t('page_texts.meta', 'الوصف المختصر (يظهر في نتائج جوجل)')}
                        </label>
                        <Input
                            id={`meta_${locale}`}
                            dir={dir}
                            value={meta}
                            onChange={(e) => set(locale, 'meta_description', e.target.value)}
                        />
                        <p className={meta.length > metaLimit ? 'text-xs text-amber-700' : 'text-xs text-muted-foreground'}>
                            {meta.length > metaLimit
                                ? t('page_texts.meta_long', 'أطول من :limit حرفاً — سيقطعه جوجل.', { limit: metaLimit })
                                : t('page_texts.meta_count', ':count من :limit حرفاً', { count: meta.length, limit: metaLimit })}
                        </p>
                        {errors[`${locale}.meta_description`] && (
                            <p className="text-xs text-destructive">{errors[`${locale}.meta_description`]}</p>
                        )}
                    </fieldset>
                );
            })}
            {errors.key && <p className="text-xs text-destructive lg:col-span-2">{errors.key}</p>}
            <div className="flex gap-2 lg:col-span-2">
                <Button type="submit" size="sm" disabled={processing}>
                    {t('common.save', 'حفظ')}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    {t('common.cancel', 'إلغاء')}
                </Button>
            </div>
        </form>
    );
}
