import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/*
 * A page text as the PARTS its layout draws (the developer's review, 2026-11-05, item 43 B).
 *
 * The storefront lays a brand or category text out as full-width blocks: the intro; "<name> at <shop>" — a
 * paragraph and the catalogue's facts as a grid of cells; every other section — a paragraph and its points; the
 * questions. Until today the admin got that layout by typing Markdown in exactly that shape. Here each part is its
 * own field, in both languages side by side:
 *
 *   • the intro — a text field;
 *   • "<name> at <shop>" — its paragraph; the FACTS are not typed: they come from the catalogue (core counts them),
 *     shown read-only, each with a switch for whether the page shows it;
 *   • each other section — its heading, its paragraph, and its points added, removed and moved one by one;
 *   • the questions block — Markdown, as it was (`### question?` then its answer);
 *   • under the fields, the page's layout drawn live from them.
 *
 * Nothing is stored differently: core compiles the parts back into the same Markdown text the page already reads
 * (SeoTextStructure), and a page whose text is not in this shape keeps the Markdown editor.
 */

export interface FactField {
    label: string;
    value: string;
    shown: boolean;
    generated: boolean;
}

export interface SectionField {
    heading: string;
    paragraph: string;
    bullets: string[];
}

export interface Structure {
    structured: boolean;
    intro: string;
    at: { present: boolean; heading: string; paragraph: string; facts: FactField[] };
    sections: SectionField[];
    faq: { heading: string; body: string };
}

/** What the save request carries for one language: the fact LINES are core's, only the labels to show are sent. */
export function toPayload(s: Structure) {
    return {
        intro: s.intro,
        at: { heading: s.at.heading, paragraph: s.at.paragraph, shown: s.at.facts.filter((f) => f.shown).map((f) => f.label) },
        sections: s.sections.map((section) => ({ heading: section.heading, paragraph: section.paragraph, bullets: section.bullets })),
        faq: { heading: s.faq.heading, body: s.faq.body },
    };
}

const AREA = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring';

