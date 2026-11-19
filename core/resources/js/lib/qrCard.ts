import qrcode from 'qrcode-generator';

/**
 * THE BRANCH'S QR CARD (2026-11-19, part 3 of the three fixes) — the pure part: the QR code's squares, the two paper
 * sizes and the card's words. The drawing (a canvas) and the files (PDF, PNG) are lib/qrCardDraw.ts; the PDF wrapper is
 * lib/pdfImage.ts. Tested by qrCard.test.ts.
 *
 * The QR always holds the branch's address ON THE SHOP (/review/<branch>), never Google's directly: that address counts
 * the scans and sends on to the branch's Google page, so a printed card keeps working if the Google link changes.
 * The words ask for an HONEST review: no incentive, no gift, no star-gating — the same rules as the e-mail.
 */

/** The QR's squares, row by row: true = dark. Error correction M (15 %), the size chosen for the address. */
export function qrMatrix(text: string): boolean[][] {
    const qr = qrcode(0, 'M');
    qr.addData(text, 'Byte');
    qr.make();
    const n = qr.getModuleCount();
    const rows: boolean[][] = [];
    for (let r = 0; r < n; r++) {
        const row: boolean[] = [];
        for (let c = 0; c < n; c++) {
            row.push(qr.isDark(r, c));
        }
        rows.push(row);
    }
    return rows;
}

export type CardSize = 'A5' | 'A6';
export type CardLang = 'en' | 'ar';

/** The paper, portrait, in millimetres: A5 for a table card, A6 for a counter card. */
export const PAPER: Record<CardSize, { w: number; h: number }> = {
    A5: { w: 148, h: 210 },
    A6: { w: 105, h: 148 },
};

/** Drawn at 200 dots per inch: sharp in print, small as a file. */
export const DPI = 200;

/** The paper size in pixels at {@link DPI}. */
export function pixelsOf(size: CardSize): { w: number; h: number } {
    const mm = PAPER[size];
    return { w: Math.round((mm.w / 25.4) * DPI), h: Math.round((mm.h / 25.4) * DPI) };
}

/** The card's words, per language: a heading, the one line asking for an honest review, and the hint under the code. */
export const CARD_WORDS: Record<CardLang, { heading: string; line: string; hint: string }> = {
    en: {
        heading: 'How was your visit?',
        line: 'Scan the code and leave us an honest review. It takes a minute.',
        hint: 'Or open:',
    },
    ar: {
        heading: 'كيف كانت زيارتك؟', // i18n-exempt: CARD COPY: the printed card in the language chosen for it, not the dashboard language
        line: 'امسح الرمز واكتب لنا رأيك بصدق، يستغرق دقيقة.', // i18n-exempt: CARD COPY: the printed card in the language chosen for it, not the dashboard language
        hint: 'أو افتح:', // i18n-exempt: CARD COPY: the printed card in the language chosen for it, not the dashboard language
    },
};

/** The file name: shop, branch and the card, ASCII only. */
export function cardFileName(shopCode: string, branchKey: string, size: CardSize | 'qr', lang: CardLang | null, ext: 'pdf' | 'png'): string {
    const safe = (s: string) => s.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '') || 'x';
    return [safe(shopCode), safe(branchKey), 'review', size === 'qr' ? 'qr' : size.toLowerCase(), lang ?? ''].filter(Boolean).join('-') + '.' + ext;
}
