'use client'
import './Disclosure.css'

/*
 * A section that opens and closes (both storefronts, 2026-10-22). A TWIN.
 *
 *  • A REAL BUTTON in the heading, `aria-expanded` saying which way it is, `aria-controls` naming
 *    the body: Enter and Space toggle it, Tab reaches it, a screen reader reads its state.
 *  • THE TEXT STAYS IN THE HTML when closed. The body collapses with the grid-template-rows
 *    0fr → 1fr trick (composite-safe, no max-height guess) and is `visibility: hidden` while
 *    closed, so what is inside is not painted, not focusable and not announced — but it is
 *    served, and a crawler reads a product's description whether or not a shopper opened it.
 *  • The chevron is a bordered corner pointing DOWN when closed and UP when open: that is
 *    symmetric, so an Arabic page needs no mirroring; the flex row puts it at the inline end.
 *  • The look is the caller's: `headClassName` styles the button (Watchizer's small tracked caps
 *    and gold rule, Brand Fashion's h2), `className` the section. This file is the mechanics.
 *
 * Controlled: the page owns `open` and `onToggle`, so it can decide the default per section and
 * per viewport in one place.
 */
export default function Disclosure({
  id,
  label,
  open,
  onToggle,
  as: Heading = 'div',
  className = '',
  headClassName = '',
  bodyClassName = '',
  children,
}) {
  const headId = `${id}-head`
  const bodyId = `${id}-body`
  return (
    <section className={`wz-disc${open ? ' is-open' : ''}${className ? ` ${className}` : ''}`} aria-labelledby={headId}>
      <Heading className="wz-disc__heading">
        <button
          type="button"
          id={headId}
          className={`wz-disc__head${headClassName ? ` ${headClassName}` : ''}`}
          aria-expanded={open ? 'true' : 'false'}
          aria-controls={bodyId}
          onClick={onToggle}
        >
          <span className="wz-disc__label">{label}</span>
          <span className="wz-disc__icon" aria-hidden="true" />
        </button>
      </Heading>
      <div id={bodyId} className={`wz-disc__body${bodyClassName ? ` ${bodyClassName}` : ''}`}>
        <div className="wz-disc__inner">{children}</div>
      </div>
    </section>
  )
}
