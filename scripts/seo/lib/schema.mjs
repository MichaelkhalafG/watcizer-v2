// Structured-data checks for the SEO crawler, after Google Search Central's rich-result docs
// (Product / merchant listing, Organization, BreadcrumbList, Article, FAQPage, WebSite, VideoObject)
// and schema.org. ERROR = Google marks the item invalid or the property is required; WARN =
// recommended / Search Console "missing field"; NOTE = facts worth printing (not a fault).

const AVAIL = new Set(['InStock', 'OutOfStock', 'BackOrder', 'PreOrder', 'PreSale', 'Discontinued', 'InStoreOnly', 'LimitedAvailability', 'OnlineOnly', 'SoldOut', 'Reserved', 'MadeToOrder'].map((x) => `https://schema.org/${x}`))

function types(node) {
  const t = node?.['@type']
  return Array.isArray(t) ? t : t ? [t] : []
}

function arr(v) {
  return v === undefined || v === null ? [] : Array.isArray(v) ? v : [v]
}

function isAbs(u) {
  return typeof u === 'string' && /^https?:\/\//.test(u)
}

function imgUrls(v) {
  return arr(v).map((x) => (typeof x === 'string' ? x : x?.url || x?.contentUrl || '')).filter(Boolean)
}

/** Every top-level item, @graph flattened. */
export function items(blocks) {
  const out = []
  for (const b of blocks) {
    if (!b.ok) {
      out.push({ parseError: b.error })
      continue
    }
    for (const d of arr(b.data)) {
      if (d && d['@graph']) for (const g of arr(d['@graph'])) out.push({ ...g, '@context': g['@context'] || d['@context'] })
      else out.push(d)
    }
  }
  return out
}

function checkOffer(o, ctx, E, W, N) {
  if (!o || typeof o !== 'object') return E.push('offers: not an object')
  if (types(o).includes('AggregateOffer')) {
    if (o.lowPrice === undefined) E.push('AggregateOffer.lowPrice missing')
    if (!o.priceCurrency) E.push('AggregateOffer.priceCurrency missing')
    return
  }
  const price = o.price ?? o.priceSpecification?.price
  if (price === undefined || price === '' || price === null) E.push('offers.price missing')
  else if (!/^\d+(\.\d+)?$/.test(String(price))) E.push(`offers.price not a plain number (${JSON.stringify(price)})`)
  else if (+price <= 0) E.push('offers.price is 0')
  if (!o.priceCurrency && !o.priceSpecification?.priceCurrency) E.push('offers.priceCurrency missing')
  if (!o.availability) W.push('offers.availability missing')
  else if (!AVAIL.has(String(o.availability).replace('http://', 'https://'))) E.push(`offers.availability invalid (${o.availability})`)
  if (!o.itemCondition) W.push('offers.itemCondition missing')
  if (!o.url) W.push('offers.url missing')
  else if (ctx.canonical && o.url !== ctx.canonical) W.push('offers.url differs from the canonical')
  if (!o.priceValidUntil) N.push('offers.priceValidUntil absent')
  else if (Date.parse(o.priceValidUntil) < Date.now()) E.push('offers.priceValidUntil is in the past')
  if (!o.shippingDetails) W.push('offers.shippingDetails missing')
  else {
    for (const s of arr(o.shippingDetails)) {
      if (!s.shippingRate) W.push('shippingDetails.shippingRate missing')
      if (!s.shippingDestination) W.push('shippingDetails.shippingDestination missing')
      if (!s.deliveryTime) W.push('shippingDetails.deliveryTime missing')
    }
  }
  if (!o.hasMerchantReturnPolicy) W.push('offers.hasMerchantReturnPolicy missing')
  else for (const r of arr(o.hasMerchantReturnPolicy)) {
    if (!r.applicableCountry) W.push('returnPolicy.applicableCountry missing')
    if (!r.returnPolicyCategory) W.push('returnPolicy.returnPolicyCategory missing')
  }
  N.push(`price=${price} ${o.priceCurrency || ''} ${String(o.availability || '').replace(/^https?:\/\/schema.org\//, '')}`)
}

function checkProduct(p, ctx, E, W, N) {
  if (!p.name) E.push('Product.name missing')
  const imgs = imgUrls(p.image)
  if (!imgs.length) E.push('Product.image missing')
  else {
    if (imgs.some((u) => !isAbs(u))) E.push('Product.image not absolute')
    N.push(`images=${imgs.length}`)
    if (ctx.ogImage && !imgs.includes(ctx.ogImage)) W.push('og:image is not one of Product.image')
  }
  if (!p.offers && !p.review && !p.aggregateRating) E.push('Product needs offers, review or aggregateRating')
  for (const o of arr(p.offers)) checkOffer(o, ctx, E, W, N)
  if (!p.brand?.name && typeof p.brand !== 'string') W.push('Product.brand.name missing')
  if (!p.sku) W.push('Product.sku missing')
  if (!p.mpn) W.push('Product.mpn missing')
  if (!p.gtin && !p.gtin8 && !p.gtin12 && !p.gtin13 && !p.gtin14) N.push('no gtin')
  if (!p.description) W.push('Product.description missing')
  if (!p.color) N.push('no color')
  if (!p.material) N.push('no material')
  if (p.aggregateRating) N.push(`aggregateRating=${p.aggregateRating.ratingValue}/${p.aggregateRating.reviewCount || p.aggregateRating.ratingCount}`)
  if (p.sku) N.push(`sku=${p.sku}`)
}

