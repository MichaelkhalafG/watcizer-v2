import Link from '@/src/Components/LocaleLink'

// ONE live data block of an article (SEO Phase 3, 2026-11-14) — a TWIN. Core resolved it (every LIVE product of its
// scope, in stock first); this only draws it, in the server HTML. THE STOCK RULE: an out-of-stock product keeps its
// card with a clear "currently unavailable" badge and a "notify me" link to its page (where the notify-me form is),
// never a buy button; nothing here adds to the cart.

const money = (n, ar) => `${Math.round(Number(n) || 0).toLocaleString('en-US')} ${ar ? 'ج.م' : 'EGP'}`
const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

function Price({ price, ar }) {
  if (!price) return null
  const sale = price.has_sale && Number(price.sale_amount) > 0 && Number(price.sale_amount) < Number(price.amount)
  return (
    <span className="wz-dblock__price">
      {sale ? (
        <>
          <strong>{money(price.sale_amount, ar)}</strong> <s>{money(price.amount, ar)}</s>
        </>
      ) : (
        <strong>{money(price.amount, ar)}</strong>
      )}
    </span>
  )
}

function srcSetOf(image) {
  const webp = (image?.srcset || []).filter((s) => s.format === 'webp' && s.src && s.width)
  return webp.length ? webp.map((s) => `${s.src} ${s.width}w`).join(', ') : undefined
}

export function BlockCard({ item, lang }) {
  const ar = lang === 'ar'
  const href = `/product/${item.slug}`
  const title = pick(item.title, lang)
  return (
    <li className={`wz-dblock__card${item.in_stock ? '' : ' is-out'}`}>
      <Link href={href} className="wz-dblock__link">
        {item.image?.src ? (
          <img
            src={item.image.src}
            srcSet={srcSetOf(item.image)}
            sizes="(max-width: 600px) 45vw, 200px"
            width={item.image.width || 400}
            height={item.image.height || 400}
            alt={item.image.alt || title}
            loading="lazy"
            decoding="async"
          />
        ) : null}
        {item.in_stock ? null : <span className="wz-dblock__badge">{ar ? 'غير متوفر حاليًا' : 'Currently unavailable'}</span>}
        {item.brand?.name ? <span className="wz-dblock__brand">{pick(item.brand.name, lang)}</span> : null}
        <span className="wz-dblock__title">{title}</span>
        <Price price={item.price} ar={ar} />
      </Link>
      <Link href={href} className={`wz-dblock__cta${item.in_stock ? '' : ' is-notify'}`}>
        {item.in_stock ? (ar ? 'شاهد التفاصيل' : 'View details') : ar ? 'أبلغني عند التوفر' : 'Notify me when available'}
      </Link>
    </li>
  )
}

const HEADINGS = {
  'new-arrivals': { en: 'New arrivals', ar: 'وصل حديثًا' },
  'in-stock-alternatives': { en: 'Available now instead', ar: 'متوفر الآن بدلًا منه' },
}

function Compare({ block, lang }) {
  const ar = lang === 'ar'
  return (
    <div className="wz-dblock wz-dblock--compare">
      <table>
        <thead>
          <tr>
            <th>{ar ? 'الموديل' : 'Model'}</th>
            <th>{ar ? 'المنتج' : 'Product'}</th>
            <th>{ar ? 'السعر' : 'Price'}</th>
            <th>{ar ? 'التوفر' : 'Availability'}</th>
          </tr>
        </thead>
        <tbody>
          {block.items.map((item) => (
            <tr key={item.id}>
              <td>{item.model || '—'}</td>
              <td>
                <Link href={`/product/${item.slug}`}>{pick(item.title, lang)}</Link>
              </td>
              <td>
                <Price price={item.price} ar={ar} />
              </td>
              <td>{item.in_stock ? (ar ? 'متوفر' : 'In stock') : ar ? 'غير متوفر حاليًا' : 'Currently unavailable'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default function DataBlock({ block, lang }) {
  if (!block?.items?.length) return null
  if (block.kind === 'compare') return <Compare block={block} lang={lang} />
  const ar = lang === 'ar'
  const heading = HEADINGS[block.kind]?.[ar ? 'ar' : 'en']
  const more = block.kind !== 'in-stock-alternatives' && block.total > block.items.length
  return (
    <section className={`wz-dblock wz-dblock--${block.kind}`} aria-label={heading || (ar ? 'منتجات' : 'Products')}>
      {heading ? <p className="wz-dblock__heading">{heading}</p> : null}
      <ul className="wz-dblock__grid">
        {block.items.map((item) => (
          <BlockCard key={item.id} item={item} lang={lang} />
        ))}
      </ul>
      {more ? (
        <p className="wz-dblock__count">
          {ar
            ? `${block.total.toLocaleString('en-US')} موديل في المتجر، منها ${block.available.toLocaleString('en-US')} متوفر الآن`
            : `${block.total.toLocaleString('en-US')} models in the shop, ${block.available.toLocaleString('en-US')} available now`}
        </p>
      ) : null}
    </section>
  )
}
