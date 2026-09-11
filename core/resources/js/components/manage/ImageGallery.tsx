import { ChevronDown, ChevronUp, ImageUp, Loader2, Star, Trash2 } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export interface GalleryImage {
    /** Null for an image added in this session and not saved yet. */
    id: number | null;
    /** The filename the DB stores, relative to Uploads_Images (study §5.4 — never a host). */
    path: string;
    url: string;
    is_cover: boolean;
    alt_ar: string;
    alt_en: string;
    width: number | null;
    height: number | null;
    renditions: Record<string, Record<string, string>>;
}

/**
 * Upload, reorder and choose the cover — the product form's image half (scope item 2).
 *
 * ── Order is the payload's order, not a column the UI edits ──────────────────────────────────
 *
 * The form sends the FULL desired list and the server writes `sort` from the array index
 * (App\Domain\Catalog\ProductWriter::writeImages). So "move up" is a swap in local state and
 * nothing else: no per-image request, no sort field to get out of step with the list on screen,
 * and no way to end up with two images claiming position 3.
 *
 * ── Explicit buttons rather than drag-and-drop ───────────────────────────────────────────────
 *
 * Deliberate. Drag-and-drop on a tablet in an RTL layout is where "natural" gestures stop being
 * natural, and a drag that fails silently is worse than a button that works. Up/down move by one;
 * the cover is a single click and is mutually exclusive by construction.
 *
 * ── The cover ────────────────────────────────────────────────────────────────────────────────
 *
 * Exactly one, always. The read layer picks it with `ORDER BY is_cover DESC, sort`, so two covers
 * make the choice arbitrary between two requests — the server enforces one and this keeps the UI
 * honest about which one it will be.
 */
