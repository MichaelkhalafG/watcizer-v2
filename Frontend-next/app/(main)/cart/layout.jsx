import CatalogBoundary from '@/src/lib/CatalogBoundary'

// This page still reads the whole catalogue in the browser (C-1 stage 4 moves it to the server).
export default function Layout({ children }) {
  return <CatalogBoundary>{children}</CatalogBoundary>
}
