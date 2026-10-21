import { useEffect } from 'react'

/*
 * ← and → move between a product's images (both storefronts, 2026-10-21). A TWIN.
 *
 *  • THE READING DIRECTION. On an Arabic page the gallery runs right to left — the next thumbnail sits to
 *    the LEFT — so there ArrowLeft is next and ArrowRight is previous. That is the WAI-ARIA carousel practice
 *    (arrows move the way the strip runs) and it is what the swipe already does; a Latin-only convention
 *    would have the right arrow walk the strip backwards for an Arabic reader.
 *  • WRAP at the ends, because the swipe on both pages wraps and two gestures on one gallery must agree.
 *  • NEVER from a control that needs the keys: an input, a textarea, a select, anything editable, a
 *    radiogroup (the colour and size pickers walk their options with the arrows), a slider, a listbox, a
 *    menu, a tablist, the quantity stepper, or anything inside an open modal (the cart drawer) — the key
 *    then does what it always did. A thumbnail button or the page body is where the keys apply.
 */

const OWN_KEYS =
  'input, textarea, select, [contenteditable=""], [contenteditable="true"], [role="radiogroup"], [role="radio"], ' +
  '[role="slider"], [role="listbox"], [role="menu"], [role="tablist"], .wz-pd-qty, [data-gallery-keys="off"]'

/** Does this focused element own the arrow keys itself? */
export function keysBelongToTarget(el) {
  return !!el && typeof el.closest === 'function' && el.closest(OWN_KEYS) !== null
}

/** The step an arrow asks for, in the reading direction: +1 next, −1 previous, 0 for any other key. */
export function galleryKeyStep(key, rtl = false) {
  if (key === 'ArrowRight') return rtl ? -1 : 1
  if (key === 'ArrowLeft') return rtl ? 1 : -1
  return 0
}

/** The index after a step, wrapping at both ends. */
export function wrapIndex(index, step, count) {
  if (!(count > 0)) return 0
  return (((index + step) % count) + count) % count
}

/** What a screen reader is told after a move. */
export function imagePosition(index, count, rtl = false) {
  return rtl
    ? `صورة ${(index + 1).toLocaleString('ar-EG')} من ${count.toLocaleString('ar-EG')}`
    : `Image ${index + 1} of ${count}`
}

export default function useGalleryKeys({ count, index, onChange, rtl = false, enabled = true }) {
  useEffect(() => {
    if (!enabled || !(count > 1)) return undefined
    const onKey = (e) => {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return
      const step = galleryKeyStep(e.key, rtl)
      if (!step) return
      if (keysBelongToTarget(e.target)) return
      // an OPEN modal owns the keys. The cart drawer is a dialog at all times and `inert` while closed (measured:
      // this guard without `:not([inert])` refused every key on a page with a closed drawer)
      if (document.querySelector('[role="dialog"][aria-modal="true"]:not([inert]):not([hidden]), .wz-cartmodal.is-open')) return
      e.preventDefault()
      onChange(wrapIndex(index, step, count))
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [count, index, onChange, rtl, enabled])
}
