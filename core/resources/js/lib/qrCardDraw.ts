import { jpegPdf } from './pdfImage';
import { CARD_WORDS, PAPER, cardFileName, pixelsOf, qrMatrix, type CardLang, type CardSize } from './qrCard';

/**
 * THE BRANCH'S QR CARD, DRAWN (2026-11-19) — the browser part: a canvas at the paper's size, saved as a print-ready PDF
 * (A5 table card or A6 counter card, English or Arabic) or the QR alone as a PNG. The canvas lays out the Arabic in the
 * dashboard's own font, right to left; the shop's logo is core's own file (same origin), so the canvas stays exportable.
 */

export interface CardBranch {
    shop: string;
    shopCode: string;
    branchName: string;
    reviewKey: string;
    link: string;
    logo: string | null;
}

const INK = '#111111';
const MUTED = '#5b5b5b';

function fontFamily(): string {
    return getComputedStyle(document.body).fontFamily || 'system-ui, sans-serif';
}

function loadImage(src: string): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = src;
    });
}

/** Draw the QR's squares, with its quiet zone (4 squares of white around it), in a square of `size` px at (x, y). */
export function drawQr(ctx: CanvasRenderingContext2D, matrix: boolean[][], x: number, y: number, size: number): void {
    const n = matrix.length + 8;
    const cell = size / n;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(x, y, size, size);
    ctx.fillStyle = '#000000';
    matrix.forEach((row, r) =>
        row.forEach((dark, c) => {
            if (dark) {
                // a hair wider than the cell, so no white seam shows between squares when printed
                ctx.fillRect(Math.floor(x + (c + 4) * cell), Math.floor(y + (r + 4) * cell), Math.ceil(cell) + 0.5, Math.ceil(cell) + 0.5);
            }
        }),
    );
}

/** Wrap a line to the width, word by word. */
function wrap(ctx: CanvasRenderingContext2D, text: string, width: number): string[] {
    const words = text.split(/\s+/);
    const lines: string[] = [];
    let line = '';
    for (const w of words) {
        const next = line ? `${line} ${w}` : w;
        if (ctx.measureText(next).width > width && line) {
            lines.push(line);
            line = w;
        } else {
            line = next;
        }
    }
    if (line) {
        lines.push(line);
    }
    return lines;
}

/** The card on a canvas: the logo, the heading, the honest-review line, the QR, the shop and branch, the address. */
export async function cardCanvas(branch: CardBranch, size: CardSize, lang: CardLang): Promise<HTMLCanvasElement> {
    const { w, h } = pixelsOf(size);
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (ctx === null) {
        throw new Error('no canvas');
    }
    const family = fontFamily();
    const words = CARD_WORDS[lang];
    const u = w / 100; // one hundredth of the width
    await document.fonts?.ready;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    // a thin frame inside the cut line
    ctx.strokeStyle = '#d9d9d9';
    ctx.lineWidth = Math.max(2, u * 0.25);
    ctx.strokeRect(u * 3, u * 3, w - u * 6, h - u * 6);

    ctx.direction = lang === 'ar' ? 'rtl' : 'ltr';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    let y = u * 10;

    const logo = branch.logo ? await loadImage(branch.logo) : null;
    if (logo) {
        const lw = Math.min(w * 0.45, logo.naturalWidth * 4);
        const lh = (lw / logo.naturalWidth) * logo.naturalHeight;
        ctx.drawImage(logo, (w - lw) / 2, y, lw, lh);
        y += lh + u * 7;
    } else {
        ctx.fillStyle = INK;
        ctx.font = `700 ${u * 6}px ${family}`;
        y += u * 6;
        ctx.fillText(branch.shop, w / 2, y);
        y += u * 6;
    }

    ctx.fillStyle = INK;
    ctx.font = `700 ${u * 6.4}px ${family}`;
    y += u * 6;
    ctx.fillText(words.heading, w / 2, y);
    y += u * 4;

    ctx.fillStyle = MUTED;
    ctx.font = `400 ${u * 4}px ${family}`;
    for (const line of wrap(ctx, words.line, w * 0.8)) {
        y += u * 5.6;
        ctx.fillText(line, w / 2, y);
    }
    y += u * 5;

    const qrSize = Math.min(w * 0.62, h - y - u * 30);
    drawQr(ctx, qrMatrix(branch.link), (w - qrSize) / 2, y, qrSize);
    y += qrSize + u * 6;

    ctx.fillStyle = INK;
    ctx.font = `600 ${u * 4.4}px ${family}`;
    ctx.fillText(`${branch.shop} · ${branch.branchName}`, w / 2, y);
    y += u * 6;

    ctx.fillStyle = MUTED;
    ctx.font = `400 ${u * 3.2}px ${family}`;
    ctx.fillText(words.hint, w / 2, y);
    y += u * 4.6;
    // the address always left to right
    ctx.direction = 'ltr';
    ctx.font = `500 ${u * 3.4}px ${family}`;
    ctx.fillText(branch.link.replace(/^https?:\/\//, ''), w / 2, y);

    return canvas;
}

function toBlob(canvas: HTMLCanvasElement, type: string, quality?: number): Promise<Blob> {
    return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('no image'))), type, quality));
}

function save(blob: Blob, name: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    // kept for a minute: a browser that starts writing the file late must still find it (a 1 s revoke cut downloads short)
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** The card as a print-ready PDF at the paper's exact size. Returns the bytes too (for the checks). */
export async function downloadCardPdf(branch: CardBranch, size: CardSize, lang: CardLang): Promise<Uint8Array> {
    const canvas = await cardCanvas(branch, size, lang);
    const jpeg = new Uint8Array(await (await toBlob(canvas, 'image/jpeg', 0.92)).arrayBuffer());
    const pdf = jpegPdf(jpeg, canvas.width, canvas.height, PAPER[size].w, PAPER[size].h);
    save(new Blob([pdf], { type: 'application/pdf' }), cardFileName(branch.shopCode, branch.reviewKey, size, lang, 'pdf'));
    return pdf;
}

/** The QR alone as a PNG, 1200 px square with its quiet zone. Returns the bytes too (for the checks). */
export async function downloadQrPng(branch: CardBranch): Promise<Uint8Array> {
    const canvas = document.createElement('canvas');
    canvas.width = 1200;
    canvas.height = 1200;
    const ctx = canvas.getContext('2d');
    if (ctx === null) {
        throw new Error('no canvas');
    }
    drawQr(ctx, qrMatrix(branch.link), 0, 0, 1200);
    const blob = await toBlob(canvas, 'image/png');
    save(blob, cardFileName(branch.shopCode, branch.reviewKey, 'qr', null, 'png'));
    return new Uint8Array(await blob.arrayBuffer());
}
