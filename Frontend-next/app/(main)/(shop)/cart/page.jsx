import Cart from '@/src/PageViews/Cart/Cart'
import { getServerV2Pickup } from '@/src/lib/serverV2'

// A SERVER page since click & collect (2026-11-14): it reads whether this shop offers "pick up in store" (held 30 s,
// never the page's failure), so the cart's "or pick up in store" line is in the first paint. The cart itself is the
// client Cart; the title comes from ./layout.jsx.
export const revalidate = 30

export default async function CartPage() {
  const pickup = await getServerV2Pickup().then((body) => body || null, () => null)
  return <Cart initialPickup={pickup} />
}
