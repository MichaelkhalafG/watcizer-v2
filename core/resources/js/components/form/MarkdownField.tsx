import { Bold, Eye, Heading2, Heading3, ImagePlus, Italic, Link2, List, ListOrdered, PenLine, Quote } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { MarkdownBody, safeHref } from '@/lib/markdown';
import { cn } from '@/lib/utils';

/**
 * An article body in Markdown, Arabic and English, with a toolbar and a live preview (2026-10-02 —
 * the blog editor, developer's option A).
 *
 * The preview is rendered by `lib/markdown.ts`, the same rules the shop renders the article with
 * (`BlogMarkdownSafeTest` proves the two produce identical HTML), so what the team sees here is what
 * shoppers will see. Every toolbar button says what it inserts. Images are uploaded through the same
 * `/manage/media` endpoint as the cover and inserted as a site path ("/Uploads_Images/…") — the only
 * kind of image an article may show.
 */
type Pair = Record<string, string>;

const LANGS: Record<string, string> = { ar: 'العربية', en: 'English' }; // i18n-exempt: language names are data, named in their own language

interface Stored {
    file: string;
    folder: string;
}

function LocaleEditor({
    locale,
    value,
    onChange,
    assetBase,
    error,
}: {
    locale: string;
    value: string;
    onChange: (text: string) => void;
    assetBase: string;
    error: string | null;
}) {
    const t = useT();
    const area = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [mode, setMode] = useState<'write' | 'preview'>('write');
    const [linking, setLinking] = useState<{ text: string; href: string } | null>(null);
    const [linkError, setLinkError] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const rtl = locale === 'ar';

    /** Replace the selection with `before + (selection || placeholder) + after`, keeping the selection on the inner text. */
    const wrap = (before: string, after: string, placeholder: string) => {
        const el = area.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        const inner = value.slice(start, end) || placeholder;
        onChange(value.slice(0, start) + before + inner + after + value.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + before.length, start + before.length + inner.length);
        });
    };

    /** Put `prefix` at the start of every line the selection touches (numbered lists count up). */
    const prefixLines = (prefix: string, numbered = false) => {
        const el = area.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        const lineEnd = value.indexOf('\n', end) === -1 ? value.length : value.indexOf('\n', end);
        const lines = value.slice(lineStart, lineEnd).split('\n');
        const done = lines.map((line, i) => (numbered ? `${i + 1}. ` : prefix) + line.replace(/^(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/, '')).join('\n');
        onChange(value.slice(0, lineStart) + done + value.slice(lineEnd));
        requestAnimationFrame(() => el?.focus());
    };

    /**
     * A block (an image) goes on its own line AFTER the line the cursor is on — never inside it. The
     * cursor is often inside inline markup (a link just inserted leaves it on the link's words), and
     * splitting `[words](/path)` with an image broke the link (found in the browser pass).
     */
    const insertBlock = (text: string) => {
        const el = area.current;
        const cursor = el?.selectionEnd ?? value.length;
        const lineEnd = value.indexOf('\n', cursor) === -1 ? value.length : value.indexOf('\n', cursor);
        const before = value.slice(0, lineEnd);
        onChange(before + (before === '' ? '' : '\n\n') + text + '\n\n' + value.slice(lineEnd).replace(/^\n+/, ''));
    };

    const upload = async (file: File) => {
        setUploadError(null);
        setUploading(true);
        const body = new FormData();
        body.append('type', 'banner');
        body.append('file', file);
        try {
            const response = await fetch('/manage/media', { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const payload: unknown = await response.json();
            if (!response.ok) {
                setUploadError(
                    typeof payload === 'object' && payload !== null && 'message' in payload
                        ? String((payload as { message: unknown }).message)
                        : t('common.upload_failed', 'تعذّر رفع الصورة.'),
                );
                return;
            }
            const stored = payload as Stored;
            const alt = file.name.replace(/\.[^.]+$/, '').replace(/[[\]()]/g, ' ').trim();
            insertBlock(`![${alt}](/Uploads_Images/${stored.folder}/${stored.file})`);
        } catch {
            setUploadError(t('common.server_unreachable', 'تعذّر الاتصال بالخادم. حاول مرة أخرى.'));
        } finally {
            setUploading(false);
        }
    };

    const tools: { icon: typeof Bold; label: string; run: () => void }[] = [
        { icon: Bold, label: t('blogs.md_bold', 'عريض — يحيط النص المحدد بـ **'), run: () => wrap('**', '**', t('blogs.md_bold_sample', 'نص عريض')) },
        { icon: Italic, label: t('blogs.md_italic', 'مائل — يحيط النص المحدد بـ *'), run: () => wrap('*', '*', t('blogs.md_italic_sample', 'نص مائل')) },
        { icon: Heading2, label: t('blogs.md_heading', 'عنوان فرعي — يضع «## » أول السطر'), run: () => prefixLines('## ') },
        { icon: Heading3, label: t('blogs.md_subheading', 'عنوان أصغر — يضع «### » أول السطر'), run: () => prefixLines('### ') },
        { icon: List, label: t('blogs.md_ul', 'قائمة نقطية — يضع «- » أول كل سطر محدد'), run: () => prefixLines('- ') },
        { icon: ListOrdered, label: t('blogs.md_ol', 'قائمة مرقمة — يرقّم الأسطر المحددة 1. 2. 3.'), run: () => prefixLines('', true) },
        { icon: Quote, label: t('blogs.md_quote', 'اقتباس — يضع «> » أول السطر'), run: () => prefixLines('> ') },
    ];

    const confirmLink = () => {
        if (!linking) return;
        const href = linking.href.trim();
        // Just a scheme ("https://", "mailto:") is not an address yet: it would insert a link to nowhere.
        if (/^(https?:\/\/|mailto:|tel:)?$/i.test(href)) {
            setLinkError(t('blogs.md_link_empty', 'اكتب العنوان الكامل للرابط أولاً.'));
            return;
        }
        if (safeHref(href) === '') {
            setLinkError(t('blogs.md_link_bad', 'العنوان يجب أن يبدأ بـ https:// أو mailto: أو tel: أو / (صفحة في الموقع). غير ذلك لا يعمل في المتجر.'));
            return;
        }
        wrap('[', `](${href})`, linking.text.trim() || href);
        setLinking(null);
        setLinkError(null);
    };

    return (
        <div className={cn('space-y-2 rounded-lg border bg-muted/30 p-3', error !== null && 'border-destructive/50')}>
            <div className="flex flex-wrap items-center gap-1">
                <span className="me-2 text-sm font-medium">{LANGS[locale] ?? locale}</span>
                {mode === 'write'
                    ? tools.map(({ icon: Icon, label, run }) => (
                          <button key={label} type="button" className="rounded p-1.5 hover:bg-background" title={label} aria-label={label} onClick={run}>
                              <Icon className="size-4" />
                          </button>
                      ))
                    : null}
                {mode === 'write' ? (
                    <>
                        <button
                            type="button"
                            className="rounded p-1.5 hover:bg-background"
                            title={t('blogs.md_link', 'رابط — يحوّل النص المحدد إلى رابط')}
                            aria-label={t('blogs.md_link', 'رابط — يحوّل النص المحدد إلى رابط')}
                            onClick={() => {
                                const el = area.current;
                                setLinking({ text: el ? value.slice(el.selectionStart, el.selectionEnd) : '', href: 'https://' });
                                setLinkError(null);
                            }}
                        >
                            <Link2 className="size-4" />
                        </button>
                        <button
                            type="button"
                            className="rounded p-1.5 hover:bg-background disabled:opacity-40"
                            title={t('blogs.md_image', 'صورة — ترفع صورة وتضعها في مكان المؤشر')}
                            aria-label={t('blogs.md_image', 'صورة — ترفع صورة وتضعها في مكان المؤشر')}
                            disabled={uploading}
                            onClick={() => fileInput.current?.click()}
                        >
                            <ImagePlus className="size-4" />
                        </button>
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                event.target.value = '';
                                if (file) void upload(file);
                            }}
                        />
                    </>
                ) : null}
                <span className="ms-auto inline-flex rounded-md border bg-background p-0.5 text-xs">
                    <button
                        type="button"
                        className={cn('inline-flex items-center gap-1 rounded px-2 py-1', mode === 'write' && 'bg-muted font-medium')}
                        onClick={() => setMode('write')}
                    >
                        <PenLine className="size-3.5" />
                        {t('blogs.md_write', 'كتابة')}
                    </button>
                    <button
                        type="button"
                        className={cn('inline-flex items-center gap-1 rounded px-2 py-1', mode === 'preview' && 'bg-muted font-medium')}
                        onClick={() => setMode('preview')}
                    >
                        <Eye className="size-3.5" />
                        {t('blogs.md_preview', 'معاينة كما في المتجر')}
                    </button>
                </span>
            </div>

            {linking ? (
                <div className="flex flex-wrap items-end gap-2 rounded-md border bg-background p-2">
                    <label className="min-w-40 flex-1 space-y-1 text-xs">
                        <span>{t('blogs.md_link_text', 'نص الرابط')}</span>
                        <Input name="md-link-text" value={linking.text} onChange={(event) => setLinking({ ...linking, text: event.target.value })} dir={rtl ? 'rtl' : 'ltr'} />
                    </label>
                    <label className="min-w-56 flex-[2] space-y-1 text-xs">
                        <span>{t('blogs.md_link_href', 'العنوان')}</span>
                        <Input
                            name="md-link-href"
                            value={linking.href}
                            dir="ltr"
                            onChange={(event) => {
                                setLinking({ ...linking, href: event.target.value });
                                setLinkError(null);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    confirmLink();
                                }
                            }}
                        />
                    </label>
                    <Button type="button" size="sm" onClick={confirmLink}>
                        {t('blogs.md_link_insert', 'أدرج الرابط')}
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setLinking(null)}>
                        {t('common.cancel', 'إلغاء')}
                    </Button>
                    {linkError ? <p className="w-full text-xs text-destructive">{linkError}</p> : null}
                </div>
            ) : null}

            {mode === 'write' ? (
                <Textarea
                    ref={area}
                    dir={rtl ? 'rtl' : 'ltr'}
                    lang={locale}
                    rows={16}
                    className="font-mono text-sm leading-6"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-label={LANGS[locale] ?? locale}
                />
            ) : value.trim() === '' ? (
                <p className="rounded-md border bg-background p-4 text-sm text-muted-foreground">{t('blogs.md_preview_empty', 'لا يوجد نص لمعاينته بعد.')}</p>
            ) : (
                <div className="md-preview max-h-[32rem] overflow-y-auto rounded-md border bg-background p-4" dir={rtl ? 'rtl' : 'ltr'} lang={locale}>
                    <MarkdownBody text={value} assetBase={assetBase} className="md-preview__body" />
                </div>
            )}
            {uploading ? <p className="text-xs text-muted-foreground">{t('blogs.md_uploading', 'جاري رفع الصورة…')}</p> : null}
            {uploadError ? <p className="text-xs text-destructive">{uploadError}</p> : null}
            {error ? <p className="text-xs text-destructive">{error}</p> : null}
        </div>
    );
}

