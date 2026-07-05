import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import serverHttp from '@/src/lib/serverFetch'
import { tablesQueryFn } from '@/src/Hooks/queries/useTables'
import { productsQueryFn } from '@/src/Hooks/queries/useProducts'
import { MyProvider } from '@/src/Context/MyProvider'
import Header from '@/src/Components/Header/Header'
import CartModalHost from '../cart-modal-host'
import SkipLink from './skip-link'
import DesktopFooter from './desktop-footer'

// Main app chrome (mirrors Frontend App.jsx MainApp): skip link → sticky header
// → #main-content landmark → desktop-only footer.
//
// CRITICAL for SSR data: the PUBLIC catalog is prefetched HERE and the
// HydrationBoundary + MyProvider wrap EVERYTHING that reads it (Header nav, the
// cart drawer, and the page). Because MyProvider now consumes the queries BELOW
// the boundary, the server render reads already-hydrated data — so product cards
// render in the SSR HTML instead of skeletons. try/catch: on a server-side
// catalog failure we hydrate an empty cache and the client refetches + shows the
// existing inline error/retry.
export default async function MainLayout({ children }) {
  const qc = new QueryClient()
  try {
    const tables = await qc.fetchQuery({
      queryKey: ['tables'],
      queryFn: tablesQueryFn(serverHttp),
    })
    await qc.prefetchQuery({
      queryKey: ['products'],
      queryFn: productsQueryFn(tables, serverHttp),
    })
  } catch {
    // Catalog unreachable server-side → client fetches + shows error/retry.
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <MyProvider>
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
      </MyProvider>
    </HydrationBoundary>
  )
}
