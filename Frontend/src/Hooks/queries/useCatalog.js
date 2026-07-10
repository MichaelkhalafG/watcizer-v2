import { useCallback, useMemo } from 'react'
import { useUIStore } from '../../Store/uiStore'
import { useTables } from './useTables'
import { useProducts } from './useProducts'

// Composite catalog hook — the exact trio consumers used to read off MyContext:
//   • products   → the localized product array (EN/AR picked by language)
//   • tables     → the lookup tables
//   • isFetching → "catalog still loading" (true until products resolve or error)
//   • isError    → the tables OR products fetch failed (fetch-layer failure —
//                  distinct from a render crash, which the ErrorBoundary handles)
//   • refetch    → retry whichever underlying query failed
//
// Composes useTables + useProducts. TanStack Query dedupes by queryKey, so any
// number of components (Header, Home, MyProvider, …) calling this share ONE
// tables fetch and ONE products fetch/transform — no duplicate network or work.
export const useCatalog = () => {
  const language = useUIStore((s) => s.language)
  const tablesQuery = useTables()
  const tablesData = tablesQuery.data
  const productsQuery = useProducts(tablesData)
  const catalog = productsQuery.data

  const products = useMemo(
    () => (language === 'en' ? catalog?.productsEn ?? [] : catalog?.productsAr ?? []),
    [language, catalog],
  )

  // Either layer failing is a catalog error. (Previously a tables failure left
  // useProducts disabled forever, so isFetching never resolved → infinite
  // skeleton; folding the tables error in fixes that too.)
  const isError = tablesQuery.isError || productsQuery.isError

  // Retry only what actually failed. If tables errored, refetching it re-enables
  // useProducts automatically once the tables data arrives.
  const refetch = useCallback(() => {
    if (tablesQuery.isError) tablesQuery.refetch()
    if (productsQuery.isError) productsQuery.refetch()
  }, [tablesQuery, productsQuery])

  return {
    products,
    tables: tablesData ?? {},
    ratings: catalog?.ratings ?? [],
    isFetching: !catalog && !isError,
    isError,
    refetch,
  }
}

export default useCatalog
