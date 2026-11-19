// node --test resources/js/lib/tableScroll.test.ts  (Node strips the types; no build step)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { arrowStep, dragScroll, headerShift, keyScroll, pinCount, pinOffsets, showsBottomBar, startsDrag } from './tableScroll.ts';

test('arrow keys move the table the way they point, in English and in Arabic', () => {
    // English: 0 at the left, up to +max
    assert.equal(keyScroll('ArrowRight', 0, 900, 400, false), 100);
    assert.equal(keyScroll('ArrowLeft', 100, 900, 400, false), 0);
    assert.equal(keyScroll('ArrowLeft', 0, 900, 400, false), 0);
    assert.equal(keyScroll('ArrowRight', 880, 900, 400, false), 900);
    // Arabic: 0 at the right, down to -max; Left goes further into the table
    assert.equal(keyScroll('ArrowLeft', 0, 900, 400, true), -100);
    assert.equal(keyScroll('ArrowRight', -100, 900, 400, true), 0);
    assert.equal(keyScroll('ArrowRight', 0, 900, 400, true), 0);
    assert.equal(keyScroll('ArrowLeft', -880, 900, 400, true), -900);
});

test('Home goes to the start and End to the end, the start being the right side in Arabic', () => {
    assert.equal(keyScroll('Home', 500, 900, 400, false), 0);
    assert.equal(keyScroll('End', 0, 900, 400, false), 900);
    assert.equal(keyScroll('Home', -500, 900, 400, true), 0);
    assert.equal(keyScroll('End', 0, 900, 400, true), -900);
    assert.equal(keyScroll('ArrowUp', 0, 900, 400, false), null);
    assert.equal(keyScroll('a', 0, 900, 400, true), null);
});

test('one arrow press moves a quarter of the visible width, never less than 80 px', () => {
    assert.equal(arrowStep(400), 100);
    assert.equal(arrowStep(200), 80);
});

test('a press becomes a drag only past 6 px and mostly sideways: a short click still clicks', () => {
    assert.equal(startsDrag(3, 0), false);
    assert.equal(startsDrag(-5, 1), false);
    assert.equal(startsDrag(7, 2), true);
    assert.equal(startsDrag(-7, 2), true);
    assert.equal(startsDrag(8, 12), false); // mostly down: a text selection, not a drag
    assert.equal(dragScroll(300, 50), 250);
    assert.equal(dragScroll(-300, -50), -250);
});

test('the header row stays under the top bar while the table passes under it, within the table', () => {
    // the table still below the bar: no move
    assert.equal(headerShift(200, 3000, 44, 64), 0);
    // scrolled 500 px under the bar: the header moves 564 - 64 = 500 + 64 - 64
    assert.equal(headerShift(-436, 3000, 44, 64), 500);
    // never past the last rows
    assert.equal(headerShift(-5000, 3000, 44, 64), 3000 - 88);
});

test('pins the first column, and the second too after a narrow # or checkbox column', () => {
    assert.equal(pinCount([40, 220, 180, 300]), 2);
    assert.equal(pinCount([240, 120, 300]), 1);
    assert.equal(pinCount([40]), 1);
    assert.deepEqual(pinOffsets([40, 220, 180], 2), [0, 40]);
    assert.deepEqual(pinOffsets([240, 120], 1), [0]);
});

test('the bottom scrollbar shows only while the table overflows, is on screen and its own bar is below the fold', () => {
    assert.equal(showsBottomBar(true, 300, 2400, 800), true);
    assert.equal(showsBottomBar(false, 300, 2400, 800), false);
    assert.equal(showsBottomBar(true, 300, 700, 800), false); // its own bar is visible
    assert.equal(showsBottomBar(true, 790, 2400, 800), false); // only its top edge peeks in
    assert.equal(showsBottomBar(true, -2000, 600, 800), false); // scrolled past
});
