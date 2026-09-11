import { ImageUp, Loader2, Trash2 } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import { Field, type FieldShellProps } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface StoredImage {
    /** The filename the database stores — never a path, never a host (study §5.4). */
    file: string;
    folder: string;
    url: string;
    width: number;
    height: number;
    bytes: number;
    renditions: Record<string, Record<string, string>>;
    skipped: string[];
}

/**
 * Upload-with-preview, talking to `POST /manage/media`.
 *
 * Deliberately a plain `fetch` rather than an Inertia visit: an upload is not a page navigation,
 * and the form must keep the state the user already typed. The CSRF token comes from the cookie
 * Laravel already set, so no meta tag and no extra prop.
 *
 * The preview appears the moment a file is chosen (an object URL), and is swapped for the stored
 * URL when the server answers — so a slow connection still gets instant feedback.
 */
export function ImageField({
    type,
    value,
    onChange,
    disabled = false,
    ...shell
}: FieldShellProps & {
    /** A key from config/media.php: product, product_gallery, brand, category, banner. */
    type: string;
    value: StoredImage | null;
    onChange: (image: StoredImage | null) => void;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    const upload = useCallback(
        async (file: File) => {
            setFailure(null);
            setPreview(URL.createObjectURL(file));
            setBusy(true);

            const body = new FormData();
            body.append('type', type);
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
                    setPreview(null);

                    return;
                }

                onChange(payload as StoredImage);
            } catch {
                setFailure('تعذّر الاتصال بالخادم. حاول مرة أخرى.');
                setPreview(null);
            } finally {
                setBusy(false);
            }
        },
        [onChange, type],
    );

    const shown = preview ?? value?.url ?? null;

    return (
        <Field
            {...shell}
            error={shell.error ?? failure}
            render={(attrs) => (
                <div className="space-y-3">
                    <div
                        className={cn(
                            'flex items-center gap-4 rounded-lg border border-dashed p-4',
                            disabled && 'opacity-60',
                            failure !== null && 'border-destructive/60',
                        )}
                    >
                        <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/40">
                            {shown !== null ? (
                                <img src={shown} alt="" className="h-full w-full object-contain" />
                            ) : (
                                <ImageUp className="h-6 w-6 text-muted-foreground" aria-hidden="true" />
                            )}
                        </div>

                        <div className="min-w-0 flex-1 space-y-2">
                            <input
                                {...attrs}
                                ref={input}
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/avif"
                                disabled={disabled || busy}
                                className="sr-only"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (file !== undefined) {
                                        void upload(file);
                                    }
                                }}
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                <Button type="button" variant="outline" size="sm" disabled={disabled || busy} onClick={() => input.current?.click()} className="gap-2">
                                    {busy ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <ImageUp className="h-4 w-4" aria-hidden="true" />}
                                    {busy ? 'جارٍ الرفع…' : value === null ? 'اختر صورة' : 'استبدال'}
                                </Button>
                                {value !== null ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={disabled || busy}
                                        onClick={() => {
                                            onChange(null);
                                            setPreview(null);
                                        }}
                                        className="gap-2 text-destructive"
                                    >
                                        <Trash2 className="h-4 w-4" aria-hidden="true" />
                                        إزالة
                                    </Button>
                                ) : null}
                            </div>

                            {value !== null ? (
                                <p className="truncate text-xs text-muted-foreground" dir="ltr" title={value.file}>
                                    {value.file} · {value.width}×{value.height} · {Math.round(value.bytes / 1024)} KB
                                    {Object.keys(value.renditions).length > 0 ? ` · ${Object.keys(value.renditions).length} أحجام` : ''}
                                </p>
                            ) : null}

                            {/* When the host could not write AVIF, or a rendition was skipped because
                                the source was too small, say so here rather than in a log nobody reads. */}
                            {value !== null && value.skipped.length > 0 ? (
                                <ul className="space-y-0.5 text-[11px] text-amber-700 dark:text-amber-400" dir="ltr">
                                    {value.skipped.map((reason) => (
                                        <li key={reason}>· {reason}</li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    </div>
                </div>
            )}
        />
    );
}
