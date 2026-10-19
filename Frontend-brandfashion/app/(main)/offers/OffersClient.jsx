'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import { money } from '@/src/lib/cardData'
import './offers.css'

// The offers page's body in the shopper's language (round 5, 2026-10-18). Each offer is the dashboard's rule:
// its name as the admin wrote it, what the shopper must do (the conditions, ALL of them), what they get (the
// rewards), and until when. Products, categories and brands named by a rule are described, not looked up —
// "a selected product" — until the page has a design that shows them. An empty list is said plainly.

const T = {
  en: {
    title: 'Offers',
    empty: 'No offers are running right now.',
    browse: 'Browse all products',
    until: (d) => `Until ${d}`,
    get: 'You get',
    when: 'When',
  },
  ar: {
    title: 'العروض',
    empty: 'لا توجد عروض جارية الآن.',
    browse: 'تصفح كل المنتجات',
    until: (d) => `حتى ${d}`,
    get: 'تحصل على',
    when: 'عند',
  },
}

const qty = (n) => Number(n) || 1

function condition(c, lang) {
  const ar = lang === 'ar'
  switch (c.type) {
    case 'cart_subtotal_min':
      return ar ? `طلب بقيمة ${money(c.amount, 'ar')} أو أكثر` : `an order of ${money(c.amount, 'en')} or more`
    case 'product_quantity_min':
    case 'variant_quantity_min':
      return ar ? `شراء ${qty(c.quantity)} أو أكثر من منتج مختار` : `buying ${qty(c.quantity)} or more of a selected product`
    case 'category_quantity_min':
      return ar ? `شراء ${qty(c.quantity)} أو أكثر من فئة مختارة` : `buying ${qty(c.quantity)} or more from a selected category`
    case 'brand_quantity_min':
      return ar ? `شراء ${qty(c.quantity)} أو أكثر من علامة مختارة` : `buying ${qty(c.quantity)} or more from a selected brand`
    case 'distinct_lines_min':
      return ar ? `${qty(c.quantity)} منتجات مختلفة أو أكثر في السلة` : `${qty(c.quantity)} or more different products in the cart`
    case 'payment_method_in':
      return ar ? 'الدفع بطريقة مختارة' : 'paying with a selected method'
    default:
      return null
  }
}

function reward(r, lang) {
  const ar = lang === 'ar'
  switch (r.type) {
    case 'free_product':
    case 'free_variant':
      return qty(r.quantity) > 1 ? (ar ? `${qty(r.quantity)} هدايا مجانية` : `${qty(r.quantity)} free gifts`) : ar ? 'هدية مجانية' : 'a free gift'
    case 'free_shipping':
      return ar ? 'شحن مجاني' : 'free shipping'
    case 'percent_discount':
      return ar ? `خصم ${Number(r.amount)}%` : `${Number(r.amount)}% off`
    case 'fixed_discount':
      return ar ? `خصم ${money(r.amount, 'ar')}` : `${money(r.amount, 'en')} off`
    default:
      return null
  }
}

const day = (stamp, lang) => {
  const d = new Date(String(stamp).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return String(stamp).slice(0, 10)
  return d.toLocaleDateString(lang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
}

export default function OffersClient({ offers = [] }) {
  const lang = useUIStore((s) => s.language) === 'ar' ? 'ar' : 'en'
  const t = T[lang]
  const join = (parts) => parts.filter(Boolean).join(lang === 'ar' ? '، ' : ', ')

  return (
    <div className="bf-offers" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      <h1 className="bf-offers__h1">{t.title}</h1>
      {offers.length === 0 ? (
        <div className="bf-offers__empty">
          <p>{t.empty}</p>
          <Link className="bf-offers__browse" href="/listing">{t.browse}</Link>
        </div>
      ) : (
        <ul className="bf-offers__list">
          {offers.map((o) => (
            <li key={o.id} className="bf-offers__item">
              <h2 className="bf-offers__name">{o.name}</h2>
              <p className="bf-offers__line"><span className="bf-offers__k">{t.when}</span> {join((o.conditions || []).map((c) => condition(c, lang)))}</p>
              <p className="bf-offers__line"><span className="bf-offers__k">{t.get}</span> {join((o.rewards || []).map((r) => reward(r, lang)))}</p>
              <p className="bf-offers__until">{t.until(day(o.ends_at, lang))}</p>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