export function ImageGallery({
    images,
    onChange,
    disabled = false,
}: {
    images: GalleryImage[];
    onChange: (images: GalleryImage[]) => void;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    const upload = useCallback(
        async (files: FileList) => {
            setFailure(null);
            setBusy(true);
            const added: GalleryImage[] = [];

            for (const file of Array.from(files)) {
                const body = new FormData();
                body.append('type', 'product_gallery');
                body.append('file', file);

                try {
                    const response = await fetch('/manage/media', {
                        method: 'POST',
                        body,
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    const payload: unknown = await response.json();

                    if (!response.ok) {
                        const message =
                            typeof payload === 'object' && payload !== null && 'message' in payload
                                ? String((payload as { message: unknown }).message)
                                : 'تعذّر رفع الصورة.';
                        setFailure(message);
                        continue;
                    }

                    const stored = payload as {
                        file: string;
                        folder: string;
                        url: string;
                        width: number;
                        height: number;
                        renditions: Record<string, Record<string, string>>;
                    };

                    added.push({
                        id: null,
                        // The DB stores "<folder>/<file>" relative to the shared tree, which is
                        // what the storefront's ImageUrl builds from — so the form stores the
                        // same string and never a full URL.
                        path: `${stored.folder}/${stored.file}`,
                        url: stored.url,
                        is_cover: false,
                        alt_ar: '',
                        alt_en: '',
                        width: stored.width,
                        height: stored.height,
                        renditions: stored.renditions ?? {},
                    });
                } catch {
                    setFailure('تعذّر الاتصال بالخادم. حاول مرة أخرى.');
                }
            }

            setBusy(false);
            if (added.length > 0) {
                const next = [...images, ...added];
                // The first image a product ever gets is its cover: a gallery with no cover would
                // make the read layer's choice arbitrary.
                if (!next.some((image) => image.is_cover)) {
                    next[0] = { ...next[0], is_cover: true };
                }
                onChange(next);
            }
        },
        [images, onChange],
    );

    const move = (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= images.length) {
            return;
        }
        const next = [...images];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    const setCover = (index: number) => onChange(images.map((image, i) => ({ ...image, is_cover: i === index })));

    const remove = (index: number) => {
        const next = images.filter((_, i) => i !== index);
        if (next.length > 0 && !next.some((image) => image.is_cover)) {
            next[0] = { ...next[0], is_cover: true };
        }
        onChange(next);
    };

    const setAlt = (index: number, locale: 'ar' | 'en', value: string) =>
        onChange(images.map((image, i) => (i === index ? { ...image, [`alt_${locale}`]: value } : image)));

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle>الصور</CardTitle>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-muted-foreground">{images.length} صورة</span>
                    <input
                        ref={input}
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/avif"
                        className="sr-only"
                        disabled={disabled || busy}
                        onChange={(event) => {
                            if (event.target.files !== null && event.target.files.length > 0) {
                                void upload(event.target.files);
                            }
                            event.target.value = '';
                        }}
                    />
                    <Button type="button" variant="outline" size="sm" disabled={disabled || busy} onClick={() => input.current?.click()} className="gap-2">
                        {busy ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <ImageUp className="h-4 w-4" aria-hidden="true" />}
                        {busy ? 'جارٍ الرفع…' : 'إضافة صور'}
                    </Button>
                </div>
            </CardHeader>

            <CardContent className="space-y-3">
                {failure === null ? null : (
                    <p role="alert" className="text-xs font-medium text-destructive">
                        {failure}
                    </p>
                )}

                {images.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        لا توجد صور. أول صورة تُرفع تصبح صورة الغلاف تلقائيًا.
                    </p>
                ) : null}

                <ul className="space-y-2">
                    {images.map((image, index) => (
                        <li
                            key={image.path + String(index)}
                            className={cn('flex flex-wrap items-start gap-3 rounded-lg border p-3', image.is_cover && 'border-brand bg-brand-muted/40')}
                        >
                            <div className="h-20 w-20 shrink-0 overflow-hidden rounded border bg-muted/40">
                                <img src={image.url} alt="" className="h-full w-full object-contain" loading="lazy" />
                            </div>

                            <div className="min-w-[12rem] flex-1 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate font-mono text-[11px] text-muted-foreground" dir="ltr" title={image.path}>
                                        {image.path}
                                    </span>
                                    {image.is_cover ? <Badge variant="default">الغلاف</Badge> : null}
                                    {image.width !== null ? (
                                        <span className="text-[11px] text-muted-foreground" dir="ltr">
                                            {image.width}×{image.height}
                                        </span>
                                    ) : null}
                                    {Object.keys(image.renditions).length > 0 ? (
                                        <Badge variant="neutral">{Object.keys(image.renditions).join(' · ')}</Badge>
                                    ) : null}
                                </div>

                                <div className="grid gap-2 sm:grid-cols-2">
                                    {/* The alt text is per locale for the same reason the title is:
                                        fallback is off, and an Arabic page with English alt text is
                                        an accessibility hole rather than a nicety. */}
                                    <Input
                                        dir="rtl"
                                        lang="ar"
                                        placeholder="نص بديل (عربي)"
                                        aria-label={`نص بديل عربي للصورة ${index + 1}`}
                                        value={image.alt_ar}
                                        disabled={disabled}
                                        onChange={(event) => setAlt(index, 'ar', event.target.value)}
                                    />
                                    <Input
                                        dir="ltr"
                                        lang="en"
                                        placeholder="Alt text (English)"
                                        aria-label={`Alt text for image ${index + 1}`}
                                        value={image.alt_en}
                                        disabled={disabled}
                                        onChange={(event) => setAlt(index, 'en', event.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`اجعل الصورة ${index + 1} غلافًا`}
                                    disabled={disabled || image.is_cover}
                                    onClick={() => setCover(index)}
                                >
                                    <Star className={cn('h-4 w-4', image.is_cover && 'fill-current')} />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`حرّك الصورة ${index + 1} لأعلى`}
                                    disabled={disabled || index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ChevronUp className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`حرّك الصورة ${index + 1} لأسفل`}
                                    disabled={disabled || index === images.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <ChevronDown className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-destructive"
                                    aria-label={`أزل الصورة ${index + 1}`}
                                    disabled={disabled}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>

                {images.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        الترتيب هنا هو الترتيب على المتجر. إزالة صورة تحذف السجل فقط — الملف يبقى في المجلد المشترك، ويُنظَّف بأمر{' '}
                        <code dir="ltr">media:prune</code> بعد مراجعة تقريره.
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
