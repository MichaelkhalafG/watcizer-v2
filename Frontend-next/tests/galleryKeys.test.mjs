import { describe, expect, it } from 'vitest'
import { galleryKeyStep, imagePosition, keysBelongToTarget, wrapIndex } from '../src/Hooks/useGalleryKeys'

// The arrow keys on a product gallery (2026-10-21): the reading direction, the wrap, and the controls the
// keys must leave alone. A stub stands in for the focused element: `closest` is all the hook asks of it.
const el = (match) => ({ closest: (sel) => (match && sel.split(',').some((s) => s.trim() === match) ? {} : null) })

describe('the arrows follow the reading direction and wrap', () => {
  it('LTR: right is next, left is previous', () => {
    expect(galleryKeyStep('ArrowRight')).toBe(1)
    expect(galleryKeyStep('ArrowLeft')).toBe(-1)
  })
  it('RTL: the next image sits to the left', () => {
    expect(galleryKeyStep('ArrowRight', true)).toBe(-1)
    expect(galleryKeyStep('ArrowLeft', true)).toBe(1)
  })
  it('any other key is nothing', () => {
    expect(galleryKeyStep('ArrowUp')).toBe(0)
    expect(galleryKeyStep('a', true)).toBe(0)
  })
  it('wraps at both ends', () => {
    expect(wrapIndex(7, 1, 8)).toBe(0)
    expect(wrapIndex(0, -1, 8)).toBe(7)
    expect(wrapIndex(3, 1, 8)).toBe(4)
    expect(wrapIndex(0, 1, 0)).toBe(0)
  })
})

describe('the keys stay with a control that needs them', () => {
  it('a text field, a select, the pickers, a slider, the stepper', () => {
    for (const m of ['input', 'textarea', 'select', '[role="radiogroup"]', '[role="radio"]', '[role="slider"]', '.wz-pd-qty', '[data-gallery-keys="off"]']) {
      expect(keysBelongToTarget(el(m)), m).toBe(true)
    }
  })
  it('the page body, a thumbnail button, nothing at all', () => {
    expect(keysBelongToTarget(el(null))).toBe(false)
    expect(keysBelongToTarget(null)).toBe(false)
    expect(keysBelongToTarget({})).toBe(false)
  })
})

describe('what a screen reader hears', () => {
  it('says the position in the page language', () => {
    expect(imagePosition(1, 8)).toBe('Image 2 of 8')
    expect(imagePosition(1, 8, true)).toBe('صورة ٢ من ٨')
  })
})
