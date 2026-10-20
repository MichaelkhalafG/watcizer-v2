import { requestLang } from '@/src/lib/requestLang'

/*
 * The cart route's own metadata (survey item 5, 2026-10-22).
 *
 * `cart/page.jsx` is a `'use client'` component, and a client component CANNOT export `metadata`
 * or `generateMetadata` — Next ignores them. So the page inherited the ROOT layout's title, which
 * is the shop's Arabic home-page title, and an English shopper's browser tab, bookmark and share
 * sheet all read it. A layout is the one place a client page can be given a title without turning
 * the page itself into a server component, which would mean restructuring a route that works.
 *
 * It follows the URL's language, as every other route's metadata does (S-AR stage 1): English on
 * `/cart`, Arabic on `/ar/cart` — never the shopper's cookie.
 *
 * NO BRAND SUFFIX, deliberately. Every other title here ends "| Watchizer", but the brand name
 * lives in each app's own root layout, which is not a twin — so putting it here would mean either
 * a new per-app content entry or splitting this file into two. The defect was an Arabic title on
 * an English page, and that is fixed either way; a suffix can be added the day the brand name has
 * one shared home.
 */

const TEXT = {
  en: { title: 'Cart', description: 'The items in your cart.' },
  ar: { title: 'سلة التسوق', description: 'المنتجات في سلة التسوق.' },
}

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const t = TEXT[urlLang] ?? TEXT.en

  return { title: t.title, description: t.description }
}

export default function CartLayout({ children }) {
  return children
}
