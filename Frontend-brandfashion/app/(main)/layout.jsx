import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerTables, getServerNav } from '@/src/lib/serverCatalog'
import { getServerV2Campaigns } from '@/src/lib/serverV2'
import { slimCampaigns } from '@/src/lib/campaign'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { LiveOffersProvider } from '@/src/Hooks/queries/usePromo'
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
  try {
    // The live campaigns (the twelve updates, Part 2, 2026-11-05), in the shape usePromo's query returns. Without this
    // the Hot Deals switch, the phone strip and the home sliders appeared after load and pushed the page down.
    // `offset: 0` — the server cannot know this device's clock; the browser's own request (it is stale by the time the
    // page hydrates) sets it. A campaign that ended after this HTML was cached is hidden by the browser at once.
    const body = (await getServerV2Campaigns())?.data || {}
    qc.setQueryData(['campaigns', STOREFRONT_CODE], { ...slimCampaigns(body), offset: 0 })
  } catch {
    // Unreachable → the campaign surfaces fill in when the client fetch lands.
  }

  // THE MENU IS NOT IN THE PAGE (the performance pass, 2026-11-05). Item 36 put its data here so the first hover never
  // met "Loading the menu…"; measured, that was 32 KB of script inside EVERY page, read by the browser before its
  // first paint (the first paint of a category page came ~250 ms later than the old build's with the same first
  // byte). The developer's brief allowed either way of having it ready; it now takes the other one: the browser asks
  // for the menu ONCE, the moment the page has loaded (useMenu — not on a hover), and keeps it for the session, so
  // every later page has it before it has finished loading. The home, whose category row draws from the menu,
  // carries it itself (Watchizer's app/(main)/page.jsx).

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      {/* the live offers, read once for every product card below (usePromo.js, 2026-11-06) — INSIDE the boundary,
          so the server render has the campaigns and a card's offer strip is in the HTML */}
      <LiveOffersProvider>
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
      </LiveOffersProvider>
    </HydrationBoundary>
  )
}