function checkBreadcrumb(b, ctx, E, W, N) {
  const list = arr(b.itemListElement)
  if (!list.length) return E.push('BreadcrumbList.itemListElement empty')
  if (list.length < 2) W.push('BreadcrumbList has fewer than 2 items')
  list.forEach((li, i) => {
    if (li.position === undefined) E.push(`ListItem ${i + 1}: position missing`)
    const name = li.name ?? li.item?.name
    if (!name) E.push(`ListItem ${i + 1}: name missing`)
    const item = typeof li.item === 'string' ? li.item : li.item?.['@id'] || li.item?.url
    if (!item && i < list.length - 1) E.push(`ListItem ${i + 1}: item missing`)
    if (item && !isAbs(item)) E.push(`ListItem ${i + 1}: item not absolute`)
    if (item && /\/listing\?/.test(item)) W.push(`ListItem ${i + 1}: points at a /listing? filter URL`)
  })
  N.push('crumbs=' + list.map((li) => li.name ?? li.item?.name).join(' > ').slice(0, 160))
}

function checkOrg(o, ctx, E, W, N) {
  if (!o.name) E.push(`${types(o)[0]}.name missing`)
  if (!o.url) W.push(`${types(o)[0]}.url missing`)
  if (!o.logo && types(o).some((t) => /Organization|OnlineStore|OnlineBusiness/.test(t))) W.push(`${types(o)[0]}.logo missing`)
  for (const k of ['sameAs', 'contactPoint', 'address', 'foundingDate', 'hasMerchantReturnPolicy', 'hasShippingService', 'telephone']) {
    if (o[k] === undefined) N.push(`no ${k}`)
  }
  if (o.foundingDate) N.push(`foundingDate=${o.foundingDate}`)
  if (o.sameAs) N.push(`sameAs=${arr(o.sameAs).length}`)
  const rp = arr(o.hasMerchantReturnPolicy)[0]
  if (rp) N.push(`returnPolicy days=${rp.merchantReturnDays} fees=${rp.returnFees} method=${rp.returnMethod}`)
}

function checkArticle(a, ctx, E, W, N) {
  if (!a.headline) E.push('Article.headline missing')
  if (!imgUrls(a.image).length) W.push('Article.image missing')
  if (!a.datePublished) W.push('Article.datePublished missing')
  if (!a.dateModified) W.push('Article.dateModified missing')
  const au = arr(a.author)
  if (!au.length) W.push('Article.author missing')
  for (const x of au) {
    if (!x?.name) W.push('author.name missing')
    if (!x?.url) W.push('author.url missing')
    N.push(`author=${types(x)[0] || '?'}:${x?.name || ''}`)
  }
}

function checkFaq(f, ctx, E, W, N) {
  const q = arr(f.mainEntity)
  if (!q.length) E.push('FAQPage.mainEntity empty')
  q.forEach((x, i) => {
    if (!x.name) E.push(`Question ${i + 1}: name missing`)
    if (!x.acceptedAnswer?.text) E.push(`Question ${i + 1}: acceptedAnswer.text missing`)
  })
  N.push(`questions=${q.length}`)
}

function checkWebSite(s, ctx, E, W, N) {
  if (!s.name) W.push('WebSite.name missing')
  if (!s.url) W.push('WebSite.url missing')
  if (s.potentialAction) N.push('SearchAction present')
}

function checkVideo(v, ctx, E, W) {
  if (!v.name) E.push('VideoObject.name missing')
  if (!v.thumbnailUrl) E.push('VideoObject.thumbnailUrl missing')
  if (!v.uploadDate) E.push('VideoObject.uploadDate missing')
}

export function validate(blocks, ctx = {}) {
  const out = []
  for (const it of items(blocks)) {
    if (it.parseError) {
      out.push({ type: '(parse error)', errors: [it.parseError], warnings: [], notes: [] })
      continue
    }
    const t = types(it)
    const E = []
    const W = []
    const N = []
    if (!it['@context'] && !ctx.inGraph) W.push('@context missing')
    if (t.includes('Product') || t.includes('ProductGroup')) checkProduct(it, ctx, E, W, N)
    else if (t.includes('BreadcrumbList')) checkBreadcrumb(it, ctx, E, W, N)
    else if (t.some((x) => /Organization|OnlineStore|OnlineBusiness|Store|LocalBusiness|Corporation/.test(x))) checkOrg(it, ctx, E, W, N)
    else if (t.some((x) => /Article|BlogPosting|NewsArticle/.test(x))) checkArticle(it, ctx, E, W, N)
    else if (t.includes('FAQPage')) checkFaq(it, ctx, E, W, N)
    else if (t.includes('WebSite')) checkWebSite(it, ctx, E, W, N)
    else if (t.includes('VideoObject')) checkVideo(it, ctx, E, W, N)
    out.push({ type: t.join('+') || '(no @type)', errors: E, warnings: W, notes: N })
  }
  return out
}
