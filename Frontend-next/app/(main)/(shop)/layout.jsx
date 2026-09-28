import CatalogBoundary from '@/src/lib/CatalogBoundary'

// Cart, checkout and account still read the whole catalogue in the browser (C-1 stage 4 moves it
// to the server). They share THIS one layout on purpose (checkout fix 4, 2026-09-28): a layout
// persists across client navigation, so /cart → /checkout no longer re-sends the ~3 MB catalogue.
// Each page used to have its own copy of this layout, and every move between them re-sent it.
// The `(shop)` route group changes no URL.
export default function Layout({ children }) {
  return <CatalogBoundary>{children}</CatalogBoundary>
}
