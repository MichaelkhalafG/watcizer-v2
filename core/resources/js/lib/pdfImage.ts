/**
 * A one-page PDF holding one JPEG, filling the page (2026-11-19, the branch's QR card). Pure: bytes in, bytes out — the
 * dashboard draws the card on a canvas (the browser lays out the Arabic correctly in the dashboard's own font) and this
 * wraps it at the paper's exact size, so it prints at 100 % on A5 or A6. No library: a PDF of one image is five objects.
 * Tested by pdfImage.test.ts.
 */

const MM_TO_PT = 72 / 25.4;

/** The page size in PDF points (1/72 inch), two decimals, as written in /MediaBox. */
export function pagePoints(widthMm: number, heightMm: number): { w: number; h: number } {
    const round = (v: number) => Math.round(v * 100) / 100;
    return { w: round(widthMm * MM_TO_PT), h: round(heightMm * MM_TO_PT) };
}

/**
 * @param jpeg     the JPEG file's bytes (DCT-encoded, RGB)
 * @param pxW      the image's width in pixels
 * @param pxH      the image's height in pixels
 */
export function jpegPdf(jpeg: Uint8Array, pxW: number, pxH: number, widthMm: number, heightMm: number): Uint8Array<ArrayBuffer> {
    const { w, h } = pagePoints(widthMm, heightMm);
    const enc = new TextEncoder();
    const parts: Uint8Array[] = [];
    const offsets: number[] = [];
    let length = 0;
    const push = (chunk: Uint8Array | string) => {
        const bytes = typeof chunk === 'string' ? enc.encode(chunk) : chunk;
        parts.push(bytes);
        length += bytes.length;
    };
    const object = (n: number, body: string | (() => void)) => {
        offsets[n] = length;
        push(`${n} 0 obj\n`);
        if (typeof body === 'string') {
            push(body);
        } else {
            body();
        }
        push('\nendobj\n');
    };

    // the header, with a comment of high bytes so every reader treats the file as binary
    push('%PDF-1.4\n');
    push(new Uint8Array([0x25, 0xe2, 0xe3, 0xcf, 0xd3, 0x0a]));
    const content = `q ${w} 0 0 ${h} 0 0 cm /Im0 Do Q`;
    object(1, '<< /Type /Catalog /Pages 2 0 R >>');
    object(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
    object(3, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`);
    object(4, () => {
        push(`<< /Type /XObject /Subtype /Image /Width ${pxW} /Height ${pxH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`);
        push(jpeg);
        push('\nendstream');
    });
    object(5, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`);

    const xref = length;
    let table = `xref\n0 6\n0000000000 65535 f \n`;
    for (let n = 1; n <= 5; n++) {
        table += `${String(offsets[n]).padStart(10, '0')} 00000 n \n`;
    }
    push(table);
    push(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);

    const out = new Uint8Array(length);
    let at = 0;
    for (const p of parts) {
        out.set(p, at);
        at += p.length;
    }
    return out;
}
