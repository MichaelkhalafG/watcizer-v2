import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerTables, getServerNav } from '@/src/lib/serverCatalog'
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
    // The lookup tables alone, process-cached (5-min TTL) — C-1 stage 4: this used to fetch the
    // whole catalogue just to take the tables out of it. Every page's server render needs them
    // (names, card transforms); without this line the home rails, the brand strip and every card
    // rendered only after the BROWSER fetched the tables (slice B dropped it; restored in slice D).
    // No page embeds the catalogue any more (C-1 stage 4, slice D).
    qc.setQueryData(['tables'], await getServerTables())
  } catch {
    // Tables unreachable server-side → the client fetches them + shows error/retry.
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
