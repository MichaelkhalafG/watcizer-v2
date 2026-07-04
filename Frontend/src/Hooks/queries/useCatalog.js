import { useMemo } from 'react'
import { useUIStore } from '../../Store/uiStore'
import { useTables } from './useTables'
import { useProducts } from './useProducts'

// Composite catalog hook — the exact trio consumers used to read off MyContext:
//   • products   → the localized product array (EN/AR picked by language)
//   • tables     → the lookup tables
//   • isFetching → "catalog still loading" (true until products resolve or error)
//
// Composes useTables + useProducts. TanStack Query dedupes by queryKey, so any
// number of components (Header, Home, MyProvider, …) calling this share ONE
// tables fetch and ONE products fetch/transform — no duplicate network or work.
export const useCatalog = () => {
  const language = useUIStore((s) => s.language)
  const { data: tablesData } = useTables()
  const productsQuery = useProducts(tablesData)
  const catalog = productsQuery.data

  const products = useMemo(
    () => (language === 'en' ? catalog?.productsEn ?? [] : catalog?.productsAr ?? []),
    [language, catalog],
  )

  return {
    products,
    tables: tablesData ?? {},
    ratings: catalog?.ratings ?? [],
    isFetching: !catalog && !productsQuery.isError,
  }
}

export default useCatalog
