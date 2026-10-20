import { Loader2, Minus, Plus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export interface CropRect {
    x: number;
    y: number;
    w: number;
    h: number;
}

/**
 * Crop an image to ONE fixed shape, in the browser, before it is stored (F1, 2026-10-20).
 *
 * ── Why this is hand-written and not a library ──────────────────────────────────────────────
 *
 * The crop here is not free-form: the device decides the shape, so the whole interaction is "move
 * and scale the picture inside a frame I cannot resize". That is the part a crop library mostly
 * does not give you — `react-easy-crop` (~12 KB gzipped) and `react-image-crop` (~6 KB) both hand
 * back a rectangle and leave the canvas work, which is the half that actually produces the file.
 * What they would add is free-form handles, aspect pickers and their own gesture layer, none of
 * which is wanted, against a new dependency in a dashboard that is already live and an upgrade to
 * track. The maths that remains is small and bounded — cover-scale, clamp, draw — and doing it here
 * keeps the three things this dashboard cannot compromise on: it reads right-to-left, it follows
 * the dark theme, and every word is translated.
 *
 * ── The rectangle is in the ORIGINAL's pixels ───────────────────────────────────────────────
 *
 * `{x, y, w, h}` describes the part of the UPLOADED image that was kept, so the tool reopens
 * exactly where it was left even though the preview is drawn at whatever size the dialog happens
 * to be. The file that comes out is drawn at the recommended size for the device.
 *
 * ── The canvas must not be tainted ──────────────────────────────────────────────────────────
 *
 * A freshly chosen file is read from the local `File`, so there is no origin to cross. A banner
 * being RE-cropped is read from the dashboard's own host (`/Uploads_Images/…`) rather than from the
 * storefront's asset host: same origin, so `toBlob` is allowed to hand back the bytes.
 */
export function ImageCrop({
    src,
    ratio,
    output,
    initial,
    busy = false,
    onCancel,
    onDone,
}: {
    /** The ORIGINAL image: an object URL for a new file, or a same-origin path for a stored one. */
    src: string;
    /** The shape to crop to, as the hero renders it. */
    ratio: { w: number; h: number };
    /** The pixel size the cropped file is written at. */
    output: { w: number; h: number };
    initial: CropRect | null;
    busy?: boolean;
    onCancel: () => void;
    onDone: (file: File, rect: CropRect) => void;
}) {
    const t = useT();
    const frameRef = useRef<HTMLDivElement>(null);
    const imageRef = useRef<HTMLImageElement | null>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    const [zoom, setZoom] = useState(1);
    // the centre of the crop, as a fraction of the original (0..1) — resolution-independent
    const [centre, setCentre] = useState({ x: 0.5, y: 0.5 });
    const [failed, setFailed] = useState(false);
    const drag = useRef<{ x: number; y: number; cx: number; cy: number } | null>(null);

    useEffect(() => {
        setFailed(false);
        const img = new Image();
        img.decoding = 'async';
        img.onload = () => {
            imageRef.current = img;
            setNatural({ w: img.naturalWidth, h: img.naturalHeight });
        };
        img.onerror = () => setFailed(true);
        img.src = src;

        return () => {
            img.onload = null;
            img.onerror = null;
        };
    }, [src]);

    // The widest crop of this shape that fits inside the original — zoom 1 — and the rectangle the
    // current zoom and centre describe, clamped so the crop can never leave the picture.
    const rectAt = useCallback(
        (z: number, c: { x: number; y: number }): CropRect | null => {
            if (!natural) return null;
            const base = Math.min(natural.w / ratio.w, natural.h / ratio.h);
            const w = Math.min((ratio.w * base) / z, natural.w);
            const h = Math.min((ratio.h * base) / z, natural.h);
            const x = Math.min(Math.max(c.x * natural.w - w / 2, 0), natural.w - w);
            const y = Math.min(Math.max(c.y * natural.h - h / 2, 0), natural.h - h);

            return { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) };
        },
        [natural, ratio.h, ratio.w],
    );

    // Reopen where the operator left it: turn the saved rectangle back into a zoom and a centre.
    useEffect(() => {
        if (!natural || !initial) return;
        const base = Math.min(natural.w / ratio.w, natural.h / ratio.h);
        const z = initial.w > 0 ? Math.min(Math.max((ratio.w * base) / initial.w, 1), 4) : 1;
        setZoom(z);
        setCentre({
            x: (initial.x + initial.w / 2) / natural.w,
            y: (initial.y + initial.h / 2) / natural.h,
        });
    }, [natural, initial, ratio.h, ratio.w]);

    const rect = rectAt(zoom, centre);

    const onPointerDown = (e: React.PointerEvent) => {
        if (!rect) return;
        drag.current = { x: e.clientX, y: e.clientY, cx: centre.x, cy: centre.y };
        e.currentTarget.setPointerCapture(e.pointerId);
    };
    const onPointerMove = (e: React.PointerEvent) => {
        const d = drag.current;
        const frame = frameRef.current;
        if (!d || !frame || !natural || !rect) return;
        // a pixel on screen is `rect.w / frameWidth` pixels of the original
        const perPx = rect.w / frame.clientWidth;
        setCentre({
            x: d.cx - ((e.clientX - d.x) * perPx) / natural.w,
            y: d.cy - ((e.clientY - d.y) * perPx) / natural.h,
        });
    };
    const onPointerUp = () => {
        drag.current = null;
    };

    const apply = () => {
        const img = imageRef.current;
        if (!img || !rect) return;
        const canvas = document.createElement('canvas');
        canvas.width = output.w;
        canvas.height = output.h;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, rect.x, rect.y, rect.w, rect.h, 0, 0, output.w, output.h);
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setFailed(true);

                    return;
                }
                onDone(new File([blob], `banner-${Date.now()}.webp`, { type: 'image/webp' }), rect);
            },
            'image/webp',
            0.9,
        );
    };

    // what the frame shows: the original, scaled so `rect` fills it exactly
    const preview =
        natural && rect
            ? {
                  width: `${(natural.w / rect.w) * 100}%`,
                  height: `${(natural.h / rect.h) * 100}%`,
                  left: `${(-rect.x / rect.w) * 100}%`,
                  top: `${(-rect.y / rect.h) * 100}%`,
              }
            : undefined;

    return (
        <div className="space-y-3">
            <div
                ref={frameRef}
                className="relative w-full overflow-hidden rounded-md border border-border bg-muted"
                style={{ aspectRatio: `${ratio.w} / ${ratio.h}`, cursor: drag.current ? 'grabbing' : 'grab', touchAction: 'none' }}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
            >
                {failed ? (
                    <p className="absolute inset-0 grid place-items-center p-4 text-center text-sm text-muted-foreground">
                        {t('banners.crop_unreadable', 'تعذّر قراءة الصورة الأصلية. ارفع صورة جديدة.')}
                    </p>
                ) : !natural ? (
                    <span className="absolute inset-0 grid place-items-center">
                        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                    </span>
                ) : (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={src} alt="" draggable={false} className="absolute max-w-none select-none" style={preview} />
                )}
            </div>

            <div className="flex items-center gap-2">
                <Button type="button" variant="outline" size="icon" onClick={() => setZoom((z) => Math.max(1, +(z - 0.1).toFixed(2)))} aria-label={t('banners.crop_out', 'تصغير')}>
                    <Minus className="h-4 w-4" />
                </Button>
                <input
                    type="range"
                    min={1}
                    max={4}
                    step={0.01}
                    value={zoom}
                    onChange={(e) => setZoom(Number(e.target.value))}
                    className="h-1 flex-1 cursor-pointer accent-primary"
                    aria-label={t('banners.crop_zoom', 'التكبير')}
                />
                <Button type="button" variant="outline" size="icon" onClick={() => setZoom((z) => Math.min(4, +(z + 0.1).toFixed(2)))} aria-label={t('banners.crop_in', 'تكبير')}>
                    <Plus className="h-4 w-4" />
                </Button>
            </div>

            <div className="flex items-center justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onCancel} disabled={busy}>
                    {t('common.cancel', 'إلغاء')}
                </Button>
                <Button type="button" onClick={apply} disabled={busy || !rect || failed}>
                    {busy ? <Loader2 className="me-2 h-4 w-4 animate-spin" /> : null}
                    {t('banners.crop_apply', 'قصّ واستخدم')}
                </Button>
            </div>
        </div>
    );
}