export function MarkdownField({
    label,
    name,
    value,
    onChange,
    errors = {},
    assetBase,
    hint,
}: {
    label: string;
    name: string;
    value: Pair;
    onChange: (value: Pair) => void;
    errors?: Record<string, string>;
    assetBase: string;
    hint?: string;
}) {
    const t = useT();

    return (
        <div className="space-y-2">
            <span className="text-sm font-medium">{label}</span>
            <div className="grid gap-3 xl:grid-cols-2">
                {['ar', 'en'].map((locale) => (
                    <LocaleEditor
                        key={locale}
                        locale={locale}
                        value={value[locale] ?? ''}
                        onChange={(text) => onChange({ ...value, [locale]: text })}
                        assetBase={assetBase}
                        error={errors[`${name}.${locale}`] ?? null}
                    />
                ))}
            </div>
            {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
            <p className="text-xs text-muted-foreground">
                {t(
                    'blogs.md_rules',
                    'ما يظهر في المتجر: العناوين والقوائم والعريض والمائل والاقتباس، والروابط التي تبدأ بـ https:// أو mailto: أو tel: أو / فقط، والصور المرفوعة من هنا فقط. أي HTML يُكتب يظهر كنص كما هو ولا يُنفّذ.',
                )}
            </p>
        </div>
    );
}
