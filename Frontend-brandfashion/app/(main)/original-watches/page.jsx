import { OriginalWatchesPage, originalWatchesMetadata } from '@/src/lib/collectionListing'

// /original-watches (Michael, 2026-11-14): the watches the hide-from-search rule leaves alone. A TWIN.
export const revalidate = 300

export async function generateMetadata() {
  return originalWatchesMetadata()
}

export default async function Page() {
  return <OriginalWatchesPage />
}
