import { requestLang } from '@/src/lib/requestLang'
import { getServerV2Home } from '@/src/lib/serverV2'
import NotFoundView from '@/src/Components/NotFound/NotFoundView'

// B8 (spec §10.8 "404", 2026-10-19): "This page has moved or never existed", a search field, the category chips on
// their aisle tints and a rail — in the request's language. It used to be English only, with two buttons.
export const metadata = {
  title: 'Page not found | Brand Fashion',
  robots: 'noindex, follow',
}

export default async function NotFound() {
  const { lang } = await requestLang()
  let home = null
  try {
    home = await getServerV2Home()
  } catch {
    home = null // a 404 never fails for want of a rail
  }
  const categories = (home?.categories || []).filter((c) => c.count > 0).slice(0, 3)
  return <NotFoundView lang={lang} categories={categories} deals={home?.deals || []} />
}
