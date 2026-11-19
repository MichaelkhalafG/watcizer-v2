// node --test resources/js/lib/qrCard.test.ts  (Node strips the types; no build step)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { jpegPdf, pagePoints } from './pdfImage.ts';
import { CARD_WORDS, PAPER, cardFileName, pixelsOf, qrMatrix } from './qrCard.ts';

test('the QR holds the branch address with the three finder squares in their corners', () => {
    const link = 'https://watchizereg.com/review/arkadia';
    const m = qrMatrix(link);
    const n = m.length;
    assert.ok(n >= 25, `version 2 or more for a ${link.length}-character address, got ${n}`);
    assert.equal((n - 17) % 4, 0, 'a QR side is 17 + 4 x version');
    for (const row of m) assert.equal(row.length, n);
    // a finder square: a dark 7x7 ring, a white ring inside it, a dark 3x3 centre
    const finder = (r0: number, c0: number) => {
        for (let i = 0; i < 7; i++) {
            assert.equal(m[r0][c0 + i], true);
            assert.equal(m[r0 + 6][c0 + i], true);
            assert.equal(m[r0 + i][c0], true);
            assert.equal(m[r0 + i][c0 + 6], true);
        }
        assert.equal(m[r0 + 1][c0 + 1], false);
        assert.equal(m[r0 + 3][c0 + 3], true);
    };
    finder(0, 0);
    finder(0, n - 7);
    finder(n - 7, 0);
    // a different address gives a different code
    assert.notDeepEqual(qrMatrix('https://watchizereg.com/review/heliopolis'), m);
});

test('the card sizes: A5 table card and A6 counter card, at 200 dpi', () => {
    assert.deepEqual(PAPER.A5, { w: 148, h: 210 });
    assert.deepEqual(PAPER.A6, { w: 105, h: 148 });
    assert.deepEqual(pixelsOf('A5'), { w: 1165, h: 1654 });
    assert.deepEqual(pixelsOf('A6'), { w: 827, h: 1165 });
});

test('the words ask for an honest review in both languages: no incentive, no stars', () => {
    for (const lang of ['en', 'ar'] as const) {
        const all = Object.values(CARD_WORDS[lang]).join(' ');
        assert.ok(all.length > 10);
        assert.doesNotMatch(all, /discount|coupon|gift|free|prize|win|5 stars|five stars|خصم|كوبون|هدية|مجان|جائزة|خمس نجوم/i);
    }
    assert.match(CARD_WORDS.en.line, /honest/);
    assert.match(CARD_WORDS.ar.line, /بصدق/);
});

test('the files are named by shop, branch, card and language, in ASCII', () => {
    assert.equal(cardFileName('watchizer', 'arkadia', 'A5', 'ar', 'pdf'), 'watchizer-arkadia-review-a5-ar.pdf');
    assert.equal(cardFileName('brandfashion', 'heliopolis', 'qr', null, 'png'), 'brandfashion-heliopolis-review-qr.png');
});

test('the PDF is one page at the paper size, holding the JPEG, with a correct cross-reference table', () => {
    // a stand-in "JPEG": the builder never reads the image, it embeds the bytes
    const jpeg = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3, 4, 0xff, 0xd9]);
    const pdf = jpegPdf(jpeg, 1165, 1654, 148, 210);
    const text = new TextDecoder('latin1').decode(pdf);
    assert.ok(text.startsWith('%PDF-1.4\n'));
    assert.ok(text.trimEnd().endsWith('%%EOF'));
    const { w, h } = pagePoints(148, 210);
    assert.equal(w, 419.53);
    assert.equal(h, 595.28);
    assert.match(text, /\/MediaBox \[0 0 419\.53 595\.28\]/);
    assert.match(text, /\/Width 1165 \/Height 1654 \/ColorSpace \/DeviceRGB \/BitsPerComponent 8 \/Filter \/DCTDecode \/Length 10/);
    assert.match(text, /\/Count 1/);
    // every xref offset points at its object
    const xrefAt = Number(/startxref\n(\d+)/.exec(text)?.[1]);
    assert.ok(text.slice(xrefAt).startsWith('xref\n0 6\n'));
    const offsets = [...text.slice(xrefAt).matchAll(/^(\d{10}) 00000 n $/gm)].map((m) => Number(m[1]));
    assert.equal(offsets.length, 5);
    offsets.forEach((at, i) => assert.ok(text.slice(at).startsWith(`${i + 1} 0 obj`), `object ${i + 1} at ${at}`));
    // the image bytes are inside, whole
    const start = text.indexOf('stream\n') + 'stream\n'.length;
    assert.deepEqual([...pdf.slice(start, start + jpeg.length)], [...jpeg]);
    // A6 too
    assert.match(new TextDecoder('latin1').decode(jpegPdf(jpeg, 827, 1165, 105, 148)), /\/MediaBox \[0 0 297\.64 419\.53\]/);
});
