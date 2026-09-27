import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog } from './serverCatalog'
import { projectCatalogForHydration } from './catalogProjection'

// The trimmed catalogue copy, embedded ONLY where a page still reads the whole catalogue (C-1
// stage 3, 2026-09-27): home, product and offer detail, cart, checkout, account. It used to sit in
// the (main) layout and ride on every page — listing, brand/category pages, blog, login — ~3 MB of
// HTML each. Stage 4 moves these readers to the server too and deletes this.
export default async function CatalogBoundary({ children }) {
  const qc = new QueryClient()
  try {
    qc.setQueryData(['products'], projectCatalogForHydration(await getServerCatalog()))
  } catch {
    // Catalogue unreachable server-side → the client fetches it and shows the inline error/retry.
  }
  return <HydrationBoundary state={dehydrate(qc)}>{children}</HydrationBoundary>
}