export function StructuredFields({ locale, value, onChange }: { locale: 'ar' | 'en'; value: Structure; onChange: (next: Structure) => void }) {
    const t = useT();
    const dir = locale === 'ar' ? 'rtl' : 'ltr';
    const setSection = (index: number, patch: Partial<SectionField>) =>
        onChange({ ...value, sections: value.sections.map((section, i) => (i === index ? { ...section, ...patch } : section)) });
    const move = (list: string[], from: number, to: number) => {
        const next = [...list];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);

        return next;
    };

    return (
        <div className="grid gap-4" dir={dir}>
            <label className="grid gap-1 text-sm">
                <span className="font-medium">{t('page_texts.s_intro', 'المقدمة')}</span>
                <textarea className={AREA} rows={4} dir={dir} value={value.intro} onChange={(e) => onChange({ ...value, intro: e.target.value })} />
            </label>

            <fieldset className="grid gap-2 rounded-md border p-3">
                <legend className="px-1 text-sm font-medium">{value.at.heading || t('page_texts.s_at', 'الماركة أو التصنيف في المتجر')}</legend>
                <label className="grid gap-1 text-sm">
                    <span className="text-xs text-muted-foreground">{t('page_texts.s_heading', 'العنوان')}</span>
                    <Input dir={dir} value={value.at.heading} onChange={(e) => onChange({ ...value, at: { ...value.at, heading: e.target.value } })} />
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="text-xs text-muted-foreground">{t('page_texts.s_paragraph', 'الفقرة')}</span>
                    <textarea
                        className={AREA}
                        rows={4}
                        dir={dir}
                        value={value.at.paragraph}
                        onChange={(e) => onChange({ ...value, at: { ...value.at, paragraph: e.target.value } })}
                    />
                </label>
                <p className="text-xs text-muted-foreground">
                    {t('page_texts.s_facts_note', 'الأرقام تُحسب من الكتالوج نفسه ولا تُكتب يدويًا. اختر ما يظهر منها في الصفحة.')}
                </p>
                {value.at.facts.length === 0 ? (
                    <p className="text-xs text-amber-700">{t('page_texts.s_no_facts', 'لا توجد أرقام لهذه الصفحة بعد في ملف الحقائق.')}</p>
                ) : (
                    <ul className="grid gap-1">
                        {value.at.facts.map((fact, index) => (
                            <li key={fact.label} className="flex items-start gap-2 rounded border px-2 py-1.5 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={fact.shown}
                                    aria-label={fact.label}
                                    onChange={(e) =>
                                        onChange({
                                            ...value,
                                            at: { ...value.at, facts: value.at.facts.map((f, i) => (i === index ? { ...f, shown: e.target.checked } : f)) },
                                        })
                                    }
                                />
                                <span className={fact.shown ? '' : 'opacity-50'}>
                                    <span className="font-medium">{fact.label}</span>
                                    <span className="block text-xs text-muted-foreground">{fact.value}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </fieldset>

            {value.sections.map((section, index) => (
                <fieldset key={index} className="grid gap-2 rounded-md border p-3">
                    <legend className="px-1 text-sm font-medium">{section.heading || t('page_texts.s_section', 'قسم')}</legend>
                    <label className="grid gap-1 text-sm">
                        <span className="text-xs text-muted-foreground">{t('page_texts.s_heading', 'العنوان')}</span>
                        <Input dir={dir} value={section.heading} onChange={(e) => setSection(index, { heading: e.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="text-xs text-muted-foreground">{t('page_texts.s_paragraph', 'الفقرة')}</span>
                        <textarea className={AREA} rows={3} dir={dir} value={section.paragraph} onChange={(e) => setSection(index, { paragraph: e.target.value })} />
                    </label>
                    <span className="text-xs text-muted-foreground">{t('page_texts.s_bullets', 'النقاط')}</span>
                    <ul className="grid gap-1">
                        {section.bullets.map((bullet, b) => (
                            <li key={b} className="flex items-center gap-1">
                                <Input
                                    dir={dir}
                                    value={bullet}
                                    onChange={(e) => setSection(index, { bullets: section.bullets.map((x, i) => (i === b ? e.target.value : x)) })}
                                />
                                <Button type="button" variant="outline" size="sm" disabled={b === 0} aria-label={t('page_texts.s_up', 'تحريك لأعلى')} onClick={() => setSection(index, { bullets: move(section.bullets, b, b - 1) })}>
                                    ↑
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={b === section.bullets.length - 1}
                                    aria-label={t('page_texts.s_down', 'تحريك لأسفل')}
                                    onClick={() => setSection(index, { bullets: move(section.bullets, b, b + 1) })}
                                >
                                    ↓
                                </Button>
                                <Button type="button" variant="outline" size="sm" aria-label={t('page_texts.s_remove', 'حذف النقطة')} onClick={() => setSection(index, { bullets: section.bullets.filter((_, i) => i !== b) })}>
                                    ×
                                </Button>
                            </li>
                        ))}
                    </ul>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => setSection(index, { bullets: [...section.bullets, ''] })}>
                            {t('page_texts.s_add_bullet', 'إضافة نقطة')}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ ...value, sections: value.sections.filter((_, i) => i !== index) })}>
                            {t('page_texts.s_remove_section', 'حذف القسم')}
                        </Button>
                    </div>
                </fieldset>
            ))}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={() => onChange({ ...value, sections: [...value.sections, { heading: '', paragraph: '', bullets: [''] }] })}>
                    {t('page_texts.s_add_section', 'إضافة قسم')}
                </Button>
            </div>

            <fieldset className="grid gap-2 rounded-md border p-3">
                <legend className="px-1 text-sm font-medium">{t('page_texts.s_faq', 'الأسئلة والأجوبة')}</legend>
                <Input dir={dir} value={value.faq.heading} placeholder={t('page_texts.s_heading', 'العنوان')} onChange={(e) => onChange({ ...value, faq: { ...value.faq, heading: e.target.value } })} />
                <textarea className={AREA} rows={6} dir={dir} value={value.faq.body} onChange={(e) => onChange({ ...value, faq: { ...value.faq, body: e.target.value } })} />
                <p className="text-xs text-muted-foreground">{t('page_texts.s_faq_hint', 'كل سؤال سطر يبدأ بـ ### وينتهي بعلامة استفهام، وتحته جوابه.')}</p>
            </fieldset>
        </div>
    );
}

/** The page's layout, drawn from the fields as they are typed: the same blocks, in the same order, as the shop. */
export function LayoutPreview({ locale, value }: { locale: 'ar' | 'en'; value: Structure }) {
    const t = useT();
    const dir = locale === 'ar' ? 'rtl' : 'ltr';
    const plain = (text: string) => text.replace(/\*\*(.+?)\*\*/g, '$1').replace(/\[(.+?)\]\((.+?)\)/g, '$1');
    const facts = value.at.facts.filter((fact) => fact.shown);

    return (
        <div className="grid gap-3 rounded-md border bg-muted/30 p-3 text-sm" dir={dir} lang={locale}>
            <span className="text-xs font-medium text-muted-foreground">{t('page_texts.s_preview', 'شكل الصفحة')}</span>
            {value.intro.trim() !== '' && <p className="max-w-[82ch] leading-7">{plain(value.intro)}</p>}
            {(value.at.paragraph.trim() !== '' || facts.length > 0) && (
                <div className="rounded-md border bg-background p-3">
                    <h3 className="mb-2 text-base font-semibold">{value.at.heading}</h3>
                    {value.at.paragraph.trim() !== '' && <p className="max-w-[82ch] leading-6">{plain(value.at.paragraph)}</p>}
                    {facts.length > 0 && (
                        <ul className="mt-3 grid grid-cols-2 gap-2 xl:grid-cols-4">
                            {facts.map((fact) => (
                                <li key={fact.label} className="rounded border px-2 py-1.5">
                                    <span className="block text-xs text-muted-foreground">{fact.label}</span>
                                    <span className="block">{plain(fact.value)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {value.sections
                .filter((section) => section.heading.trim() !== '')
                .map((section, index) => (
                    <div key={index} className="rounded-md border bg-background p-3">
                        <h3 className="mb-2 text-base font-semibold">{section.heading}</h3>
                        {section.paragraph.trim() !== '' && <p className="max-w-[82ch] leading-6">{plain(section.paragraph)}</p>}
                        {section.bullets.filter((b) => b.trim() !== '').length > 0 && (
                            <ul className="mt-2 list-disc gap-x-8 ps-5 xl:columns-2">
                                {section.bullets
                                    .filter((b) => b.trim() !== '')
                                    .map((bullet, b) => (
                                        <li key={b}>{plain(bullet)}</li>
                                    ))}
                            </ul>
                        )}
                    </div>
                ))}
            {value.faq.heading.trim() !== '' && value.faq.body.trim() !== '' && (
                <div>
                    <h3 className="mb-1 text-base font-semibold">{value.faq.heading}</h3>
                    <ul className="grid gap-1 xl:grid-cols-2">
                        {value.faq.body
                            .split('\n')
                            .filter((line) => line.startsWith('### '))
                            .map((line, q) => (
                                <li key={q} className="rounded border bg-background px-2 py-1.5">
                                    {line.slice(4)}
                                </li>
                            ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
