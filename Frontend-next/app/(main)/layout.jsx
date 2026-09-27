import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog, getServerNav } from '@/src/lib/serverCatalog'
import AppStateBridge from './app-state-bridge'
import Header from '@/src/Components/Header/Header'
import CartModalHost from '../cart-modal-host'
import SkipLink from './skip-link'
import DesktopFooter from './desktop-footer'

// Main app chrome (mirrors Frontend App.jsx MainApp): skip link → sticky header
// → #main-content landmark → desktop-only footer.
//
// CRITICAL for SSR data: the PUBLIC catalog is prefetched HERE and the
// HydrationBoundary wraps EVERYTHING that reads it (Header nav, the cart drawer,
// and the page). <AppStateBridge/> (formerly MyProvider's effects) sits INSIDE
// the boundary so it consumes the queries BELOW it — the server render reads
// already-hydrated data, so product cards render in the SSR HTML instead of
// skeletons. try/catch: on a server-side catalog failure we hydrate an empty
// cache and the client refetches + shows the existing inline error/retry.
export default async function MainLayout({ children }) {
  const qc = new QueryClient()
  try {
    // getServerCatalog is process-cached (5-min TTL) and shared with the listing
    // page + generateMetadata, so this is ONE Laravel round-trip, not one per nav.
    const catalog = await getServerCatalog()
    qc.setQueryData(['tables'], catalog.tables)
    // The catalogue itself is NOT embedded here any more (C-1 stage 3): only the pages that still
    // read it wrap themselves in <CatalogBoundary> (home, product/offer detail, cart, checkout,
    // account). The listing, the brand/category pages and everything else carry no copy.
  } catch {
    // Catalog unreachable server-side → client fetches + shows error/retry.
  }
  try {
    // The header menu's facts (C-1 stage 2): the menu renders in the server HTML without the catalogue.
    qc.setQueryData(['nav'], await getServerNav())
  } catch {
    // Unreachable → the menu fills in when the client fetch lands.
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <AppStateBridge />
      <SkipLink />
      <div className="wz-header-wrapper">
        <Header />
      </div>
      <main id="main-content" tabIndex={-1}>
        {children}
      </main>
      <DesktopFooter />
      {/* Cart drawer lives here (below the boundary) — CartModal reads the
          catalog to resolve line-item names. */}
      <CartModalHost />
    </HydrationBoundary>
  )
}
