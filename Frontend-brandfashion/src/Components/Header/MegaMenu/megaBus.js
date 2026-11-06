// The one handle on the mega menu (the developer's review, 2026-11-05). A TWIN.
//
// ONE MENU, NOT TWO: every entry of the nav row — a category, a gender, Brands, Sale — opens THE SAME panel on
// its own entry; "All categories" opens it on the first. The nav row belongs to each shop (its Nav.jsx is not a
// twin) and the panel is the twin MegaMenu, so the row talks to the panel through this small bus instead of
// each shop wiring refs through its own tree: the mounted MegaMenu registers itself, and a nav item spreads
// `megaItemProps(target)` on its <li>.
//
//   target   { kind: 'category', legacyId, slug } | { kind: 'gender', name } | { kind: 'brands' } | { kind: 'sale' }
//
// Pointing at an item opens the panel on that entry; leaving it starts the panel's own close delay (the panel
// cancels it when the pointer arrives — and a bridge above the panel leaves no gap to fall through); ArrowDown
// on a focused item opens the panel and moves focus into it, so the keyboard reaches everything the pointer does.

let handler = null

export const megaBus = {
  /** The mounted menu registers itself; returns the un-register function for its effect cleanup. */
  register(next) {
    handler = next
    return () => {
      if (handler === next) handler = null
    }
  },
  open(target, options) {
    handler?.open(target, options || {})
  },
  closeSoon() {
    handler?.closeSoon()
  },
}

/** What a nav item's <li> spreads to take part in the one menu. */
export const megaItemProps = (target) => ({
  onMouseEnter: () => megaBus.open(target),
  onMouseLeave: () => megaBus.closeSoon(),
  onKeyDown: (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      megaBus.open(target, { focus: true })
    }
  },
})
