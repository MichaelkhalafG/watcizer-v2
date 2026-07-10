import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog } from '@/src/lib/serverCatalog'
import { projectCatalogForHydration } from '@/src/lib/catalogProjection'
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
    // Dehydrate a LIGHT card projection — NOT the full ~17 MB catalog (C-1 fix).
    // Cards/filters/search/facets read only these fields; the product detail page
    // fetches the full record by id. See src/lib/catalogProjection.js.
    qc.setQueryData(['products'], projectCatalogForHydration(catalog))
  } catch {
    // Catalog unreachable server-side → client fetches + shows error/retry.
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
